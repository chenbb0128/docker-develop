package project

import (
	"context"
	"errors"
	"strings"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/task"
)

var ErrNotFound = errors.New("project not found")

func (m *Manager) Save(ctx context.Context, input domain.Project) (domain.Project, bool, error) {
	project, err := m.normalize(input)
	if err != nil {
		return domain.Project{}, false, err
	}
	projects, err := m.store.List(ctx)
	if err != nil {
		return domain.Project{}, false, err
	}
	updated := false
	for index, existing := range projects {
		if existing.Key == project.Key {
			projects[index] = project
			updated = true
			break
		}
	}
	if !updated {
		projects = append(projects, project)
	}
	if err := m.store.Save(ctx, projects); err != nil {
		return domain.Project{}, false, err
	}
	return project, updated, nil
}

func (m *Manager) Scaffold(ctx context.Context, input ScaffoldRequest) (ScaffoldResult, error) {
	project, err := m.normalize(domain.Project{
		Key: input.Key, Name: input.Name, Type: input.Type, Path: input.Path,
		Port: input.Port, Service: input.Service, Services: input.Services,
		Command: input.Command, Log: input.Log, URL: input.URL,
	})
	if err != nil {
		return ScaffoldResult{}, err
	}
	actions := []string{"Save project record: projects.json"}
	nextSteps := []string{"Click Start on the project card; the panel will build and run services automatically."}
	var composeInfo any

	actions = append(actions, "Generate Go docker-compose service: "+project.Service)
	composeInfo = map[string]any{"service": project.Service, "hostPort": project.Port, "containerPort": 8080}
	if !input.DryRun {
		if err := m.ensureGoComposeService(project); err != nil {
			return ScaffoldResult{}, err
		}
	}

	if !input.DryRun {
		if _, _, err := m.Save(ctx, project); err != nil {
			return ScaffoldResult{}, err
		}
	}
	return ScaffoldResult{Project: project, Compose: composeInfo, Actions: actions, NextSteps: nextSteps}, nil
}

func (m *Manager) Start(ctx context.Context, key string) (CommandResult, error) {
	project, err := m.find(ctx, key)
	if err != nil {
		return CommandResult{}, err
	}
	if err := m.ensureRuntime(project); err != nil {
		return CommandResult{}, err
	}
	output, err := m.startProjectServices(ctx, ServicesFor(project))
	if err != nil {
		return CommandResult{}, err
	}
	return CommandResult{Output: output, URL: project.URL}, nil
}

func (m *Manager) Stop(ctx context.Context, key string) (CommandResult, error) {
	project, err := m.find(ctx, key)
	if err != nil {
		return CommandResult{}, err
	}
	service := dedicatedService(project)
	if service == "" {
		return CommandResult{Output: "No dedicated project process to stop."}, nil
	}
	result, err := m.compose.Stop(ctx, []string{service})
	if err != nil {
		return CommandResult{}, err
	}
	return CommandResult{Output: strings.TrimSpace(result.Output)}, nil
}

func (m *Manager) Restart(ctx context.Context, key string) (CommandResult, error) {
	project, err := m.find(ctx, key)
	if err != nil {
		return CommandResult{}, err
	}
	if service := dedicatedService(project); service != "" {
		_, _ = m.compose.Stop(ctx, []string{service})
	}
	return m.Start(ctx, key)
}

func (m *Manager) CreateActionTask(ctx context.Context, key string, action string) (task.StartResult, error) {
	if action != "start" && action != "restart" {
		return task.StartResult{}, errors.New("unsupported project action")
	}
	project, err := m.find(ctx, key)
	if err != nil {
		return task.StartResult{}, err
	}
	if err := m.ensureRuntime(project); err != nil {
		return task.StartResult{}, err
	}
	services := ServicesFor(project)
	if len(services) == 0 {
		return task.StartResult{}, errors.New("project does not have services to start")
	}
	return m.tasks.Start(project.Key, services, func(taskCtx context.Context, log *task.Logger) error {
		return m.runProjectActionTask(taskCtx, log, project, action, services)
	})
}

func (m *Manager) ReadTask(_ context.Context, taskID string, offset int64) (task.Snapshot, error) {
	return m.tasks.Read(taskID, offset)
}

func (m *Manager) Logs(ctx context.Context, key string) (string, error) {
	project, err := m.find(ctx, key)
	if err != nil {
		return "", err
	}
	service := dedicatedService(project)
	if service == "" {
		return "This project type does not have a managed process log.", nil
	}
	result, err := m.compose.Logs(ctx, 200, []string{service})
	if err != nil {
		return "", err
	}
	return result.Output, nil
}

func (m *Manager) RunCommand(ctx context.Context, key string, req RunRequest) (CommandResult, error) {
	project, err := m.find(ctx, key)
	if err != nil {
		return CommandResult{}, err
	}
	command, err := resolveCommand(strings.TrimSpace(req.Command), strings.TrimSpace(req.Custom))
	if err != nil {
		return CommandResult{}, err
	}
	if looksDangerous(command) {
		return CommandResult{}, errors.New("this command is blocked by the launcher")
	}
	if err := m.ensureRuntime(project); err != nil {
		return CommandResult{}, err
	}
	service := commandService(project)
	if _, err := m.startProjectServices(ctx, []string{service}); err != nil {
		return CommandResult{}, err
	}
	path := project.Path
	prepare := "if command -v git >/dev/null 2>&1; then git config --global --add safe.directory " + shellQuote(path) + " 2>/dev/null || true; fi"
	_, _ = m.compose.ExecShell(ctx, service, prepare)
	result, err := m.compose.ExecShell(ctx, service, "cd "+shellQuote(path)+" && "+command)
	if err != nil {
		return CommandResult{}, err
	}
	return CommandResult{Command: command, Output: result.Output}, nil
}

func (m *Manager) DeleteEnvironment(ctx context.Context, key string, options DeleteOptions) (DeleteResult, error) {
	if options.RemoveSource {
		return DeleteResult{}, errors.New("source deletion is not supported by this panel")
	}
	projects, err := m.store.List(ctx)
	if err != nil {
		return DeleteResult{}, err
	}
	projectIndex := -1
	var project domain.Project
	for index, item := range projects {
		if item.Key == key {
			projectIndex = index
			project = item
			break
		}
	}
	if projectIndex < 0 {
		return DeleteResult{}, ErrNotFound
	}
	actions := []string{}
	warnings := []string{}
	outputs := []string{}
	service := dedicatedService(project)
	if options.RemoveContainer {
		if service == "" {
			warnings = append(warnings, "No dedicated container is managed for this project type.")
		} else if result, err := m.compose.Remove(ctx, []string{service}); err != nil {
			warnings = append(warnings, "Container cleanup failed: "+err.Error())
		} else {
			actions = append(actions, "Remove dedicated container: "+service)
			outputs = append(outputs, result.Output)
		}
	}
	if options.RemoveCompose {
		if service == "" {
			warnings = append(warnings, "Compose service cleanup is only available for dedicated project services.")
		} else if removed, err := m.removeComposeService(project.Type, service); err != nil {
			return DeleteResult{}, err
		} else if removed {
			actions = append(actions, "Remove docker-compose service block: "+service)
		} else {
			warnings = append(warnings, "docker-compose service block was not found: "+service)
		}
	}
	projects = append(projects[:projectIndex], projects[projectIndex+1:]...)
	if err := m.store.Save(ctx, projects); err != nil {
		return DeleteResult{}, err
	}
	actions = append(actions, "Remove project record from projects.json")
	return DeleteResult{Actions: actions, Warnings: warnings, Output: strings.TrimSpace(strings.Join(outputs, "\n\n"))}, nil
}

func (m *Manager) find(ctx context.Context, key string) (domain.Project, error) {
	projects, err := m.store.List(ctx)
	if err != nil {
		return domain.Project{}, err
	}
	for _, project := range projects {
		if project.Key == key {
			return m.normalize(project)
		}
	}
	return domain.Project{}, ErrNotFound
}

func (m *Manager) startProjectServices(ctx context.Context, services []string) (string, error) {
	if len(services) == 0 {
		return "", nil
	}
	first, err := m.compose.UpNoBuild(ctx, services)
	if err == nil {
		return strings.TrimSpace(first.Output), nil
	}
	build, buildErr := m.compose.Build(ctx, services)
	if buildErr != nil {
		return "", errors.New(withImageAdvice(buildErr.Error()))
	}
	up, upErr := m.compose.Up(ctx, services)
	if upErr != nil {
		return "", errors.New(withImageAdvice(upErr.Error()))
	}
	return strings.TrimSpace(build.Output + "\n\n" + up.Output), nil
}

func (m *Manager) ensureRuntime(project domain.Project) error {
	if project.Type == domain.ProjectGo {
		return m.ensureGoComposeService(project)
	}
	return nil
}
