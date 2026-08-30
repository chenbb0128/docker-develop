package httpapi

import (
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/config"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
)

type fakeDocker struct {
	containers []domain.Container
}

func (d fakeDocker) Ping(context.Context) (domain.DockerPing, error) {
	return domain.DockerPing{APIVersion: "1.55", OSType: "linux"}, nil
}

func (d fakeDocker) ListContainers(context.Context, bool) ([]domain.Container, error) {
	return append([]domain.Container(nil), d.containers...), nil
}

func (d fakeDocker) InspectContainer(context.Context, string) (map[string]any, error) {
	return map[string]any{"Id": "abc"}, nil
}

func (d fakeDocker) ContainerLogs(context.Context, string, int) (string, error) {
	return "logs", nil
}

func (d fakeDocker) ContainerStats(context.Context, string) (map[string]any, error) {
	return map[string]any{"ok": true}, nil
}
func (d fakeDocker) StartContainer(context.Context, string) error { return nil }

func (d fakeDocker) StopContainer(context.Context, string, time.Duration) error { return nil }

func (d fakeDocker) RestartContainer(context.Context, string, time.Duration) error { return nil }

func (d fakeDocker) RemoveContainer(context.Context, string, bool) error { return nil }

type fakeProjectReader struct {
	projects []domain.Project
	exists   bool
}

func (p fakeProjectReader) List(context.Context) ([]domain.Project, error) {
	return append([]domain.Project(nil), p.projects...), nil
}

func (p fakeProjectReader) ConfigExists() bool { return p.exists }

func TestRouterHealthVersionAndReady(t *testing.T) {
	stateDir := t.TempDir()
	router := NewRouter(Dependencies{
		Config: config.Config{
			StateDir:     stateDir,
			ProjectRoot:  stateDir,
			ProjectsFile: stateDir + "/projects.json",
			Version:      config.Version{Version: "test", Commit: "abc", Go: "go-test", OS: "testos", Arch: "testarch"},
		},
		Logger:       slog.New(slog.NewTextHandler(io.Discard, nil)),
		StartedAt:    time.Now(),
		Docker:       fakeDocker{},
		ProjectStore: fakeProjectReader{exists: true},
		Projects:     fakeProjectReader{exists: true},
	})

	assertStatus(t, router, http.MethodGet, "/api/health", http.StatusOK)
	assertStatus(t, router, http.MethodGet, "/api/ready", http.StatusOK)

	body := assertStatus(t, router, http.MethodGet, "/api/version", http.StatusOK)
	var payload struct {
		Success bool           `json:"success"`
		Data    config.Version `json:"data"`
	}
	if err := json.Unmarshal(body, &payload); err != nil {
		t.Fatalf("decode version response: %v", err)
	}
	if !payload.Success || payload.Data.Version != "test" || payload.Data.Commit != "abc" {
		t.Fatalf("version payload = %#v", payload)
	}
}

func TestRouterContainerListShape(t *testing.T) {
	router := NewRouter(Dependencies{
		Config:    config.Config{StateDir: t.TempDir()},
		Logger:    slog.New(slog.NewTextHandler(io.Discard, nil)),
		StartedAt: time.Now(),
		Docker: fakeDocker{containers: []domain.Container{{
			ID:     "abc123",
			Name:   "docker-develop-redis-1",
			Image:  "redis:latest",
			State:  "running",
			Labels: map[string]string{},
		}}},
		ProjectStore: fakeProjectReader{},
		Projects:     fakeProjectReader{},
	})

	body := assertStatus(t, router, http.MethodGet, "/api/containers", http.StatusOK)
	var payload struct {
		Containers []domain.Container `json:"containers"`
	}
	if err := json.Unmarshal(body, &payload); err != nil {
		t.Fatalf("decode containers response: %v", err)
	}
	if len(payload.Containers) != 1 || payload.Containers[0].Name != "docker-develop-redis-1" {
		t.Fatalf("containers payload = %#v", payload)
	}
}

func assertStatus(t *testing.T, handler http.Handler, method, path string, want int) []byte {
	t.Helper()
	recorder := httptest.NewRecorder()
	req := httptest.NewRequest(method, path, nil)
	handler.ServeHTTP(recorder, req)
	if recorder.Code != want {
		t.Fatalf("%s %s status = %d, want %d, body = %s", method, path, recorder.Code, want, recorder.Body.String())
	}
	return recorder.Body.Bytes()
}
