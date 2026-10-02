package main

import "testing"

func TestCleanRepoPath(t *testing.T) {
	cases := map[string]string{"/": "", "": "", "/garrysmod/addons/x": "garrysmod/addons/x", "../../etc": "etc", "/a/../../b": "b", "a//b/": "a/b"}
	for in, want := range cases {
		got, err := cleanRepoPath(in)
		if err != nil || got != want {
			t.Errorf("cleanRepoPath(%q) = %q, %v; want %q", in, got, err, want)
		}
	}
	if _, err := cleanRepoPath("a\nb"); err == nil {
		t.Error("newline accepted")
	}
}

func TestValidRemoteURL(t *testing.T) {
	good := []string{"https://github.com/a/b.git", "git@github.com:FearlessGaming/CityRP.git", "ssh://git@gitlab.com:22/a/b.git"}
	bad := []string{"file:///etc", "ext::sh -c id", "-uhttps://x", "http://insecure/x", "/local/path", "git@host:a b", "https://"}
	for _, u := range good {
		if !validRemoteURL(u) {
			t.Errorf("rejected %q", u)
		}
	}
	for _, u := range bad {
		if validRemoteURL(u) {
			t.Errorf("accepted %q", u)
		}
	}
}

func TestRedactURL(t *testing.T) {
	if got := redactURL("https://user:ghp_secret@github.com/a/b.git"); got != "https://user:REDACTED@github.com/a/b.git" {
		t.Errorf("got %q", got)
	}
	if got := redactURL("https://ghp_secret@github.com/a/b.git"); got != "https://REDACTED@github.com/a/b.git" {
		t.Errorf("got %q", got)
	}
	if got := redactURL("git@github.com:a/b.git"); got != "git@github.com:a/b.git" {
		t.Errorf("got %q", got)
	}
}

func TestShellQuote(t *testing.T) {
	if got := shellQuote("it's"); got != `'it'\''s'` {
		t.Errorf("got %s", got)
	}
}

func TestParseScanAndLog(t *testing.T) {
	repos := parseScan("noise\nREPO\t/garrysmod/gamemodes/cityrp\tmain\tb4bb6d8\thttps://t:x@github.com/a.git\t3\t1\t2\t1700000000\nREPO\t\t(detached)\tabc\t\t0\t0\t0\t0\n")
	if len(repos) != 2 || repos[0].Changes != 3 || repos[0].Behind != 1 || repos[0].Ahead != 2 || repos[0].Remote != "https://t:REDACTED@github.com/a.git" || repos[1].Path != "/" {
		t.Fatalf("bad scan parse: %+v", repos)
	}
	commits := parseLog("h1\x1fs1\x1fAlice\x1f1700000000\x1fFix thing\x1e\nh2\x1fs2\x1fBob\x1f1700000001\x1fAdd | pipes\x1e\n")
	if len(commits) != 2 || commits[1].Subject != "Add | pipes" || commits[0].Author != "Alice" {
		t.Fatalf("bad log parse: %+v", commits)
	}
}
