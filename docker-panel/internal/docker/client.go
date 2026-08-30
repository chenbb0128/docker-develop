package docker

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"strconv"
	"strings"
	"time"

	"github.com/chenbb0128/docker-develop/docker-panel/internal/domain"
	"github.com/moby/moby/api/pkg/stdcopy"
	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"
)

type Client struct {
	client *client.Client
}

func NewClient() (*Client, error) {
	cli, err := client.NewClientWithOpts(client.FromEnv, client.WithAPIVersionNegotiation())
	if err != nil {
		return nil, err
	}
	return &Client{client: cli}, nil
}

func (c *Client) Ping(ctx context.Context) (domain.DockerPing, error) {
	ping, err := c.client.Ping(ctx, client.PingOptions{NegotiateAPIVersion: true})
	if err != nil {
		return domain.DockerPing{}, err
	}
	return domain.DockerPing{
		APIVersion:   ping.APIVersion,
		OSType:       ping.OSType,
		Experimental: ping.Experimental,
	}, nil
}

func (c *Client) ListContainers(ctx context.Context, all bool) ([]domain.Container, error) {
	result, err := c.client.ContainerList(ctx, client.ContainerListOptions{All: all})
	if err != nil {
		return nil, err
	}

	containers := make([]domain.Container, 0, len(result.Items))
	for _, item := range result.Items {
		name := ""
		if len(item.Names) > 0 {
			name = strings.TrimPrefix(item.Names[0], "/")
		}

		containers = append(containers, domain.Container{
			ID:      shortID(item.ID),
			Name:    name,
			Image:   item.Image,
			State:   string(item.State),
			Status:  item.Status,
			Ports:   formatPorts(item.Ports),
			Created: time.Unix(item.Created, 0).Format("2006-01-02 15:04:05"),
			Labels:  item.Labels,
		})
	}

	return containers, nil
}

func (c *Client) InspectContainer(ctx context.Context, id string) (map[string]any, error) {
	inspect, err := c.client.ContainerInspect(ctx, id, client.ContainerInspectOptions{})
	if err != nil {
		return nil, err
	}

	payload := inspect.Raw
	if len(payload) == 0 {
		payload, err = json.Marshal(inspect.Container)
		if err != nil {
			return nil, err
		}
	}

	var out map[string]any
	if err := json.Unmarshal(payload, &out); err != nil {
		return nil, err
	}
	return out, nil
}

func (c *Client) ContainerLogs(ctx context.Context, id string, tail int) (string, error) {
	if tail <= 0 {
		tail = 100
	}

	reader, err := c.client.ContainerLogs(ctx, id, client.ContainerLogsOptions{
		ShowStdout: true,
		ShowStderr: true,
		Tail:       strconv.Itoa(tail),
	})
	if err != nil {
		return "", err
	}
	defer reader.Close()

	raw, err := io.ReadAll(reader)
	if err != nil {
		return "", err
	}
	if len(raw) == 0 {
		return "", nil
	}

	var decoded bytes.Buffer
	if _, err := stdcopy.StdCopy(&decoded, &decoded, bytes.NewReader(raw)); err == nil && decoded.Len() > 0 {
		return decoded.String(), nil
	}

	return string(raw), nil
}

func (c *Client) ContainerStats(ctx context.Context, id string) (map[string]any, error) {
	stats, err := c.client.ContainerStats(ctx, id, client.ContainerStatsOptions{Stream: false})
	if err != nil {
		return nil, err
	}
	defer stats.Body.Close()

	var out map[string]any
	if err := json.NewDecoder(stats.Body).Decode(&out); err != nil {
		return nil, err
	}
	return out, nil
}

func (c *Client) StartContainer(ctx context.Context, id string) error {
	_, err := c.client.ContainerStart(ctx, id, client.ContainerStartOptions{})
	return err
}

func (c *Client) StopContainer(ctx context.Context, id string, timeout time.Duration) error {
	seconds := int(timeout.Seconds())
	if seconds <= 0 {
		seconds = 10
	}
	_, err := c.client.ContainerStop(ctx, id, client.ContainerStopOptions{Timeout: &seconds})
	return err
}

func (c *Client) RestartContainer(ctx context.Context, id string, timeout time.Duration) error {
	seconds := int(timeout.Seconds())
	if seconds <= 0 {
		seconds = 10
	}
	_, err := c.client.ContainerRestart(ctx, id, client.ContainerRestartOptions{Timeout: &seconds})
	return err
}

func (c *Client) RemoveContainer(ctx context.Context, id string, force bool) error {
	_, err := c.client.ContainerRemove(ctx, id, client.ContainerRemoveOptions{Force: force})
	return err
}
func shortID(id string) string {
	if len(id) <= 12 {
		return id
	}
	return id[:12]
}

func formatPorts(ports []container.PortSummary) string {
	formatted := make([]string, 0, len(ports))
	for _, port := range ports {
		if port.PublicPort == 0 {
			continue
		}
		if !port.IP.IsValid() || port.IP.IsUnspecified() {
			formatted = append(formatted, fmt.Sprintf("%d:%d", port.PublicPort, port.PrivatePort))
			continue
		}
		formatted = append(formatted, fmt.Sprintf("%s:%d:%d", port.IP.String(), port.PublicPort, port.PrivatePort))
	}
	return strings.Join(formatted, ", ")
}
