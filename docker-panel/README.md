# Docker Panel

`docker-panel` 是 Docker Develop 的本地 Web 控制台。当前后端为 Go，默认只绑定到 `127.0.0.1:9501`，用于本机管理 Docker 容器、Go 项目启动任务、静态站点配置和配置中心。

## 启动方式

推荐从仓库根目录启动：

```bash
docker-compose up -d docker-panel
# 或
docker compose up -d docker-panel
```

`docker-panel` 服务会构建 `Dockerfile.panel-go`。项目管理入口只生成 Go 服务。

## 主要接口

认证和状态：

```text
POST /api/login
POST /api/logout
GET  /api/check-auth
GET  /api/health
GET  /api/ready
GET  /api/version
```

容器：

```text
GET    /api/containers
GET    /api/containers/{id}
POST   /api/containers/{id}/start
POST   /api/containers/{id}/stop
POST   /api/containers/{id}/restart
GET    /api/containers/{id}/logs
GET    /api/containers/{id}/stats
DELETE /api/containers/{id}
```

环境和项目：

```text
GET  /api/env/presets
GET  /api/env/status
GET  /api/env/doctor
POST /api/env/start/{preset}
POST /api/env/stop-all
POST /api/terminal/start-env

GET    /api/projects
POST   /api/projects
POST   /api/project-scaffold
POST   /api/projects/{key}/start
POST   /api/projects/{key}/stop
POST   /api/projects/{key}/restart
POST   /api/projects/{key}/start-task
POST   /api/projects/{key}/restart-task
GET    /api/project-tasks/{taskId}
GET    /api/projects/{key}/logs
POST   /api/projects/{key}/run
POST   /api/projects/{key}/delete-environment
DELETE /api/projects/{key}
```

站点和配置中心：

```text
GET    /api/sites
POST   /api/sites
DELETE /api/sites/{filename}
POST   /api/sites/reload-nginx
POST   /api/sites/restart-nginx
GET    /api/sites/hosts-command

GET    /api/config/center/files
GET    /api/config/center/read
POST   /api/config/center/save
POST   /api/config/center/validate
GET    /api/config/center/backups
POST   /api/config/center/restore
GET    /api/config/center/backup-read
```

## 关键文件

```text
docker-panel/Dockerfile.panel-go       Go 面板镜像
docker-panel/cmd/panel/main.go         Go 入口
docker-panel/internal/httpapi          HTTP API
docker-panel/internal/project          Go 项目启动与 compose 生成
docker-panel/internal/compose          docker compose 命令封装
docker-panel/internal/task             后台任务日志
docker-panel/public/index.html         单页前端
```

## 开发注意

- 面板容器挂载 `/var/run/docker.sock`，等同于拥有本机 Docker 管理权限，只建议本机访问。
- Go 后端会把 `BASE_IMAGE_REGISTRY` 在 compose 命令环境中置空，避免把阿里云加速器地址误当成 Dockerfile `FROM` 前缀。
- 阿里云加速器应该配置在 Docker Desktop 的 Docker Engine `registry-mirrors` 中。
- `projects.json` 和 `docker-panel/runtime/` 是本机运行数据，不建议提交。