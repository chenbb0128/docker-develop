# Docker Develop：Go 本地开发环境

Docker Develop 使用 Go 编写本地 Docker 管理面板，当前项目管理入口只接入 Go 项目。每个 Go 项目使用独立的 Compose 服务，直接通过宿主机端口访问；Redis、MySQL、RabbitMQ 按需共享，Nginx 仅用于可选的静态站点。

当前实现基于 Go 1.25、chi、Moby Docker SDK 和 Docker Compose。PHP 旧版代码保存在 `docker-panel-legacy-php/`，作为历史参考，不参与当前面板构建。

> 当前版本仍处于迁移收尾阶段。启动脚本已切换到 Go 并适配 WSL2；动态操作按钮引号、“停止全部”未限定本仓库容器等问题仍待修复，不能据此认为所有面板操作都已验收。历史问题与验证范围见 [Go 版本检查报告](docs/GO_CODE_REVIEW_2026-09-06.md)。

## 运行要求

- Docker Desktop 使用 Linux containers；Linux 主机使用 Docker Engine 和 Compose 插件。
- 建议使用 Docker Compose V2，先确认 `docker compose version` 可用。
- Docker 引擎可访问基础镜像仓库，构建环境可访问 Go 模块和系统软件源。
- 仅运行容器无需在宿主机安装 Go；修改、测试面板后端需 Go 1.25 或以上兼容版本。
- 面板宿主机端口为 `127.0.0.1:9501`；项目访问端口需自行避免冲突。

## 启动或升级 Go 面板

在仓库根目录操作。已有 `.env` 时保留本机配置；缺失时才从 `.env.example` 创建：

```powershell
# Windows PowerShell
if (-not (Test-Path -LiteralPath .env)) {
    Copy-Item -LiteralPath .env.example -Destination .env
}
```

```bash
# Linux / macOS
test -f .env || cp .env.example .env
```

检查 `.env` 中的项目根目录、端口和数据库密码。Windows 示例：

```dotenv
HOST_PROJECT_PATH=C:/Develop
CONTAINER_PROJECT_PATH=/develop
DATA_PATH=./data
GO_VERSION=1.25
GOPROXY=https://goproxy.cn,direct
GO_CGO_ENABLED=1
BASE_IMAGE_REGISTRY=
```

首次运行、从 PHP 升级或修改 Go 面板代码后，需要重新构建并创建面板容器：

```bash
docker compose config --quiet
docker compose up -d --build --force-recreate docker-panel
docker compose ps docker-panel
docker compose logs --tail=100 docker-panel
```

打开 [http://localhost:9501](http://localhost:9501)。可通过以下接口检查面板：

- `GET /api/health`：HTTP 服务存活。
- `GET /api/ready`：状态目录可写、项目配置可读、Docker API 可连接。
- `GET /api/version`：Go 运行时和构建信息；当前默认构建的版本、提交号可能仍显示 `dev`、`unknown`。

`/api/ready` 不检查业务项目 HTTP 是否可访问。只执行 `up -d` 或 `restart` 不保证重建已经存在的旧 PHP 镜像。只指定 `docker-panel` 服务，避免无参数启动 Compose 中已有的本机业务项目。

也可以双击 `start-panel.bat`，或在 Linux/macOS 中运行 `bash ./start-panel.sh`。启动脚本会初始化缺失配置、检查环境、执行 `up -d --build docker-panel`，并等待 `/api/ready` 返回 200；失败时显示日志并退出，不自动停止其他服务。`scaffold.ps1` 和部分 `Makefile` 目标仍残留旧流程。

## WSL2 启动

推荐使用 Docker Desktop 的 WSL2 后端。在 Docker Desktop 中启用 **Settings > General > Use the WSL 2 based engine**，再在 **Resources > WSL Integration** 中启用 Ubuntu 等普通发行版。需要该发行版内的 Linux `docker` CLI 和 Compose 可用，不必再装第二套 Docker Engine。

Windows 仓库可直接双击 [start-panel-wsl.bat](start-panel-wsl.bat)。入口优先选择 Ubuntu，否则选择首个普通 WSL2 发行版，跳过 `docker-desktop*`，不会修改系统默认发行版。指定发行版时使用 `start-panel-wsl.bat -Distribution Ubuntu-24.04`。

源码在 WSL Linux 文件系统中时，在对应发行版的仓库目录运行：

```bash
bash ./start-panel.sh
```

`.env` 支持以下宿主机路径，容器路径仍使用 `/develop`：

| 源码位置 | `HOST_PROJECT_PATH` 示例 |
| --- | --- |
| Windows 磁盘 | `C:\Develop` 或 `/mnt/c/Develop` |
| WSL Linux 文件系统 | `/home/you/projects` |
| 仓库内目录 | `./data` |

脚本使用 `wslpath` 临时转换 Windows 盘符路径，不把 `.env` 改写成 WSL 专用配置。共享同一份配置并交替从 Windows/WSL 启动时，Windows 磁盘建议保留 `C:\Develop` 写法；Linux 专属目录请从 WSL 入口启动。路径含空格也支持，不要在 `.env` 路径中使用 shell 命令或变量表达式。

面板通过只读绑定挂载取得业务目录、数据目录和 Nginx 目录的 Docker 引擎实际路径，后续点击启动使用这些路径，而不是猜测 Docker Desktop 内部映射。更改这些根目录配置后，需要重新运行启动入口重建容器挂载；只重启进程不够。项目表单可填写 `/develop/demo`；宿主机根目录配置为绝对路径时，也支持把该根目录下的 Windows/WSL 绝对路径转换为容器路径。

Windows 浏览器访问 [http://localhost:9501](http://localhost:9501)。若 WSL 内运行自建 Docker Engine，Windows 访问还依赖 WSL 的 localhost 转发和防火墙设置；不要同时混用两套引擎，否则容器、镜像和数据不是同一套。大量 Go 文件建议放在 WSL Linux 文件系统以减少跨文件系统 I/O。

启动入口不会安装 WSL/Docker、修改 Docker 镜像加速器或删除旧数据。镜像源和 Go 模块下载仍需要网络可用。

## 接入 Go 项目

面板的“新增 Go 项目”用于登记已有源码并生成运行环境，不会生成 Go 业务代码或自动创建 `go.mod`。

| 表单项 | 示例 | 含义 |
| --- | --- | --- |
| 项目标识 | `demo-go` | 通常生成服务名 `go-demo-go` |
| 项目路径 | `/develop/demo-go` | 容器内含 `go.mod` 的项目目录 |
| 宿主机端口 | `18080` | 浏览器访问端口，映射到容器 `8080` |
| 访问地址 | `http://localhost:18080` | 展示地址，不会改变应用监听配置 |
| 依赖服务 | Redis / MySQL / RabbitMQ | 按需勾选，多个项目共享这些服务 |
| 启动命令 | `go run ./cmd/api` | 可留空，使用默认入口探测 |

`HOST_PROJECT_PATH=C:/Develop` 与 `CONTAINER_PROJECT_PATH=/develop` 的映射关系：

```text
宿主机 C:/Develop/demo-go/go.mod
容器内 /develop/demo-go/go.mod
表单填写 /develop/demo-go
```

所有 Go 项目目前共用这一组根目录挂载。源码应位于宿主机根目录下，表单优先填写容器路径；当前校验尚未完整检查路径是否存在、是否在挂载范围内，以及端口是否被占用。

应用需监听容器内 `0.0.0.0:8080` 或 `:8080`。面板注入 `PORT`、`SERVER_PORT`、`APP_PORT` 为 `8080`，但业务代码必须实际读取它们。只监听 `127.0.0.1`，或使用其他容器端口，都会导致宿主机无法访问。

默认命令先运行 `go mod download`，再按以下顺序探测入口：

1. 根目录存在 `main.go`：`go run .`。
2. 存在 `cmd/server`、`cmd/api` 或 `cmd/app` 目录：运行第一个匹配目录。
3. 都不匹配时，用 `go list` 选择第一个 `main` 包。

多入口项目建议显式填写启动命令。自动探测不等于验证入口正确，也不包含热重载、业务迁移或自动建库。

保存会更新 `projects.json` 和主 `docker-compose.yml` 中的 `# BEGIN Go project` 管理块。启动时会重新生成该块，持久配置应保存在项目记录中，手工修改管理块可能被覆盖。当前卡片按钮缺陷修复前，已生成的示例服务可按以下方式启动：

```bash
# 仅在已保存 demo-go、生成 go-demo-go 服务后执行
docker compose up -d --build go-demo-go
docker compose logs --tail=100 go-demo-go
```

`go` 通用工具服务运行的是 `tail -f /dev/null`，启动它不会自动运行某个 Go 应用。

## 依赖、数据与配置

| 依赖 | Go 容器中的地址 | 注入的配置 |
| --- | --- | --- |
| MySQL | `mysql:3306` | `DB_HOST`、`DB_PORT`、`DB_NAME`、`DB_USERNAME=root`、`DB_PASSWORD` |
| Redis | `redis:6379` | `REDIS_HOST`、`REDIS_PORT`、`REDIS_PASSWORD` |
| RabbitMQ | `rabbitmq:5672` | `RABBITMQ_HOST`、`RABBITMQ_PORT`、`RABBITMQ_USERNAME`、`RABBITMQ_PASSWORD` |

业务容器中连接依赖时使用 Compose 服务名；宿主机客户端使用 `localhost` 和 `.env` 中的映射端口。面板不会把业务项目自己的 `.env` 自动加载成容器环境变量，框架专用变量需由项目启动命令或应用配置处理。

MySQL 的数据库名和 root 密码取自仓库 `.env`；Redis 密码取自 `REDIS_PASSWORD`。RabbitMQ 当前固定使用 `guest` 开发账号，连接策略应按实际镜像验证。初始化数据库变量主要在空数据目录首次初始化时生效；修改 `.env` 不会自动修改已有数据库的账号或密码。

| 数据 | 默认位置 | 用途 |
| --- | --- | --- |
| 项目记录 | `projects.json` | 面板项目配置；缺失时读取 `projects.example.json` |
| 项目服务 | `docker-compose.yml` | 当前直接写入主文件，没有独立生成文件 |
| 后台任务 | `docker-panel/runtime/tasks/` | 状态和日志文件 |
| 数据服务 | `data/mysql`、`data/redis`、`data/rabbitmq` | 持久数据目录 |
| Go 缓存 | `go-mod-cache`、`go-build-cache` | Compose 命名卷 |

项目列表只展示 `type=go` 的记录，旧 PHP/Java 记录不会自动迁移。`projects.example.json` 是配置示例，不附带可直接运行的示例 Go 源码。

修改仓库 `.env` 后应重启 Go 面板，再重新创建受影响的业务容器，因为面板启动时会把配置读入进程环境。更改面板代码、Dockerfile 或编译依赖需重新构建镜像。

## 镜像与依赖下载

Docker 镜像加速器、Go 模块代理、系统软件源是三项独立配置：

- Docker Hub 拉取：在 Docker Desktop → Settings → Docker Engine 合并 `registry-mirrors`，保留已有 JSON 字段，然后应用配置。
- Go 项目模块：`.env` 中的 `GOPROXY`。
- Go 开发镜像的 Debian 软件包：`CHANGE_SOURCE=true` 时使用 Dockerfile 配置的国内软件源。

阿里云示例，实际应使用自己账号可用的加速地址：

```json
{
  "registry-mirrors": [
    "https://mlvdenum.mirror.aliyuncs.com"
  ]
}
```

`BASE_IMAGE_REGISTRY` 保持为空。Go 面板执行 Compose 时也会强制置空该变量。加速器能否拉到某个标签取决于镜像服务和网络；`manifest unknown`、`unknown blob` 或 TLS 错误需要分别检查标签、镜像服务、Docker 代理配置。

`Dockerfile.panel-go` 目前没有接入业务侧 `GOPROXY` 和 `CHANGE_SOURCE` 构建参数，因此这些变量不会自动改善面板自身构建时的模块或 Alpine 软件包下载。

## 开发与验证

```powershell
cd docker-panel
$env:GOCACHE = Join-Path (Get-Location) '.gocache'
go test -mod=readonly ./...
go vet -mod=readonly ./...
gofmt -l cmd internal
cd ..
docker compose --env-file .env.example config --quiet
```

```text
docker-panel/cmd/panel          进程入口、信号处理
docker-panel/internal/app       依赖组装与 HTTP 生命周期
docker-panel/internal/httpapi   路由、请求解析和响应
docker-panel/internal/project   项目管理、服务生成与启动流程
docker-panel/internal/compose   Compose 命令执行
docker-panel/internal/docker    Docker SDK 封装
docker-panel/internal/storage   JSON 项目存储
docker-panel/internal/task      后台任务与日志
docker-panel/internal/web       静态页面与内嵌兜底页
docker-panel/public             当前 Go 面板前端
docker-panel-legacy-php         PHP 旧版备份目录
services/go                    Go 业务项目开发镜像
```

后端接口与开发细节见 [面板 README](docker-panel/README.md)，架构背景见 [Go 技术文档](docs/GO_BACKEND_REFACTOR.md)。`PROJECTS.md` 目前仍是旧版项目说明，不适用于 Go 接入。

## 当前边界

- 面板挂载 Docker socket，具有 Docker 管理权限；当前没有有效的 API 鉴权，`PANEL_AUTH_ENABLED=true` 也尚未接入鉴权逻辑，只用于可信本机环境。
- “停止全部”目前可能停止其他 Compose 项目的容器，暂勿用于多项目 Docker 引擎；单项目停止接口只停止专属 Go 服务。
- 面板端口限定在 `127.0.0.1`，但业务和数据服务端口目前未统一限定回环地址，不能据此认为所有服务都只对本机开放。
- 任务成功目前只确认容器 running，不确认业务 HTTP 就绪；构建输出在子命令结束后才写入日志，暂不是真正的逐行实时构建日志。
- 项目文件尚无完整并发写入保护、事务回滚；任务尚无同项目互斥、取消和重启恢复。
- `.env` 虽列在 `.gitignore` 中，但当前仍被 Git 跟踪；忽略规则不会撤销已有跟踪，提交前必须检查暂存内容。
- PHP 备份目录和旧 `services/php-fpm`、`services/workspace`、`services/java` 仅保留作历史参考，主 Compose 不启用这些旧运行时。

完整问题清单、定位和建议修复顺序见 [2026-09-06 检查报告](docs/GO_CODE_REVIEW_2026-09-06.md)。
