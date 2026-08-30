package httpapi

import (
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/go-chi/chi/v5"
)

type siteCreateRequest struct {
	Name       string `json:"name"`
	ServerName string `json:"serverName"`
	Root       string `json:"root"`
	Port       int    `json:"port"`
	Type       string `json:"type"`
	UsePort    bool   `json:"usePort"`
}

func (h *handlers) listSites(w http.ResponseWriter, r *http.Request) {
	siteDir := filepath.Join(h.cfg.ProjectRoot, "services", "nginx", "sites")
	entries, _ := os.ReadDir(siteDir)
	sites := []map[string]any{}
	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".conf") {
			continue
		}
		path := filepath.Join(siteDir, entry.Name())
		contentBytes, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		active := stripCommentedLines(string(contentBytes))
		sites = append(sites, map[string]any{
			"filename":   entry.Name(),
			"serverName": regexFirst(active, `server_name\s+([^;]+);`, ""),
			"root":       regexFirst(active, `root\s+([^;]+);`, ""),
			"port":       parseInt(regexFirst(active, `listen\s+(\d+);`, "80"), 80),
			"enabled":    regexp.MustCompile(`(?m)^\s*server\s*\{`).MatchString(active),
		})
	}
	sort.Slice(sites, func(i, j int) bool { return sites[i]["filename"].(string) < sites[j]["filename"].(string) })
	writeJSON(w, http.StatusOK, map[string]any{"sites": sites, "exposedPorts": h.exposedPorts(r)})
}

func (h *handlers) siteMutation(w http.ResponseWriter, r *http.Request) {
	switch {
	case r.Method == http.MethodPost && r.URL.Path == "/api/sites":
		h.createSite(w, r)
	case r.Method == http.MethodDelete:
		h.deleteSite(w, r)
	case strings.HasSuffix(r.URL.Path, "/reload-nginx"):
		h.reloadNginx(w, r)
	case strings.HasSuffix(r.URL.Path, "/restart-nginx"):
		h.restartNginx(w, r)
	default:
		writeError(w, r, http.StatusBadRequest, "SITE_ACTION_UNKNOWN", errors.New("unsupported site action"))
	}
}

func (h *handlers) createSite(w http.ResponseWriter, r *http.Request) {
	var input siteCreateRequest
	if !decodeBody(w, r, &input) {
		return
	}
	if input.Type != "" && input.Type != "static" {
		writeError(w, r, http.StatusBadRequest, "SITE_TYPE_UNSUPPORTED", errors.New("only static Nginx sites are supported"))
		return
	}
	name := cleanFilename(strings.TrimSpace(input.Name))
	root := strings.TrimSpace(input.Root)
	if name == "" || root == "" {
		writeError(w, r, http.StatusBadRequest, "SITE_INVALID", errors.New("name and root are required"))
		return
	}
	port := input.Port
	if port == 0 {
		port = 80
	}
	if input.UsePort && (port < 1024 || port > 65535) {
		writeError(w, r, http.StatusBadRequest, "SITE_PORT_INVALID", errors.New("port must be between 1024 and 65535"))
		return
	}
	serverName := strings.TrimSpace(input.ServerName)
	if serverName == "" || input.UsePort {
		serverName = "localhost"
	}
	filename := name + ".conf"
	siteDir := filepath.Join(h.cfg.ProjectRoot, "services", "nginx", "sites")
	if err := os.MkdirAll(siteDir, 0o775); err != nil {
		writeError(w, r, http.StatusInternalServerError, "SITE_WRITE_FAILED", err)
		return
	}
	path := filepath.Join(siteDir, filename)
	if _, err := os.Stat(path); err == nil {
		writeError(w, r, http.StatusBadRequest, "SITE_EXISTS", errors.New("site config already exists"))
		return
	}
	if err := os.WriteFile(path, []byte(generateNginxSite(serverName, root, port)), 0o664); err != nil {
		writeError(w, r, http.StatusInternalServerError, "SITE_WRITE_FAILED", err)
		return
	}
	portExposed := true
	needRestart := false
	if input.UsePort {
		if changed, err := h.ensureNginxPort(port); err != nil {
			portExposed = false
		} else {
			needRestart = changed
		}
	}
	accessURL := "http://" + serverName
	if input.UsePort {
		accessURL = fmt.Sprintf("http://localhost:%d", port)
	}
	writeSuccess(w, "Site created", map[string]any{"filename": filename, "accessUrl": accessURL, "portExposed": portExposed, "needRestartNginx": needRestart, "hostsEntry": "127.0.0.1 " + serverName, "usePort": input.UsePort})
}

func (h *handlers) deleteSite(w http.ResponseWriter, r *http.Request) {
	filename := chi.URLParam(r, "filename")
	if !regexp.MustCompile(`^[a-zA-Z0-9_.-]+\.conf$`).MatchString(filename) {
		writeError(w, r, http.StatusBadRequest, "SITE_FILENAME_INVALID", errors.New("invalid filename"))
		return
	}
	path := filepath.Join(h.cfg.ProjectRoot, "services", "nginx", "sites", filename)
	content, err := os.ReadFile(path)
	if err != nil {
		writeError(w, r, http.StatusNotFound, "SITE_NOT_FOUND", err)
		return
	}
	_ = os.WriteFile(path+".bak."+time.Now().Format("20060102150405"), content, 0o664)
	if err := os.Remove(path); err != nil {
		writeError(w, r, http.StatusInternalServerError, "SITE_DELETE_FAILED", err)
		return
	}
	writeSuccess(w, "Site deleted", map[string]any{})
}

func (h *handlers) reloadNginx(w http.ResponseWriter, r *http.Request) {
	if h.compose == nil {
		writeError(w, r, http.StatusServiceUnavailable, "COMPOSE_UNAVAILABLE", errors.New("compose runner is not configured"))
		return
	}
	if result, err := h.compose.ExecShell(r.Context(), "nginx", "nginx -t"); err != nil {
		writeError(w, r, http.StatusBadRequest, "NGINX_TEST_FAILED", errors.New(result.Output+err.Error()))
		return
	}
	if result, err := h.compose.ExecShell(r.Context(), "nginx", "nginx -s reload"); err != nil {
		writeError(w, r, http.StatusInternalServerError, "NGINX_RELOAD_FAILED", errors.New(result.Output+err.Error()))
		return
	}
	writeSuccess(w, "Nginx reloaded", map[string]any{})
}

func (h *handlers) restartNginx(w http.ResponseWriter, r *http.Request) {
	if h.compose == nil {
		writeError(w, r, http.StatusServiceUnavailable, "COMPOSE_UNAVAILABLE", errors.New("compose runner is not configured"))
		return
	}
	result, err := h.compose.Up(r.Context(), []string{"nginx"})
	if err != nil {
		writeError(w, r, http.StatusInternalServerError, "NGINX_RESTART_FAILED", err)
		return
	}
	writeSuccess(w, "Nginx restarted", map[string]any{"output": result.Output})
}

func (h *handlers) hostsCommand(w http.ResponseWriter, r *http.Request) {
	domain := strings.TrimSpace(r.URL.Query().Get("domain"))
	if domain == "" {
		writeJSON(w, http.StatusOK, map[string]any{"command": ""})
		return
	}
	entry := "127.0.0.1 " + domain
	writeSuccess(w, "ok", map[string]any{"domain": domain, "entry": entry, "windowsCmd": "echo " + entry + " >> C:\\Windows\\System32\\drivers\\etc\\hosts", "powershellCmd": "Add-Content -Path 'C:\\Windows\\System32\\drivers\\etc\\hosts' -Value '" + entry + "'"})
}

func (h *handlers) exposedPorts(_ *http.Request) []int {
	content, err := os.ReadFile(filepath.Join(h.cfg.ProjectRoot, "docker-compose.yml"))
	if err != nil {
		return []int{80, 443}
	}
	seen := map[int]struct{}{80: {}, 443: {}}
	for _, match := range regexp.MustCompile(`-\s*["']?(\d+):(\d+)["']?`).FindAllStringSubmatch(string(content), -1) {
		port, _ := strconv.Atoi(match[1])
		if port > 0 {
			seen[port] = struct{}{}
		}
	}
	ports := make([]int, 0, len(seen))
	for port := range seen {
		ports = append(ports, port)
	}
	sort.Ints(ports)
	return ports
}

func (h *handlers) ensureNginxPort(port int) (bool, error) {
	composeFile := filepath.Join(h.cfg.ProjectRoot, "docker-compose.yml")
	contentBytes, err := os.ReadFile(composeFile)
	if err != nil {
		return false, err
	}
	content := string(contentBytes)
	portText := fmt.Sprintf("\"%d:%d\"", port, port)
	if strings.Contains(content, portText) {
		return false, nil
	}
	pattern := regexp.MustCompile(`(?ms)(  nginx:\r?\n(?:(?!^  [a-zA-Z0-9_-]+:).)*?    ports:\r?\n)((?:      - .*\r?\n)+)`)
	matches := pattern.FindStringSubmatch(content)
	if len(matches) != 3 {
		return false, errors.New("unable to find nginx ports block in docker-compose.yml")
	}
	replacement := matches[1] + strings.TrimRight(matches[2], "\r\n") + "\n      - " + portText + "\n"
	updated := pattern.ReplaceAllString(content, replacement)
	return true, os.WriteFile(composeFile, []byte(updated), 0o664)
}

func stripCommentedLines(content string) string {
	return regexp.MustCompile(`(?m)^\s*#.*(?:\r?\n)?`).ReplaceAllString(content, "")
}

func regexFirst(content, pattern, fallback string) string {
	match := regexp.MustCompile(pattern).FindStringSubmatch(content)
	if len(match) < 2 {
		return fallback
	}
	return strings.TrimSpace(match[1])
}

func parseInt(value string, fallback int) int {
	parsed, err := strconv.Atoi(strings.TrimSpace(value))
	if err != nil {
		return fallback
	}
	return parsed
}

func cleanFilename(value string) string {
	return regexp.MustCompile(`[^a-zA-Z0-9_-]+`).ReplaceAllString(strings.TrimSpace(value), "")
}

func generateNginxSite(serverName, root string, port int) string {
	logName := regexp.MustCompile(`[^a-zA-Z0-9_-]+`).ReplaceAllString(serverName, "_")
	if logName == "" {
		logName = "site"
	}
	if port != 80 {
		logName = fmt.Sprintf("%s_%d", logName, port)
	}
	lines := []string{
		"server {",
		fmt.Sprintf("    listen %d;", port),
		fmt.Sprintf("    listen [::]:%d;", port),
		"",
		"    server_name " + serverName + ";",
		"    root " + root + ";",
		"    index index.html index.htm;",
		"",
		"    location / {",
		"        try_files $uri $uri/ =404;",
		"    }",
		"",
		"    location ~ /\\.ht {",
		"        deny all;",
		"    }",
		"",
		"    error_log /var/log/nginx/" + logName + "_error.log;",
		"    access_log /var/log/nginx/" + logName + "_access.log;",
		"}",
		"",
	}
	return strings.Join(lines, "\n")
}
