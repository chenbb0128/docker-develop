package httpapi

import (
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/compose"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/config"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
	projectmanager "github.com/chenbb0128/docker-develop/docker-panel/internal/project"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/task"
	"github.com/go-chi/chi/v5"
)

type handlers struct {
	cfg          config.Config
	startedAt    time.Time
	docker       DockerClient
	dockerError  error
	projectStore ProjectStore
	projects     ProjectService
	projectOps   ProjectActions
	compose      *compose.Runner
	tasks        *task.Manager
}

func newHandlers(deps Dependencies) *handlers {
	return &handlers{
		cfg:          deps.Config,
		startedAt:    deps.StartedAt,
		docker:       deps.Docker,
		dockerError:  deps.DockerError,
		projectStore: deps.ProjectStore,
		projects:     deps.Projects,
		projectOps:   deps.ProjectOps,
		compose:      deps.Compose,
		tasks:        deps.Tasks,
	}
}

func (h *handlers) login(w http.ResponseWriter, _ *http.Request) {
	writeSuccess(w, "Auth disabled", map[string]any{"token": "local-dev"})
}

func (h *handlers) logout(w http.ResponseWriter, _ *http.Request) {
	writeSuccess(w, "Logged out", map[string]any{})
}

func (h *handlers) checkAuth(w http.ResponseWriter, _ *http.Request) {
	writeSuccess(w, "success", map[string]any{"authenticated": true})
}

func (h *handlers) health(w http.ResponseWriter, _ *http.Request) {
	writeSuccess(w, "ok", map[string]any{"status": "ok", "uptimeSec": int64(time.Since(h.startedAt).Seconds())})
}

func (h *handlers) ready(w http.ResponseWriter, r *http.Request) {
	checks := make([]map[string]string, 0, 3)
	ok := true
	if err := checkWritableDir(h.cfg.StateDir); err != nil {
		ok = false
		checks = append(checks, readyCheck("state", "error", err.Error()))
	} else {
		checks = append(checks, readyCheck("state", "ok", h.cfg.StateDir))
	}
	if _, err := h.projectStore.List(r.Context()); err != nil {
		ok = false
		checks = append(checks, readyCheck("projects", "error", err.Error()))
	} else {
		checks = append(checks, readyCheck("projects", "ok", h.cfg.ProjectsFile))
	}
	if err := h.requireDocker(r); err != nil {
		ok = false
		checks = append(checks, readyCheck("docker", "error", err.Error()))
	} else if ping, err := h.docker.Ping(r.Context()); err != nil {
		ok = false
		checks = append(checks, readyCheck("docker", "error", err.Error()))
	} else {
		checks = append(checks, readyCheck("docker", "ok", "API "+ping.APIVersion))
	}
	payload := map[string]any{"status": "ready", "checks": checks}
	if !ok {
		payload["status"] = "not_ready"
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{"success": false, "error": "Panel is not ready", "code": "PANEL_NOT_READY", "requestId": requestIDFromContext(r.Context()), "data": payload})
		return
	}
	writeSuccess(w, "ready", payload)
}

func (h *handlers) version(w http.ResponseWriter, _ *http.Request) {
	writeSuccess(w, "ok", h.cfg.Version)
}

func (h *handlers) listContainers(w http.ResponseWriter, r *http.Request) {
	if err := h.requireDocker(r); err != nil {
		writeError(w, r, http.StatusServiceUnavailable, "DOCKER_UNAVAILABLE", err)
		return
	}
	containers, err := h.docker.ListContainers(r.Context(), true)
	if err != nil {
		writeError(w, r, http.StatusServiceUnavailable, "DOCKER_LIST_FAILED", err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"containers": containers})
}

func (h *handlers) showContainer(w http.ResponseWriter, r *http.Request) {
	if err := h.requireDocker(r); err != nil {
		writeError(w, r, http.StatusServiceUnavailable, "DOCKER_UNAVAILABLE", err)
		return
	}
	container, err := h.docker.InspectContainer(r.Context(), chi.URLParam(r, "id"))
	if err != nil {
		writeError(w, r, http.StatusInternalServerError, "DOCKER_INSPECT_FAILED", err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"container": container})
}

func (h *handlers) containerLogs(w http.ResponseWriter, r *http.Request) {
	if err := h.requireDocker(r); err != nil {
		writeError(w, r, http.StatusServiceUnavailable, "DOCKER_UNAVAILABLE", err)
		return
	}
	tail := parseBoundedInt(r.URL.Query().Get("tail"), 100, 1, 10000)
	logs, err := h.docker.ContainerLogs(r.Context(), chi.URLParam(r, "id"), tail)
	if err != nil {
		writeError(w, r, http.StatusInternalServerError, "DOCKER_LOGS_FAILED", err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"logs": logs})
}

func (h *handlers) containerStats(w http.ResponseWriter, r *http.Request) {
	if err := h.requireDocker(r); err != nil {
		writeError(w, r, http.StatusServiceUnavailable, "DOCKER_UNAVAILABLE", err)
		return
	}
	stats, err := h.docker.ContainerStats(r.Context(), chi.URLParam(r, "id"))
	if err != nil {
		writeError(w, r, http.StatusInternalServerError, "DOCKER_STATS_FAILED", err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"stats": stats})
}

func (h *handlers) containerMutation(w http.ResponseWriter, r *http.Request) {
	if err := h.requireDocker(r); err != nil {
		writeError(w, r, http.StatusServiceUnavailable, "DOCKER_UNAVAILABLE", err)
		return
	}
	id := chi.URLParam(r, "id")
	var err error
	switch {
	case r.Method == http.MethodDelete:
		err = h.docker.RemoveContainer(r.Context(), id, true)
	case strings.HasSuffix(r.URL.Path, "/start"):
		err = h.docker.StartContainer(r.Context(), id)
	case strings.HasSuffix(r.URL.Path, "/stop"):
		err = h.docker.StopContainer(r.Context(), id, 10*time.Second)
	case strings.HasSuffix(r.URL.Path, "/restart"):
		err = h.docker.RestartContainer(r.Context(), id, 10*time.Second)
	default:
		err = errors.New("unsupported container action")
	}
	if err != nil {
		writeError(w, r, http.StatusInternalServerError, "DOCKER_MUTATION_FAILED", err)
		return
	}
	writeSuccess(w, "Container action finished", map[string]any{"id": id})
}

func (h *handlers) listProjects(w http.ResponseWriter, r *http.Request) {
	projects, err := h.projects.List(r.Context())
	if err != nil {
		writeError(w, r, http.StatusServiceUnavailable, "PROJECT_LIST_FAILED", err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"projects": projects, "configExists": h.projects.ConfigExists(), "configPath": "projects.json"})
}

func (h *handlers) projectMutation(w http.ResponseWriter, r *http.Request) {
	if h.projectOps == nil {
		writeNotImplemented(w, r, "Project mutation")
		return
	}
	key := chi.URLParam(r, "key")
	var result any
	var message string
	var err error
	switch {
	case r.Method == http.MethodPost && r.URL.Path == "/api/projects":
		var input domain.Project
		if !decodeBody(w, r, &input) {
			return
		}
		project, updated, saveErr := h.projectOps.Save(r.Context(), input)
		err = saveErr
		result = map[string]any{"project": project}
		if updated {
			message = "Project updated"
		} else {
			message = "Project created"
		}
	case r.Method == http.MethodPost && r.URL.Path == "/api/project-scaffold":
		var input projectmanager.ScaffoldRequest
		if !decodeBody(w, r, &input) {
			return
		}
		result, err = h.projectOps.Scaffold(r.Context(), input)
		message = "Project scaffolded"
	case strings.HasSuffix(r.URL.Path, "/start"):
		result, err = h.projectOps.Start(r.Context(), key)
		message = "Project started"
	case strings.HasSuffix(r.URL.Path, "/stop"):
		result, err = h.projectOps.Stop(r.Context(), key)
		message = "Project stopped"
	case strings.HasSuffix(r.URL.Path, "/restart"):
		result, err = h.projectOps.Restart(r.Context(), key)
		message = "Project restarted"
	case strings.HasSuffix(r.URL.Path, "/start-task"):
		result, err = h.projectOps.CreateActionTask(r.Context(), key, "start")
		message = "Project task started"
	case strings.HasSuffix(r.URL.Path, "/restart-task"):
		result, err = h.projectOps.CreateActionTask(r.Context(), key, "restart")
		message = "Project task started"
	case strings.HasSuffix(r.URL.Path, "/run"):
		var input projectmanager.RunRequest
		if !decodeBody(w, r, &input) {
			return
		}
		result, err = h.projectOps.RunCommand(r.Context(), key, input)
		message = "Command finished"
	case r.Method == http.MethodDelete:
		result, err = h.projectOps.DeleteEnvironment(r.Context(), key, projectmanager.DeleteOptions{})
		message = "Project deleted"
	case strings.HasSuffix(r.URL.Path, "/delete-environment"):
		var input projectmanager.DeleteOptions
		if !decodeBody(w, r, &input) {
			return
		}
		result, err = h.projectOps.DeleteEnvironment(r.Context(), key, input)
		message = "Project environment deleted"
	default:
		err = errors.New("unsupported project action")
	}
	if err != nil {
		status := http.StatusInternalServerError
		if errors.Is(err, projectmanager.ErrNotFound) {
			status = http.StatusNotFound
		} else if strings.Contains(strings.ToLower(err.Error()), "unsupported") || strings.Contains(strings.ToLower(err.Error()), "required") || strings.Contains(strings.ToLower(err.Error()), "must be") {
			status = http.StatusBadRequest
		}
		writeError(w, r, status, "PROJECT_MUTATION_FAILED", err)
		return
	}
	writeSuccess(w, message, result)
}

func (h *handlers) projectTask(w http.ResponseWriter, r *http.Request) {
	if h.projectOps == nil {
		writeNotImplemented(w, r, "Project task")
		return
	}
	offset, _ := strconv.ParseInt(r.URL.Query().Get("offset"), 10, 64)
	snapshot, err := h.projectOps.ReadTask(r.Context(), chi.URLParam(r, "taskId"), offset)
	if err != nil {
		writeError(w, r, http.StatusNotFound, "PROJECT_TASK_NOT_FOUND", err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"success": true, "data": snapshot})
}

func (h *handlers) projectLogs(w http.ResponseWriter, r *http.Request) {
	if h.projectOps == nil {
		writeNotImplemented(w, r, "Project logs")
		return
	}
	logs, err := h.projectOps.Logs(r.Context(), chi.URLParam(r, "key"))
	if err != nil {
		writeError(w, r, http.StatusInternalServerError, "PROJECT_LOGS_FAILED", err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"logs": logs})
}

func (h *handlers) envPresets(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"presets": envPresets()})
}

func (h *handlers) envStatus(w http.ResponseWriter, r *http.Request) {
	if err := h.requireDocker(r); err != nil {
		writeError(w, r, http.StatusServiceUnavailable, "DOCKER_UNAVAILABLE", err)
		return
	}
	containers, err := h.docker.ListContainers(r.Context(), true)
	if err != nil {
		writeError(w, r, http.StatusServiceUnavailable, "DOCKER_LIST_FAILED", err)
		return
	}
	status := map[string]map[string]any{}
	for _, preset := range envPresets() {
		services := stringSlice(preset["services"])
		running := 0
		for _, service := range services {
			for _, container := range containers {
				if strings.Contains(container.Name, service) && container.State == "running" {
					running++
					break
				}
			}
		}
		status[preset["key"].(string)] = map[string]any{"running": running, "total": len(services), "active": running == len(services)}
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": status})
}

func (h *handlers) envDoctor(w http.ResponseWriter, r *http.Request) {
	checks := []map[string]string{{"title": ".env config", "status": boolStatus(fileExists(filepath.Join(h.cfg.ProjectRoot, ".env"))), "badge": h.cfg.ProjectRoot, "message": "Project root: " + h.cfg.ProjectRoot, "suggestion": "Keep BASE_IMAGE_REGISTRY empty; configure Aliyun mirror in Docker Desktop registry-mirrors."}}
	if err := h.requireDocker(r); err != nil {
		checks = append(checks, map[string]string{"title": "Docker daemon", "status": "error", "badge": "unavailable", "message": err.Error(), "suggestion": "Start Docker Desktop and make sure the Docker socket is mounted."})
	} else if ping, err := h.docker.Ping(r.Context()); err != nil {
		checks = append(checks, map[string]string{"title": "Docker daemon", "status": "error", "badge": "unavailable", "message": err.Error(), "suggestion": "Start Docker Desktop and retry."})
	} else {
		checks = append(checks, map[string]string{"title": "Docker daemon", "status": "ok", "badge": "API " + ping.APIVersion, "message": "Docker daemon is reachable.", "suggestion": ""})
	}
	writeJSON(w, http.StatusOK, map[string]any{"checks": checks})
}

func (h *handlers) requireDocker(_ *http.Request) error {
	if h.dockerError != nil {
		return h.dockerError
	}
	if h.docker == nil {
		return errors.New("docker client is not configured")
	}
	return nil
}

func decodeBody(w http.ResponseWriter, r *http.Request, out any) bool {
	decoder := json.NewDecoder(r.Body)
	if err := decoder.Decode(out); err != nil {
		writeError(w, r, http.StatusBadRequest, "BAD_JSON", err)
		return false
	}
	return true
}

func envPresets() []map[string]any {
	return []map[string]any{
		{"key": "minimal", "name": "Go Dev", "description": "Go toolchain + Redis", "services": []string{"go", "redis"}, "icon": "Go"},
		{"key": "go", "name": "Go Dev", "description": "Go toolchain + Redis", "services": []string{"go", "redis"}, "icon": "Go"},
		{"key": "data", "name": "Data Services", "description": "Redis + MySQL + RabbitMQ", "services": []string{"redis", "mysql", "rabbitmq"}, "icon": "DB"},
	}
}

func stringSlice(value any) []string {
	items, ok := value.([]string)
	if !ok {
		return nil
	}
	return items
}

func parseBoundedInt(value string, fallback, min, max int) int {
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return fallback
	}
	if parsed < min {
		return min
	}
	if parsed > max {
		return max
	}
	return parsed
}

func checkWritableDir(dir string) error {
	if err := os.MkdirAll(dir, 0o775); err != nil {
		return err
	}
	file := filepath.Join(dir, ".ready-check")
	if err := os.WriteFile(file, []byte(time.Now().UTC().Format(time.RFC3339Nano)), 0o600); err != nil {
		return err
	}
	return os.Remove(file)
}

func readyCheck(name, status, message string) map[string]string {
	return map[string]string{"name": name, "status": status, "message": message}
}

func boolStatus(ok bool) string {
	if ok {
		return "ok"
	}
	return "warn"
}

func fileExists(path string) bool {
	_, err := os.Stat(path)
	return err == nil
}
