# Docker Develop 本地开发环境

Docker Develop 现在按 Go-only 方式运行：面板后端是 Go，新建项目只生成 Go 服务。Go 项目直接通过宿主机端口访问，本地默认不需要 Nginx。

## 当前目标

- 打开面板：`http://localhost:9501`
- 新建 Go 项目后，点击启动即可自动生成 compose 服务、构建镜像、启动依赖并显示实时日志。
- Redis、MySQL、RabbitMQ 作为可选依赖，由项目创建表单勾选。
- 阿里云镜像加速器配置在 Docker Desktop 的 Docker Engine `registry-mirrors`，不要写进 `.env` 的 `BASE_IMAGE_REGISTRY`。

## 启动面板

```bash
docker-compose up -d docker-panel
# 或
docker compose up -d docker-panel
```

`docker-compose.yml` 中的 `docker-panel` 服务会构建 `docker-panel/Dockerfile.panel-go`，并绑定：

```text
127.0.0.1:9501 -> docker-panel:9501
```

## Go 项目接入流程

1. 打开 `http://localhost:9501`。
2. 点击新增 Go 项目。
3. 填写项目标识、容器内项目路径和宿主机访问端口。
4. 勾选需要的依赖服务，例如 Redis、MySQL、RabbitMQ。
5. 保存后点击项目卡片上的启动。

Go 项目生成的服务会使用：

```text
services/go/Dockerfile
宿主机端口 -> 容器 8080
/go/pkg/mod -> go-mod-cache
/home/developer/.cache/go-build -> go-build-cache
```

默认启动命令会自动判断入口：

```text
go run .
go run ./cmd/server
go run ./cmd/api
go run ./cmd/app
```

如果都不存在，会扫描第一个 `main` package 并执行。

## 常用配置

`.env` 是本机配置，建议保留：

```env
HOST_PROJECT_PATH=C:\Develop
CONTAINER_PROJECT_PATH=/develop
GOPROXY=https://goproxy.cn,direct
GO_VERSION=1.25
BASE_IMAGE_REGISTRY=
```

如果你使用阿里云镜像加速器，请放到 Docker Desktop：

```json
{
  "builder": {
    "gc": {
      "defaultKeepStorage": "20GB",
      "enabled": true
    }
  },
  "experimental": false,
  "registry-mirrors": [
    "https://mlvdenum.mirror.aliyuncs.com"
  ]
}
```

## 验证

开发侧验证：

```bash
cd docker-panel
go test -mod=mod ./...
go vet -mod=mod ./...
```

Compose 配置验证：

```bash
BASE_IMAGE_REGISTRY= docker-compose config --quiet
```

Windows PowerShell 可写成：

```powershell
$env:BASE_IMAGE_REGISTRY=''
docker-compose config --quiet
```

## 关键目录

```text
docker-panel/cmd/panel            Go 面板入口
docker-panel/internal/httpapi     HTTP API
docker-panel/internal/project     项目启动、任务和 compose 生成
docker-panel/internal/compose     docker compose 命令封装
docker-panel/internal/task        后台任务日志
docker-panel/public/index.html    单页前端
services/go                       Go 开发镜像
services/redis                    Redis 配置
services/mysql                    MySQL 配置
services/nginx                    静态文件服务，可选
```

## 说明

`docker-panel` 会挂载 `/var/run/docker.sock`，因此它拥有本机 Docker 管理权限。面板默认只绑定 `127.0.0.1`，不要暴露到局域网或公网。