package project

import (
	"context"
	"regexp"
	"strings"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
)

type Store interface {
	List(context.Context) ([]domain.Project, error)
	ConfigExists() bool
}

type ContainerLister interface {
	ListContainers(context.Context, bool) ([]domain.Container, error)
}

type Service struct {
	store          Store
	containers     ContainerLister
	composeProject string
}

func NewService(store Store, containers ContainerLister, composeProject string) *Service {
	if composeProject == "" {
		composeProject = "docker-develop"
	}
	return &Service{store: store, containers: containers, composeProject: composeProject}
}

func (s *Service) ConfigExists() bool {
	return s.store.ConfigExists()
}

func (s *Service) List(ctx context.Context) ([]domain.Project, error) {
	projects, err := s.store.List(ctx)
	if err != nil {
		return nil, err
	}

	containers := []domain.Container{}
	if s.containers != nil {
		containers, err = s.containers.ListContainers(ctx, true)
		if err != nil {
			return nil, err
		}
	}

	activeProjects := make([]domain.Project, 0, len(projects))
	for index := range projects {
		if projects[index].Type != domain.ProjectGo {
			continue
		}
		activeProjects = append(activeProjects, s.withStatus(projects[index], containers))
	}
	return activeProjects, nil
}

func (s *Service) withStatus(project domain.Project, containers []domain.Container) domain.Project {
	services := ServicesFor(project)
	running := 0
	statuses := make([]domain.ServiceStatus, 0, len(services))

	for _, service := range services {
		matched := s.findContainerForService(containers, service)
		isRunning := matched != nil && matched.State == "running"
		if isRunning {
			running++
		}

		status := domain.ServiceStatus{
			Name:    service,
			State:   "missing",
			Status:  "Container not created",
			Running: isRunning,
		}
		if matched != nil {
			status.Container = matched.Name
			status.State = matched.State
			status.Status = matched.Status
		}
		statuses = append(statuses, status)
	}

	project.Services = services
	project.Status = &domain.ProjectStatus{
		ServicesRunning: running,
		ServicesTotal:   len(services),
		Active:          len(services) > 0 && running == len(services),
		Services:        statuses,
	}
	return project
}

func ServicesFor(project domain.Project) []string {
	services := normalizeServices(project.Services)
	if project.Type == domain.ProjectGo {
		services = normalizeProjectServices(services, goService(project))
	}
	return uniqueStrings(services)
}

func (s *Service) findContainerForService(containers []domain.Container, service string) *domain.Container {
	if service == "" {
		return nil
	}

	var fallback *domain.Container
	fallbackScore := -1

	for index := range containers {
		container := &containers[index]
		labelService := container.Labels["com.docker.compose.service"]
		labelProject := container.Labels["com.docker.compose.project"]
		score := -1

		switch {
		case labelService == service && labelProject == s.composeProject:
			return container
		case labelService == service:
			score = 80
		case container.Name == s.composeProject+"-"+service+"-1" || container.Name == s.composeProject+"_"+service+"_1":
			score = 70
		case container.Name == service:
			score = 60
		case containerMatchesService(container.Name, service):
			score = 10
		}

		if score >= 0 && container.State == "running" {
			score += 2
		}
		if score > fallbackScore {
			fallback = container
			fallbackScore = score
		}
	}

	return fallback
}

var invalidServiceChars = regexp.MustCompile(`[^a-zA-Z0-9_-]+`)

func goService(project domain.Project) string {
	if service := cleanService(project.Service); service != "" {
		return service
	}
	return serviceName("go-", project.Key)
}

func serviceName(prefix, key string) string {
	name := strings.ToLower(invalidServiceChars.ReplaceAllString(key, "-"))
	if name == "" {
		name = "project"
	}
	return prefix + name
}

func normalizeProjectServices(services []string, dedicated string) []string {
	filtered := make([]string, 0, len(services)+1)
	for _, service := range services {
		if service == "go" {
			continue
		}
		if dedicated != "" && service == dedicated {
			filtered = append(filtered, service)
			continue
		}
		if strings.HasPrefix(service, "go-") {
			continue
		}
		filtered = append(filtered, service)
	}

	if dedicated != "" && !contains(filtered, dedicated) {
		filtered = append([]string{dedicated}, filtered...)
	}
	return uniqueStrings(filtered)
}

func normalizeServices(services []string) []string {
	normalized := make([]string, 0, len(services))
	for _, service := range services {
		service = cleanService(service)
		if service != "" {
			normalized = append(normalized, service)
		}
	}
	return uniqueStrings(normalized)
}

func cleanService(service string) string {
	return invalidServiceChars.ReplaceAllString(strings.TrimSpace(service), "")
}

func uniqueStrings(values []string) []string {
	seen := make(map[string]struct{}, len(values))
	out := make([]string, 0, len(values))
	for _, value := range values {
		if value == "" {
			continue
		}
		if _, ok := seen[value]; ok {
			continue
		}
		seen[value] = struct{}{}
		out = append(out, value)
	}
	return out
}

func contains(values []string, needle string) bool {
	for _, value := range values {
		if value == needle {
			return true
		}
	}
	return false
}

func containerMatchesService(containerName, service string) bool {
	if containerName == "" || service == "" {
		return false
	}
	return containerName == service ||
		strings.Contains(containerName, "-"+service+"-") ||
		strings.Contains(containerName, "_"+service+"_") ||
		strings.HasSuffix(containerName, "-"+service) ||
		strings.HasSuffix(containerName, "_"+service)
}
