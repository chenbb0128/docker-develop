# 项目启动器使用说明

这个面板的推荐用法是：

- Laravel / 普通 PHP 项目共用 `nginx + php-fpm` 容器。
- Hyperf 项目使用一个项目一个独立容器，例如 `hyperf-order-api`。
- `workspace` 只作为备用工具箱；项目命令优先在项目配置的 PHP 服务里执行。

也可以用根目录向导快速写入项目配置：

```powershell
.\scaffold.bat
```

## Hyperf 项目

1. 确认 `.env` 里的项目挂载路径，例如：

```env
HOST_PROJECT_PATH=D:/Projects
CONTAINER_PROJECT_PATH=/develop
WORKSPACE_INSTALL_SWOOLE=true
```

2. Windows 项目目录：

```text
D:/Projects/order-api
```

3. 面板项目路径填写容器内路径：

```text
/develop/order-api
```

4. 推荐配置：

```json
{
  "key": "order-api",
  "name": "Order API",
  "type": "hyperf",
  "path": "/develop/order-api",
  "port": 9502,
  "php": "php83-fpm",
  "service": "hyperf-order-api",
  "services": ["hyperf-order-api", "redis"],
  "command": "if php bin/hyperf.php list 2>/dev/null | grep -q \"server:watch\"; then php bin/hyperf.php server:watch; else php bin/hyperf.php start; fi",
  "log": "runtime/logs/hyperf.log",
  "url": "http://localhost:9502"
}
```

推荐用 `.\scaffold.bat` 创建 Hyperf 项目，它会自动写入 `projects.json`，并在 `docker-compose.yml` 里生成项目专属 service。

Hyperf 项目容器默认按 `宿主机端口:容器内端口` 映射。推荐让 Hyperf 在容器内监听 `0.0.0.0:9501`，例如宿主机 `9502` 映射到容器内 `9501`，这样宿主机通过 `http://localhost:9502` 访问。

## 常用按钮

- `Start`: 启动项目配置里的服务。Hyperf 会启动它自己的项目容器；本地开发默认优先使用 `server:watch` 热更新，项目未安装 watcher 时自动回退到 `start`；如果缺少 `vendor/autoload.php` 会先自动执行 `composer install`。
- `Stop`: 停止 Hyperf 项目的专属容器。
- `Restart`: 重启 Hyperf 项目的专属容器和依赖服务。
- `PHP`: 在项目目录执行 `php -v`。
- `Composer`: 在项目目录执行 `composer install`。
- `Migrate`: Hyperf 执行 `php bin/hyperf.php migrate`，Laravel 执行 `php artisan migrate`。
- `DI`: Hyperf 执行 `php bin/hyperf.php di:init-proxy`。
- `Logs`: 查看 Hyperf 项目容器日志。
- `Run...`: 在项目目录执行自定义命令。

## 普通 Laravel / PHP-FPM 项目

普通 Web 项目仍然走 `nginx + php-fpm`。先在 `Sites` 面板里创建 Nginx 站点，再在 `Projects` 里添加项目卡片。Projects 路径填写项目根目录，命令会优先在项目配置的 `php` 服务里执行；只有没配置 PHP 服务时才回退到 `workspace`。
