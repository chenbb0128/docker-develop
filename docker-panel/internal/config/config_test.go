package config

import (
	"log/slog"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestLoadDefaults(t *testing.T) {
	root := t.TempDir()
	writeEmptyFile(t, filepath.Join(root, "docker-compose.yml"))

	t.Setenv("PANEL_PROJECT_ROOT", root)
	t.Setenv("PANEL_ENV_FILE", filepath.Join(root, ".env"))
	t.Setenv("PANEL_LISTEN_ADDR", "")
	t.Setenv("PANEL_STATE_DIR", "")
	t.Setenv("PANEL_PROJECTS_FILE", "")
	t.Setenv("PANEL_EXAMPLE_PROJECTS_FILE", "")
	t.Setenv("PANEL_GENERATED_COMPOSE_FILE", "")
	t.Setenv("PANEL_WEB_DIR", "")
	t.Setenv("PANEL_AUTH_ENABLED", "")
	t.Setenv("PANEL_UNSAFE_TERMINAL", "")
	t.Setenv("PANEL_TASK_BUILD_TIMEOUT", "")
	t.Setenv("PANEL_TASK_RETENTION", "")
	t.Setenv("PANEL_LOG_LEVEL", "")

	cfg, err := Load(Version{Version: "test", Commit: "abc"})
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}

	if cfg.ListenAddr != "0.0.0.0:9501" {
		t.Fatalf("ListenAddr = %q", cfg.ListenAddr)
	}
	if cfg.ProjectRoot != filepath.Clean(root) {
		t.Fatalf("ProjectRoot = %q, want %q", cfg.ProjectRoot, filepath.Clean(root))
	}
	if cfg.StateDir != filepath.Join(root, "docker-panel", "runtime") {
		t.Fatalf("StateDir = %q", cfg.StateDir)
	}
	if cfg.AuthEnabled {
		t.Fatalf("AuthEnabled should default to false")
	}
	if cfg.TaskBuildTimeout != 30*time.Minute {
		t.Fatalf("TaskBuildTimeout = %s", cfg.TaskBuildTimeout)
	}
	if cfg.LogLevel != slog.LevelInfo {
		t.Fatalf("LogLevel = %s", cfg.LogLevel)
	}
	if cfg.Version.Go == "" || cfg.Version.OS == "" || cfg.Version.Arch == "" {
		t.Fatalf("Version runtime fields were not populated: %#v", cfg.Version)
	}
}

func TestLoadEnvOverrides(t *testing.T) {
	root := t.TempDir()
	writeEmptyFile(t, filepath.Join(root, "docker-compose.yml"))

	t.Setenv("PANEL_PROJECT_ROOT", root)
	t.Setenv("PANEL_ENV_FILE", filepath.Join(root, ".env"))
	t.Setenv("PANEL_LISTEN_ADDR", "127.0.0.1:9599")
	t.Setenv("PANEL_AUTH_ENABLED", "true")
	t.Setenv("PANEL_TASK_BUILD_TIMEOUT", "45m")
	t.Setenv("PANEL_LOG_LEVEL", "debug")

	cfg, err := Load(Version{})
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}

	if cfg.ListenAddr != "127.0.0.1:9599" {
		t.Fatalf("ListenAddr = %q", cfg.ListenAddr)
	}
	if !cfg.AuthEnabled {
		t.Fatalf("AuthEnabled should be true")
	}
	if cfg.TaskBuildTimeout != 45*time.Minute {
		t.Fatalf("TaskBuildTimeout = %s", cfg.TaskBuildTimeout)
	}
	if cfg.LogLevel != slog.LevelDebug {
		t.Fatalf("LogLevel = %s", cfg.LogLevel)
	}
}

func writeEmptyFile(t *testing.T, path string) {
	t.Helper()
	if err := os.WriteFile(path, []byte(""), 0o600); err != nil {
		t.Fatalf("write file %s: %v", path, err)
	}
}
