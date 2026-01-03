FROM php:8.1-cli-alpine

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
RUN docker-php-ext-install pcntl

# 清理
RUN apk del $PHPIZE_DEPS linux-headers \
    && rm -rf /var/cache/apk/* /tmp/*

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 设置工作目录
WORKDIR /var/www/docker-panel

# 默认命令
CMD ["php", "bin/hyperf.php", "start"]
