package main

import (
	"bytes"
	"compress/gzip"
	"encoding/json"
	"io"
	"io/fs"
	"log"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
)

const (
	diskCacheTTL     = 5 * time.Minute
	diskMaxEntries   = 2_000_000
	diskMinFraction  = 1.0 / 5000 // nodes smaller than this share of the total are grouped
	diskMaxChildren  = 250
	diskTopFolders   = 60
	diskTopTypes     = 40
	diskFolderTypes  = 6
	diskNoExtension  = "(none)"
	diskOtherTypes   = "(other)"
	diskGroupedLabel = "smaller items"
)

// diskNode is the full in-memory tree built while scanning.
type diskNode struct {
	name     string
	size     int64
	files    int64
	ext      string
	isDir    bool
	children []*diskNode
}

// DiskNode is the pruned tree sent to the browser.
type DiskNode struct {
	Name     string      `json:"n"`
	Size     int64       `json:"s"`
	Files    int64       `json:"f,omitempty"`
	Ext      string      `json:"x,omitempty"`
	Grouped  int64       `json:"o,omitempty"`
	Children []*DiskNode `json:"d,omitempty"`
}

type DiskType struct {
	Ext   string `json:"ext"`
	Size  int64  `json:"size"`
	Count int64  `json:"count"`
}

type DiskFolder struct {
	Path    string     `json:"path"`
	Size    int64      `json:"size"`
	Entries int        `json:"entries"`
	Types   []DiskType `json:"types"`
}

type DiskReport struct {
	ScannedAt  int64        `json:"scanned_at"`
	DurationMs int64        `json:"duration_ms"`
	Total      int64        `json:"total"`
	Files      int64        `json:"files"`
	Dirs       int64        `json:"dirs"`
	Truncated  bool         `json:"truncated"`
	Tree       *DiskNode    `json:"tree"`
	Types      []DiskType   `json:"types"`
	Folders    []DiskFolder `json:"folders"`
}

type diskCacheEntry struct {
	mu     sync.Mutex
	report []byte // gzipped JSON
	at     time.Time
}

type DiskScanner struct {
	mu    sync.Mutex
	cache map[string]*diskCacheEntry
}

func newDiskScanner() *DiskScanner {
	return &DiskScanner{cache: map[string]*diskCacheEntry{}}
}

func fileExt(name string) string {
	ext := strings.ToLower(strings.TrimPrefix(path.Ext(name), "."))
	if ext == "" || len(ext) > 12 || strings.ContainsAny(ext, " ") {
		return diskNoExtension
	}
	return ext
}

// scanDir walks root without following symlinks and builds the full tree.
func scanDir(root string) (*diskNode, int64, int64, bool, error) {
	top := &diskNode{name: "", isDir: true}
	dirs := map[string]*diskNode{".": top}
	var files, ndirs, seen int64
	truncated := false

	err := filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil // unreadable entries are skipped
		}
		rel, _ := filepath.Rel(root, p)
		if rel == "." {
			return nil
		}
		seen++
		if seen > diskMaxEntries {
			truncated = true
			return filepath.SkipAll
		}
		parent := dirs[filepath.Dir(rel)]
		if parent == nil {
			return nil
		}
		if d.IsDir() {
			n := &diskNode{name: d.Name(), isDir: true}
			parent.children = append(parent.children, n)
			dirs[rel] = n
			ndirs++
			return nil
		}
		if !d.Type().IsRegular() {
			return nil // symlinks, sockets, devices
		}
		info, err := d.Info()
		if err != nil {
			return nil
		}
		parent.children = append(parent.children, &diskNode{name: d.Name(), size: info.Size(), files: 1, ext: fileExt(d.Name())})
		files++
		return nil
	})
	if err != nil {
		return nil, 0, 0, false, err
	}

	var total func(n *diskNode)
	total = func(n *diskNode) {
		if !n.isDir {
			return
		}
		for _, c := range n.children {
			total(c)
			n.size += c.size
			n.files += c.files
		}
		sort.Slice(n.children, func(i, j int) bool { return n.children[i].size > n.children[j].size })
	}
	total(top)

	return top, files, ndirs, truncated, nil
}

func pruneTree(n *diskNode, min int64) *DiskNode {
	out := &DiskNode{Name: n.name, Size: n.size, Ext: n.ext}
	if !n.isDir {
		return out
	}
	out.Files = n.files
	if out.Files == 0 {
		out.Files = -1 // marks an empty directory as a directory
	}
	var grouped *DiskNode
	for i, c := range n.children {
		if c.size >= min && i < diskMaxChildren {
			out.Children = append(out.Children, pruneTree(c, min))
			continue
		}
		if grouped == nil {
			grouped = &DiskNode{Name: diskGroupedLabel}
		}
		grouped.Size += c.size
		grouped.Grouped++
	}
	if grouped != nil && grouped.Size > 0 {
		out.Children = append(out.Children, grouped)
	}
	return out
}

func typeBreakdown(n *diskNode, into map[string]*DiskType) {
	if !n.isDir {
		t := into[n.ext]
		if t == nil {
			t = &DiskType{Ext: n.ext}
			into[n.ext] = t
		}
		t.Size += n.size
		t.Count++
		return
	}
	for _, c := range n.children {
		typeBreakdown(c, into)
	}
}

func sortedTypes(m map[string]*DiskType, limit int) []DiskType {
	list := make([]DiskType, 0, len(m))
	for _, t := range m {
		list = append(list, *t)
	}
	sort.Slice(list, func(i, j int) bool { return list[i].Size > list[j].Size })
	if len(list) > limit {
		other := DiskType{Ext: diskOtherTypes}
		for _, t := range list[limit:] {
			other.Size += t.Size
			other.Count += t.Count
		}
		list = append(list[:limit], other)
	}
	return list
}

func topFolders(root *diskNode) []DiskFolder {
	type entry struct {
		path string
		node *diskNode
	}
	var all []entry
	var walk func(n *diskNode, p string)
	walk = func(n *diskNode, p string) {
		for _, c := range n.children {
			if c.isDir {
				cp := p + "/" + c.name
				all = append(all, entry{cp, c})
				walk(c, cp)
			}
		}
	}
	walk(root, "")
	sort.Slice(all, func(i, j int) bool { return all[i].node.size > all[j].node.size })
	if len(all) > diskTopFolders {
		all = all[:diskTopFolders]
	}

	out := make([]DiskFolder, 0, len(all))
	for _, e := range all {
		types := map[string]*DiskType{}
		typeBreakdown(e.node, types)
		out = append(out, DiskFolder{Path: e.path, Size: e.node.size, Entries: len(e.node.children), Types: sortedTypes(types, diskFolderTypes)})
	}
	return out
}

func (s *DiskScanner) scan(root string) ([]byte, error) {
	start := time.Now()
	tree, files, dirs, truncated, err := scanDir(root)
	if err != nil {
		return nil, err
	}

	min := int64(float64(tree.size) * diskMinFraction)
	types := map[string]*DiskType{}
	typeBreakdown(tree, types)

	report := DiskReport{
		ScannedAt:  time.Now().Unix(),
		DurationMs: time.Since(start).Milliseconds(),
		Total:      tree.size,
		Files:      files,
		Dirs:       dirs,
		Truncated:  truncated,
		Tree:       pruneTree(tree, min),
		Types:      sortedTypes(types, diskTopTypes),
		Folders:    topFolders(tree),
	}

	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	if err := json.NewEncoder(gz).Encode(report); err != nil {
		return nil, err
	}
	if err := gz.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

// Report returns a cached gzipped report, rescanning if stale or forced.
func (s *DiskScanner) Report(uuid, root string, refresh bool) ([]byte, error) {
	s.mu.Lock()
	e := s.cache[uuid]
	if e == nil {
		e = &diskCacheEntry{}
		s.cache[uuid] = e
	}
	s.mu.Unlock()

	e.mu.Lock()
	defer e.mu.Unlock()
	if e.report != nil && !refresh && time.Since(e.at) < diskCacheTTL {
		return e.report, nil
	}
	report, err := s.scan(root)
	if err != nil {
		return nil, err
	}
	e.report, e.at = report, time.Now()
	return report, nil
}

func (g *Gateway) serveDisk(w http.ResponseWriter, r *http.Request, uuid string) {
	now := time.Now()
	claims, err := verifyToken(r.URL.Query().Get("token"), g.cfg.Secret, now, diskAudience)
	if err != nil || claims.ServerUUID != uuid {
		http.Error(w, "invalid or expired token", http.StatusUnauthorized)
		return
	}
	if !g.used.claim(claims.ID, claims.Expires, now) {
		http.Error(w, "token already used", http.StatusUnauthorized)
		return
	}

	root := filepath.Join(g.cfg.VolumesDir, uuid)
	if st, err := os.Stat(root); err != nil || !st.IsDir() {
		http.Error(w, "server volume not found", http.StatusNotFound)
		return
	}

	report, err := g.disk.Report(uuid, root, r.URL.Query().Get("refresh") == "1")
	if err != nil {
		log.Printf("disk scan %s: %v", uuid, err)
		http.Error(w, "scan failed", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	if strings.Contains(r.Header.Get("Accept-Encoding"), "gzip") {
		w.Header().Set("Content-Encoding", "gzip")
		_, _ = w.Write(report)
		return
	}
	gz, err := gzip.NewReader(bytes.NewReader(report))
	if err != nil {
		http.Error(w, "scan failed", http.StatusInternalServerError)
		return
	}
	defer gz.Close()
	_, _ = io.Copy(w, gz)
}
