package httpapi

import (
	"context"
	"errors"
	"net/http"
	"strings"
	"time"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/task"
)

type startEnvRequest struct {
	Preset string `json:"preset"`
}

func (h *handlers) envMutation(w http.ResponseWriter, r *http.Request) {
	if strings.HasSuffix(r.URL.Path, "/stop-all") {
		h.stopAllContainers(w, r)
		return
	}
	preset := strings.TrimPrefix(r.URL.Path, "/api/env/start/")
	if preset == r.URL.Path || preset == "" {
		writeError(w, r, http.StatusBadRequest, "BAD_PRESET", errors.New("preset is required"))
		return
	}
	h.startEnvTask(w, r, preset)
}

func (h *handlers) terminalMutation(w http.ResponseWriter, r *http.Request) {
	if strings.HasSuffix(r.URL.Path, "/start-env") {
		var input startEnvRequest
		if !decodeBody(w, r, &input) {
			return
		}
		h.startEnvTask(w, r, input.Preset)
		return
	}
	if !h.cfg.UnsafeTerminal {
		writeError(w, r, http.StatusForbidden, "UNSAFE_TERMINAL_DISABLED", errors.New("unsafe terminal execution is disabled"))
		return
	}
	writeNotImplemented(w, r, "Unsafe terminal execution")
}

func (h *handlers) startEnvTask(w http.ResponseWriter, r *http.Request, presetKey string) {
	if h.compose == nil || h.tasks == nil {
		writeError(w, r, http.StatusServiceUnavailable, "COMPOSE_UNAVAILABLE", errors.New("compose runner is not configured"))
		return
	}
	preset, ok := findEnvPreset(presetKey)
	if !ok {
		writeError(w, r, http.StatusNotFound, "PRESET_NOT_FOUND", errors.New("preset not found"))
		return
	}
	services := stringSlice(preset["services"])
	started, err := h.tasks.Start(presetKey, services, func(ctx context.Context, log *task.Logger) error {
		log.Line("Start environment: " + preset["name"].(string))
		log.Line("Services: " + strings.Join(services, ", "))
		log.Line("Stage 1/3: try existing images")
		first, firstErr := h.compose.UpNoBuild(ctx, services)
		log.Command(first.Command)
		log.Raw(first.Output)
		if firstErr == nil {
			log.Line("Environment services started.")
			return nil
		}
		log.Line("Direct start failed; building images.")
		log.Line("Stage 2/3: build environment images")
		build, err := h.compose.Build(ctx, services)
		log.Command(build.Command)
		log.Raw(build.Output)
		if err != nil {
			return err
		}
		log.Line("Stage 3/3: start environment services")
		up, err := h.compose.Up(ctx, services)
		log.Command(up.Command)
		log.Raw(up.Output)
		if err != nil {
			return err
		}
		log.Line("Environment services started.")
		return nil
	})
	if err != nil {
		writeError(w, r, http.StatusInternalServerError, "ENV_TASK_FAILED", err)
		return
	}
	writeSuccess(w, "Environment task started", started)
}

func (h *handlers) stopAllContainers(w http.ResponseWriter, r *http.Request) {
	if err := h.requireDocker(r); err != nil {
		writeError(w, r, http.StatusServiceUnavailable, "DOCKER_UNAVAILABLE", err)
		return
	}
	containers, err := h.docker.ListContainers(r.Context(), false)
	if err != nil {
		writeError(w, r, http.StatusInternalServerError, "DOCKER_LIST_FAILED", err)
		return
	}
	results := map[string]string{}
	for _, container := range containers {
		if strings.Contains(container.Name, "docker-panel") {
			continue
		}
		if container.State != "running" {
			continue
		}
		if err := h.docker.StopContainer(r.Context(), container.ID, 10*time.Second); err != nil {
			results[container.Name] = "error"
		} else {
			results[container.Name] = "stopped"
		}
	}
	writeSuccess(w, "Stopped development containers", map[string]any{"results": results})
}

func findEnvPreset(key string) (map[string]any, bool) {
	for _, preset := range envPresets() {
		if preset["key"] == key {
			return preset, true
		}
	}
	return nil, false
}
