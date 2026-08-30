package compose

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"
)

type Runner struct {
	ProjectRoot string
	EnvFile     string
	command     []string
	hostRoot    string
}

type Result struct {
	Command  string
	Output   string
	ExitCode int
}

func NewRunner(projectRoot string) *Runner {
	return &Runner{
		ProjectRoot: projectRoot,
		EnvFile:     filepath.Join(projectRoot, ".env"),
	}
}

func (r *Runner) Run(ctx context.Context, args ...string) (Result, error) {
	cmdSpec, err := r.commandSpec()
	if err != nil {
		return Result{}, err
	}

	fullArgs := append([]string{}, cmdSpec[1:]...)
	fullArgs = append(fullArgs, args...)
	cmd := exec.CommandContext(ctx, cmdSpec[0], fullArgs...)
	cmd.Dir = r.ProjectRoot
	cmd.Env = r.composeEnv(ctx)

	var output bytes.Buffer
	cmd.Stdout = &output
	cmd.Stderr = &output

	result := Result{Command: strings.Join(append([]string{cmdSpec[0]}, fullArgs...), " ")}
	if err := cmd.Run(); err != nil {
		result.Output = output.String()
		var exitErr *exec.ExitError
		if errors.As(err, &exitErr) {
			result.ExitCode = exitErr.ExitCode()
			return result, fmt.Errorf("%s", strings.TrimSpace(defaultString(result.Output, err.Error())))
		}
		result.ExitCode = -1
		return result, err
	}

	result.Output = output.String()
	return result, nil
}

func (r *Runner) RunWithTimeout(ctx context.Context, timeout time.Duration, args ...string) (Result, error) {
	if timeout <= 0 {
		return r.Run(ctx, args...)
	}
	taskCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	return r.Run(taskCtx, args...)
}

func (r *Runner) UpNoBuild(ctx context.Context, services []string) (Result, error) {
	args := append([]string{"up", "-d", "--no-build"}, CleanServices(services)...)
	return r.Run(ctx, args...)
}

func (r *Runner) UpBuild(ctx context.Context, services []string) (Result, error) {
	args := append([]string{"up", "-d", "--build"}, CleanServices(services)...)
	return r.Run(ctx, args...)
}

func (r *Runner) Up(ctx context.Context, services []string) (Result, error) {
	args := append([]string{"up", "-d"}, CleanServices(services)...)
	return r.Run(ctx, args...)
}

func (r *Runner) Build(ctx context.Context, services []string) (Result, error) {
	args := append([]string{"build"}, CleanServices(services)...)
	return r.Run(ctx, args...)
}

func (r *Runner) Stop(ctx context.Context, services []string) (Result, error) {
	args := append([]string{"stop"}, CleanServices(services)...)
	return r.Run(ctx, args...)
}

func (r *Runner) Remove(ctx context.Context, services []string) (Result, error) {
	args := append([]string{"rm", "-sf"}, CleanServices(services)...)
	return r.Run(ctx, args...)
}

func (r *Runner) Logs(ctx context.Context, tail int, services []string) (Result, error) {
	if tail <= 0 {
		tail = 200
	}
	args := append([]string{"logs", fmt.Sprintf("--tail=%d", tail)}, CleanServices(services)...)
	return r.Run(ctx, args...)
}

func (r *Runner) PS(ctx context.Context, services []string) (Result, error) {
	args := append([]string{"ps"}, CleanServices(services)...)
	return r.Run(ctx, args...)
}

func (r *Runner) RunningServices(ctx context.Context, services []string) (Result, error) {
	args := append([]string{"ps", "--services", "--status", "running"}, CleanServices(services)...)
	return r.Run(ctx, args...)
}

func (r *Runner) ExecShell(ctx context.Context, service string, shellCommand string) (Result, error) {
	service = cleanService(service)
	if service == "" {
		return Result{}, errors.New("compose service is empty")
	}
	return r.Run(ctx, "exec", "-T", service, "sh", "-lc", shellCommand)
}

func (r *Runner) Config(ctx context.Context, quiet bool) (Result, error) {
	if quiet {
		return r.Run(ctx, "config", "--quiet")
	}
	return r.Run(ctx, "config")
}

func (r *Runner) CommandLine(args ...string) string {
	cmdSpec, err := r.commandSpec()
	if err != nil {
		return strings.Join(append([]string{"docker-compose"}, args...), " ")
	}
	return strings.Join(append(cmdSpec, args...), " ")
}

func (r *Runner) commandSpec() ([]string, error) {
	if len(r.command) > 0 {
		return append([]string{}, r.command...), nil
	}
	if path, err := exec.LookPath("docker-compose"); err == nil {
		r.command = []string{path}
		return append([]string{}, r.command...), nil
	}
	if path, err := exec.LookPath("docker"); err == nil {
		r.command = []string{path, "compose"}
		return append([]string{}, r.command...), nil
	}
	return nil, errors.New("docker-compose or docker compose was not found")
}

func (r *Runner) composeEnv(ctx context.Context) []string {
	env := make([]string, 0, len(os.Environ())+12)
	for _, item := range os.Environ() {
		name := item
		if index := strings.IndexByte(item, '='); index >= 0 {
			name = item[:index]
		}
		switch name {
		case "BASE_IMAGE_REGISTRY":
			continue
		default:
			env = append(env, item)
		}
	}

	hostRoot := r.hostProjectRoot(ctx)
	values := readEnvFile(r.EnvFile)
	env = append(env,
		"DOCKER_BUILDKIT=0",
		"COMPOSE_DOCKER_CLI_BUILD=0",
		"DOCKER_DEVELOP_HOST_PATH="+hostRoot,
		"BASE_IMAGE_REGISTRY=",
	)

	for _, key := range []string{
		"HOST_PROJECT_PATH",
		"DATA_PATH",
		"NGINX_HOST_LOG_PATH",
		"NGINX_SITES_PATH",
		"NGINX_SSL_PATH",
		"MYSQL_HOST_DATA_PATH",
		"MYSQL_ENTRYPOINT_INITDB",
	} {
		if value, ok := values[key]; ok && isRelativeHostPath(value) {
			env = append(env, key+"="+joinHostPath(hostRoot, value))
		}
	}

	return env
}

func (r *Runner) hostProjectRoot(ctx context.Context) string {
	if r.hostRoot != "" {
		return r.hostRoot
	}
	fallback := strings.TrimSpace(os.Getenv("DOCKER_DEVELOP_HOST_PATH"))
	if fallback == "" {
		fallback = r.ProjectRoot
	}

	hostname, err := os.Hostname()
	if err != nil || hostname == "" {
		r.hostRoot = fallback
		return r.hostRoot
	}

	cmd := exec.CommandContext(ctx, "docker", "inspect", hostname, "--format", "{{json .Mounts}}")
	var output bytes.Buffer
	cmd.Stdout = &output
	cmd.Stderr = &output
	if err := cmd.Run(); err != nil {
		r.hostRoot = fallback
		return r.hostRoot
	}

	var mounts []struct {
		Source      string `json:"Source"`
		Destination string `json:"Destination"`
	}
	if err := json.Unmarshal(bytes.TrimSpace(output.Bytes()), &mounts); err != nil {
		r.hostRoot = fallback
		return r.hostRoot
	}
	projectRoot := filepath.ToSlash(filepath.Clean(r.ProjectRoot))
	for _, mount := range mounts {
		if filepath.ToSlash(filepath.Clean(mount.Destination)) == projectRoot && mount.Source != "" {
			r.hostRoot = mount.Source
			return r.hostRoot
		}
	}

	r.hostRoot = fallback
	return r.hostRoot
}

func CleanServices(services []string) []string {
	out := make([]string, 0, len(services))
	seen := map[string]struct{}{}
	for _, service := range services {
		service = cleanService(service)
		if service == "" {
			continue
		}
		if _, ok := seen[service]; ok {
			continue
		}
		seen[service] = struct{}{}
		out = append(out, service)
	}
	sort.Strings(out)
	return out
}

var servicePattern = regexp.MustCompile(`[^a-zA-Z0-9_-]+`)

func cleanService(service string) string {
	return servicePattern.ReplaceAllString(strings.TrimSpace(service), "")
}

func readEnvFile(path string) map[string]string {
	values := map[string]string{}
	content, err := os.ReadFile(path)
	if err != nil {
		return values
	}
	for _, line := range strings.Split(string(content), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") || !strings.Contains(line, "=") {
			continue
		}
		parts := strings.SplitN(line, "=", 2)
		name := strings.TrimSpace(parts[0])
		value := strings.Trim(strings.TrimSpace(parts[1]), "\"'")
		if name != "" {
			values[name] = value
		}
	}
	return values
}

func isRelativeHostPath(path string) bool {
	path = strings.TrimSpace(path)
	if path == "" || strings.Contains(path, "${") {
		return false
	}
	path = strings.ReplaceAll(path, "\\", "/")
	if strings.HasPrefix(path, "/") {
		return false
	}
	return !regexp.MustCompile(`^[a-zA-Z]:/`).MatchString(path)
}

func joinHostPath(base, relative string) string {
	relative = strings.TrimSpace(relative)
	relative = strings.TrimPrefix(relative, "./")
	relative = strings.Trim(relative, "\\/")
	if relative == "" {
		return strings.TrimRight(base, "\\/")
	}
	separator := "/"
	if regexp.MustCompile(`^[a-zA-Z]:[\\/]`).MatchString(base) {
		separator = "\\"
	}
	relative = strings.ReplaceAll(relative, "\\", separator)
	relative = strings.ReplaceAll(relative, "/", separator)
	return strings.TrimRight(base, "\\/") + separator + relative
}

func defaultString(value, fallback string) string {
	if strings.TrimSpace(value) == "" {
		return fallback
	}
	return value
}
