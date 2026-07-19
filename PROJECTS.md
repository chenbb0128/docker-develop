# Projects 项目启动器

Projects 用来把业务项目接入本地 Docker Develop 环境。它只写环境配置和管理卡片，不会修改业务项目源码。

推荐优先使用面板首页的 `新增项目`，命令行向导作为备用入口：

```powershell
.\scaffold.bat
```

## 配置文件

- `projects.example.json`：仓库默认示例，新 clone 时用于生成初始项目卡片。
- `projects.json`：本机运行状态文件，面板会自动生成，已经从仓库中移除并加入 `.gitignore`。
- `docker-compose.yml`：Hyperf 项目的专属容器配置会写入带 `BEGIN/END Hyperf project` 标记的 service block。

如果 `projects.json` 丢失，面板会先加载 `projects.example.json`，再合并 `docker-compose.yml` 里带标记的 Hyperf 项目。

## 路径规则

面板里建议填写容器路径，例如：

```text
/develop/company/order-api
```

`.env` 负责把宿主机项目根目录映射到容器：

```env
CONTAINER_PROJECT_PATH=/develop
```

不同平台的 `HOST_PROJECT_PATH` 示例：

```env
# Windows PowerShell / CMD
HOST_PROJECT_PATH=D:\Develop

# WSL 访问 Windows D 盘
HOST_PROJECT_PATH=/mnt/d/Develop

# WSL Linux 文件系统
HOST_PROJECT_PATH=/home/you/Develop

# macOS
HOST_PROJECT_PATH=/Users/you/Develop
```

## Laravel / 普通 PHP-FPM / 静态项目

这类项目共用 `nginx + php*-fpm + redis`。新增项目时面板可以同时生成：

- 项目卡片
- Nginx site 配置
- Nginx 端口映射

PHP 8.3 是默认推荐版本；旧项目再单独选择 `php81-fpm`、`php80-fpm` 或 `php73-fpm`。

## Hyperf 项目

Hyperf 使用“一项目一容器”。新增 Hyperf 项目时，面板会在 `docker-compose.yml` 里生成专属 service，例如 `hyperf-order-api`。

默认启动命令会自动判断是否安装 watcher：

```bash
if php bin/hyperf.php list 2>/dev/null | grep -q "server:watch"; then php bin/hyperf.php server:watch; else php bin/hyperf.php start; fi
```

启动前还会自动处理：

- `git safe.directory`，避免 Windows/WSL 挂载目录触发 Git ownership 警告。
- 项目有 `composer.json` 但缺少 `vendor/autoload.php` 时自动执行 `composer install`。
- Composer 默认使用阿里云源。

Hyperf 项目建议监听容器内 `0.0.0.0:9501`，宿主机用项目端口访问，例如：

```text
http://localhost:9502
```

## 常用操作

- `启动`：启动项目依赖服务；Hyperf 会启动专属容器。
- `停止`：停止 Hyperf 专属容器；共享 PHP-FPM 项目不会停掉公共容器。
- `重启`：重启项目相关服务。
- `PHP 版本`：在项目目录执行 `php -v`。
- `Composer`：在项目目录执行 `composer install`。
- `迁移`：Hyperf 执行 `php bin/hyperf.php migrate`，Laravel 执行 `php artisan migrate`。
- `日志`：查看 Hyperf 项目容器日志。
- `删除环境`：只清理 Docker/Nginx/面板配置，不删除项目源码。
