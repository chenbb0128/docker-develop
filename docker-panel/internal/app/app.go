package app

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"path/filepath"
	"time"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/compose"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/config"
	dockerclient "github.com/chenbb0128/docker-develop/docker-panel/internal/docker"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/httpapi"
	projectsvc "github.com/chenbb0128/docker-develop/docker-panel/internal/project"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/storage"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/task"
)

type App struct {
	cfg    config.Config
	log    *slog.Logger
	server *http.Server
}

func New(cfg config.Config, logger *slog.Logger) (*App, error) {
	dockerClient, dockerErr := dockerclient.NewClient()
	if dockerErr != nil {
		logger.Warn("docker client is not ready", "error", dockerErr)
	}

	projectStore := storage.NewProjectStore(cfg.ProjectsFile, cfg.ExampleProjectsFile)
	projectService := projectsvc.NewService(projectStore, dockerClient, filepath.Base(cfg.ProjectRoot))
	composeRunner := compose.NewRunner(cfg.ProjectRoot)
	taskManager := task.NewManager(cfg.StateDir, cfg.TaskBuildTimeout)
	projectManager := projectsvc.NewManager(cfg, projectStore, composeRunner, taskManager)

	router := httpapi.NewRouter(httpapi.Dependencies{
		Config:       cfg,
		Logger:       logger,
		StartedAt:    time.Now(),
		Docker:       dockerClient,
		DockerError:  dockerErr,
		ProjectStore: projectStore,
		Projects:     projectService,
		ProjectOps:   projectManager,
		Compose:      composeRunner,
		Tasks:        taskManager,
	})

	server := &http.Server{
		Addr:              cfg.ListenAddr,
		Handler:           router,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      5 * time.Minute,
		IdleTimeout:       2 * time.Minute,
	}

	return &App{
		cfg:    cfg,
		log:    logger,
		server: server,
	}, nil
}

func (a *App) Run(ctx context.Context) error {
	errCh := make(chan error, 1)

	go func() {
		a.log.Info("starting docker panel go backend", "addr", a.cfg.ListenAddr, "projectRoot", a.cfg.ProjectRoot)
		err := a.server.ListenAndServe()
		if errors.Is(err, http.ErrServerClosed) {
			err = nil
		}
		errCh <- err
	}()

	select {
	case <-ctx.Done():
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		a.log.Info("shutting down docker panel go backend")
		if err := a.server.Shutdown(shutdownCtx); err != nil {
			return err
		}
		return <-errCh
	case err := <-errCh:
		return err
	}
}
