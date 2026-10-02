// Command pelican-vscode-gateway runs openvscode-server containers with a
// Pelican server's volume mounted, and proxies authenticated browser
// sessions to them. Launch tokens are minted by the vscode-files panel plugin
// and signed with the node's daemon token.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"html"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"os/signal"
	"path/filepath"
	"regexp"
	"strings"
	"syscall"
	"time"

	"gopkg.in/yaml.v3"
)

const sessionCookie = "pvg"

var uuidPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

type Config struct {
	Listen      string
	Prefix      string
	Image       string
	Network     string
	DataDir     string
	Memory      string
	CPUs        string
	PidsLimit   int
	IdleTimeout time.Duration
	SessionTTL  time.Duration

	SSHImage    string
	SSHHomesDir string
	SSHMemory   string
	SSHListen   string
	NodeName    string

	// Read from the Wings configuration.
	Secret     []byte
	TokenID    string
	PanelURL   string
	VolumesDir string
	UID        int
	GID        int
}

func (c *Config) BasePath(uuid string) string {
	return c.Prefix + "/s/" + uuid
}

type wingsConfig struct {
	Token   string `yaml:"token"`
	TokenID string `yaml:"token_id"`
	Remote  string `yaml:"remote"`
	System  struct {
		Data string `yaml:"data"`
		User struct {
			UID int `yaml:"uid"`
			GID int `yaml:"gid"`
		} `yaml:"user"`
	} `yaml:"system"`
}

func loadConfig() (*Config, error) {
	cfg := &Config{}
	wingsPath := flag.String("wings-config", "/etc/pelican/config.yml", "path to the Wings configuration")
	flag.StringVar(&cfg.Listen, "listen", "127.0.0.1:8089", "address to listen on")
	flag.StringVar(&cfg.Prefix, "prefix", "/vscode-gw", "URL path prefix this gateway is served under")
	flag.StringVar(&cfg.Image, "image", "pelican-vscode:1.105.1", "openvscode-server image (built locally from vscode-image/ by install.sh)")
	flag.StringVar(&cfg.Network, "network", "pelican_vscode", "docker network for editor containers")
	flag.StringVar(&cfg.DataDir, "data-dir", "/var/lib/pelican/vscode-data", "where per-server editor settings and extensions are kept")
	flag.StringVar(&cfg.Memory, "memory", "1g", "memory limit per editor container")
	flag.StringVar(&cfg.CPUs, "cpus", "1", "CPU limit per editor container")
	flag.IntVar(&cfg.PidsLimit, "pids-limit", 512, "process limit per editor container")
	flag.DurationVar(&cfg.IdleTimeout, "idle-timeout", 30*time.Minute, "stop editor containers after this long without traffic")
	flag.StringVar(&cfg.SSHImage, "ssh-image", "pelican-ssh:latest", "image for SSH console containers")
	flag.StringVar(&cfg.SSHHomesDir, "ssh-homes-dir", "/var/lib/pelican/ssh-homes", "persistent per-user home folders for SSH containers")
	flag.StringVar(&cfg.SSHMemory, "ssh-memory", "2g", "memory limit per SSH container (VS Code Remote-SSH needs ~1 GiB)")
	flag.StringVar(&cfg.SSHListen, "ssh-listen", ":2222", "address for the SSH server (empty to disable)")
	flag.StringVar(&cfg.NodeName, "node-name", "", "node name shown in the SSH banner (defaults to the hostname)")
	flag.DurationVar(&cfg.SessionTTL, "session-ttl", 8*time.Hour, "browser session lifetime")
	flag.Parse()

	cfg.Prefix = "/" + strings.Trim(cfg.Prefix, "/")

	raw, err := os.ReadFile(*wingsPath)
	if err != nil {
		return nil, err
	}
	var wc wingsConfig
	if err := yaml.Unmarshal(raw, &wc); err != nil {
		return nil, fmt.Errorf("parse %s: %w", *wingsPath, err)
	}
	if wc.Token == "" || wc.System.Data == "" {
		return nil, errors.New("wings config is missing token or system.data")
	}
	cfg.Secret = []byte(wc.Token)
	cfg.TokenID = wc.TokenID
	cfg.PanelURL = wc.Remote
	if cfg.NodeName == "" {
		cfg.NodeName, _ = os.Hostname()
	}
	cfg.VolumesDir = wc.System.Data
	cfg.UID = wc.System.User.UID
	cfg.GID = wc.System.User.GID

	return cfg, nil
}

type Gateway struct {
	cfg     *Config
	manager *Manager
	used    *usedTokens
	disk    *DiskScanner

	revocations *Revocations

	sshFingerprint string
}

func (g *Gateway) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	// Public: lets the panel show users the SSH host key to verify.
	if r.URL.Path == g.cfg.Prefix+"/ssh-info" {
		_, port, _ := net.SplitHostPort(g.cfg.SSHListen)
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{"enabled": g.sshFingerprint != "", "fingerprint": g.sshFingerprint, "port": port})
		return
	}

	if r.URL.Path == g.cfg.Prefix+"/revoke" {
		g.serveRevoke(w, r)
		return
	}

	if uuid, ok := strings.CutPrefix(r.URL.Path, g.cfg.Prefix+"/git/"); ok {
		if !uuidPattern.MatchString(uuid) {
			http.NotFound(w, r)
			return
		}
		g.serveGit(w, r, uuid)
		return
	}

	if uuid, ok := strings.CutPrefix(r.URL.Path, g.cfg.Prefix+"/disk/"); ok {
		if !uuidPattern.MatchString(uuid) {
			http.NotFound(w, r)
			return
		}
		g.serveDisk(w, r, uuid)
		return
	}

	if uuid, ok := strings.CutPrefix(r.URL.Path, g.cfg.Prefix+"/ssh/"); ok {
		if !uuidPattern.MatchString(uuid) {
			http.NotFound(w, r)
			return
		}
		g.serveSSH(w, r, uuid)
		return
	}

	rest, ok := strings.CutPrefix(r.URL.Path, g.cfg.Prefix+"/s/")
	if !ok {
		http.NotFound(w, r)
		return
	}
	uuid, _, _ := strings.Cut(rest, "/")
	if !uuidPattern.MatchString(uuid) {
		http.NotFound(w, r)
		return
	}
	base := g.cfg.BasePath(uuid)

	if r.URL.Path == base {
		target := base + "/"
		if r.URL.RawQuery != "" {
			target += "?" + r.URL.RawQuery
		}
		http.Redirect(w, r, target, http.StatusFound)
		return
	}

	if token := r.URL.Query().Get("token"); token != "" {
		g.launch(w, r, uuid, token)
		return
	}

	cookie, err := r.Cookie(sessionCookie)
	if err != nil {
		g.deny(w, "No editor session. Open VS Code again from the panel.")
		return
	}
	session, err := verifySession(cookie.Value, g.cfg.Secret, time.Now())
	issuedAt := session.Expires - int64(g.cfg.SessionTTL.Seconds())
	if err != nil || session.ServerUUID != uuid || !g.revocations.Allows(uuid, session.UserUUID, issuedAt) {
		g.deny(w, "Your editor session has expired. Open VS Code again from the panel.")
		return
	}

	inst, err := g.manager.Get(r.Context(), uuid, session.Mode)
	if err != nil {
		log.Printf("start %s: %v", uuid, err)
		http.Error(w, "Could not start the editor for this server.", http.StatusBadGateway)
		return
	}

	inst.touch(1)
	defer inst.touch(-1)
	g.proxy(inst).ServeHTTP(w, r)
}

func (g *Gateway) launch(w http.ResponseWriter, r *http.Request, uuid, token string) {
	now := time.Now()
	claims, err := verifyJWT(token, g.cfg.Secret, now)
	if err != nil || claims.ServerUUID != uuid {
		log.Printf("rejected launch token for %s: %v", uuid, err)
		g.deny(w, "This link is invalid or has expired. Open VS Code again from the panel.")
		return
	}
	if !g.used.claim(claims.ID, claims.Expires, now) {
		g.deny(w, "This link has already been used. Open VS Code again from the panel.")
		return
	}

	session := Session{
		ServerUUID: uuid,
		UserUUID:   claims.UserUUID,
		Mode:       claims.Mode,
		Expires:    now.Add(g.cfg.SessionTTL).Unix(),
	}
	http.SetCookie(w, &http.Cookie{
		Name:     sessionCookie,
		Value:    signSession(session, g.cfg.Secret),
		Path:     g.cfg.BasePath(uuid) + "/",
		Expires:  time.Unix(session.Expires, 0),
		HttpOnly: true,
		Secure:   true,
		SameSite: http.SameSiteLaxMode,
	})

	// Start the container now so the redirect lands on a ready editor.
	if _, err := g.manager.Get(r.Context(), uuid, claims.Mode); err != nil {
		log.Printf("start %s: %v", uuid, err)
		http.Error(w, "Could not start the editor for this server.", http.StatusBadGateway)
		return
	}

	q := url.Values{}
	q.Set("folder", "/home/container")
	http.Redirect(w, r, g.cfg.BasePath(uuid)+"/?"+q.Encode(), http.StatusFound)
}

func (g *Gateway) proxy(inst *Instance) *httputil.ReverseProxy {
	return &httputil.ReverseProxy{
		Rewrite: func(pr *httputil.ProxyRequest) {
			pr.SetURL(&url.URL{Scheme: "http", Host: inst.Addr})
			pr.Out.Host = pr.In.Host
			pr.SetXForwarded()

			// Swap our session cookie for the editor's connection token so the
			// browser never sees it.
			var kept []string
			for _, c := range pr.In.Cookies() {
				if c.Name != sessionCookie && c.Name != "vscode-tkn" {
					kept = append(kept, c.String())
				}
			}
			kept = append(kept, "vscode-tkn="+inst.Token)
			pr.Out.Header.Set("Cookie", strings.Join(kept, "; "))
		},
		ModifyResponse: func(res *http.Response) error {
			var kept []string
			for _, v := range res.Header.Values("Set-Cookie") {
				if !strings.HasPrefix(v, "vscode-tkn=") {
					kept = append(kept, v)
				}
			}
			res.Header.Del("Set-Cookie")
			for _, v := range kept {
				res.Header.Add("Set-Cookie", v)
			}

			// The workbench reads the connection token from this cookie in JS and
			// sends it in the websocket handshake. Refresh it on every page load so
			// it matches the current container. It is useless without the HttpOnly
			// session cookie, since containers are only reachable through us.
			base := g.cfg.BasePath(uuidFromPath(res.Request.URL.Path, g.cfg.Prefix)) + "/"
			if res.Request.URL.Path == base {
				res.Header.Add("Set-Cookie", (&http.Cookie{
					Name:     "vscode-tkn",
					Value:    inst.Token,
					Path:     base,
					Secure:   true,
					SameSite: http.SameSiteLaxMode,
				}).String())
			}
			return nil
		},
		FlushInterval: -1,
	}
}

func uuidFromPath(path, prefix string) string {
	rest := strings.TrimPrefix(path, prefix+"/s/")
	uuid, _, _ := strings.Cut(rest, "/")
	return uuid
}

func (g *Gateway) deny(w http.ResponseWriter, msg string) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.WriteHeader(http.StatusUnauthorized)
	fmt.Fprintf(w, `<!doctype html><meta charset="utf-8"><title>VS Code</title>
<body style="font-family:system-ui,sans-serif;background:#1e1e1e;color:#ccc;display:grid;place-items:center;height:100vh;margin:0">
<p>%s</p></body>`, html.EscapeString(msg))
}

func main() {
	cfg, err := loadConfig()
	if err != nil {
		log.Fatal(err)
	}

	manager := newManager(cfg)
	manager.Cleanup()

	gateway := &Gateway{
		cfg:         cfg,
		manager:     manager,
		used:        newUsedTokens(),
		disk:        newDiskScanner(),
		revocations: loadRevocations(filepath.Join(cfg.DataDir, "revocations.json"), cfg.SessionTTL),
	}
	srv := &http.Server{
		Addr:              cfg.Listen,
		Handler:           gateway,
		ReadHeaderTimeout: 10 * time.Second,
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	if cfg.SSHListen != "" {
		if cfg.TokenID == "" || cfg.PanelURL == "" {
			log.Print("ssh disabled: wings config is missing token_id or remote")
		} else if err := gateway.startSSHD(ctx); err != nil {
			log.Fatalf("ssh: %v", err)
		}
	}

	go func() {
		t := time.NewTicker(time.Minute)
		defer t.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-t.C:
				manager.Reap()
			}
		}
	}()

	go func() {
		log.Printf("listening on %s under %s", cfg.Listen, cfg.Prefix)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Fatal(err)
		}
	}()

	<-ctx.Done()
	shutdown, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = srv.Shutdown(shutdown)
	manager.Cleanup()
}
