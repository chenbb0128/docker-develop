#!/bin/sh
set -e

APP_DIR=/var/www/docker-panel
cd "$APP_DIR"

# 消除 bind-mount 目录的 git "dubious ownership" 警告
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true

# 1. 缺 .env 就从 .env.example 复制
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        echo "[entrypoint] Created .env from .env.example"
    else
        echo "[entrypoint] WARNING: .env missing and no .env.example" >&2
    fi
fi

# 2. 缺 vendor 就装依赖（首次几十秒，之后跳过）
#    注意：不能加 --no-dev，Hyperf 的 devtool/command 在运行时（DI 扫描、proxy 初始化）也要用
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] Installing composer dependencies..."
    composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/
    composer install --no-interaction --optimize-autoloader
else
    echo "[entrypoint] Dependencies present, skipping composer install."
fi

# 3. 交给原命令（compose 里的 php bin/hyperf.php start）
exec "$@"
