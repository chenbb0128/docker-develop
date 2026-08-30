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

func (m *Manager) ensureGoComposeService(project domain.Project) error {
	composeFile := filepath.Join(m.cfg.ProjectRoot, "docker-compose.yml")
	content, err := os.ReadFile(composeFile)
	if err != nil {
		return err
	}
	service := goService(project)
	begin := "# BEGIN Go project: " + service
	end := "# END Go project: " + service
	block := begin + "\n" + newGoServiceConfig(project) + end
	updated, replaced := replaceManagedBlock(string(content), begin, end, block)
	if !replaced {
		marker := regexp.MustCompile(`(?m)^#-+\r?\n# Go Development Environment\r?\n#-+\r?\n`)
		if !marker.MatchString(updated) {
			return errors.New("unable to find Go marker in docker-compose.yml")
		}
		updated = marker.ReplaceAllStringFunc(updated, func(match string) string { return "\n" + block + "\n\n" + match })
	}
	return os.WriteFile(composeFile, []byte(strings.TrimSpace(updated)+"\n"), 0o664)
}

func newGoServiceConfig(project domain.Project) string {
	service := goService(project)
	deps := intersect([]string{"redis", "mysql", "rabbitmq"}, normalizeServices(project.Services))
	startup := project.Command
	if startup == "" {
		startup = GoDevCommand
	}
	startup = escapeComposeDollars(startup)
	envLines := []string{
		"      - TZ=${TIMEZONE}",
		"      - GO111MODULE=on",
		"      - GOPROXY=${GOPROXY}",
		"      - CGO_ENABLED=${GO_CGO_ENABLED}",
		"      - PORT=8080",
		"      - SERVER_PORT=8080",
		"      - APP_PORT=8080",
	}
	if contains(deps, "redis") {
		envLines = append(envLines, "      - REDIS_HOST=redis", "      - REDIS_PORT=6379", "      - REDIS_PASSWORD=${REDIS_PASSWORD}")
	}
	if contains(deps, "mysql") {
		envLines = append(envLines, "      - DB_HOST=mysql", "      - DB_PORT=3306", "      - DB_NAME=${MYSQL_DATABASE}", "      - DB_USERNAME=root", "      - DB_PASSWORD=${MYSQL_ROOT_PASSWORD}")
	}
	if contains(deps, "rabbitmq") {
		envLines = append(envLines, "      - RABBITMQ_HOST=rabbitmq", "      - RABBITMQ_PORT=5672", "      - RABBITMQ_USERNAME=guest", "      - RABBITMQ_PASSWORD=guest")
	}
	lines := []string{
		"",
		"  " + service + ":",
		"    build:",
		"      context: ./services/go",
		"      args:",
		"        - GO_VERSION=${GO_VERSION}",
		"        - GOPROXY=${GOPROXY}",
		"        - PUID=${GO_PUID}",
		"        - PGID=${GO_PGID}",
		"        - CONTAINER_PROJECT_PATH=${CONTAINER_PROJECT_PATH}",
		"        - INSTALL_DELVE=${GO_INSTALL_DELVE}",
		"        - CHANGE_SOURCE=${CHANGE_SOURCE}",
		"        - BASE_IMAGE_REGISTRY=${BASE_IMAGE_REGISTRY}",
		"    working_dir: " + yamlSingleQuote(project.Path),
		"    command: sh -c " + yamlSingleQuote(startup),
		"    volumes:",
		"      - ${HOST_PROJECT_PATH}:${CONTAINER_PROJECT_PATH}",
		"      - go-mod-cache:/go/pkg/mod",
		"      - go-build-cache:/home/developer/.cache/go-build",
		"    environment:",
	}
	lines = append(lines, envLines...)
	lines = append(lines,
		"    ports:",
		fmt.Sprintf("      - \"%d:8080\"", project.Port),
		"    extra_hosts:",
		"      - \"dockerhost:${DOCKER_HOST_IP}\"",
		"      - \"host.docker.internal:host-gateway\"",
	)
	if len(deps) > 0 {
		lines = append(lines, "    depends_on:")
		for _, dep := range deps {
			lines = append(lines, "      "+dep+":", "        condition: service_healthy")
		}
	}
	lines = append(lines, "    tty: true", "    networks:", "      - frontend", "      - backend")
	return strings.Join(lines, "\n") + "\n"
}
