package domain

type ProjectType string

const (
	ProjectGo ProjectType = "go"
)

type Project struct {
	Key      string         `json:"key"`
	Name     string         `json:"name"`
	Type     ProjectType    `json:"type"`
	Path     string         `json:"path"`
	Port     int            `json:"port"`
	Service  string         `json:"service,omitempty"`
	Services []string       `json:"services"`
	Command  string         `json:"command,omitempty"`
	Log      string         `json:"log,omitempty"`
	URL      string         `json:"url,omitempty"`
	Notes    string         `json:"notes,omitempty"`
	Status   *ProjectStatus `json:"status,omitempty"`
}

type ProjectStatus struct {
	ServicesRunning int             `json:"servicesRunning"`
	ServicesTotal   int             `json:"servicesTotal"`
	Active          bool            `json:"active"`
	Services        []ServiceStatus `json:"services"`
}

type ServiceStatus struct {
	Name      string `json:"name"`
	Container string `json:"container"`
	State     string `json:"state"`
	Status    string `json:"status"`
	Running   bool   `json:"running"`
}
