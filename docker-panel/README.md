# Docker Panel

`docker-panel` 是 Docker Develop 的本地 Web 控制台，基于 Hyperf + Swoole。它只绑定到 `127.0.0.1:9501`，用于本机开发环境管理。

当前默认免登录，前端仍会请求 `/api/login` 获取 `local-dev` token，后端 `AuthController::AUTH_ENABLED=false`。

## 启动方式

推荐从仓库根目录启动：

```bash
# Windows
start-panel.bat

# WSL / macOS
bash ./start-panel.sh
```

也可以直接使用 Compose：

```bash
docker-compose up -d docker-panel
# 或
docker compose up -d docker-panel
```

容器启动时会自动：

- 设置 Composer 阿里云源。
- 如果缺少 `vendor/autoload.php`，执行 `composer install --no-interaction --prefer-dist --no-progress`。
- 启动 `php bin/hyperf.php start`。

## 主要接口

认证和状态：

```text
POST /api/login
POST /api/logout
GET  /api/check-auth
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

环境：

```text
GET  /api/env/presets
GET  /api/env/status
GET  /api/env/doctor
POST /api/env/start/{preset}
POST /api/env/stop-all
POST /api/terminal/start-env
```

Projects：

```text
GET    /api/projects
POST   /api/projects
POST   /api/project-scaffold
POST   /api/projects/{key}/start
POST   /api/projects/{key}/stop
POST   /api/projects/{key}/restart
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
GET    /api/config/center/files
GET    /api/config/center/read
POST   /api/config/center/save
POST   /api/config/center/validate
GET    /api/config/center/backups
POST   /api/config/center/restore
```

## 关键文件

```text
docker-panel/start-panel.sh              容器内启动脚本
docker-panel/public/index.html           单页前端
docker-panel/app/Controller/*Controller  后端接口
docker-panel/app/Service/DockerService.php Docker API 封装
docker-panel/config/autoload/server.php  Hyperf HTTP 服务配置
```

## 开发注意

- 新增或修改控制器后，需要重启 `docker-panel` 让 Hyperf 重新加载。
- 面板容器挂载 `/var/run/docker.sock`，拥有本机 Docker 管理权限，只能本机使用。
- 配置中心保存文件会先生成 `.bak.YmdHis` 备份。
- `projects.json` 是本机生成文件，不应提交到仓库。
