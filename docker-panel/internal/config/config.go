package config

import (
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"

	"github.com/joho/godotenv"
)

type Version struct {
	Version   string `json:"version"`
	Commit    string `json:"commit"`
	BuildTime string `json:"buildTime,omitempty"`
	Go        string `json:"go"`
	OS        string `json:"os"`
	Arch      string `json:"arch"`
}

type Config struct {
	ListenAddr           string
	PublicBind           string
	ProjectRoot          string
	StateDir             string
	ProjectsFile         string
	ExampleProjectsFile  string
	GeneratedComposeFile string
	WebDir               string
	AuthEnabled          bool
	UnsafeTerminal       bool
	TaskBuildTimeout     time.Duration
	TaskRetention        time.Duration
	LogLevel             slog.Level
	Version              Version
}

func Load(version Version) (Config, error) {
	cwd, err := os.Getwd()
	if err != nil {
		return Config{}, err
	}

	projectRoot := getenv("PANEL_PROJECT_ROOT", "")
	if projectRoot == "" {
		projectRoot = detectProjectRoot(cwd)
	}
	projectRoot, err = absClean(projectRoot)
	if err != nil {
		return Config{}, fmt.Errorf("resolve project root: %w", err)
	}

	envFile := getenv("PANEL_ENV_FILE", filepath.Join(projectRoot, ".env"))
	if fileExists(envFile) {
		if err := godotenv.Load(envFile); err != nil {
			return Config{}, fmt.Errorf("load dotenv %s: %w", envFile, err)
		}
	}

	if updatedRoot := getenv("PANEL_PROJECT_ROOT", ""); updatedRoot != "" && updatedRoot != projectRoot {
		projectRoot, err = absClean(updatedRoot)
		if err != nil {
			return Config{}, fmt.Errorf("resolve project root: %w", err)
		}
	}

	stateDir := getenv("PANEL_STATE_DIR", filepath.Join(projectRoot, "docker-panel", "runtime"))
	projectsFile := getenv("PANEL_PROJECTS_FILE", filepath.Join(projectRoot, "projects.json"))
	exampleProjectsFile := getenv("PANEL_EXAMPLE_PROJECTS_FILE", filepath.Join(projectRoot, "projects.example.json"))
	generatedComposeFile := getenv("PANEL_GENERATED_COMPOSE_FILE", filepath.Join(projectRoot, "docker-compose.projects.yml"))
	webDir := getenv("PANEL_WEB_DIR", filepath.Join(projectRoot, "docker-panel", "public"))

	stateDir, err = absClean(stateDir)
	if err != nil {
		return Config{}, fmt.Errorf("resolve state dir: %w", err)
	}
	projectsFile, err = absClean(projectsFile)
	if err != nil {
		return Config{}, fmt.Errorf("resolve projects file: %w", err)
	}
	exampleProjectsFile, err = absClean(exampleProjectsFile)
	if err != nil {
		return Config{}, fmt.Errorf("resolve example projects file: %w", err)
	}
	generatedComposeFile, err = absClean(generatedComposeFile)
	if err != nil {
		return Config{}, fmt.Errorf("resolve generated compose file: %w", err)
	}
	webDir, err = absClean(webDir)
	if err != nil {
		return Config{}, fmt.Errorf("resolve web dir: %w", err)
	}

	version.Go = runtime.Version()
	version.OS = runtime.GOOS
	version.Arch = runtime.GOARCH

	return Config{
		ListenAddr:           getenv("PANEL_LISTEN_ADDR", "0.0.0.0:9501"),
		PublicBind:           getenv("PANEL_PUBLIC_BIND", "127.0.0.1"),
		ProjectRoot:          projectRoot,
		StateDir:             stateDir,
		ProjectsFile:         projectsFile,
		ExampleProjectsFile:  exampleProjectsFile,
		GeneratedComposeFile: generatedComposeFile,
		WebDir:               webDir,
		AuthEnabled:          getenvBool("PANEL_AUTH_ENABLED", false),
		UnsafeTerminal:       getenvBool("PANEL_UNSAFE_TERMINAL", false),
		TaskBuildTimeout:     getenvDuration("PANEL_TASK_BUILD_TIMEOUT", 30*time.Minute),
		TaskRetention:        getenvDuration("PANEL_TASK_RETENTION", 168*time.Hour),
		LogLevel:             getenvLogLevel("PANEL_LOG_LEVEL", slog.LevelInfo),
		Version:              version,
	}, nil
}

func detectProjectRoot(start string) string {
	dir, err := filepath.Abs(start)
	if err != nil {
		return start
	}

	for {
		if fileExists(filepath.Join(dir, "docker-compose.yml")) {
			return dir
		}
		if filepath.Base(dir) == "docker-panel" {
			parent := filepath.Dir(dir)
			if fileExists(filepath.Join(parent, "docker-compose.yml")) {
				return parent
			}
		}
		next := filepath.Dir(dir)
		if next == dir {
			break
		}
		dir = next
	}

	return start
}

func getenv(name, fallback string) string {
	value := strings.TrimSpace(os.Getenv(name))
	if value == "" {
		return fallback
	}
	return value
}

func getenvBool(name string, fallback bool) bool {
	value := strings.TrimSpace(strings.ToLower(os.Getenv(name)))
	if value == "" {
		return fallback
	}
	switch value {
	case "1", "true", "yes", "on":
		return true
	case "0", "false", "no", "off":
		return false
	default:
		return fallback
	}
}

func getenvDuration(name string, fallback time.Duration) time.Duration {
	value := strings.TrimSpace(os.Getenv(name))
	if value == "" {
		return fallback
	}
	if duration, err := time.ParseDuration(value); err == nil {
		return duration
	}
	if minutes, err := strconv.Atoi(value); err == nil {
		return time.Duration(minutes) * time.Minute
	}
	return fallback
}

func getenvLogLevel(name string, fallback slog.Level) slog.Level {
	switch strings.ToLower(strings.TrimSpace(os.Getenv(name))) {
	case "debug":
		return slog.LevelDebug
	case "info", "":
		return fallback
	case "warn", "warning":
		return slog.LevelWarn
	case "error":
		return slog.LevelError
	default:
		return fallback
	}
}

func fileExists(path string) bool {
	if path == "" {
		return false
	}
	_, err := os.Stat(path)
	return err == nil
}

func absClean(path string) (string, error) {
	if path == "" {
		return "", nil
	}
	absolute, err := filepath.Abs(path)
	if err != nil {
		return "", err
	}
	return filepath.Clean(absolute), nil
}
