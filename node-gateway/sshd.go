package main

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/creack/pty"
	"golang.org/x/crypto/ssh"
	"golang.org/x/sys/unix"
)

// The SSH server lets desktop tools (VS Code Remote-SSH, terminals, scp,
// rsync, SFTP clients) reach the same SSH container as the browser console.
// Logins are checked by the panel exactly like Wings SFTP:
// username "<panel user>.<server short id>", panel password or a saved SSH key.

const sshMaxConns = 200

var sshEnvAllowed = regexp.MustCompile(`^(LANG|LC_[A-Z]+|TERM|COLORTERM|VSCODE_[A-Z_]+)$`)

type sshdServer struct {
	g        *Gateway
	config   *ssh.ServerConfig
	panelURL string
	bearer   string
	client   *http.Client
	conns    atomic.Int32

	infoMu sync.Mutex
	info   map[string]cachedInfo
}

type cachedInfo struct {
	info SSHInfo
	at   time.Time
}

func sshHostKeyPath(cfg *Config) string {
	return filepath.Join(cfg.DataDir, "ssh_host_ed25519_key")
}

func loadOrCreateHostKey(file string) (ssh.Signer, error) {
	if raw, err := os.ReadFile(file); err == nil {
		return ssh.ParsePrivateKey(raw)
	}
	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, err
	}
	block, err := ssh.MarshalPrivateKey(priv, "pelican-gateway")
	if err != nil {
		return nil, err
	}
	if err := os.MkdirAll(filepath.Dir(file), 0o750); err != nil {
		return nil, err
	}
	if err := os.WriteFile(file, pem.EncodeToMemory(block), 0o600); err != nil {
		return nil, err
	}
	return ssh.NewSignerFromKey(priv)
}

func (g *Gateway) startSSHD(ctx context.Context) error {
	signer, err := loadOrCreateHostKey(sshHostKeyPath(g.cfg))
	if err != nil {
		return err
	}
	g.sshFingerprint = ssh.FingerprintSHA256(signer.PublicKey())

	s := &sshdServer{
		g:        g,
		panelURL: strings.TrimRight(g.cfg.PanelURL, "/"),
		bearer:   g.cfg.TokenID + "." + string(g.cfg.Secret),
		client:   &http.Client{Timeout: 15 * time.Second},
		info:     map[string]cachedInfo{},
	}
	s.config = &ssh.ServerConfig{
		ServerVersion: "SSH-2.0-PelicanGateway",
		MaxAuthTries:  6,
		PasswordCallback: func(c ssh.ConnMetadata, pw []byte) (*ssh.Permissions, error) {
			return s.authenticate(c, "password", string(pw))
		},
		PublicKeyCallback: func(c ssh.ConnMetadata, key ssh.PublicKey) (*ssh.Permissions, error) {
			return s.authenticate(c, "public_key", strings.TrimSpace(string(ssh.MarshalAuthorizedKey(key))))
		},
	}
	s.config.AddHostKey(signer)

	ln, err := net.Listen("tcp", g.cfg.SSHListen)
	if err != nil {
		return err
	}
	log.Printf("ssh listening on %s (host key %s)", g.cfg.SSHListen, g.sshFingerprint)

	go func() {
		<-ctx.Done()
		_ = ln.Close()
	}()
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				if ctx.Err() != nil {
					return
				}
				continue
			}
			if s.conns.Load() >= sshMaxConns {
				_ = conn.Close()
				continue
			}
			go s.handleConn(conn)
		}
	}()
	return nil
}

type panelAuthResponse struct {
	User        string   `json:"user"`
	Server      string   `json:"server"`
	Permissions []string `json:"permissions"`
}

// authenticate asks the panel to verify the login, like Wings SFTP does, then
// requires the SSH console permission on top.
func (s *sshdServer) authenticate(c ssh.ConnMetadata, kind, secret string) (*ssh.Permissions, error) {
	body, _ := json.Marshal(map[string]string{
		"type":           kind,
		"username":       c.User(),
		"password":       secret,
		"ip":             strings.Split(c.RemoteAddr().String(), ":")[0],
		"session_id":     hex.EncodeToString(c.SessionID()),
		"client_version": string(c.ClientVersion()),
	})
	req, _ := http.NewRequest(http.MethodPost, s.panelURL+"/api/remote/sftp/auth", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+s.bearer)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	res, err := s.client.Do(req)
	if err != nil {
		log.Printf("ssh auth: panel unreachable: %v", err)
		return nil, errors.New("authentication unavailable")
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return nil, errors.New("authentication failed")
	}

	var auth panelAuthResponse
	if err := json.NewDecoder(io.LimitReader(res.Body, 1<<20)).Decode(&auth); err != nil || !uuidPattern.MatchString(auth.Server) || !uuidPattern.MatchString(auth.User) {
		return nil, errors.New("authentication failed")
	}
	allowed := false
	for _, p := range auth.Permissions {
		if p == "*" || p == "ssh.connect" {
			allowed = true
		}
	}
	if !allowed {
		log.Printf("ssh login refused for %s: no ssh.connect permission", c.User())
		return nil, errors.New("authentication failed")
	}

	username := c.User()
	if i := strings.LastIndex(username, "."); i > 0 {
		username = username[:i]
	}
	return &ssh.Permissions{Extensions: map[string]string{"server": auth.Server, "user": auth.User, "username": username}}, nil
}

// serverInfo fetches banner details from the panel (cached for 5 minutes).
func (s *sshdServer) serverInfo(uuid string) SSHInfo {
	s.infoMu.Lock()
	if c, ok := s.info[uuid]; ok && time.Since(c.at) < 5*time.Minute {
		s.infoMu.Unlock()
		return c.info
	}
	s.infoMu.Unlock()

	info := SSHInfo{Name: uuid[:8], Node: s.g.cfg.NodeName}
	req, _ := http.NewRequest(http.MethodGet, s.panelURL+"/api/remote/servers/"+uuid, nil)
	req.Header.Set("Authorization", "Bearer "+s.bearer)
	req.Header.Set("Accept", "application/json")
	if res, err := s.client.Do(req); err == nil {
		defer res.Body.Close()
		var d struct {
			Settings struct {
				Meta struct {
					Name string `json:"name"`
				} `json:"meta"`
				Build struct {
					Disk int64 `json:"disk_space"`
				} `json:"build"`
				Allocations struct {
					Default struct {
						IP   string `json:"ip"`
						Port int    `json:"port"`
					} `json:"default"`
				} `json:"allocations"`
			} `json:"settings"`
		}
		if json.NewDecoder(io.LimitReader(res.Body, 4<<20)).Decode(&d) == nil {
			if d.Settings.Meta.Name != "" {
				info.Name = d.Settings.Meta.Name
			}
			info.Disk = d.Settings.Build.Disk
			if d.Settings.Allocations.Default.IP != "" {
				info.Address = net.JoinHostPort(d.Settings.Allocations.Default.IP, strconv.Itoa(d.Settings.Allocations.Default.Port))
			}
		}
	}

	s.infoMu.Lock()
	s.info[uuid] = cachedInfo{info: info, at: time.Now()}
	s.infoMu.Unlock()
	return info
}

func (s *sshdServer) handleConn(raw net.Conn) {
	s.conns.Add(1)
	defer s.conns.Add(-1)

	_ = raw.SetDeadline(time.Now().Add(30 * time.Second))
	conn, chans, reqs, err := ssh.NewServerConn(raw, s.config)
	if err != nil {
		_ = raw.Close()
		return
	}
	_ = raw.SetDeadline(time.Time{})
	defer conn.Close()

	ext := conn.Permissions.Extensions
	claims := Claims{ServerUUID: ext["server"], UserUUID: ext["user"], Username: ext["username"]}
	claims.Info = s.serverInfo(claims.ServerUUID)
	log.Printf("ssh login: %s on %s from %s", claims.Username, claims.ServerUUID, raw.RemoteAddr())

	go ssh.DiscardRequests(reqs)
	for nc := range chans {
		switch nc.ChannelType() {
		case "session":
			go s.handleSession(nc, claims)
		case "direct-tcpip":
			go s.handleDirectTCPIP(nc, claims)
		default:
			_ = nc.Reject(ssh.UnknownChannelType, "unsupported channel type")
		}
	}
}

type ptyRequest struct {
	Term          string
	Cols, Rows    uint32
	Width, Height uint32
	Modes         string
}

func (s *sshdServer) handleSession(nc ssh.NewChannel, claims Claims) {
	ch, reqs, err := nc.Accept()
	if err != nil {
		return
	}
	defer ch.Close()

	var (
		env     []string
		pt      *ptyRequest
		ptmx    *os.File
		started bool
		done    = make(chan struct{})
	)

	for req := range reqs {
		switch req.Type {
		case "env":
			var kv struct{ Name, Value string }
			if ssh.Unmarshal(req.Payload, &kv) == nil && sshEnvAllowed.MatchString(kv.Name) && !strings.ContainsAny(kv.Value, "\x00\n") {
				env = append(env, kv.Name+"="+kv.Value)
			}
			_ = req.Reply(true, nil)

		case "pty-req":
			var p ptyRequest
			if ssh.Unmarshal(req.Payload, &p) != nil || started {
				_ = req.Reply(false, nil)
				continue
			}
			pt = &p
			_ = req.Reply(true, nil)

		case "window-change":
			var w struct{ Cols, Rows, Width, Height uint32 }
			if ssh.Unmarshal(req.Payload, &w) == nil && ptmx != nil && w.Cols > 0 && w.Rows > 0 && w.Cols < 1000 && w.Rows < 500 {
				_ = pty.Setsize(ptmx, &pty.Winsize{Cols: uint16(w.Cols), Rows: uint16(w.Rows)})
			}
			if req.WantReply {
				_ = req.Reply(true, nil)
			}

		case "shell", "exec", "subsystem":
			if started {
				_ = req.Reply(false, nil)
				continue
			}
			var command []string
			switch req.Type {
			case "shell":
				command = []string{"bash", "-l"}
			case "exec":
				var c struct{ Command string }
				if ssh.Unmarshal(req.Payload, &c) != nil {
					_ = req.Reply(false, nil)
					continue
				}
				command = []string{"bash", "-c", c.Command}
			case "subsystem":
				var c struct{ Name string }
				if ssh.Unmarshal(req.Payload, &c) != nil || c.Name != "sftp" {
					_ = req.Reply(false, nil)
					continue
				}
				command = []string{"/usr/lib/openssh/sftp-server"}
			}

			inst, err := s.g.manager.GetSSH(context.Background(), claims)
			if err != nil {
				log.Printf("ssh %s: %v", claims.ServerUUID, err)
				_ = req.Reply(false, nil)
				return
			}
			_ = req.Reply(true, nil)
			started = true
			inst.touch(1)

			var p *os.File
			p, err = s.runInContainer(ch, inst, claims, command, env, pt, req.Type == "shell", done)
			if err != nil {
				inst.touch(-1)
				_, _ = fmt.Fprintf(ch.Stderr(), "could not start session: %v\r\n", err)
				return
			}
			ptmx = p
			go func() {
				<-done
				inst.touch(-1)
				_ = ch.CloseWrite()
				_ = ch.Close()
			}()

		default:
			if req.WantReply {
				_ = req.Reply(false, nil)
			}
		}
	}
	if started {
		<-done
	}
}

// runInContainer starts the command via docker exec and wires it to the channel.
// It closes done after sending the exit status.
func (s *sshdServer) runInContainer(ch ssh.Channel, inst *Instance, c Claims, command, env []string, pt *ptyRequest, login bool, done chan struct{}) (*os.File, error) {
	disk := "unlimited"
	if c.Info.Disk > 0 {
		disk = fmt.Sprintf("%.1f GiB", float64(c.Info.Disk)/1024)
	}
	args := []string{"exec", "-i"}
	if pt != nil {
		args = append(args, "-t")
	}
	args = append(args,
		"-w", "/home/container-"+c.ServerUUID[:8],
		"-e", "HOME=/home/"+homeName(c.Username),
		"-e", "USER=container",
	)
	if login {
		args = append(args,
			"-e", "PSSH_SERVER_NAME="+c.Info.Name,
			"-e", "PSSH_SERVER_UUID="+c.ServerUUID,
			"-e", "PSSH_STATUS="+c.Info.Status,
			"-e", "PSSH_NODE="+c.Info.Node,
			"-e", "PSSH_ADDRESS="+c.Info.Address,
			"-e", "PSSH_DISK_LIMIT="+disk,
		)
	}
	if pt != nil {
		term := pt.Term
		if term == "" {
			term = "xterm-256color"
		}
		args = append(args, "-e", "TERM="+term)
	}
	for _, e := range env {
		if pt != nil && strings.HasPrefix(e, "TERM=") {
			continue
		}
		args = append(args, "-e", e)
	}
	args = append(args, inst.Name)
	args = append(args, command...)

	cmd := exec.Command("docker", args...)
	finish := func() {
		code := 0
		if err := cmd.Wait(); err != nil {
			var exitErr *exec.ExitError
			if errors.As(err, &exitErr) {
				code = exitErr.ExitCode()
			} else {
				code = 255
			}
		}
		status := make([]byte, 4)
		binary.BigEndian.PutUint32(status, uint32(code))
		_, _ = ch.SendRequest("exit-status", false, status)
		close(done)
	}

	if pt != nil {
		cols, rows := pt.Cols, pt.Rows
		if cols == 0 || cols > 1000 {
			cols = 80
		}
		if rows == 0 || rows > 500 {
			rows = 24
		}
		ptmx, err := pty.StartWithSize(cmd, &pty.Winsize{Cols: uint16(cols), Rows: uint16(rows)})
		if err != nil {
			return nil, err
		}
		go func() { _, _ = io.Copy(ptmx, ch) }()
		go func() {
			_, _ = io.Copy(ch, ptmx)
			_ = ptmx.Close()
			finish()
		}()
		return ptmx, nil
	}

	stdin, err := cmd.StdinPipe()
	if err != nil {
		return nil, err
	}
	cmd.Stdout = ch
	cmd.Stderr = ch.Stderr()
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	go func() {
		_, _ = io.Copy(stdin, ch)
		_ = stdin.Close()
	}()
	go finish()
	return nil, nil
}

// handleDirectTCPIP forwards to a port on the container's own loopback, which
// is how VS Code Remote-SSH reaches the server it installs. Other destinations
// are refused so the gateway can't be used as a general proxy.
func (s *sshdServer) handleDirectTCPIP(nc ssh.NewChannel, claims Claims) {
	var p struct {
		Host     string
		Port     uint32
		OrigHost string
		OrigPort uint32
	}
	if ssh.Unmarshal(nc.ExtraData(), &p) != nil || p.Port == 0 || p.Port > 65535 {
		_ = nc.Reject(ssh.ConnectionFailed, "bad request")
		return
	}
	host := p.Host
	switch host {
	case "localhost", "127.0.0.1", "::1":
		if host == "localhost" {
			host = "127.0.0.1"
		}
	default:
		_ = nc.Reject(ssh.Prohibited, "only forwarding to localhost is allowed")
		return
	}

	inst, err := s.g.manager.GetSSH(context.Background(), claims)
	if err != nil {
		_ = nc.Reject(ssh.ConnectionFailed, "container unavailable")
		return
	}
	pidStr, err := docker(context.Background(), "inspect", "-f", "{{.State.Pid}}", inst.Name)
	pid, _ := strconv.Atoi(pidStr)
	if err != nil || pid <= 0 {
		_ = nc.Reject(ssh.ConnectionFailed, "container unavailable")
		return
	}

	conn, err := dialInNetns(pid, net.JoinHostPort(host, strconv.Itoa(int(p.Port))))
	if err != nil {
		_ = nc.Reject(ssh.ConnectionFailed, "connection refused")
		return
	}
	ch, reqs, err := nc.Accept()
	if err != nil {
		_ = conn.Close()
		return
	}
	go ssh.DiscardRequests(reqs)

	inst.touch(1)
	defer inst.touch(-1)

	var wg sync.WaitGroup
	wg.Add(2)
	go func() {
		defer wg.Done()
		_, _ = io.Copy(conn, ch)
		if tc, ok := conn.(*net.TCPConn); ok {
			_ = tc.CloseWrite()
		}
	}()
	go func() {
		defer wg.Done()
		_, _ = io.Copy(ch, conn)
		_ = ch.CloseWrite()
	}()
	wg.Wait()
	_ = ch.Close()
	_ = conn.Close()
}

// dialInNetns opens a TCP connection from inside the network namespace of pid.
func dialInNetns(pid int, addr string) (net.Conn, error) {
	type result struct {
		conn net.Conn
		err  error
	}
	out := make(chan result, 1)

	go func() {
		runtime.LockOSThread()
		orig, err := os.Open("/proc/thread-self/ns/net")
		if err != nil {
			runtime.UnlockOSThread()
			out <- result{nil, err}
			return
		}
		defer orig.Close()
		target, err := os.Open(fmt.Sprintf("/proc/%d/ns/net", pid))
		if err != nil {
			runtime.UnlockOSThread()
			out <- result{nil, err}
			return
		}
		defer target.Close()

		if err := unix.Setns(int(target.Fd()), syscall.CLONE_NEWNET); err != nil {
			runtime.UnlockOSThread()
			out <- result{nil, err}
			return
		}
		conn, err := net.DialTimeout("tcp", addr, 5*time.Second)

		// Only hand the thread back if we got back to our own namespace;
		// otherwise it is discarded when this goroutine exits.
		if unix.Setns(int(orig.Fd()), syscall.CLONE_NEWNET) == nil {
			runtime.UnlockOSThread()
		}
		out <- result{conn, err}
	}()

	r := <-out
	return r.conn, r.err
}
