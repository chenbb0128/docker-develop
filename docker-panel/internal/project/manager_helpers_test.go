package project

import "testing"

func TestNormalizeContainerPathHostPlatforms(t *testing.T) {
	for _, tc := range []struct {
		name, host, input, want string
	}{
		{"windows-backslashes", `C:\Develop`, `C:\Develop\demo`, "/develop/demo"},
		{"windows-root", `C:\Develop`, `C:\Develop`, "/develop"},
		{"windows-case", `C:\Develop`, `c:\develop\Demo`, "/develop/Demo"},
		{"wsl-with-windows-env", `C:\Develop`, "/mnt/c/Develop/demo", "/develop/demo"},
		{"windows-with-wsl-env", "/mnt/c/Develop", `C:\Develop\demo`, "/develop/demo"},
		{"wsl-native", "/home/dev/My Projects", "/home/dev/My Projects/demo", "/develop/demo"},
		{"wsl-disk", "/mnt/d/Develop", "/mnt/d/Develop/demo", "/develop/demo"},
		{"container", "/mnt/c/Develop", "/develop/demo", "/develop/demo"},
		{"similar-prefix", "/home/dev/projects", "/home/dev/projects-other/demo", "/home/dev/projects-other/demo"},
		{"clean-before-mapping", "/home/dev/projects", "/home/dev/projects/../other", "/home/dev/other"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			t.Setenv("HOST_PROJECT_PATH", tc.host)
			t.Setenv("CONTAINER_PROJECT_PATH", "/develop")
			if got := normalizeContainerPath(tc.input); got != tc.want {
				t.Fatalf("normalizeContainerPath(%q) = %q, want %q", tc.input, got, tc.want)
			}
		})
	}
}
