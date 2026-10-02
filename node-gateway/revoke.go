package main

import (
	"context"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

const revokeAudience = "revoke-gateway"

// Revocations records when a user's access to a server was changed. Browser
// sessions issued before that moment are rejected. Persisted so a gateway
// restart doesn't resurrect revoked sessions.
type Revocations struct {
	mu   sync.Mutex
	file string
	ttl  time.Duration
	at   map[string]int64
}

func revocationKey(serverUUID, userUUID string) string {
	return serverUUID + "|" + userUUID
}

func loadRevocations(file string, ttl time.Duration) *Revocations {
	r := &Revocations{file: file, ttl: ttl, at: map[string]int64{}}
	if raw, err := os.ReadFile(file); err == nil {
		_ = json.Unmarshal(raw, &r.at)
	}
	return r
}

// Revoke marks the moment access changed and saves the list.
func (r *Revocations) Revoke(serverUUID, userUUID string, now time.Time) error {
	r.mu.Lock()
	defer r.mu.Unlock()

	// Sessions can't outlive the TTL, so older entries no longer matter.
	for k, t := range r.at {
		if now.Unix()-t > int64(r.ttl.Seconds()) {
			delete(r.at, k)
		}
	}
	r.at[revocationKey(serverUUID, userUUID)] = now.Unix()

	raw, err := json.Marshal(r.at)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(r.file), 0o750); err != nil {
		return err
	}
	tmp := r.file + ".tmp"
	if err := os.WriteFile(tmp, raw, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, r.file)
}

// Allows reports whether a session issued at issuedAt is still valid.
func (r *Revocations) Allows(serverUUID, userUUID string, issuedAt int64) bool {
	r.mu.Lock()
	defer r.mu.Unlock()
	t, ok := r.at[revocationKey(serverUUID, userUUID)]
	return !ok || issuedAt > t
}

// RemoveMatching stops tracked and running containers whose labels match.
func (m *Manager) RemoveMatching(ctx context.Context, filters ...string) {
	args := []string{"ps", "-aq", "--filter", "label=" + containerLabel + "=1"}
	for _, f := range filters {
		args = append(args, "--filter", "label="+f)
	}
	ids, err := docker(ctx, args...)
	if err != nil {
		log.Print(err)
		return
	}
	for _, id := range strings.Fields(ids) {
		name, _ := docker(ctx, "inspect", "-f", "{{.Name}}", id)
		name = strings.TrimPrefix(name, "/")
		m.mu.Lock()
		delete(m.instances, name)
		m.mu.Unlock()
		log.Printf("revoked: removing %s", name)
		_, _ = docker(ctx, "rm", "-f", id)
	}
}

// serveRevoke is called by the panel when a user's access to a server changes.
func (g *Gateway) serveRevoke(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	now := time.Now()
	claims, err := verifyToken(r.URL.Query().Get("token"), g.cfg.Secret, now, revokeAudience)
	if err != nil || !uuidPattern.MatchString(claims.ServerUUID) || !uuidPattern.MatchString(claims.UserUUID) {
		http.Error(w, "invalid or expired token", http.StatusUnauthorized)
		return
	}
	if !g.used.claim(claims.ID, claims.Expires, now) {
		http.Error(w, "token already used", http.StatusUnauthorized)
		return
	}

	if err := g.revocations.Revoke(claims.ServerUUID, claims.UserUUID, now); err != nil {
		log.Printf("revoke %s/%s: %v", claims.ServerUUID, claims.UserUUID, err)
		http.Error(w, "could not save revocation", http.StatusInternalServerError)
		return
	}

	ctx := context.Background()
	// The user's SSH containers may mount this server even when opened from
	// another one, so remove all of them.
	g.manager.RemoveMatching(ctx, containerLabel+".user="+claims.UserUUID)
	// Editor containers are shared per server; restarting them drops the revoked
	// user's open connections. Other users reconnect with their own sessions.
	g.manager.RemoveMatching(ctx, containerLabel+".server="+claims.ServerUUID, containerLabel+".editor=1")

	log.Printf("revoked sessions for user %s on %s", claims.UserUUID, claims.ServerUUID)
	w.WriteHeader(http.StatusNoContent)
}
