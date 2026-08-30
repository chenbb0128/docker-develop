package project

import (
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
)

func replaceManagedBlock(content, begin, end, block string) (string, bool) {
	pattern := regexp.MustCompile(`(?s)\r?\n?` + regexp.QuoteMeta(begin) + `.*?` + regexp.QuoteMeta(end) + `\r?\n?`)
	if pattern.MatchString(content) {
		replacement := "\n"
		if block != "" {
			replacement = "\n" + block + "\n"
		}
		return pattern.ReplaceAllStringFunc(content, func(string) string { return replacement }), true
	}
	return content, false
}

func (m *Manager) removeComposeService(projectType domain.ProjectType, service string) (bool, error) {
	prefix := map[domain.ProjectType]string{domain.ProjectGo: "Go"}[projectType]
	if prefix == "" {
		return false, nil
	}
	composeFile := filepath.Join(m.cfg.ProjectRoot, "docker-compose.yml")
	content, err := os.ReadFile(composeFile)
	if err != nil {
		return false, err
	}
	begin := fmt.Sprintf("# BEGIN %s project: %s", prefix, service)
	end := fmt.Sprintf("# END %s project: %s", prefix, service)
	updated, removed := replaceManagedBlock(string(content), begin, end, "")
	if !removed {
		return false, nil
	}
	return true, os.WriteFile(composeFile, []byte(strings.TrimSpace(updated)+"\n"), 0o664)
}

func intersect(order []string, values []string) []string {
	set := map[string]struct{}{}
	for _, value := range values {
		set[value] = struct{}{}
	}
	out := []string{}
	for _, value := range order {
		if _, ok := set[value]; ok {
			out = append(out, value)
		}
	}
	return out
}

func imageAdvice(text string) string {
	lower := strings.ToLower(text)
	for _, needle := range []string{"manifest unknown", "unknown blob", "tls: first record", "context deadline exceeded", "client.timeout", "registry-1.docker.io", "pull access denied"} {
		if strings.Contains(lower, needle) {
			return "Image source hint: configure Aliyun mirror in Docker Desktop registry-mirrors. Keep BASE_IMAGE_REGISTRY empty so Dockerfile FROM names are not polluted."
		}
	}
	return ""
}

func withImageAdvice(text string) string {
	advice := imageAdvice(text)
	if advice == "" {
		return text
	}
	return strings.TrimSpace(text + "\n\n" + advice)
}
