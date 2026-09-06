package compose

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestEnvWithMounts(t *testing.T) {
	for _, tc := range []struct {
		name, root, projects, data string
	}{
		{"wsl-desktop", "/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/repo-id", "/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/projects-id", "/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/data-id"},
		{"wsl-engine", "/mnt/c/My Projects/docker-develop", "/mnt/d/Develop", "/home/dev/database"},
		{"windows-desktop", `C:\Develop\Docker\docker-develop`, `D:\My Projects`, `E:\database`},
		{"linux", "/home/dev/docker-develop", "/home/dev/projects", "/srv/database"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			t.Setenv("HOST_PROJECT_PATH", `C:\stale`)
			t.Setenv("DATA_PATH", "./data")
			t.Setenv("DOCKER_DEVELOP_HOST_PATH", "/stale")
			t.Setenv("BASE_IMAGE_REGISTRY", "unreachable.example/")
			r := NewRunner("/var/www/docker-develop")
			r.EnvFile = filepath.Join(t.TempDir(), ".env")
			mounts := []hostMount{
				{Source: tc.root, Destination: r.ProjectRoot},
				{Source: tc.projects, Destination: hostPathMountPrefix + "HOST_PROJECT_PATH"},
				{Source: tc.data, Destination: hostPathMountPrefix + "DATA_PATH"},
			}
			want := map[string]string{"DOCKER_DEVELOP_HOST_PATH": tc.root, "HOST_PROJECT_PATH": tc.projects, "DATA_PATH": tc.data, "BASE_IMAGE_REGISTRY": "", "DOCKER_BUILDKIT": "0"}
			for _, key := range []string{"NGINX_HOST_LOG_PATH", "NGINX_SITES_PATH", "NGINX_SSL_PATH", "MYSQL_ENTRYPOINT_INITDB"} {
				t.Setenv(key, "./stale")
				source := joinHostPath(tc.root, "separate/"+key)
				mounts = append(mounts, hostMount{Source: source, Destination: hostPathMountPrefix + key})
				want[key] = source
			}
			got := envMap(t, r.envWithMounts(mounts))
			for key, value := range want {
				if got[key] != value {
					t.Errorf("%s = %q, want %q", key, got[key], value)
				}
			}
		})
	}
}

func TestEnvWithoutMountsPreservesNativeOverrides(t *testing.T) {
	r := NewRunner(t.TempDir())
	t.Setenv("DOCKER_DEVELOP_HOST_PATH", "/home/dev/repo")
	t.Setenv("HOST_PROJECT_PATH", "/mnt/c/Develop")
	t.Setenv("DATA_PATH", "")
	if err := os.WriteFile(r.EnvFile, []byte("HOST_PROJECT_PATH=C:\\Develop\r\nDATA_PATH=./data\r\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	got := envMap(t, r.envWithMounts(nil))
	if got["HOST_PROJECT_PATH"] != "/mnt/c/Develop" || got["DATA_PATH"] != "/home/dev/repo/data" {
		t.Fatalf("unexpected native paths: host=%q data=%q", got["HOST_PROJECT_PATH"], got["DATA_PATH"])
	}
}

func envMap(t *testing.T, items []string) map[string]string {
	t.Helper()
	values := make(map[string]string)
	for _, item := range items {
		key, value, _ := strings.Cut(item, "=")
		if _, exists := values[key]; exists {
			t.Fatalf("duplicate environment key %q", key)
		}
		values[key] = value
	}
	return values
}
