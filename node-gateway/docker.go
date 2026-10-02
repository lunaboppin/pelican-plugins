package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"log"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	containerLabel = "pelican.vscode"
	vscodePort     = 3000
	vscodeBinary   = "/home/.openvscode-server/bin/openvscode-server"
)

// Instance is a running openvscode-server container for one server + mode.
type Instance struct {
	Name  string
	Addr  string
	Token string

	// MountKey identifies the set of volumes an SSH container was created with.
	MountKey string

	mu         sync.Mutex
	active     int
	lastActive time.Time
}

func (i *Instance) touch(delta int) {
	i.mu.Lock()
	i.active += delta
	i.lastActive = time.Now()
	i.mu.Unlock()
}

func (i *Instance) idleSince() (bool, time.Time) {
	i.mu.Lock()
	defer i.mu.Unlock()
	return i.active == 0, i.lastActive
}

type Manager struct {
	cfg *Config

	mu        sync.Mutex
	instances map[string]*Instance
	starting  map[string]*sync.Mutex
}

func newManager(cfg *Config) *Manager {
	return &Manager{cfg: cfg, instances: map[string]*Instance{}, starting: map[string]*sync.Mutex{}}
}

func docker(ctx context.Context, args ...string) (string, error) {
	out, err := exec.CommandContext(ctx, "docker", args...).CombinedOutput()
	if err != nil {
		return "", fmt.Errorf("docker %s: %w: %s", args[0], err, strings.TrimSpace(string(out)))
	}
	return strings.TrimSpace(string(out)), nil
}

func containerName(uuid, mode string) string {
	return "pelican-vscode-" + uuid + "-" + mode
}

// Get returns a ready editor instance, starting a container if needed.
func (m *Manager) Get(ctx context.Context, uuid, mode string) (*Instance, error) {
	return m.getOrStart(ctx, containerName(uuid, mode), nil, func(ctx context.Context) (*Instance, error) {
		return m.start(ctx, uuid, mode)
	})
}

// getOrStart returns the tracked instance called name, or starts one. If
// reusable is set and rejects an idle existing instance, it is replaced.
func (m *Manager) getOrStart(ctx context.Context, name string, reusable func(*Instance) bool, start func(context.Context) (*Instance, error)) (*Instance, error) {
	m.mu.Lock()
	lock, ok := m.starting[name]
	if !ok {
		lock = &sync.Mutex{}
		m.starting[name] = lock
	}
	m.mu.Unlock()

	lock.Lock()
	defer lock.Unlock()

	m.mu.Lock()
	inst, ok := m.instances[name]
	m.mu.Unlock()
	if ok {
		idle, _ := inst.idleSince()
		if reusable == nil || reusable(inst) || !idle {
			return inst, nil
		}
		log.Printf("replacing %s", name)
		m.mu.Lock()
		delete(m.instances, name)
		m.mu.Unlock()
		_, _ = docker(ctx, "rm", "-f", name)
	}

	// Starting must not depend on the HTTP request that triggered it: if the
	// browser reloads or gives up, a half-started container would be killed and
	// the next request would start over. Requests that disconnect simply stop
	// waiting; the start finishes for whoever asks next.
	startCtx, cancel := context.WithTimeout(context.Background(), 3*time.Minute)
	defer cancel()
	inst, err := start(startCtx)
	if err != nil {
		return nil, err
	}

	m.mu.Lock()
	m.instances[name] = inst
	m.mu.Unlock()
	return inst, nil
}

func (m *Manager) start(ctx context.Context, uuid, mode string) (*Instance, error) {
	name := containerName(uuid, mode)

	volume := filepath.Join(m.cfg.VolumesDir, uuid)
	if st, err := os.Stat(volume); err != nil || !st.IsDir() {
		return nil, fmt.Errorf("server volume %s does not exist", volume)
	}

	dataDir := filepath.Join(m.cfg.DataDir, uuid)
	if err := os.MkdirAll(dataDir, 0o750); err != nil {
		return nil, err
	}
	if err := os.Chown(dataDir, m.cfg.UID, m.cfg.GID); err != nil {
		return nil, err
	}

	extDir, err := m.ensureDefaultsExtension()
	if err != nil {
		return nil, err
	}

	buf := make([]byte, 32)
	if _, err := rand.Read(buf); err != nil {
		return nil, err
	}
	token := hex.EncodeToString(buf)
	tokenFile := ".connection-token-" + mode
	if err := os.WriteFile(filepath.Join(dataDir, tokenFile), []byte(token), 0o600); err != nil {
		return nil, err
	}
	if err := os.Chown(filepath.Join(dataDir, tokenFile), m.cfg.UID, m.cfg.GID); err != nil {
		return nil, err
	}

	if err := m.ensureNetwork(ctx); err != nil {
		return nil, err
	}

	// Remove anything left over from a previous run with the same name.
	_, _ = docker(ctx, "rm", "-f", name)

	mount := volume + ":/home/container"
	if mode == "ro" {
		mount += ":ro"
	}

	args := []string{
		"run", "-d",
		"--name", name,
		"--label", containerLabel + "=1",
		"--label", containerLabel + ".server=" + uuid,
		"--label", containerLabel + ".mode=" + mode,
		"--label", containerLabel + ".editor=1",
		"--network", m.cfg.Network,
		"--user", fmt.Sprintf("%d:%d", m.cfg.UID, m.cfg.GID),
		"--memory", m.cfg.Memory,
		"--cpus", m.cfg.CPUs,
		"--pids-limit", strconv.Itoa(m.cfg.PidsLimit),
		"--security-opt", "no-new-privileges",
		"--cap-drop", "ALL",
		"--restart", "no",
		"-e", "HOME=/data/home",
		"-v", mount,
		"-v", dataDir + ":/data",
		"-v", extDir + ":/home/.openvscode-server/extensions/pelican-defaults:ro",
		"-w", "/home/container",
		// Run the launcher through sh so it doesn't need to be directly executable.
		"--entrypoint", "/bin/sh",
		m.cfg.Image,
		"-c", `exec "$0" "$@"`, vscodeBinary,
		"--host", "0.0.0.0",
		"--port", strconv.Itoa(vscodePort),
		"--connection-token-file", "/data/" + tokenFile,
		"--server-base-path", m.cfg.BasePath(uuid),
		"--server-data-dir", "/data/server",
		"--user-data-dir", "/data/user",
		"--extensions-dir", "/data/extensions",
		"--disable-telemetry",
		"--disable-workspace-trust",
		"--default-folder", "/home/container",
	}

	log.Printf("starting %s", name)
	if _, err := docker(ctx, args...); err != nil {
		return nil, err
	}

	ip, err := docker(ctx, "inspect", "-f", "{{(index .NetworkSettings.Networks \""+m.cfg.Network+"\").IPAddress}}", name)
	if err != nil || ip == "" {
		_, _ = docker(context.Background(), "rm", "-f", name)
		return nil, fmt.Errorf("could not determine container address: %v", err)
	}

	addr := net.JoinHostPort(ip, strconv.Itoa(vscodePort))
	deadline := time.Now().Add(60 * time.Second)
	for {
		conn, err := net.DialTimeout("tcp", addr, time.Second)
		if err == nil {
			conn.Close()
			break
		}
		running, _ := docker(ctx, "inspect", "-f", "{{.State.Running}}", name)
		if running == "false" || time.Now().After(deadline) {
			logs, _ := docker(context.Background(), "logs", "--tail", "20", name)
			_, _ = docker(context.Background(), "rm", "-f", name)
			if strings.Contains(logs, "exec format error") {
				logs += " (the image doesn't match this node's CPU architecture or is corrupt; re-pull it with: docker pull " + m.cfg.Image + ")"
			}
			return nil, fmt.Errorf("openvscode-server did not become ready: %s", strings.TrimSpace(logs))
		}
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-time.After(250 * time.Millisecond):
		}
	}

	return &Instance{Name: name, Addr: addr, Token: token, lastActive: time.Now()}, nil
}

// defaultsExtension is mounted into every editor as a built-in extension. Its
// configurationDefaults change the defaults (users can still override them).
const defaultsExtension = `{
    "name": "pelican-defaults",
    "displayName": "Pelican Defaults",
    "publisher": "pelican",
    "version": "1.0.0",
    "engines": { "vscode": "*" },
    "contributes": {
        "configurationDefaults": {
            "workbench.colorTheme": "Default Dark Modern",
            "workbench.preferredDarkColorTheme": "Default Dark Modern",
            "workbench.startupEditor": "none",
            "workbench.tips.enabled": false,
            "workbench.welcomePage.walkthroughs.openOnInstall": false,
            "workbench.secondarySideBar.defaultVisibility": "hidden",
            "workbench.enableExperiments": false,
            "chat.disableAIFeatures": true,
            "chat.commandCenter.enabled": false,
            "chat.agent.enabled": false,
            "telemetry.telemetryLevel": "off",
            "extensions.ignoreRecommendations": true,
            "git.openRepositoryInParentFolders": "never"
        }
    }
}
`

// ensureDefaultsExtension writes the extension to the host and returns its directory.
func (m *Manager) ensureDefaultsExtension() (string, error) {
	dir := filepath.Join(m.cfg.DataDir, "_extensions", "pelican-defaults")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", err
	}
	for _, d := range []string{m.cfg.DataDir, filepath.Join(m.cfg.DataDir, "_extensions")} {
		if err := os.Chmod(d, 0o755); err != nil {
			return "", err
		}
	}
	return dir, os.WriteFile(filepath.Join(dir, "package.json"), []byte(defaultsExtension), 0o644)
}

func (m *Manager) ensureNetwork(ctx context.Context) error {
	if _, err := docker(ctx, "network", "inspect", m.cfg.Network); err == nil {
		return nil
	}
	_, err := docker(ctx, "network", "create",
		"--driver", "bridge",
		"--label", containerLabel+"=1",
		"-o", "com.docker.network.bridge.enable_icc=false",
		m.cfg.Network)
	return err
}

// Reap removes containers that have had no traffic for the idle timeout.
func (m *Manager) Reap() {
	m.mu.Lock()
	var stale []*Instance
	for name, inst := range m.instances {
		idle, last := inst.idleSince()
		if idle && time.Since(last) > m.cfg.IdleTimeout {
			stale = append(stale, inst)
			delete(m.instances, name)
		}
	}
	m.mu.Unlock()

	for _, inst := range stale {
		log.Printf("stopping idle %s", inst.Name)
		if _, err := docker(context.Background(), "rm", "-f", inst.Name); err != nil {
			log.Print(err)
		}
	}
}

// Cleanup removes every container this gateway has created.
func (m *Manager) Cleanup() {
	ids, err := docker(context.Background(), "ps", "-aq", "--filter", "label="+containerLabel+"=1")
	if err != nil {
		log.Print(err)
		return
	}
	for _, id := range strings.Fields(ids) {
		_, _ = docker(context.Background(), "rm", "-f", id)
	}

	m.mu.Lock()
	m.instances = map[string]*Instance{}
	m.mu.Unlock()
}
