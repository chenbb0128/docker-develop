package httpapi

import (
	"crypto/sha1"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
)

func (h *handlers) configFileInfo(path string) map[string]any {
	fullPath := h.fullConfigPath(path)
	info, err := os.Stat(fullPath)
	meta := h.configMetadata(path)
	return map[string]any{
		"path":        path,
		"name":        filepath.Base(path),
		"description": meta.Description,
		"group":       meta.Group,
		"groupOrder":  groupOrder(meta.Group),
		"risk":        meta.Risk,
		"validator":   meta.Validator,
		"nextAction":  meta.NextAction,
		"applyAction": meta.ApplyAction,
		"exists":      err == nil && !info.IsDir(),
		"size":        fileSize(info, err),
		"modified":    fileModified(info, err),
		"backupCount": len(h.configBackupInfos(path)),
	}
}

func (h *handlers) configMetadata(path string) configMeta {
	if meta, ok := knownConfigFiles[path]; ok {
		return meta
	}
	return configMeta{"Nginx site config", "Nginx", "medium", "nginx", "Validate and reload Nginx after saving.", "reload-nginx"}
}

func groupOrder(group string) int {
	for _, item := range configGroups {
		if item["name"] == group {
			if order, ok := item["order"].(int); ok {
				return order
			}
		}
	}
	return 999
}

func (h *handlers) isAllowedConfigFile(path string) bool {
	if path == "" || strings.Contains(path, "..") || strings.Contains(path, "\\") || strings.HasPrefix(path, "/") {
		return false
	}
	if _, ok := knownConfigFiles[path]; ok {
		return true
	}
	return regexp.MustCompile(`^services/nginx/sites/[A-Za-z0-9_.-]+\.conf$`).MatchString(path)
}

func (h *handlers) fullConfigPath(path string) string {
	return filepath.Join(h.cfg.ProjectRoot, filepath.FromSlash(path))
}

func (h *handlers) siteConfigPaths() []string {
	dir := filepath.Join(h.cfg.ProjectRoot, "services", "nginx", "sites")
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil
	}
	paths := []string{}
	pattern := regexp.MustCompile(`^[A-Za-z0-9_.-]+\.conf$`)
	for _, entry := range entries {
		if !entry.IsDir() && pattern.MatchString(entry.Name()) {
			paths = append(paths, "services/nginx/sites/"+entry.Name())
		}
	}
	sort.Strings(paths)
	return paths
}

func (h *handlers) configBackupInfos(path string) []map[string]any {
	fullPath := h.fullConfigPath(path)
	matches, _ := filepath.Glob(fullPath + ".bak.*")
	backups := []map[string]any{}
	for _, backupPath := range matches {
		info, err := os.Stat(backupPath)
		if err != nil || info.IsDir() {
			continue
		}
		backups = append(backups, map[string]any{"name": filepath.Base(backupPath), "size": info.Size(), "modified": info.ModTime().Format("2006-01-02 15:04:05")})
	}
	sort.Slice(backups, func(i, j int) bool { return backups[i]["modified"].(string) > backups[j]["modified"].(string) })
	return backups
}

func (h *handlers) configBackupFullPath(path, backup string) string {
	if backup == "" || strings.Contains(backup, "/") || strings.Contains(backup, "\\") {
		return ""
	}
	for _, item := range h.configBackupInfos(path) {
		if item["name"] == backup {
			return filepath.Join(filepath.Dir(h.fullConfigPath(path)), backup)
		}
	}
	return ""
}

func validateJSON(content string) error {
	var payload any
	if err := json.Unmarshal([]byte(content), &payload); err != nil {
		return fmt.Errorf("invalid JSON: %w", err)
	}
	return nil
}

func validateEnvText(content string) error {
	errorsList := []string{}
	pattern := regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*=.*$`)
	for index, line := range strings.Split(content, "\n") {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") {
			continue
		}
		if !pattern.MatchString(trimmed) {
			errorsList = append(errorsList, fmt.Sprintf("line %d is not KEY=value", index+1))
		}
	}
	if len(errorsList) > 0 {
		return errors.New(strings.Join(errorsList, "\n"))
	}
	return nil
}

func validatePlainText(content string) error {
	if strings.Contains(content, "\x00") {
		return errors.New("file contains NUL bytes")
	}
	return nil
}

func sha1Hex(content []byte) string {
	sum := sha1.Sum(content)
	return hex.EncodeToString(sum[:])
}

func baseOrEmpty(path string) string {
	if path == "" {
		return ""
	}
	return filepath.Base(path)
}

func fileSize(info os.FileInfo, err error) int64 {
	if err != nil || info == nil || info.IsDir() {
		return 0
	}
	return info.Size()
}

func fileModified(info os.FileInfo, err error) any {
	if err != nil || info == nil || info.IsDir() {
		return nil
	}
	return info.ModTime().Format("2006-01-02 15:04:05")
}
