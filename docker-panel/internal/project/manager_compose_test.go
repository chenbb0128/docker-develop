package project

import (
	"strings"
	"testing"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
)

func TestReplaceManagedBlockKeepsComposeVariables(t *testing.T) {
	project := domain.Project{Key: "demo", Name: "Demo", Type: domain.ProjectGo, Path: "/develop/demo", Port: 8081, Service: "go-demo", Services: []string{"go-demo", "redis"}, Command: GoDevCommand}
	block := "# BEGIN Go project: go-demo\n" + newGoServiceConfig(project) + "# END Go project: go-demo"
	content := "services:\n# BEGIN Go project: go-demo\nold\n# END Go project: go-demo\n"
	updated, replaced := replaceManagedBlock(content, "# BEGIN Go project: go-demo", "# END Go project: go-demo", block)
	if !replaced {
		t.Fatalf("expected block to be replaced")
	}
	for _, needle := range []string{"${GO_VERSION}", "${GOPROXY}", "${HOST_PROJECT_PATH}", "${CONTAINER_PROJECT_PATH}"} {
		if !strings.Contains(updated, needle) {
			t.Fatalf("updated block lost %s:\n%s", needle, updated)
		}
	}
}
