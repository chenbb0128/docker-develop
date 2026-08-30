package storage

import (
	"context"
	"os"
	"path/filepath"
	"testing"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
)

func TestProjectStoreFallsBackToExample(t *testing.T) {
	dir := t.TempDir()
	example := filepath.Join(dir, "projects.example.json")
	if err := os.WriteFile(example, []byte(`{"projects":[{"key":"demo","name":"Demo","type":"go","path":"/develop/demo","port":8004,"services":["go-demo"]}]}`), 0o600); err != nil {
		t.Fatalf("write example: %v", err)
	}

	store := NewProjectStore(filepath.Join(dir, "projects.json"), example)
	projects, err := store.List(context.Background())
	if err != nil {
		t.Fatalf("List() error = %v", err)
	}
	if len(projects) != 1 || projects[0].Key != "demo" || projects[0].Type != domain.ProjectGo {
		t.Fatalf("projects = %#v", projects)
	}
	if store.ConfigExists() {
		t.Fatalf("ConfigExists() should be false when only example exists")
	}
}

func TestProjectStoreReadsPrimaryConfig(t *testing.T) {
	dir := t.TempDir()
	primary := filepath.Join(dir, "projects.json")
	if err := os.WriteFile(primary, []byte(`{"projects":[{"key":"real","name":"Real","type":"go","path":"/develop/real","port":8081,"service":"go-real"}]}`), 0o600); err != nil {
		t.Fatalf("write primary: %v", err)
	}

	store := NewProjectStore(primary, "")
	projects, err := store.List(context.Background())
	if err != nil {
		t.Fatalf("List() error = %v", err)
	}
	if len(projects) != 1 || projects[0].Key != "real" || projects[0].Service != "go-real" {
		t.Fatalf("projects = %#v", projects)
	}
	if !store.ConfigExists() {
		t.Fatalf("ConfigExists() should be true")
	}
}
