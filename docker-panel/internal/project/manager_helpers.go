package project

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
)

func (m *Manager) normalize(project domain.Project) (domain.Project, error) {
	project.Key = cleanKey(project.Key)
	project.Name = strings.TrimSpace(project.Name)
	project.Path = normalizeContainerPath(strings.TrimSpace(project.Path))
	project.Service = cleanService(project.Service)
	project.Command = strings.TrimSpace(project.Command)
	project.Log = strings.TrimSpace(project.Log)
	project.URL = strings.TrimSpace(project.URL)

	if project.Type == "" {
		project.Type = domain.ProjectGo
	}
	if project.Name == "" {
		project.Name = project.Key
	}
	if project.Port == 0 {
		project.Port = defaultPort(project.Type)
	}

	if project.Type == domain.ProjectGo {
		if project.Service == "" {
			project.Service = goService(project)
		}
		if project.Command == "" {
			project.Command = GoDevCommand
		}
	}

	if len(project.Services) == 0 {
		project.Services = defaultServices(project)
	}
	project.Services = ServicesFor(project)
	if project.URL == "" && project.Port > 0 {
		project.URL = fmt.Sprintf("http://localhost:%d", project.Port)
	}
	if err := validate(project); err != nil {
		return domain.Project{}, err
	}
	return project, nil
}

func defaultPort(domain.ProjectType) int {
	return 8081
}

func defaultServices(project domain.Project) []string {
	if project.Type == domain.ProjectGo {
		return []string{goService(project)}
	}
	return []string{}
}

func validate(project domain.Project) error {
	if !regexp.MustCompile(`^[a-zA-Z0-9_-]{2,64}$`).MatchString(project.Key) {
		return errors.New("project key must be 2-64 letters, numbers, dashes, or underscores")
	}
	if project.Name == "" {
		return errors.New("project name is required")
	}
	if project.Type != domain.ProjectGo {
		return errors.New("unsupported project type: only Go projects are enabled")
	}
	if project.Path == "" {
		return errors.New("project path is required")
	}
	if project.Port != 0 && (project.Port < 1024 || project.Port > 65535) {
		return errors.New("port must be between 1024 and 65535")
	}
	return nil
}

func dedicatedService(project domain.Project) string {
	if project.Type == domain.ProjectGo {
		return goService(project)
	}
	return ""
}

func commandService(project domain.Project) string {
	if service := dedicatedService(project); service != "" {
		return service
	}
	return "go"
}

func resolveCommand(commandKey, custom string) (string, error) {
	commands := map[string]string{
		"go-version":      "go version",
		"go-env":          "go env GOPROXY GOOS GOARCH",
		"go-mod-download": "go mod download",
		"build":           "go build ./...",
		"test":            "go test ./...",
	}
	if commandKey == "custom" {
		if custom == "" {
			return "", errors.New("custom command is empty")
		}
		return custom, nil
	}
	command, ok := commands[commandKey]
	if !ok {
		return "", errors.New("unknown project command")
	}
	return command, nil
}

func looksDangerous(command string) bool {
	lower := strings.ToLower(command)
	for _, blocked := range []string{"rm -rf /", "mkfs", ":(){", "chmod -r 777 /", "chown -r", "dd if="} {
		if strings.Contains(lower, blocked) {
			return true
		}
	}
	return false
}

func cleanKey(value string) string {
	return strings.ToLower(regexp.MustCompile(`[^a-zA-Z0-9_-]+`).ReplaceAllString(strings.TrimSpace(value), "-"))
}

func normalizeContainerPath(path string) string {
	path = strings.Trim(path, "\"'")
	if path == "" {
		return ""
	}
	path = strings.ReplaceAll(path, "\\", "/")
	if regexp.MustCompile(`^[a-zA-Z]:/`).MatchString(path) {
		host := filepath.ToSlash(strings.TrimSpace(os.Getenv("HOST_PROJECT_PATH")))
		container := strings.TrimSpace(os.Getenv("CONTAINER_PROJECT_PATH"))
		if container == "" {
			container = "/develop"
		}
		if host != "" {
			host = strings.TrimRight(filepath.ToSlash(host), "/")
			if strings.HasPrefix(strings.ToLower(path), strings.ToLower(host)+"/") {
				return joinContainerPath(container, path[len(host):])
			}
		}
	}
	if !strings.HasPrefix(path, "/") {
		path = "/" + path
	}
	parts := []string{}
	for _, part := range strings.Split(path, "/") {
		if part == "" || part == "." {
			continue
		}
		if part == ".." {
			if len(parts) > 0 {
				parts = parts[:len(parts)-1]
			}
			continue
		}
		parts = append(parts, part)
	}
	return "/" + strings.Join(parts, "/")
}

func joinContainerPath(base, child string) string {
	base = "/" + strings.Trim(strings.ReplaceAll(base, "\\", "/"), "/")
	child = strings.Trim(strings.ReplaceAll(child, "\\", "/"), "/")
	if child == "" {
		return base
	}
	return base + "/" + child
}

func shellQuote(value string) string {
	return "'" + strings.ReplaceAll(value, "'", "'\\''") + "'"
}

func yamlSingleQuote(value string) string {
	return "'" + strings.ReplaceAll(value, "'", "''") + "'"
}

func escapeComposeDollars(value string) string {
	return strings.ReplaceAll(value, "$", "$$")
}
