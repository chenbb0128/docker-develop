package storage

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
)

type ProjectStore struct {
	path        string
	examplePath string
}

func NewProjectStore(path, examplePath string) *ProjectStore {
	return &ProjectStore{path: path, examplePath: examplePath}
}

func (s *ProjectStore) ConfigExists() bool {
	_, err := os.Stat(s.path)
	return err == nil
}

func (s *ProjectStore) Path() string {
	return s.path
}

func (s *ProjectStore) Save(ctx context.Context, projects []domain.Project) error {
	select {
	case <-ctx.Done():
		return ctx.Err()
	default:
	}
	if err := os.MkdirAll(filepath.Dir(s.path), 0o775); err != nil {
		return fmt.Errorf("create projects directory: %w", err)
	}
	payload, err := json.MarshalIndent(struct {
		Projects []domain.Project `json:"projects"`
	}{Projects: projects}, "", "    ")
	if err != nil {
		return fmt.Errorf("encode projects file: %w", err)
	}
	if err := os.WriteFile(s.path, append(payload, '\n'), 0o664); err != nil {
		return fmt.Errorf("write projects file: %w", err)
	}
	return nil
}
func (s *ProjectStore) List(ctx context.Context) ([]domain.Project, error) {
	select {
	case <-ctx.Done():
		return nil, ctx.Err()
	default:
	}

	path := s.path
	if _, err := os.Stat(path); err != nil {
		if s.examplePath == "" {
			if errors.Is(err, os.ErrNotExist) {
				return []domain.Project{}, nil
			}
			return nil, err
		}
		path = s.examplePath
	}

	file, err := os.Open(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return []domain.Project{}, nil
		}
		return nil, fmt.Errorf("open projects file: %w", err)
	}
	defer file.Close()

	var payload struct {
		Projects []domain.Project `json:"projects"`
	}
	if err := json.NewDecoder(file).Decode(&payload); err != nil {
		return nil, fmt.Errorf("decode projects file: %w", err)
	}
	if payload.Projects == nil {
		return []domain.Project{}, nil
	}
	return payload.Projects, nil
}
