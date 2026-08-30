package project

import (
	"context"
	"testing"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
)

type fakeStore struct {
	projects []domain.Project
}

func (s fakeStore) List(context.Context) ([]domain.Project, error) {
	return append([]domain.Project(nil), s.projects...), nil
}

func (s fakeStore) ConfigExists() bool { return true }

type fakeContainers struct {
	containers []domain.Container
}

func (c fakeContainers) ListContainers(context.Context, bool) ([]domain.Container, error) {
	return append([]domain.Container(nil), c.containers...), nil
}

func TestServiceListAddsProjectStatusFromComposeLabels(t *testing.T) {
	service := NewService(
		fakeStore{projects: []domain.Project{{
			Key:      "hoodly-joy-go",
			Name:     "hoodly-joy-go",
			Type:     domain.ProjectGo,
			Service:  "go-hoodly-joy-go",
			Services: []string{"go-hoodly-joy-go", "redis"},
		}}},
		fakeContainers{containers: []domain.Container{
			{
				Name:   "docker-develop-go-hoodly-joy-go-1",
				State:  "running",
				Status: "Up 2 minutes",
				Labels: map[string]string{
					"com.docker.compose.project": "docker-develop",
					"com.docker.compose.service": "go-hoodly-joy-go",
				},
			},
			{
				Name:   "docker-develop-redis-1",
				State:  "exited",
				Status: "Exited",
				Labels: map[string]string{
					"com.docker.compose.project": "docker-develop",
					"com.docker.compose.service": "redis",
				},
			},
		}},
		"docker-develop",
	)

	projects, err := service.List(context.Background())
	if err != nil {
		t.Fatalf("List() error = %v", err)
	}
	if len(projects) != 1 {
		t.Fatalf("projects length = %d", len(projects))
	}
	status := projects[0].Status
	if status == nil {
		t.Fatalf("status should be populated")
	}
	if status.ServicesRunning != 1 || status.ServicesTotal != 2 || status.Active {
		t.Fatalf("status = %#v", status)
	}
	if projects[0].Services[0] != "go-hoodly-joy-go" {
		t.Fatalf("services = %#v", projects[0].Services)
	}
}

func TestServicesForGoRemovesGenericRuntime(t *testing.T) {
	services := ServicesFor(domain.Project{
		Key:      "api",
		Type:     domain.ProjectGo,
		Services: []string{"go", "redis"},
	})

	if len(services) != 2 || services[0] != "go-api" || services[1] != "redis" {
		t.Fatalf("services = %#v", services)
	}
}

func TestServiceListHidesUnsupportedProjects(t *testing.T) {
	service := NewService(
		fakeStore{projects: []domain.Project{
			{Key: "old", Name: "Old", Type: domain.ProjectType("legacy-web"), Services: []string{"nginx"}},
			{Key: "api", Name: "API", Type: domain.ProjectGo, Service: "go-api", Services: []string{"go-api"}},
		}},
		fakeContainers{},
		"docker-develop",
	)

	projects, err := service.List(context.Background())
	if err != nil {
		t.Fatalf("List() error = %v", err)
	}
	if len(projects) != 1 || projects[0].Key != "api" {
		t.Fatalf("projects = %#v", projects)
	}
}
