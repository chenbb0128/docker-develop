#!/bin/sh
set -eu

cd /var/www/docker-panel
unset PHP_VERSION

: "${COMPOSER_REPO_PACKAGIST:=https://mirrors.aliyun.com/composer/}"

if ! command -v composer >/dev/null 2>&1; then
    echo "Composer is not installed in docker-panel container." >&2
    exit 1
fi

composer config -g repo.packagist composer "$COMPOSER_REPO_PACKAGIST" >/dev/null 2>&1 || true

if [ ! -f vendor/autoload.php ]; then
    echo "docker-panel vendor/autoload.php not found, running composer install..."
    composer install --no-interaction --prefer-dist --no-progress
fi

exec php bin/hyperf.php start