# Docker Develop 本地开发环境

这是一个面向 Windows 本地开发的 Docker 工作环境，用来统一管理 PHP/Laravel、Hyperf、Go、Nginx、Redis 和本地可视化面板。

它的定位是个人本地开发工具，不建议暴露到局域网或公网，因为面板可以操作 Docker 容器、Nginx 站点和配置文件。当前 `docker-panel` 默认绑定到 `127.0.0.1:9501`，只给本机访问。

## 当前架构

- `docker-panel`：本地 Web 面板，地址 `http://localhost:9501`，当前默认免登录。
- `nginx`：普通 Web 入口，适合 Laravel、ThinkPHP、普通 PHP-FPM、静态站点。
- `php-fpm` / `php73-fpm` / `php80-fpm` / `php81-fpm` / `php83-fpm`：多 PHP 版本运行环境。
- `hyperf-*`：Hyperf 项目专属容器，一个 Hyperf 项目一个常驻容器。
- `go`：Go 开发工具容器，用于 `go run`、`go test`、`go build`。
- `redis`：公共 Redis 服务。
- `workspace`：备用工具箱，只有需要通用 Composer、Node、诊断命令时再启动。

## 路径映射

`.env` 里控制宿主机路径和容器路径：

```env
HOST_PROJECT_PATH=C:\Develop
CONTAINER_PROJECT_PATH=/develop
```

例如 Windows 项目：

```text
C:\Develop\Hua\Projects\YouQuanGou
```

在容器里对应：

```text
/develop/Hua/Projects/YouQuanGou
```

面板、Nginx、Projects 里建议都填写容器内路径，例如 `/develop/...`。

## 启动面板

先启动 Docker Desktop，然后在项目根目录执行：

```powershell
docker-compose up -d docker-panel
```

也可以双击：

```text
启动面板.bat
start-panel.bat
```

打开：

```text
http://localhost:9501
```

当前面板默认免登录，打开后直接进入控制台。

## 首页能力

首页现在主要分为几块：

- `环境健康检查`：检查 Docker、面板、Nginx、Redis、Projects、Sites 和配置中心状态。
- `Projects 项目启动器`：新增、启动、停止、重启、运行项目命令和删除项目环境。
- `快捷环境`：一键启动 PHP / Go 常用组合。
- `容器列表`：查看、启动、停止、重启、日志和删除容器。
- `站点中心`：创建和管理 Nginx 本地站点。
- `配置中心`：编辑、验证、备份和恢复面板配置文件。

## 新增项目

推荐优先使用首页 `Projects 项目启动器` 的 `新增项目`。

新增项目有两种接入方式：

- `完整接入环境（推荐）`：写入项目卡片，并按类型生成 Nginx 或 docker-compose 环境配置。
- `仅添加管理卡片`：只保存 `projects.json` 里的项目卡片，适合环境已经手动配置好的项目。

不同类型的行为：

- Laravel / PHP-FPM：写入 `projects.json`、Nginx site、Nginx 端口映射。
- Static：写入 `projects.json`、Nginx site、Nginx 端口映射。
- Hyperf：写入 `projects.json`，并在 `docker-compose.yml` 中生成项目专属容器。

推荐流程：

1. 打开 `http://localhost:9501`。
2. 在 `Projects 项目启动器` 点击 `新增项目`。
3. 选择项目类型、接入方式、PHP 版本和服务。
4. 填写项目标识、项目路径、访问端口。
5. 先点 `预览配置`，确认会写入哪些环境配置。
6. 确认无误后点 `生成并保存`。

这个过程不会删除或修改项目源码。

命令行脚手架仍然保留：

```powershell
.\scaffold.bat
```

一行命令示例：

```powershell
.\scaffold.bat laravel -Key youquangou -Name YouQuanGou -Path C:\Develop\Hua\Projects\YouQuanGou -Port 8002 -Php php83-fpm
```

## 站点中心

顶部点击 `站点` 打开 `Nginx 站点中心`。

站点中心用于管理 Nginx site，只操作环境配置，不操作业务项目源码。

创建站点时可以选择：

- `Laravel / PHP`：root 通常是项目的 `public` 目录。
- `Static 静态站点`：root 通常是静态文件目录本身。
- `普通 PHP`：root 是入口文件所在目录。

访问方式：

- `端口访问（推荐）`：例如 `http://localhost:8002`。
- `域名访问`：例如 `http://classmate.test`，需要写入 Windows hosts。

新增端口映射后需要重启 Nginx 容器；只改 Nginx site 时重载 Nginx 即可。

## 配置中心

顶部点击 `配置` 打开 `配置中心`。

配置中心按分组管理：

- 核心配置：`docker-compose.yml`、`.env`、`Makefile`
- 项目配置：`projects.json`、`projects.example.json`、`PROJECTS.md`
- Nginx 配置：`nginx.conf`、`services/nginx/sites/*.conf`
- PHP 配置：各版本 `php.ini`、`opcache.ini`、`xdebug.ini`
- Redis 配置：`redis.conf`

保存配置前会自动备份。增强接口加载后，还支持备份列表、恢复备份和按文件类型验证。新增后端控制器后需要重启 `docker-panel` 才会加载增强接口。

## Laravel / 普通 PHP 项目

普通 Web 项目共用 `nginx + php-fpm + redis`。

PHP 8.3 示例：

```powershell
docker-compose up -d nginx php83-fpm redis docker-panel
```

PHP 8.1 示例：

```powershell
docker-compose up -d nginx php81-fpm redis docker-panel
```

Nginx site 的根目录通常指向 `public`：

```text
/develop/Hua/Projects/YouQuanGou/public
```

Projects 卡片的工作目录则填写项目根目录：

```text
/develop/Hua/Projects/YouQuanGou
```

访问示例：

```text
http://localhost:8002
```

## Hyperf 项目

Hyperf 项目建议一个项目一个容器，不和 Laravel/PHP-FPM 共用常驻进程容器。

容器端口规则：

```text
容器内监听：0.0.0.0:9501
宿主机访问：localhost:9502
端口映射：9502:9501
```

启动 ClassMate 示例：

```powershell
docker-compose build hyperf-classmate
docker-compose up -d hyperf-classmate redis docker-panel
```

如果访问不到，优先检查 Hyperf 是否监听 `0.0.0.0:9501`，不要只监听 `127.0.0.1`。

Hyperf/Swoole 容器默认关闭 Xdebug。Xdebug 和 Swoole 同时启用时可能导致 worker signal=11 崩溃，所以 Hyperf 专属容器的 compose 配置会设置 `XDEBUG_MODE=off`，并建议 `WORKSPACE_INSTALL_XDEBUG=false`。

## Go 开发环境

Go 环境由 `go` 服务提供，默认配置在 `.env`：

```env
GO_VERSION=1.25
GOPROXY=https://goproxy.cn,direct
GO_HOST_PORT=8081
GO_DEBUG_PORT=2345
GO_CGO_ENABLED=1
GO_INSTALL_DELVE=false
```

第一次使用先构建：

```powershell
docker-compose build go
```

启动 Go 开发环境：

```powershell
docker-compose up -d go redis docker-panel
```

查看版本：

```powershell
docker-compose exec go go version
```

进入容器：

```powershell
docker-compose exec go sh
```

在某个 Go 项目里执行命令：

```powershell
docker-compose exec -w /develop/path/to/go-project go go mod tidy
docker-compose exec -w /develop/path/to/go-project go go test ./...
docker-compose exec -w /develop/path/to/go-project go go run .
```

如果你的 Go Web 服务监听容器内 `8080`，宿主机默认访问：

```text
http://localhost:8081
```

如果需要 Delve 调试器，把 `.env` 改成：

```env
GO_INSTALL_DELVE=true
```

然后重新构建：

```powershell
docker-compose build go
```

## workspace 的用途

`workspace` 现在不是主开发路径，只作为备用工具箱。

日常建议：

- Laravel / PHP：用对应 `php*-fpm` 容器执行项目命令。
- Hyperf：用项目自己的 `hyperf-*` 容器。
- Go：用 `go` 容器。
- workspace：只在需要一个临时通用工具箱时启动。

启动 workspace：

```powershell
docker-compose up -d workspace
```

## 删除项目环境

Projects 卡片里的 `Delete` 是环境清理，不删除项目源码。

Hyperf 项目默认可以清理：

- 专属 `hyperf-*` 容器。
- `docker-compose.yml` 中面板生成的 Hyperf service block。
- `projects.json` 项目记录。

Laravel/PHP/static 项目使用共享容器，不会删除 `nginx`、`php-fpm` 或 `redis`。可选清理：

- 备份并删除 `services/nginx/sites/<key>.conf`。
- 在确认没有其它项目或站点使用该端口时移除 Nginx 端口映射。
- 删除 `projects.json` 项目记录。

面板没有删除业务源码目录的 UI 或 API。

## 常用命令

查看面板状态：

```powershell
docker-compose ps docker-panel
```

查看所有服务：

```powershell
docker-compose ps
```

查看面板日志：

```powershell
docker-compose logs -f docker-panel
```

重启面板：

```powershell
docker-compose restart docker-panel
```

停止全部容器：

```powershell
docker-compose down
```

检查 compose 配置：

```powershell
docker-compose config
```

## 常见问题

### 面板打不开

先检查容器：

```powershell
docker-compose ps docker-panel
```

没有运行就启动：

```powershell
docker-compose up -d docker-panel
```

### 新站点访问不了

按顺序检查：

1. Nginx site 根目录是否是容器内路径。
2. 对应 PHP 容器是否启动。
3. 新增端口后是否重启过 Nginx 容器。
4. 端口是否已经写入 `docker-compose.yml` 的 `nginx.ports`。
5. 域名访问是否写入 Windows hosts。

### Composer 报 dubious ownership

面板项目命令会自动执行 `git config --global --add safe.directory` 预处理。如果你手动进入容器执行命令，可以自己执行：

```bash
git config --global --add safe.directory /develop/your-project
```

### Go 依赖下载慢

默认使用：

```env
GOPROXY=https://goproxy.cn,direct
```

如果你想用官方代理，可以改成：

```env
GOPROXY=https://proxy.golang.org,direct
```

改完后重建 Go 容器。

## 安全提醒

当前面板为了本地开发方便，默认免登录。请只在本机使用，不要把 `9501` 暴露到公网或不可信局域网。

当前 compose 已将面板绑定到：

```yaml
127.0.0.1:9501:9501
```