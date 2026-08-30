package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/app"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/config"
)

var (
	version   = "dev"
	commit    = "unknown"
	buildTime = ""
)

func main() {
	cfg, err := config.Load(config.Version{
		Version:   version,
		Commit:    commit,
		BuildTime: buildTime,
	})
	if err != nil {
		slog.Error("load config", "error", err)
		os.Exit(1)
	}

	logger := slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{
		Level: cfg.LogLevel,
	}))
	slog.SetDefault(logger)

	panel, err := app.New(cfg, logger)
	if err != nil {
		logger.Error("create app", "error", err)
		os.Exit(1)
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	if err := panel.Run(ctx); err != nil {
		logger.Error("panel stopped", "error", err)
		os.Exit(1)
	}
}
