FROM php:8.3-cli-alpine

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
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ARG COMPOSER_REPO_PACKAGIST=https://mirrors.aliyun.com/composer/
RUN composer config -g repo.packagist composer ${COMPOSER_REPO_PACKAGIST}

# 设置工作目录
WORKDIR /var/www/docker-panel

# Keep the entrypoint inside the image so Windows bind mounts cannot reintroduce CRLF.
COPY start-panel.sh /usr/local/bin/start-panel
RUN sed -i 's/\r$//' /usr/local/bin/start-panel \
    && chmod +x /usr/local/bin/start-panel

# 默认命令
CMD ["sh", "/usr/local/bin/start-panel"]
