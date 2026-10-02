package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/json"
	"strings"
	"testing"
	"time"
)

var testSecret = []byte("daemon-token")

func makeToken(t *testing.T, secret []byte, alg string, claims map[string]any) string {
	t.Helper()
	h, _ := json.Marshal(map[string]string{"typ": "JWT", "alg": alg})
	p, _ := json.Marshal(claims)
	unsigned := b64.EncodeToString(h) + "." + b64.EncodeToString(p)
	mac := hmac.New(sha256.New, secret)
	mac.Write([]byte(unsigned))
	return unsigned + "." + b64.EncodeToString(mac.Sum(nil))
}

func validClaims(now time.Time) map[string]any {
	return map[string]any{
		"server_uuid": "6f1affaa-1241-4e86-a20e-8aaad682a227",
		"user_uuid":   "u",
		"mode":        "rw",
		"jti":         "abc",
		"aud":         tokenAudience,
		"exp":         now.Add(time.Minute).Unix(),
		"nbf":         now.Add(-time.Minute).Unix(),
	}
}

func TestVerifyJWT(t *testing.T) {
	now := time.Now()

	if _, err := verifyJWT(makeToken(t, testSecret, "HS256", validClaims(now)), testSecret, now); err != nil {
		t.Fatalf("valid token rejected: %v", err)
	}

	arrayAud := validClaims(now)
	arrayAud["aud"] = []string{"other", tokenAudience}
	if _, err := verifyJWT(makeToken(t, testSecret, "HS256", arrayAud), testSecret, now); err != nil {
		t.Fatalf("array audience rejected: %v", err)
	}

	cases := map[string]func(map[string]any){
		"expired":      func(c map[string]any) { c["exp"] = now.Add(-time.Second).Unix() },
		"no exp":       func(c map[string]any) { delete(c, "exp") },
		"future nbf":   func(c map[string]any) { c["nbf"] = now.Add(time.Hour).Unix() },
		"wings token":  func(c map[string]any) { c["aud"] = "https://node-dev.clart.zip:443" },
		"bad mode":     func(c map[string]any) { c["mode"] = "admin" },
		"missing jti":  func(c map[string]any) { delete(c, "jti") },
		"missing mode": func(c map[string]any) { delete(c, "mode") },
	}
	for name, mutate := range cases {
		c := validClaims(now)
		mutate(c)
		if _, err := verifyJWT(makeToken(t, testSecret, "HS256", c), testSecret, now); err == nil {
			t.Errorf("%s: expected rejection", name)
		}
	}

	if _, err := verifyJWT(makeToken(t, []byte("wrong"), "HS256", validClaims(now)), testSecret, now); err == nil {
		t.Error("wrong secret accepted")
	}
	if _, err := verifyJWT(makeToken(t, testSecret, "none", validClaims(now)), testSecret, now); err == nil {
		t.Error("alg none accepted")
	}
	if _, err := verifyJWT("garbage", testSecret, now); err == nil {
		t.Error("garbage accepted")
	}
}

func TestSession(t *testing.T) {
	now := time.Now()
	s := Session{ServerUUID: "srv", UserUUID: "usr", Mode: "ro", Expires: now.Add(time.Hour).Unix()}
	v := signSession(s, testSecret)

	got, err := verifySession(v, testSecret, now)
	if err != nil || got != s {
		t.Fatalf("round trip failed: %v %+v", err, got)
	}
	if _, err := verifySession(v, []byte("other"), now); err == nil {
		t.Error("session accepted with wrong secret")
	}
	if _, err := verifySession(v, testSecret, now.Add(2*time.Hour)); err == nil {
		t.Error("expired session accepted")
	}

	tampered := signSession(Session{ServerUUID: "srv", UserUUID: "usr", Mode: "rw", Expires: s.Expires}, []byte("other"))
	body, _, _ := strings.Cut(tampered, ".")
	_, sig, _ := strings.Cut(v, ".")
	if _, err := verifySession(body+"."+sig, testSecret, now); err == nil {
		t.Error("tampered session accepted")
	}
}

func TestUsedTokens(t *testing.T) {
	now := time.Now()
	u := newUsedTokens()
	if !u.claim("a", now.Add(time.Minute).Unix(), now) {
		t.Fatal("first use rejected")
	}
	if u.claim("a", now.Add(time.Minute).Unix(), now) {
		t.Fatal("replay accepted")
	}
	if !u.claim("a", now.Add(3*time.Minute).Unix(), now.Add(2*time.Minute)) {
		t.Fatal("expired entry not pruned")
	}
}

func TestRevocations(t *testing.T) {
	file := t.TempDir() + "/rev.json"
	now := time.Now()
	r := loadRevocations(file, time.Hour)
	if !r.Allows("s", "u", now.Unix()) {
		t.Fatal("unrevoked session rejected")
	}
	if err := r.Revoke("s", "u", now); err != nil {
		t.Fatal(err)
	}
	if r.Allows("s", "u", now.Unix()-10) {
		t.Fatal("session issued before revocation accepted")
	}
	if !r.Allows("s", "u", now.Unix()+1) {
		t.Fatal("session issued after revocation rejected")
	}
	if !r.Allows("s", "other", now.Unix()-10) {
		t.Fatal("other user affected")
	}
	if reloaded := loadRevocations(file, time.Hour); reloaded.Allows("s", "u", now.Unix()-10) {
		t.Fatal("revocation not persisted")
	}
}
