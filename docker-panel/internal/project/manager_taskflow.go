package project

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/compose"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
	"github.com/chenbb0128/docker-develop/docker-panel/internal/task"
)

func (m *Manager) runProjectActionTask(ctx context.Context, log *task.Logger, project domain.Project, action string, services []string) error {
	actionName := "Start"
	if action == "restart" {
		actionName = "Restart"
	}
	log.Line(actionName + " project: " + project.Name)
	log.Line("Services: " + strings.Join(services, ", "))

	stage := 1
	stageTotal := 4
	if action == "restart" && dedicatedService(project) != "" {
		stageTotal = 5
		log.Line(fmt.Sprintf("Stage %d/%d: stop old project container", stage, stageTotal))
		service := dedicatedService(project)
		result, err := m.compose.Stop(ctx, []string{service})
		log.Command(result.Command)
		log.Raw(result.Output)
		if err != nil {
			log.Line("Old container stop failed; continuing with rebuild/start.")
		}
		stage++
	}

	log.Line(fmt.Sprintf("Stage %d/%d: try existing images", stage, stageTotal))
	first, firstErr := m.compose.UpNoBuild(ctx, services)
	log.Command(first.Command)
	log.Raw(first.Output)
	if firstErr == nil {
		if err := m.verifyServices(ctx, log, services, stageTotal); err == nil {
			return nil
		}
		log.Line("Services did not stay healthy; rebuilding project images.")
	} else {
		log.Line("Direct start failed. The image may not exist yet, a base image may need pulling, or compose needs regeneration.")
	}
	stage++

	log.Line(fmt.Sprintf("Stage %d/%d: build project images", stage, stageTotal))
	build, err := m.compose.Build(ctx, services)
	log.Command(build.Command)
	log.Raw(build.Output)
	if err != nil {
		log.Line(imageAdvice(build.Output + "\n" + err.Error()))
		return err
	}
	stage++

	log.Line(fmt.Sprintf("Stage %d/%d: start project services", stage, stageTotal))
	up, err := m.compose.Up(ctx, services)
	log.Command(up.Command)
	log.Raw(up.Output)
	if err != nil {
		log.Line(imageAdvice(up.Output + "\n" + err.Error()))
		return err
	}
	return m.verifyServices(ctx, log, services, stageTotal)
}

func (m *Manager) verifyServices(ctx context.Context, log *task.Logger, services []string, stageTotal int) error {
	services = compose.CleanServices(services)
	log.Line("Project services started; waiting for containers to stay running...")
	var last string
	for attempt := 0; attempt < 8; attempt++ {
		wait := 2 * time.Second
		if attempt == 0 {
			wait = 3 * time.Second
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(wait):
		}
		running, err := m.compose.RunningServices(ctx, services)
		last = running.Output
		if err == nil && allServicesRunning(services, running.Output) {
			ps, _ := m.compose.PS(ctx, services)
			log.Command(ps.Command)
			log.Raw(ps.Output)
			logs, _ := m.compose.Logs(ctx, 80, services)
			log.Line(fmt.Sprintf("Stage %d/%d: check status and read recent logs", stageTotal, stageTotal))
			log.Command(logs.Command)
			log.Raw(logs.Output)
			return nil
		}
	}

	log.Line(fmt.Sprintf("Stage %d/%d: check status and read recent logs", stageTotal, stageTotal))
	for _, service := range missingServices(services, last) {
		log.Line("Service is not running: " + service)
	}
	logs, _ := m.compose.Logs(ctx, 120, services)
	log.Command(logs.Command)
	log.Raw(logs.Output)
	return errors.New("not all services stayed running; recent container logs were collected")
}

func allServicesRunning(services []string, output string) bool {
	return len(missingServices(services, output)) == 0
}

func missingServices(services []string, output string) []string {
	running := map[string]struct{}{}
	for _, line := range strings.Split(output, "\n") {
		line = strings.TrimSpace(line)
		if line != "" {
			running[line] = struct{}{}
		}
	}
	missing := []string{}
	for _, service := range services {
		if _, ok := running[service]; !ok {
			missing = append(missing, service)
		}
	}
	return missing
}
