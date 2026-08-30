package web

import (
	"embed"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

//go:embed assets/index.html
var fallback embed.FS

func NewHandler(webDir string) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet && r.Method != http.MethodHead {
			http.NotFound(w, r)
			return
		}

		path := r.URL.Path
		if path == "/" {
			serveIndex(w, r, webDir)
			return
		}

		if webDir != "" {
			clean := strings.TrimPrefix(filepath.Clean(path), string(filepath.Separator))
			fullPath := filepath.Join(webDir, clean)
			if info, err := os.Stat(fullPath); err == nil && !info.IsDir() {
				http.ServeFile(w, r, fullPath)
				return
			}
		}

		http.NotFound(w, r)
	})
}

func serveIndex(w http.ResponseWriter, r *http.Request, webDir string) {
	if webDir != "" {
		indexPath := filepath.Join(webDir, "index.html")
		if info, err := os.Stat(indexPath); err == nil && !info.IsDir() {
			w.Header().Set("Cache-Control", "no-store, no-cache, must-revalidate, max-age=0")
			w.Header().Set("Pragma", "no-cache")
			http.ServeFile(w, r, indexPath)
			return
		}
	}

	data, err := fallback.ReadFile("assets/index.html")
	if err != nil {
		http.Error(w, "index unavailable", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store, no-cache, must-revalidate, max-age=0")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(data)
}
