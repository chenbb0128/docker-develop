package task

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"
)

type Manager struct {
	Dir     string
	Timeout time.Duration
	mu      sync.Mutex
}

type Logger struct {
	file string
	mu   *sync.Mutex
}

type StartResult struct {
	TaskID   string   `json:"taskId"`
	Status   string   `json:"status"`
	PID      string   `json:"pid"`
	Services []string `json:"services,omitempty"`
}

type Snapshot struct {
	TaskID     string `json:"taskId"`
	Status     string `json:"status"`
	ExitCode   *int   `json:"exitCode"`
	Offset     int64  `json:"offset"`
	Output     string `json:"output"`
	Elapsed    int64  `json:"elapsed"`
	FinishedAt *int64 `json:"finishedAt"`
}

func NewManager(stateDir string, timeout time.Duration) *Manager {
	if timeout <= 0 {
		timeout = 30 * time.Minute
	}
	return &Manager{Dir: filepath.Join(stateDir, "tasks"), Timeout: timeout}
}

func (m *Manager) Start(key string, services []string, run func(context.Context, *Logger) error) (StartResult, error) {
	if err := os.MkdirAll(m.Dir, 0o775); err != nil {
		return StartResult{}, err
	}
	id := time.Now().Format("20060102150405") + "-" + sanitize(key, "task") + "-" + randomSuffix()
	logger := &Logger{file: m.taskFile(id, ".log"), mu: &m.mu}

	if err := os.WriteFile(m.taskFile(id, ".status"), []byte("queued"), 0o664); err != nil {
		return StartResult{}, err
	}
	started := fmt.Sprintf("%d", time.Now().Unix())
	if err := os.WriteFile(m.taskFile(id, ".started"), []byte(started), 0o664); err != nil {
		return StartResult{}, err
	}
	_ = os.WriteFile(m.taskFile(id, ".exit"), []byte(""), 0o664)
	logger.Line("已创建后台任务，正在准备执行。")

	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), m.Timeout)
		defer cancel()
		m.writeStatus(id, "running")
		err := run(ctx, logger)
		code := 0
		if err != nil {
			code = 1
			logger.Line(err.Error())
		}
		if ctx.Err() == context.DeadlineExceeded {
			code = 124
			logger.Line("任务超时。")
		}
		_ = os.WriteFile(m.taskFile(id, ".exit"), []byte(fmt.Sprintf("%d", code)), 0o664)
		_ = os.WriteFile(m.taskFile(id, ".finished"), []byte(fmt.Sprintf("%d", time.Now().Unix())), 0o664)
		if code == 0 {
			m.writeStatus(id, "success")
			logger.Line("任务完成。")
		} else {
			m.writeStatus(id, "failed")
			logger.Line("任务失败，请查看上方最后一段错误。")
		}
	}()

	return StartResult{TaskID: id, Status: "queued", PID: "go-routine", Services: services}, nil
}

func (m *Manager) Read(taskID string, offset int64) (Snapshot, error) {
	id := sanitizeTaskID(taskID)
	if id == "" {
		return Snapshot{}, os.ErrNotExist
	}
	statusFile := m.taskFile(id, ".status")
	if _, err := os.Stat(statusFile); err != nil {
		return Snapshot{}, err
	}

	status := strings.TrimSpace(readString(statusFile))
	if status == "" {
		status = "running"
	}
	exitText := strings.TrimSpace(readString(m.taskFile(id, ".exit")))
	var exitCode *int
	if exitText != "" {
		var parsed int
		_, _ = fmt.Sscanf(exitText, "%d", &parsed)
		exitCode = &parsed
	}
	started := parseUnix(readString(m.taskFile(id, ".started")), time.Now().Unix())
	finished := parseUnix(readString(m.taskFile(id, ".finished")), 0)

	logFile := m.taskFile(id, ".log")
	stat, err := os.Stat(logFile)
	if err != nil {
		return Snapshot{}, err
	}
	size := stat.Size()
	if offset < 0 || offset > size {
		offset = 0
	}
	output := ""
	if size > 0 {
		file, err := os.Open(logFile)
		if err != nil {
			return Snapshot{}, err
		}
		defer file.Close()
		if _, err := file.Seek(offset, 0); err == nil {
			buf := make([]byte, size-offset)
			read, _ := file.Read(buf)
			output = string(buf[:read])
		}
	}

	elapsedEnd := time.Now().Unix()
	var finishedPtr *int64
	if finished > 0 {
		elapsedEnd = finished
		finishedPtr = &finished
	}

	return Snapshot{
		TaskID:     id,
		Status:     status,
		ExitCode:   exitCode,
		Offset:     size,
		Output:     output,
		Elapsed:    maxInt64(0, elapsedEnd-started),
		FinishedAt: finishedPtr,
	}, nil
}

func (m *Manager) taskFile(taskID, suffix string) string {
	return filepath.Join(m.Dir, sanitizeTaskID(taskID)+suffix)
}

func (m *Manager) writeStatus(taskID, status string) {
	_ = os.WriteFile(m.taskFile(taskID, ".status"), []byte(status), 0o664)
}

func (l *Logger) Line(message string) {
	if strings.TrimSpace(message) == "" {
		l.Raw("\n")
		return
	}
	for _, line := range strings.Split(strings.TrimRight(message, "\r\n"), "\n") {
		l.Raw(fmt.Sprintf("[%s] %s\n", time.Now().Format("15:04:05"), strings.TrimRight(line, "\r")))
	}
}

func (l *Logger) Command(command string) {
	l.Line("$ " + command)
}

func (l *Logger) Raw(text string) {
	l.mu.Lock()
	defer l.mu.Unlock()
	file, err := os.OpenFile(l.file, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o664)
	if err != nil {
		return
	}
	defer file.Close()
	_, _ = file.WriteString(text)
}

var taskIDPattern = regexp.MustCompile(`[^a-zA-Z0-9_.-]+`)
var keyPattern = regexp.MustCompile(`[^a-zA-Z0-9_-]+`)

func sanitizeTaskID(value string) string {
	return taskIDPattern.ReplaceAllString(strings.TrimSpace(value), "")
}

func sanitize(value, fallback string) string {
	out := keyPattern.ReplaceAllString(strings.TrimSpace(value), "")
	if out == "" {
		return fallback
	}
	return out
}

func randomSuffix() string {
	buf := make([]byte, 3)
	if _, err := rand.Read(buf); err != nil {
		return "000000"
	}
	return hex.EncodeToString(buf)
}

func readString(path string) string {
	data, err := os.ReadFile(path)
	if err != nil {
		return ""
	}
	return string(data)
}

func parseUnix(value string, fallback int64) int64 {
	value = strings.TrimSpace(value)
	if value == "" {
		return fallback
	}
	var parsed int64
	if _, err := fmt.Sscanf(value, "%d", &parsed); err != nil {
		return fallback
	}
	return parsed
}

func maxInt64(a, b int64) int64 {
	if a > b {
		return a
	}
	return b
}
