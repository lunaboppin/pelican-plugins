package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestScanAndPrune(t *testing.T) {
	root := t.TempDir()
	write := func(p string, n int) {
		full := filepath.Join(root, p)
		if err := os.MkdirAll(filepath.Dir(full), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(full, make([]byte, n), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	write("big/a.gma", 10000)
	write("big/b.GMA", 5000)
	write("small/c.lua", 10)
	write("noext", 100)
	if err := os.Symlink("/etc/passwd", filepath.Join(root, "link")); err != nil {
		t.Fatal(err)
	}

	tree, files, dirs, truncated, err := scanDir(root)
	if err != nil {
		t.Fatal(err)
	}
	if tree.size != 15110 || files != 4 || dirs != 2 || truncated {
		t.Fatalf("got size=%d files=%d dirs=%d truncated=%v", tree.size, files, dirs, truncated)
	}
	if tree.children[0].name != "big" {
		t.Fatalf("children not sorted by size: %s", tree.children[0].name)
	}

	types := map[string]*DiskType{}
	typeBreakdown(tree, types)
	if types["gma"] == nil || types["gma"].Size != 15000 || types["gma"].Count != 2 {
		t.Fatalf("gma breakdown wrong: %+v", types["gma"])
	}
	if types[diskNoExtension] == nil {
		t.Fatal("missing no-extension type")
	}

	pruned := pruneTree(tree, 1000)
	last := pruned.Children[len(pruned.Children)-1]
	if last.Name != diskGroupedLabel || last.Grouped != 2 || last.Size != 110 {
		t.Fatalf("expected small items grouped, got %+v", last)
	}

	folders := topFolders(tree)
	if len(folders) != 2 || folders[0].Path != "/big" || folders[0].Types[0].Ext != "gma" {
		t.Fatalf("folders wrong: %+v", folders)
	}
}
