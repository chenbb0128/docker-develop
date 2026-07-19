ARG DOCKER_PANEL_PHP_IMAGE=php:8.3-cli-alpine
ARG DOCKER_PANEL_COMPOSER_IMAGE=composer:2
FROM ${DOCKER_PANEL_COMPOSER_IMAGE} AS composer-bin

FROM ${DOCKER_PANEL_PHP_IMAGE}

# 安装系统依赖
RUN apk add --no-cache \
    curl \
    bash \
    docker-cli \
    docker-cli-compose \
    git \
    linux-headers \
    openssl-dev \
    curl-dev \
    $PHPIZE_DEPS

# 安装 Swoole 扩展
RUN pecl install swoole \
    && docker-php-ext-enable swoole

# 安装 pcntl 扩展
RUN docker-php-ext-install pcntl sockets

# 清理
RUN apk del $PHPIZE_DEPS linux-headers \
    && rm -rf /var/cache/apk/* /tmp/*

# 安装 Composer
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ARG COMPOSER_REPO_PACKAGIST=https://mirrors.aliyun.com/composer/
RUN composer config -g repo.packagist composer ${COMPOSER_REPO_PACKAGIST}

# Alpine 的 docker-cli-compose 通常只提供 `docker compose` 插件；保留 docker-compose 兼容命令给面板后端使用。
RUN if ! command -v docker-compose >/dev/null 2>&1; then \
        printf '%s\n' '#!/bin/sh' 'exec docker compose "$@"' > /usr/local/bin/docker-compose; \
        chmod +x /usr/local/bin/docker-compose; \
    fi

# 设置工作目录
WORKDIR /var/www/docker-panel

# Keep the entrypoint inside the image so Windows bind mounts cannot reintroduce CRLF.
COPY start-panel.sh /usr/local/bin/start-panel
RUN sed -i 's/\r$//' /usr/local/bin/start-panel \
    && chmod +x /usr/local/bin/start-panel

# 默认命令
CMD ["sh", "/usr/local/bin/start-panel"]
