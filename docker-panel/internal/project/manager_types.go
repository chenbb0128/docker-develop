package project

import (
	"context"
	"time"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/compose"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/config"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/task"
)

const GoDevCommand = `export PATH=/go/bin:/usr/local/go/bin:$PATH; PORT=${PORT:-8080}; export PORT; SERVER_PORT=${SERVER_PORT:-$PORT}; export SERVER_PORT; APP_PORT=${APP_PORT:-$PORT}; export APP_PORT; if [ ! -f go.mod ]; then echo "go.mod not found"; exit 1; fi; go mod download; if [ -f main.go ]; then exec go run .; elif [ -d cmd/server ]; then exec go run ./cmd/server; elif [ -d cmd/api ]; then exec go run ./cmd/api; elif [ -d cmd/app ]; then exec go run ./cmd/app; else MAIN_PACKAGE=$(go list -f "{{if eq .Name \"main\"}}{{.ImportPath}}{{end}}" ./... 2>/dev/null | sed -n "/./{p;q;}"); if [ -z "$MAIN_PACKAGE" ]; then echo "No Go main package found"; exit 1; fi; exec go run "$MAIN_PACKAGE"; fi`

type WritableStore interface {
	List(context.Context) ([]domain.Project, error)
	Save(context.Context, []domain.Project) error
	ConfigExists() bool
}

type Manager struct {
	cfg     config.Config
	store   WritableStore
	compose *compose.Runner
	tasks   *task.Manager
}

type ScaffoldRequest struct {
	Key      string             `json:"key"`
	Name     string             `json:"name"`
	Type     domain.ProjectType `json:"type"`
	Path     string             `json:"path"`
	Port     int                `json:"port"`
	Service  string             `json:"service"`
	Services []string           `json:"services"`
	Command  string             `json:"command"`
	Log      string             `json:"log"`
	URL      string             `json:"url"`
	DryRun   bool               `json:"dryRun"`
}

type ScaffoldResult struct {
	Project   domain.Project `json:"project"`
	Compose   any            `json:"compose"`
	Actions   []string       `json:"actions"`
	NextSteps []string       `json:"nextSteps"`
}

type CommandResult struct {
	Output  string `json:"output"`
	URL     string `json:"url,omitempty"`
	Command string `json:"command,omitempty"`
}

type RunRequest struct {
	Command string `json:"command"`
	Custom  string `json:"custom"`
}

type DeleteOptions struct {
	RemoveContainer bool `json:"removeContainer"`
	RemoveCompose   bool `json:"removeCompose"`
	RemoveSource    bool `json:"removeSource"`
}

type DeleteResult struct {
	Actions  []string `json:"actions"`
	Warnings []string `json:"warnings"`
	Output   string   `json:"output"`
}

func NewManager(cfg config.Config, store WritableStore, composeRunner *compose.Runner, taskManager *task.Manager) *Manager {
	if taskManager == nil {
		taskManager = task.NewManager(cfg.StateDir, 30*time.Minute)
	}
	return &Manager{cfg: cfg, store: store, compose: composeRunner, tasks: taskManager}
}
