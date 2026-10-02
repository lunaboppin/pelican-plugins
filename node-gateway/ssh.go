package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/creack/pty"
	"github.com/gorilla/websocket"
)

var usernameUnsafe = regexp.MustCompile(`[^a-z0-9_-]+`)

func sshContainerName(serverUUID, userUUID string) string {
	return "pelican-ssh-" + serverUUID[:8] + "-" + userUUID
}

func homeName(username string) string {
	name := strings.Trim(usernameUnsafe.ReplaceAllString(strings.ToLower(username), "-"), "-")
	if name == "" || strings.HasPrefix(name, "container") {
		name = "user-" + name
	}
	return name
}

// sshMounts returns the server volumes to mount, current server first, and a
// key identifying the set.
func (m *Manager) sshMounts(c Claims) ([]SSHServer, string) {
	seen := map[string]bool{}
	var servers []SSHServer
	add := func(s SSHServer) {
		if seen[s.UUID] || !uuidPattern.MatchString(s.UUID) {
			return
		}
		if st, err := os.Stat(filepath.Join(m.cfg.VolumesDir, s.UUID)); err != nil || !st.IsDir() {
			return
		}
		seen[s.UUID] = true
		servers = append(servers, s)
	}
	add(SSHServer{UUID: c.ServerUUID, Name: c.Info.Name})
	for _, s := range c.Servers {
		add(s)
	}

	ids := make([]string, len(servers))
	for i, s := range servers {
		ids[i] = s.UUID
	}
	sort.Strings(ids)
	sum := sha256.Sum256([]byte(strings.Join(ids, ",")))
	return servers, hex.EncodeToString(sum[:8])
}

// GetSSH returns the user's SSH container for a server, starting it if needed.
func (m *Manager) GetSSH(ctx context.Context, c Claims) (*Instance, error) {
	servers, key := m.sshMounts(c)
	if len(servers) == 0 || servers[0].UUID != c.ServerUUID {
		return nil, fmt.Errorf("server volume for %s does not exist", c.ServerUUID)
	}
	name := sshContainerName(c.ServerUUID, c.UserUUID)

	return m.getOrStart(ctx, name, func(i *Instance) bool { return i.MountKey == key }, func(ctx context.Context) (*Instance, error) {
		home := filepath.Join(m.cfg.SSHHomesDir, c.UserUUID)
		if err := os.MkdirAll(home, 0o700); err != nil {
			return nil, err
		}
		if err := os.Chown(home, m.cfg.UID, m.cfg.GID); err != nil {
			return nil, err
		}
		if err := m.ensureNetwork(ctx); err != nil {
			return nil, err
		}
		if _, err := docker(ctx, "image", "inspect", m.cfg.SSHImage); err != nil {
			return nil, fmt.Errorf("SSH image %s is missing; build it from gateway/ssh-image", m.cfg.SSHImage)
		}

		_, _ = docker(ctx, "rm", "-f", name)

		args := []string{
			"run", "-d", "--init",
			"--name", name,
			"--hostname", "ssh-" + c.ServerUUID[:8],
			"--label", containerLabel + "=1",
			"--label", containerLabel + ".ssh=1",
			"--label", containerLabel + ".user=" + c.UserUUID,
			"--label", containerLabel + ".server=" + c.ServerUUID,
			"--network", m.cfg.Network,
			"--user", fmt.Sprintf("%d:%d", m.cfg.UID, m.cfg.GID),
			"--memory", m.cfg.SSHMemory,
			"--cpus", m.cfg.CPUs,
			"--pids-limit", strconv.Itoa(m.cfg.PidsLimit),
			"--security-opt", "no-new-privileges",
			"--cap-drop", "ALL",
			"--restart", "no",
			"-e", "LANG=C.UTF-8",
			"-e", "LC_ALL=C.UTF-8",
			"-v", home + ":/home/" + homeName(c.Username),
		}
		for _, s := range servers {
			args = append(args, "-v", filepath.Join(m.cfg.VolumesDir, s.UUID)+":/home/container-"+s.UUID[:8])
		}
		args = append(args, "--entrypoint", "sleep", m.cfg.SSHImage, "infinity")

		log.Printf("starting %s", name)
		if _, err := docker(ctx, args...); err != nil {
			return nil, err
		}
		return &Instance{Name: name, MountKey: key, lastActive: time.Now()}, nil
	})
}

var upgrader = websocket.Upgrader{
	ReadBufferSize:  4096,
	WriteBufferSize: 32 * 1024,
	CheckOrigin: func(r *http.Request) bool {
		origin := r.Header.Get("Origin")
		if origin == "" {
			return true
		}
		u, err := url.Parse(origin)
		return err == nil && strings.EqualFold(u.Host, r.Host)
	},
}

type sshMessage struct {
	Type string `json:"t"`
	Data string `json:"d"`
	Cols uint16 `json:"c"`
	Rows uint16 `json:"r"`
}

func (g *Gateway) serveSSH(w http.ResponseWriter, r *http.Request, uuid string) {
	now := time.Now()
	claims, err := verifyToken(r.URL.Query().Get("token"), g.cfg.Secret, now, sshAudience)
	if err != nil || claims.ServerUUID != uuid || !uuidPattern.MatchString(claims.UserUUID) {
		log.Printf("rejected ssh token for %s: %v", uuid, err)
		http.Error(w, "invalid or expired token", http.StatusUnauthorized)
		return
	}
	if !g.used.claim(claims.ID, claims.Expires, now) {
		http.Error(w, "token already used", http.StatusUnauthorized)
		return
	}

	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		return
	}
	defer conn.Close()

	var wmu sync.Mutex
	write := func(b []byte) error {
		wmu.Lock()
		defer wmu.Unlock()
		_ = conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
		return conn.WriteMessage(websocket.BinaryMessage, b)
	}

	_ = write([]byte("\x1b[2mStarting SSH container…\x1b[0m\r\n"))

	inst, err := g.manager.GetSSH(r.Context(), claims)
	if err != nil {
		log.Printf("ssh %s: %v", uuid, err)
		_ = write([]byte("\x1b[31mCould not start the SSH container.\x1b[0m\r\n"))
		return
	}
	inst.touch(1)
	defer inst.touch(-1)

	cols, _ := strconv.Atoi(r.URL.Query().Get("cols"))
	rows, _ := strconv.Atoi(r.URL.Query().Get("rows"))
	if cols <= 0 || cols > 1000 {
		cols = 120
	}
	if rows <= 0 || rows > 500 {
		rows = 32
	}

	disk := "unlimited"
	if claims.Info.Disk > 0 {
		disk = fmt.Sprintf("%.1f GiB", float64(claims.Info.Disk)/1024)
	}
	cmd := exec.Command("docker", "exec", "-it",
		"-w", "/home/container-"+uuid[:8],
		"-e", "HOME=/home/"+homeName(claims.Username),
		"-e", "TERM=xterm-256color",
		"-e", "PSSH_SERVER_NAME="+claims.Info.Name,
		"-e", "PSSH_SERVER_UUID="+uuid,
		"-e", "PSSH_STATUS="+claims.Info.Status,
		"-e", "PSSH_NODE="+claims.Info.Node,
		"-e", "PSSH_ADDRESS="+claims.Info.Address,
		"-e", "PSSH_DISK_LIMIT="+disk,
		inst.Name, "bash", "-l")
	ptmx, err := pty.StartWithSize(cmd, &pty.Winsize{Cols: uint16(cols), Rows: uint16(rows)})
	if err != nil {
		log.Printf("ssh exec %s: %v", inst.Name, err)
		_ = write([]byte("\x1b[31mCould not open a shell.\x1b[0m\r\n"))
		return
	}
	defer func() {
		_ = ptmx.Close()
		if cmd.Process != nil {
			_ = cmd.Process.Kill()
		}
		_ = cmd.Wait()
	}()
	log.Printf("ssh session opened: %s by %s", inst.Name, claims.UserUUID)

	done := make(chan struct{})
	go func() {
		defer close(done)
		buf := make([]byte, 32*1024)
		for {
			n, err := ptmx.Read(buf)
			if n > 0 {
				inst.touch(0)
				if write(buf[:n]) != nil {
					return
				}
			}
			if err != nil {
				return
			}
		}
	}()

	go func() {
		for {
			_, data, err := conn.ReadMessage()
			if err != nil {
				_ = ptmx.Close()
				return
			}
			var msg sshMessage
			if json.Unmarshal(data, &msg) != nil {
				continue
			}
			switch msg.Type {
			case "i":
				inst.touch(0)
				_, _ = ptmx.Write([]byte(msg.Data))
			case "r":
				if msg.Cols > 0 && msg.Rows > 0 && msg.Cols <= 1000 && msg.Rows <= 500 {
					_ = pty.Setsize(ptmx, &pty.Winsize{Cols: msg.Cols, Rows: msg.Rows})
				}
			}
		}
	}()

	<-done
	wmu.Lock()
	_ = conn.WriteControl(websocket.CloseMessage, websocket.FormatCloseMessage(websocket.CloseNormalClosure, "session ended"), time.Now().Add(time.Second))
	wmu.Unlock()
}
