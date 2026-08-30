package httpapi

import (
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

type configSaveRequest struct {
	Path    string `json:"path"`
	Content string `json:"content"`
}

type configRestoreRequest struct {
	Path   string `json:"path"`
	Backup string `json:"backup"`
}

type configValidateRequest struct {
	Path    string  `json:"path"`
	Content *string `json:"content"`
}

type configMeta struct {
	Description string
	Group       string
	Risk        string
	Validator   string
	NextAction  string
	ApplyAction string
}

var configGroups = []map[string]any{
	{"name": "Core", "order": 10, "hint": "Docker and local environment files."},
	{"name": "Projects", "order": 20, "hint": "Project launcher records."},
	{"name": "Go", "order": 25, "hint": "Go development image and module cache."},
	{"name": "Nginx", "order": 30, "hint": "Legacy web site configs."},
	{"name": "Data", "order": 50, "hint": "Redis, MySQL and related services."},
}

var knownConfigFiles = map[string]configMeta{
	"docker-compose.yml":        {"Compose service config", "Core", "high", "compose", "Validate Compose and restart affected containers.", "restart-containers"},
	".env":                      {"Local environment variables", "Core", "high", "env", "Restart affected containers after saving.", "restart-containers"},
	".env.example":              {"Environment variable example", "Core", "low", "env", "No restart is usually needed.", "none"},
	"projects.json":             {"Project launcher config", "Projects", "medium", "json", "Refresh project list after saving.", "refresh-projects"},
	"projects.example.json":     {"Project launcher example", "Projects", "low", "json", "No restart is usually needed.", "none"},
	"PROJECTS.md":               {"Project launcher notes", "Projects", "low", "text", "Documentation takes effect immediately.", "none"},
	"services/go/Dockerfile":    {"Go development image", "Go", "medium", "text", "Rebuild Go project images after saving.", "restart-containers"},
	"services/redis/redis.conf": {"Redis config", "Data", "medium", "text", "Restart Redis after saving.", "restart-redis"},
	"services/nginx/nginx.conf": {"Nginx config", "Nginx", "high", "nginx", "Validate and reload Nginx after saving.", "reload-nginx"},
}

func (h *handlers) configCenterFiles(w http.ResponseWriter, _ *http.Request) {
	files := []map[string]any{}
	paths := make([]string, 0, len(knownConfigFiles))
	for path := range knownConfigFiles {
		paths = append(paths, path)
	}
	sort.Strings(paths)
	for _, path := range paths {
		files = append(files, h.configFileInfo(path))
	}
	for _, path := range h.siteConfigPaths() {
		files = append(files, h.configFileInfo(path))
	}
	sort.Slice(files, func(i, j int) bool {
		left := files[i]["groupOrder"].(int)
		right := files[j]["groupOrder"].(int)
		if left == right {
			return files[i]["path"].(string) < files[j]["path"].(string)
		}
		return left < right
	})
	writeJSON(w, http.StatusOK, map[string]any{"files": files, "groups": configGroups})
}

func (h *handlers) configReadOnly(w http.ResponseWriter, r *http.Request) {
	switch {
	case strings.HasSuffix(r.URL.Path, "/read"):
		h.readConfigFile(w, r)
	case strings.HasSuffix(r.URL.Path, "/backups"):
		h.listConfigBackups(w, r)
	case strings.HasSuffix(r.URL.Path, "/backup-read"):
		h.readConfigBackup(w, r)
	case strings.HasSuffix(r.URL.Path, "/validate"):
		h.validateConfigFile(w, r)
	default:
		writeError(w, r, http.StatusBadRequest, "CONFIG_ACTION_UNKNOWN", errors.New("unsupported config action"))
	}
}

func (h *handlers) configMutation(w http.ResponseWriter, r *http.Request) {
	switch {
	case strings.HasSuffix(r.URL.Path, "/save"):
		h.saveConfigFile(w, r)
	case strings.HasSuffix(r.URL.Path, "/restore"):
		h.restoreConfigFile(w, r)
	default:
		writeError(w, r, http.StatusBadRequest, "CONFIG_ACTION_UNKNOWN", errors.New("unsupported config action"))
	}
}

func (h *handlers) readConfigFile(w http.ResponseWriter, r *http.Request) {
	path := strings.TrimSpace(r.URL.Query().Get("path"))
	if !h.isAllowedConfigFile(path) {
		writeError(w, r, http.StatusForbidden, "CONFIG_FORBIDDEN", errors.New("file is not allowed"))
		return
	}
	content, err := os.ReadFile(h.fullConfigPath(path))
	if err != nil {
		writeError(w, r, http.StatusNotFound, "CONFIG_NOT_FOUND", err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"file": h.configFileInfo(path), "content": string(content), "checksum": sha1Hex(content), "size": len(content)})
}

func (h *handlers) saveConfigFile(w http.ResponseWriter, r *http.Request) {
	var input configSaveRequest
	if !decodeBody(w, r, &input) {
		return
	}
	path := strings.TrimSpace(input.Path)
	if !h.isAllowedConfigFile(path) {
		writeError(w, r, http.StatusForbidden, "CONFIG_FORBIDDEN", errors.New("file is not allowed"))
		return
	}
	fullPath := h.fullConfigPath(path)
	if err := os.MkdirAll(filepath.Dir(fullPath), 0o775); err != nil {
		writeError(w, r, http.StatusInternalServerError, "CONFIG_WRITE_FAILED", err)
		return
	}
	backup := ""
	if existing, err := os.ReadFile(fullPath); err == nil {
		backup = fullPath + ".bak." + time.Now().Format("20060102150405")
		if err := os.WriteFile(backup, existing, 0o664); err != nil {
			writeError(w, r, http.StatusInternalServerError, "CONFIG_BACKUP_FAILED", err)
			return
		}
	}
	content := []byte(input.Content)
	if err := os.WriteFile(fullPath, content, 0o664); err != nil {
		writeError(w, r, http.StatusInternalServerError, "CONFIG_WRITE_FAILED", err)
		return
	}
	writeSuccess(w, "File saved", map[string]any{"file": h.configFileInfo(path), "size": len(content), "checksum": sha1Hex(content), "backup": baseOrEmpty(backup), "backups": h.configBackupInfos(path)})
}

func (h *handlers) listConfigBackups(w http.ResponseWriter, r *http.Request) {
	path := strings.TrimSpace(r.URL.Query().Get("path"))
	if !h.isAllowedConfigFile(path) {
		writeError(w, r, http.StatusForbidden, "CONFIG_FORBIDDEN", errors.New("file is not allowed"))
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"backups": h.configBackupInfos(path)})
}

func (h *handlers) restoreConfigFile(w http.ResponseWriter, r *http.Request) {
	var input configRestoreRequest
	if !decodeBody(w, r, &input) {
		return
	}
	path := strings.TrimSpace(input.Path)
	if !h.isAllowedConfigFile(path) {
		writeError(w, r, http.StatusForbidden, "CONFIG_FORBIDDEN", errors.New("file is not allowed"))
		return
	}
	backupPath := h.configBackupFullPath(path, filepath.Base(input.Backup))
	if backupPath == "" {
		writeError(w, r, http.StatusNotFound, "CONFIG_BACKUP_NOT_FOUND", errors.New("backup not found"))
		return
	}
	fullPath := h.fullConfigPath(path)
	if existing, err := os.ReadFile(fullPath); err == nil {
		_ = os.WriteFile(fullPath+".bak."+time.Now().Format("20060102150405"), existing, 0o664)
	}
	content, err := os.ReadFile(backupPath)
	if err != nil {
		writeError(w, r, http.StatusNotFound, "CONFIG_BACKUP_NOT_FOUND", err)
		return
	}
	if err := os.WriteFile(fullPath, content, 0o664); err != nil {
		writeError(w, r, http.StatusInternalServerError, "CONFIG_RESTORE_FAILED", err)
		return
	}
	writeSuccess(w, "Backup restored", map[string]any{"file": h.configFileInfo(path), "content": string(content), "checksum": sha1Hex(content), "backups": h.configBackupInfos(path)})
}

func (h *handlers) readConfigBackup(w http.ResponseWriter, r *http.Request) {
	path := strings.TrimSpace(r.URL.Query().Get("path"))
	backup := filepath.Base(strings.TrimSpace(r.URL.Query().Get("backup")))
	if !h.isAllowedConfigFile(path) {
		writeError(w, r, http.StatusForbidden, "CONFIG_FORBIDDEN", errors.New("file is not allowed"))
		return
	}
	backupPath := h.configBackupFullPath(path, backup)
	if backupPath == "" {
		writeError(w, r, http.StatusNotFound, "CONFIG_BACKUP_NOT_FOUND", errors.New("backup not found"))
		return
	}
	content, err := os.ReadFile(backupPath)
	if err != nil {
		writeError(w, r, http.StatusNotFound, "CONFIG_BACKUP_NOT_FOUND", err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"backup": backup, "content": string(content), "size": len(content), "checksum": sha1Hex(content)})
}

func (h *handlers) validateConfigFile(w http.ResponseWriter, r *http.Request) {
	var input configValidateRequest
	if !decodeBody(w, r, &input) {
		return
	}
	path := strings.TrimSpace(input.Path)
	if !h.isAllowedConfigFile(path) {
		writeError(w, r, http.StatusForbidden, "CONFIG_FORBIDDEN", errors.New("file is not allowed"))
		return
	}
	content := ""
	if input.Content != nil {
		content = *input.Content
	} else if data, err := os.ReadFile(h.fullConfigPath(path)); err == nil {
		content = string(data)
	}
	validator := h.configMetadata(path).Validator
	var err error
	switch validator {
	case "json":
		err = validateJSON(content)
	case "env":
		err = validateEnvText(content)
	case "compose":
		if h.compose == nil {
			err = errors.New("compose runner is not configured")
		} else {
			_, err = h.compose.Config(r.Context(), true)
		}
	case "nginx":
		if h.compose == nil {
			err = errors.New("compose runner is not configured")
		} else {
			_, err = h.compose.ExecShell(r.Context(), "nginx", "nginx -t")
		}
	default:
		err = validatePlainText(content)
	}
	if err != nil {
		writeError(w, r, http.StatusBadRequest, "CONFIG_VALIDATE_FAILED", err)
		return
	}
	writeSuccess(w, "Config validation passed", map[string]any{})
}
