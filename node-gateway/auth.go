package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	tokenAudience = "vscode-gateway"
	sshAudience   = "ssh-gateway"
	diskAudience  = "disk-gateway"
)

var b64 = base64.RawURLEncoding

// Claims are the fields the panel plugin puts in the launch token.
type Claims struct {
	ServerUUID string `json:"server_uuid"`
	UserUUID   string `json:"user_uuid"`
	Mode       string `json:"mode"`
	ID         string `json:"jti"`
	Expires    int64  `json:"exp"`
	NotBefore  int64  `json:"nbf"`
	Audience   any    `json:"aud"`

	// Git operations: the action this token allows.
	Action string `json:"action"`

	// SSH console only.
	Username string      `json:"username"`
	Servers  []SSHServer `json:"servers"`
	Info     SSHInfo     `json:"info"`
}

// SSHServer is a server whose volume is mounted into the user's SSH container.
type SSHServer struct {
	UUID string `json:"uuid"`
	Name string `json:"name"`
}

// SSHInfo is shown in the login banner.
type SSHInfo struct {
	Name    string `json:"name"`
	Status  string `json:"status"`
	Node    string `json:"node"`
	Address string `json:"address"`
	Disk    int64  `json:"disk"`
}

func (c Claims) hasAudience(aud string) bool {
	switch v := c.Audience.(type) {
	case string:
		return v == aud
	case []any:
		for _, a := range v {
			if s, ok := a.(string); ok && s == aud {
				return true
			}
		}
	}
	return false
}

// verifyJWT validates an HS256 token signed with the node daemon token.
func verifyJWT(token string, secret []byte, now time.Time) (Claims, error) {
	c, err := verifyToken(token, secret, now, tokenAudience)
	if err == nil && c.Mode != "rw" && c.Mode != "ro" {
		return c, errors.New("invalid mode")
	}
	return c, err
}

// verifyToken checks signature, timing, audience and jti.
func verifyToken(token string, secret []byte, now time.Time, audience string) (Claims, error) {
	var c Claims
	parts := strings.Split(token, ".")
	if len(parts) != 3 {
		return c, errors.New("malformed token")
	}

	header, err := b64.DecodeString(parts[0])
	if err != nil {
		return c, errors.New("malformed header")
	}
	var h struct {
		Alg string `json:"alg"`
	}
	if err := json.Unmarshal(header, &h); err != nil || h.Alg != "HS256" {
		return c, errors.New("unsupported algorithm")
	}

	sig, err := b64.DecodeString(parts[2])
	if err != nil {
		return c, errors.New("malformed signature")
	}
	mac := hmac.New(sha256.New, secret)
	mac.Write([]byte(parts[0] + "." + parts[1]))
	if !hmac.Equal(sig, mac.Sum(nil)) {
		return c, errors.New("invalid signature")
	}

	payload, err := b64.DecodeString(parts[1])
	if err != nil {
		return c, errors.New("malformed payload")
	}
	if err := json.Unmarshal(payload, &c); err != nil {
		return c, errors.New("malformed payload")
	}

	switch {
	case c.Expires == 0 || now.Unix() >= c.Expires:
		return c, errors.New("token expired")
	case c.NotBefore != 0 && now.Unix() < c.NotBefore:
		return c, errors.New("token not yet valid")
	case !c.hasAudience(audience):
		return c, errors.New("wrong audience")
	case c.ID == "":
		return c, errors.New("missing jti")
	}

	return c, nil
}

// Session is what we keep in the browser cookie after a launch token is exchanged.
type Session struct {
	ServerUUID string
	UserUUID   string
	Mode       string
	Expires    int64
}

func cookieKey(secret []byte) []byte {
	mac := hmac.New(sha256.New, secret)
	mac.Write([]byte("pelican-vscode-session"))
	return mac.Sum(nil)
}

func signSession(s Session, secret []byte) string {
	body := b64.EncodeToString([]byte(fmt.Sprintf("%s|%s|%s|%d", s.ServerUUID, s.UserUUID, s.Mode, s.Expires)))
	mac := hmac.New(sha256.New, cookieKey(secret))
	mac.Write([]byte(body))
	return body + "." + b64.EncodeToString(mac.Sum(nil))
}

func verifySession(value string, secret []byte, now time.Time) (Session, error) {
	var s Session
	body, sig, ok := strings.Cut(value, ".")
	if !ok {
		return s, errors.New("malformed session")
	}
	want := hmac.New(sha256.New, cookieKey(secret))
	want.Write([]byte(body))
	got, err := b64.DecodeString(sig)
	if err != nil || !hmac.Equal(got, want.Sum(nil)) {
		return s, errors.New("invalid session")
	}
	raw, err := b64.DecodeString(body)
	if err != nil {
		return s, errors.New("malformed session")
	}
	f := strings.Split(string(raw), "|")
	if len(f) != 4 {
		return s, errors.New("malformed session")
	}
	exp, err := strconv.ParseInt(f[3], 10, 64)
	if err != nil {
		return s, errors.New("malformed session")
	}
	s = Session{ServerUUID: f[0], UserUUID: f[1], Mode: f[2], Expires: exp}
	if now.Unix() >= s.Expires {
		return s, errors.New("session expired")
	}
	return s, nil
}

// usedTokens makes launch tokens single-use until they expire.
type usedTokens struct {
	mu   sync.Mutex
	seen map[string]int64
}

func newUsedTokens() *usedTokens {
	return &usedTokens{seen: map[string]int64{}}
}

// claim returns false if the jti has already been used.
func (u *usedTokens) claim(id string, exp int64, now time.Time) bool {
	u.mu.Lock()
	defer u.mu.Unlock()
	for k, e := range u.seen {
		if now.Unix() >= e {
			delete(u.seen, k)
		}
	}
	if _, ok := u.seen[id]; ok {
		return false
	}
	u.seen[id] = exp
	return true
}
