package domain

type Container struct {
	ID      string            `json:"id"`
	Name    string            `json:"name"`
	Image   string            `json:"image"`
	State   string            `json:"state"`
	Status  string            `json:"status"`
	Ports   string            `json:"ports"`
	Created string            `json:"created"`
	Labels  map[string]string `json:"labels"`
}

type DockerPing struct {
	APIVersion   string `json:"apiVersion"`
	OSType       string `json:"osType"`
	Experimental bool   `json:"experimental"`
}
