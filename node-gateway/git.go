package main

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"golang.org/x/crypto/ssh"
)

const (
	gitAudience  = "git-gateway"
	gitMaxOutput = 64 * 1024
	gitMaxRepos  = 200
)

// GitCredential is an HTTPS username/token pair for one host.
type GitCredential struct {
	Host     string `json:"host"`
	Username string `json:"username"`
	Password string `json:"password"`
}

type GitRequest struct {
	Action      string          `json:"action"`
	Path        string          `json:"path"`
	URL         string          `json:"url"`
	Branch      string          `json:"branch"`
	Regenerate  bool            `json:"regenerate"`
	Credentials []GitCredential `json:"credentials"`
}

type GitRepo struct {
	Path    string `json:"path"`
	Branch  string `json:"branch"`
	Commit  string `json:"commit"`
	Remote  string `json:"remote"`
	Changes int    `json:"changes"`
	Behind  int    `json:"behind"`
	Ahead   int    `json:"ahead"`
	Updated int64  `json:"updated"`
}

type GitCommit struct {
	Hash    string `json:"hash"`
	Short   string `json:"short"`
	Author  string `json:"author"`
	Time    int64  `json:"time"`
	Subject string `json:"subject"`
}

type GitResponse struct {
	OK        bool        `json:"ok"`
	Output    string      `json:"output,omitempty"`
	Repos     []GitRepo   `json:"repos,omitempty"`
	Commits   []GitCommit `json:"commits,omitempty"`
	PublicKey string      `json:"public_key,omitempty"`
}

var (
	gitBranchPattern = regexp.MustCompile(`^[A-Za-z0-9._/-]{1,200}$`)
	gitScpPattern    = regexp.MustCompile(`^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._~/-]+$`)
	gitHostPattern   = regexp.MustCompile(`^[A-Za-z0-9.-]+(:[0-9]{1,5})?$`)
	gitLocks         sync.Map // server uuid -> *sync.Mutex
)

// shellQuote wraps s in single quotes for bash.
func shellQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", `'\''`) + "'"
}

// cleanRepoPath turns a user path like "/garrysmod/addons/x" into a clean
// relative path inside the volume, or an error if it escapes.
func cleanRepoPath(p string) (string, error) {
	if strings.ContainsAny(p, "\x00\n\r\t") {
		return "", errors.New("invalid path")
	}
	clean := path.Clean("/" + p)
	if clean == "/" {
		return "", nil
	}
	return strings.TrimPrefix(clean, "/"), nil
}

func validRemoteURL(raw string) bool {
	if strings.HasPrefix(raw, "-") || strings.ContainsAny(raw, " \t\n\r\x00") {
		return false
	}
	if gitScpPattern.MatchString(raw) {
		return true
	}
	u, err := url.Parse(raw)
	if err != nil || u.Host == "" {
		return false
	}
	return u.Scheme == "https" || u.Scheme == "ssh"
}

// redactURL removes passwords/tokens embedded in remote URLs.
func redactURL(raw string) string {
	u, err := url.Parse(raw)
	if err != nil || u.User == nil {
		return raw
	}
	if _, has := u.User.Password(); has {
		u.User = url.UserPassword(u.User.Username(), "REDACTED")
	} else if u.Scheme == "https" {
		u.User = url.User("REDACTED")
	}
	return u.String()
}

func (g *Gateway) gitKeyDir(uuid string) string {
	return filepath.Join(filepath.Dir(g.cfg.DataDir), "git-keys", uuid)
}

// ensureDeployKey returns the server's deploy public key, creating it if needed.
func (g *Gateway) ensureDeployKey(uuid string, regenerate bool) (string, error) {
	dir := g.gitKeyDir(uuid)
	priv := filepath.Join(dir, "id_ed25519")
	pub := priv + ".pub"

	if !regenerate {
		if b, err := os.ReadFile(pub); err == nil {
			return strings.TrimSpace(string(b)), nil
		}
	}

	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	if err := os.Chown(dir, g.cfg.UID, g.cfg.GID); err != nil {
		return "", err
	}

	pubKey, privKey, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return "", err
	}
	comment := "pelican-" + uuid[:8]
	block, err := ssh.MarshalPrivateKey(privKey, comment)
	if err != nil {
		return "", err
	}
	sshPub, err := ssh.NewPublicKey(pubKey)
	if err != nil {
		return "", err
	}
	pubLine := strings.TrimSpace(string(ssh.MarshalAuthorizedKey(sshPub))) + " " + comment

	for file, data := range map[string][]byte{priv: pem.EncodeToMemory(block), pub: []byte(pubLine + "\n")} {
		if err := os.WriteFile(file+".tmp", data, 0o600); err != nil {
			return "", err
		}
		if err := os.Chown(file+".tmp", g.cfg.UID, g.cfg.GID); err != nil {
			return "", err
		}
		if err := os.Rename(file+".tmp", file); err != nil {
			return "", err
		}
	}
	return pubLine, nil
}

// gitPreamble configures git safely and installs a credential helper that
// answers from the supplied HTTPS credentials.
func gitPreamble(creds []GitCredential, hasKey bool) (string, error) {
	var lines []string
	for _, c := range creds {
		if !gitHostPattern.MatchString(c.Host) || strings.ContainsAny(c.Username+c.Password, "\t\n\r\x00") {
			return "", fmt.Errorf("invalid credential for %q", c.Host)
		}
		lines = append(lines, c.Host+"\t"+c.Username+"\t"+c.Password)
	}
	credsB64 := base64.StdEncoding.EncodeToString([]byte(strings.Join(lines, "\n") + "\n"))

	sshCmd := "ssh -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/git-key/known_hosts -o BatchMode=yes"
	if hasKey {
		sshCmd += " -i /git-key/id_ed25519 -o IdentitiesOnly=yes"
	}

	return `set -o pipefail
umask 022
export HOME=/tmp GIT_TERMINAL_PROMPT=0 GIT_ASKPASS=/bin/false
export GIT_SSH_COMMAND=` + shellQuote(sshCmd) + `
export GIT_CONFIG_COUNT=4
export GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0='*'
export GIT_CONFIG_KEY_1=core.hooksPath GIT_CONFIG_VALUE_1=/dev/null
export GIT_CONFIG_KEY_2=core.fsmonitor GIT_CONFIG_VALUE_2=false
export GIT_CONFIG_KEY_3=credential.helper GIT_CONFIG_VALUE_3='!/tmp/pgit-cred'
(umask 077; printf '%s' ` + shellQuote(credsB64) + ` | base64 -d > /tmp/pgit-creds)
cat > /tmp/pgit-cred <<'PGITEOF'
#!/bin/bash
[ "$1" = get ] || exit 0
host=
while IFS='=' read -r k v; do [ "$k" = host ] && host=$v; done
while IFS=$'\t' read -r h u p; do
    if [ -n "$h" ] && [ "$h" = "$host" ]; then printf 'username=%s\npassword=%s\n' "$u" "$p"; exit 0; fi
done < /tmp/pgit-creds
PGITEOF
chmod 700 /tmp/pgit-cred
cd /home/container
`, nil
}

// runGit runs a bash script in a throwaway container with the server's volume.
func (g *Gateway) runGit(ctx context.Context, uuid, script string, creds []GitCredential, timeout time.Duration) (string, int, error) {
	if _, err := g.ensureDeployKey(uuid, false); err != nil {
		return "", -1, err
	}
	_, keyErr := os.Stat(filepath.Join(g.gitKeyDir(uuid), "id_ed25519"))
	preamble, err := gitPreamble(creds, keyErr == nil)
	if err != nil {
		return "", -1, err
	}
	if err := g.manager.ensureNetwork(ctx); err != nil {
		return "", -1, err
	}

	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	suffix := make([]byte, 4)
	_, _ = rand.Read(suffix)
	name := "pelican-git-" + uuid[:8] + "-" + hex.EncodeToString(suffix)

	cmd := exec.CommandContext(ctx, "docker", "run", "--rm", "-i",
		"--name", name,
		"--label", containerLabel+"=1",
		"--label", containerLabel+".git=1",
		"--network", g.cfg.Network,
		"--user", fmt.Sprintf("%d:%d", g.cfg.UID, g.cfg.GID),
		"--memory", "1g",
		"--cpus", "1",
		"--pids-limit", "256",
		"--security-opt", "no-new-privileges",
		"--cap-drop", "ALL",
		"-v", filepath.Join(g.cfg.VolumesDir, uuid)+":/home/container",
		"-v", g.gitKeyDir(uuid)+":/git-key",
		"-e", "LANG=C.UTF-8",
		"-e", "LC_ALL=C.UTF-8",
		"--entrypoint", "bash",
		g.cfg.SSHImage, "-s")
	cmd.Stdin = strings.NewReader(preamble + script)
	var out bytes.Buffer
	cmd.Stdout = &out
	cmd.Stderr = &out
	cmd.Cancel = func() error {
		_, _ = docker(context.Background(), "rm", "-f", name)
		return cmd.Process.Kill()
	}

	err = cmd.Run()
	output := out.String()
	if len(output) > gitMaxOutput {
		output = output[len(output)-gitMaxOutput:]
	}
	if ctx.Err() == context.DeadlineExceeded {
		return output + "\n(timed out)", -1, nil
	}
	var exitErr *exec.ExitError
	if errors.As(err, &exitErr) {
		return output, exitErr.ExitCode(), nil
	}
	return output, 0, err
}

const gitScanScript = `
find /home/container -maxdepth 8 \( -name node_modules -o -name .cache -o -name .steam \) -prune -o -name .git -print -prune 2>/dev/null | head -n ` + "200" + ` | while IFS= read -r g; do
    d=${g%/.git}
    b=$(git -C "$d" symbolic-ref --short -q HEAD 2>/dev/null || echo "(detached)")
    c=$(git -C "$d" rev-parse --short HEAD 2>/dev/null || true)
    r=$(git -C "$d" remote get-url origin 2>/dev/null || true)
    s=$(git -C "$d" status --porcelain 2>/dev/null | wc -l)
    ab=$(git -C "$d" rev-list --left-right --count '@{u}...HEAD' 2>/dev/null || printf '0\t0')
    t=$(git -C "$d" log -1 --format=%ct 2>/dev/null || echo 0)
    printf 'REPO\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "${d#/home/container}" "$b" "$c" "$r" "$s" "$ab" "$t"
done
`

func parseScan(out string) []GitRepo {
	var repos []GitRepo
	for _, line := range strings.Split(out, "\n") {
		f := strings.Split(line, "\t")
		if len(f) != 9 || f[0] != "REPO" {
			continue
		}
		atoi := func(s string) int { n, _ := strconv.Atoi(strings.TrimSpace(s)); return n }
		t, _ := strconv.ParseInt(strings.TrimSpace(f[8]), 10, 64)
		p := f[1]
		if p == "" {
			p = "/"
		}
		repos = append(repos, GitRepo{
			Path: p, Branch: f[2], Commit: f[3], Remote: redactURL(f[4]),
			Changes: atoi(f[5]), Behind: atoi(f[6]), Ahead: atoi(f[7]), Updated: t,
		})
		if len(repos) >= gitMaxRepos {
			break
		}
	}
	return repos
}

func parseLog(out string) []GitCommit {
	var commits []GitCommit
	for _, rec := range strings.Split(out, "\x1e") {
		f := strings.Split(strings.TrimLeft(rec, "\n"), "\x1f")
		if len(f) != 5 {
			continue
		}
		t, _ := strconv.ParseInt(f[3], 10, 64)
		commits = append(commits, GitCommit{Hash: f[0], Short: f[1], Author: f[2], Time: t, Subject: f[4]})
	}
	return commits
}

func (g *Gateway) handleGit(ctx context.Context, uuid string, req GitRequest) (GitResponse, int) {
	rel, err := cleanRepoPath(req.Path)
	if err != nil {
		return GitResponse{Output: err.Error()}, http.StatusBadRequest
	}
	dir := "/home/container"
	if rel != "" {
		dir += "/" + rel
	}
	hostPath := filepath.Join(g.cfg.VolumesDir, uuid, filepath.FromSlash(rel))

	switch req.Action {
	case "scan":
		out, code, err := g.runGit(ctx, uuid, gitScanScript, nil, 60*time.Second)
		if err != nil || code != 0 {
			return GitResponse{Output: out}, http.StatusBadGateway
		}
		return GitResponse{OK: true, Repos: parseScan(out)}, http.StatusOK

	case "log":
		out, code, err := g.runGit(ctx, uuid, "git -C "+shellQuote(dir)+" log -n 50 --format='%H%x1f%h%x1f%an%x1f%at%x1f%s%x1e'\n", nil, 30*time.Second)
		if err != nil || code != 0 {
			return GitResponse{Output: out}, http.StatusBadGateway
		}
		return GitResponse{OK: true, Commits: parseLog(out)}, http.StatusOK

	case "pull":
		if st, err := os.Stat(filepath.Join(hostPath, ".git")); err != nil || st == nil {
			return GitResponse{Output: "not a git repository"}, http.StatusBadRequest
		}
		script := "cd " + shellQuote(dir) + " && git pull --ff-only --no-rebase 2>&1 && echo && git log -1 --format='Now at %h: %s'\n"
		out, code, err := g.runGit(ctx, uuid, script, req.Credentials, 280*time.Second)
		if err != nil {
			return GitResponse{Output: err.Error()}, http.StatusBadGateway
		}
		return GitResponse{OK: code == 0, Output: out}, http.StatusOK

	case "clone":
		if rel == "" || !validRemoteURL(req.URL) || (req.Branch != "" && (!gitBranchPattern.MatchString(req.Branch) || strings.HasPrefix(req.Branch, "-"))) {
			return GitResponse{Output: "invalid path, URL or branch"}, http.StatusBadRequest
		}
		if entries, err := os.ReadDir(hostPath); err == nil && len(entries) > 0 {
			return GitResponse{Output: "the target folder already exists and is not empty"}, http.StatusBadRequest
		} else if err == nil {
			// An empty folder is fine; git clones into it.
		} else if !os.IsNotExist(err) {
			return GitResponse{Output: "the target path is not a folder"}, http.StatusBadRequest
		}
		branch := ""
		if req.Branch != "" {
			branch = "--branch " + shellQuote(req.Branch) + " "
		}
		script := "git clone --progress " + branch + "-- " + shellQuote(req.URL) + " " + shellQuote(dir) + " 2>&1\n"
		out, code, err := g.runGit(ctx, uuid, script, req.Credentials, 280*time.Second)
		if err != nil {
			return GitResponse{Output: err.Error()}, http.StatusBadGateway
		}
		return GitResponse{OK: code == 0, Output: out}, http.StatusOK

	case "deploy_key":
		key, err := g.ensureDeployKey(uuid, req.Regenerate)
		if err != nil {
			log.Printf("deploy key %s: %v", uuid, err)
			return GitResponse{Output: "could not create deploy key"}, http.StatusInternalServerError
		}
		return GitResponse{OK: true, PublicKey: key}, http.StatusOK
	}

	return GitResponse{Output: "unknown action"}, http.StatusBadRequest
}

// serveGit handles POST {prefix}/git/{uuid}. Called server-to-server by the
// panel, so credentials never pass through the browser.
func (g *Gateway) serveGit(w http.ResponseWriter, r *http.Request, uuid string) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	now := time.Now()
	claims, err := verifyToken(r.URL.Query().Get("token"), g.cfg.Secret, now, gitAudience)
	if err != nil || claims.ServerUUID != uuid {
		http.Error(w, "invalid or expired token", http.StatusUnauthorized)
		return
	}
	if !g.used.claim(claims.ID, claims.Expires, now) {
		http.Error(w, "token already used", http.StatusUnauthorized)
		return
	}

	var req GitRequest
	if err := json.NewDecoder(io.LimitReader(r.Body, 1<<20)).Decode(&req); err != nil || req.Action != claims.Action {
		http.Error(w, "bad request", http.StatusBadRequest)
		return
	}
	if st, err := os.Stat(filepath.Join(g.cfg.VolumesDir, uuid)); err != nil || !st.IsDir() {
		http.Error(w, "server volume not found", http.StatusNotFound)
		return
	}

	// One git operation per server at a time avoids index.lock races.
	lock, _ := gitLocks.LoadOrStore(uuid, &sync.Mutex{})
	lock.(*sync.Mutex).Lock()
	defer lock.(*sync.Mutex).Unlock()

	log.Printf("git %s on %s by %s path=%q", req.Action, uuid, claims.UserUUID, req.Path)
	res, status := g.handleGit(r.Context(), uuid, req)

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(res)
}
