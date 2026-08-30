package httpapi

import (
	"context"
	"log/slog"
	"net/http"
	"time"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/compose"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/config"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
	projectmanager "github.com/chenbb0128/docker-develop/docker-panel/internal/project"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/task"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/web"
	"github.com/go-chi/chi/v5"
)

type DockerClient interface {
	Ping(context.Context) (domain.DockerPing, error)
	ListContainers(context.Context, bool) ([]domain.Container, error)
	InspectContainer(context.Context, string) (map[string]any, error)
	ContainerLogs(context.Context, string, int) (string, error)
	ContainerStats(context.Context, string) (map[string]any, error)
	StartContainer(context.Context, string) error
	StopContainer(context.Context, string, time.Duration) error
	RestartContainer(context.Context, string, time.Duration) error
	RemoveContainer(context.Context, string, bool) error
}

type ProjectStore interface {
	List(context.Context) ([]domain.Project, error)
	ConfigExists() bool
}

type ProjectService interface {
	List(context.Context) ([]domain.Project, error)
	ConfigExists() bool
}

type ProjectActions interface {
	Save(context.Context, domain.Project) (domain.Project, bool, error)
	Scaffold(context.Context, projectmanager.ScaffoldRequest) (projectmanager.ScaffoldResult, error)
	Start(context.Context, string) (projectmanager.CommandResult, error)
	Stop(context.Context, string) (projectmanager.CommandResult, error)
	Restart(context.Context, string) (projectmanager.CommandResult, error)
	CreateActionTask(context.Context, string, string) (task.StartResult, error)
	ReadTask(context.Context, string, int64) (task.Snapshot, error)
	Logs(context.Context, string) (string, error)
	RunCommand(context.Context, string, projectmanager.RunRequest) (projectmanager.CommandResult, error)
	DeleteEnvironment(context.Context, string, projectmanager.DeleteOptions) (projectmanager.DeleteResult, error)
}

type Dependencies struct {
	Config       config.Config
	Logger       *slog.Logger
	StartedAt    time.Time
	Docker       DockerClient
	DockerError  error
	ProjectStore ProjectStore
	Projects     ProjectService
	ProjectOps   ProjectActions
	Compose      *compose.Runner
	Tasks        *task.Manager
}

func NewRouter(deps Dependencies) http.Handler {
	router := chi.NewRouter()
	router.Use(requestID)
	router.Use(securityHeaders)
	router.Use(recoverer(deps.Logger))
	router.Use(accessLog(deps.Logger))

	h := newHandlers(deps)

	router.Post("/api/login", h.login)
	router.Post("/api/logout", h.logout)
	router.Get("/api/check-auth", h.checkAuth)

	router.Get("/api/health", h.health)
	router.Get("/api/ready", h.ready)
	router.Get("/api/version", h.version)

	router.Get("/api/containers", h.listContainers)
	router.Get("/api/containers/{id}", h.showContainer)
	router.Get("/api/containers/{id}/logs", h.containerLogs)
	router.Get("/api/containers/{id}/stats", h.containerStats)
	router.Post("/api/containers/{id}/start", h.containerMutation)
	router.Post("/api/containers/{id}/stop", h.containerMutation)
	router.Post("/api/containers/{id}/restart", h.containerMutation)
	router.Delete("/api/containers/{id}", h.containerMutation)

	router.Get("/api/projects", h.listProjects)
	router.Post("/api/projects", h.projectMutation)
	router.Post("/api/project-scaffold", h.projectMutation)
	router.Post("/api/projects/{key}/start", h.projectMutation)
	router.Post("/api/projects/{key}/stop", h.projectMutation)
	router.Post("/api/projects/{key}/restart", h.projectMutation)
	router.Post("/api/projects/{key}/start-task", h.projectMutation)
	router.Post("/api/projects/{key}/restart-task", h.projectMutation)
	router.Get("/api/project-tasks/{taskId}", h.projectTask)
	router.Get("/api/projects/{key}/logs", h.projectLogs)
	router.Post("/api/projects/{key}/run", h.projectMutation)
	router.Delete("/api/projects/{key}", h.projectMutation)
	router.Post("/api/projects/{key}/delete-environment", h.projectMutation)

	router.Get("/api/env/presets", h.envPresets)
	router.Get("/api/env/status", h.envStatus)
	router.Get("/api/env/doctor", h.envDoctor)
	router.Post("/api/env/start/{preset}", h.envMutation)
	router.Post("/api/env/stop-all", h.envMutation)
	router.Post("/api/terminal/exec", h.terminalMutation)
	router.Post("/api/terminal/start-env", h.terminalMutation)

	router.Get("/api/sites", h.listSites)
	router.Post("/api/sites", h.siteMutation)
	router.Delete("/api/sites/{filename}", h.siteMutation)
	router.Post("/api/sites/reload-nginx", h.siteMutation)
	router.Post("/api/sites/restart-nginx", h.siteMutation)
	router.Get("/api/sites/hosts-command", h.hostsCommand)

	router.Get("/api/config/center/files", h.configCenterFiles)
	router.Get("/api/config/center/read", h.configReadOnly)
	router.Get("/api/config/center/backups", h.configReadOnly)
	router.Get("/api/config/center/backup-read", h.configReadOnly)
	router.Post("/api/config/center/save", h.configMutation)
	router.Post("/api/config/center/restore", h.configMutation)
	router.Post("/api/config/center/validate", h.configReadOnly)

	router.Get("/api/config/files", h.configCenterFiles)
	router.Get("/api/config/read", h.configReadOnly)
	router.Post("/api/config/save", h.configMutation)
	router.Post("/api/config/validate", h.configReadOnly)

	router.Handle("/*", web.NewHandler(deps.Config.WebDir))
	return router
}
