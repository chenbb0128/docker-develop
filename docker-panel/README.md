# Docker Panel：Go 后端

当前后端使用 Go 1.25、chi 和 Moby Docker SDK；入口为 `cmd/panel`，镜像文件为 `Dockerfile.panel-go`。`../docker-panel-legacy-php/` 是 PHP 旧版备份目录，当前 Compose 不引用它。

业务项目接口只接受 Go 项目，Nginx 站点接口只接受静态站点。当前前端提供项目、容器、环境和状态信息；站点和配置中心虽有后端 API，尚未提供对应的完整 Go 前端操作页面。

> 迁移尚未完成全部功能验收。启动脚本已适配 Go/WSL2；动态按钮引号、跨项目停止等遗留问题见 [检查报告](../docs/GO_CODE_REVIEW_2026-09-06.md)。单元测试通过不能代表完整业务流程可用。

## 容器启动与升级

在仓库根目录检查本机 `.env`，然后执行：

```bash
docker compose config --quiet
docker compose up -d --build --force-recreate docker-panel
docker compose logs --tail=100 docker-panel
```

打开 [http://localhost:9501](http://localhost:9501)。修改 Go 源码后需重建镜像；二进制位于 `/usr/local/bin/docker-panel-go`，挂载源码不会自动重新编译。`public/` 是挂载的静态文件目录；内嵌 `internal/web/assets/index.html` 只是缺少页面文件时的提示页，不是另一套完整前端。

Compose 将宿主机 `127.0.0.1:9501` 映射到容器 `9501`，同时挂载 Docker socket、仓库和面板目录。`start-panel.bat` / `start-panel.sh` 会构建 Go 镜像并检查 `/api/ready`。Windows 下使用 WSL2 启动可双击 `start-panel-wsl.bat`，具体配置见 [WSL2 启动](../README.md#wsl2-启动)。

业务根目录、数据目录及 Nginx/MySQL 初始化目录额外只读挂载到 `/var/run/docker-develop/host-paths/<环境变量名>`。Compose runner 每次通过 `docker inspect` 读取实际 Source，以兼容 Docker Desktop WSL2 的内部绑定路径；不会把 `/mnt/c` 或 Linux home 路径硬编码为引擎路径。修改挂载配置后必须重新运行启动入口以更新容器，不能只重启 Go 进程。

## 本地开发

在仓库根目录执行，端口被占用时换一个空闲端口：

```powershell
$env:PANEL_PROJECT_ROOT = (Get-Location).Path
$env:PANEL_LISTEN_ADDR = '127.0.0.1:9503'
cd docker-panel
$env:GOCACHE = Join-Path (Get-Location) '.gocache'
go run -mod=readonly ./cmd/panel
```

需要本机 Docker Engine 和 Compose 可用。业务容器中的 Linux 命令通过 Compose 执行，本机仅启动面板 Go 进程。直接运行 Go 默认监听 `0.0.0.0:9501`，本地开发应显式设置回环地址。

## 模块职责

| 目录 | 职责 |
| --- | --- |
| `cmd/panel` | 配置加载、日志初始化、信号处理 |
| `internal/app` | 依赖组装、HTTP 启停 |
| `internal/httpapi` | HTTP 路由、请求解码、响应和中间件 |
| `internal/domain` | 项目、服务状态和容器数据结构 |
| `internal/project` | 项目保存、Go Compose 生成、启动和删除流程 |
| `internal/compose` | Compose 子进程、运行参数与宿主机路径 |
| `internal/docker` | Docker SDK：容器查询、日志与操作 |
| `internal/storage` | `projects.json` 读写及示例回退 |
| `internal/task` | 后台 goroutine、超时、状态与日志文件 |
| `internal/web`、`public` | 静态页面服务和前端 |

## 配置行为

配置在进程启动时加载 `PANEL_ENV_FILE` 指定的 dotenv 文件，默认为仓库 `.env`；已有进程环境变量优先。修改 `.env` 后需重启面板，避免后续 Compose 命令继续使用旧环境值。

| 变量 | 默认值或行为 |
| --- | --- |
| `PANEL_PROJECT_ROOT` | 自动寻找含主 Compose 文件的仓库目录；容器中显式配置为 `/var/www/docker-develop` |
| `PANEL_LISTEN_ADDR` | `0.0.0.0:9501`；宿主机原生运行应改为回环地址 |
| `PANEL_STATE_DIR` | 仓库下 `docker-panel/runtime` |
| `PANEL_PROJECTS_FILE` | 仓库下 `projects.json` |
| `PANEL_EXAMPLE_PROJECTS_FILE` | 仓库下 `projects.example.json` |
| `PANEL_WEB_DIR` | 面板 `public` 目录 |
| `PANEL_TASK_BUILD_TIMEOUT` | 后台任务默认 `30m`；Compose 当前显式设置为 `30m` |
| `PANEL_LOG_LEVEL` | 默认 `info`，可用 `debug`、`warn`、`error` |

以下变量虽然在配置结构中存在，但不能视为已完成的功能：

- `PANEL_AUTH_ENABLED`：未接入鉴权，登录接口固定返回 `local-dev`。
- `PANEL_PUBLIC_BIND`：未用于配置 HTTP 监听或 Compose 端口映射。
- `PANEL_GENERATED_COMPOSE_FILE`：未用于生成，实际仍改写主 `docker-compose.yml`。
- `PANEL_TASK_RETENTION`：未接入任务清理逻辑。
- `PANEL_UNSAFE_TERMINAL`：只控制 `/api/terminal/exec` 的禁用分支，开启后该接口仍未实现；项目 `/run` 的自定义命令不受它限制。

Compose runner 当前先寻找 `docker-compose`，再使用 `docker compose`，并强制设置 `DOCKER_BUILDKIT=0`、`COMPOSE_DOCKER_CLI_BUILD=0` 和空 `BASE_IMAGE_REGISTRY`。命令名称带连字符本身不能证明是 Compose V1。

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

## 接口语义与边界

- `/api/health` 仅验证进程；`/api/ready` 检查状态目录、项目文件和 Docker 连接，不验证业务 HTTP。
- `POST /api/projects` 只保存项目记录；`POST /api/project-scaffold` 同时生成 Compose 服务，`dryRun=true` 不落盘，但也不运行完整 Compose 校验。
- `/start-task`、`/restart-task` 返回 `taskId`；前端用 `/api/project-tasks/{taskId}?offset=...` 轮询增量日志。同步 `/start`、`/restart` 不等价于后台任务的状态检查。
- 项目 `/stop` 只停止专属 Go 服务；`/env/stop-all` 当前遍历整个引擎的运行容器，仅按名称跳过面板，未限定 Compose 项目。
- `DELETE /api/projects/{key}` 默认只删项目记录；`/delete-environment` 按 `removeContainer`、`removeCompose` 决定清理范围，`removeSource=true` 被拒绝。删除记录不代表所有容器清理都成功，需检查返回的 `warnings`。
- `/api/containers` 返回的 `ports` 是字符串；当前前端误按数组处理。
- 配置保存会备份文件，但未做原子写入或事务回滚。Compose/Nginx 校验接口当前校验的是磁盘或容器现有配置，不是请求中的候选 `content`。
- 后台任务日志在文件中持久化，但 goroutine 不会在面板重启后恢复，旧任务可能一直显示 running；当前没有任务取消或同项目启动互斥。
- 项目启动先尝试已有镜像，失败后构建再启动；失败原因尚未精确分类，业务配置错误也可能触发重建。running 不等于 HTTP ready，输出按子命令完成后写入。

## 验证

在 `docker-panel/` 目录：

```powershell
$env:GOCACHE = Join-Path (Get-Location) '.gocache'
go test -mod=readonly ./...
go vet -mod=readonly ./...
gofmt -l cmd internal
```

在仓库根目录：

```bash
docker compose --env-file .env.example config --quiet
```

现有测试覆盖配置加载、部分状态与 HTTP 查询、服务名归一化、文件读取、Windows/WSL/Linux 项目路径及 Compose 挂载路径映射。`bash scripts/tests/startup_test.sh`（仓库根目录）使用模拟 Docker CLI 检查 Go 启动、路径转换和失败退出；`powershell -NoProfile -File scripts/tests/start-panel-wsl.Tests.ps1` 使用模拟 WSL CLI 检查发行版选择和退出码，都不操作真实容器。尚未覆盖真实 Compose 启停、任务生命周期、并发写入及浏览器操作。2026-09-06 的静态检查与最小复现结果见 [检查报告](../docs/GO_CODE_REVIEW_2026-09-06.md)。

当前面板无有效鉴权，并能访问 Docker socket、项目源码和配置文件，仅适用于可信本机环境。`.env` 当前仍被 Git 跟踪；提交前检查暂存内容，不提交本机凭据和运行数据。
