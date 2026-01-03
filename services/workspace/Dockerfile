ARG UBUNTU_VERSION=22.04
FROM ubuntu:${UBUNTU_VERSION}

LABEL maintainer="Docker Develop"

# 设置环境变量
ENV DEBIAN_FRONTEND=noninteractive
ENV TERM=xterm-256color

# 设置语言
RUN apt-get update && apt-get install -y locales \
    && locale-gen en_US.UTF-8

ENV LANGUAGE=en_US.UTF-8
ENV LC_ALL=en_US.UTF-8
ENV LC_CTYPE=en_US.UTF-8
ENV LANG=en_US.UTF-8

# 时区设置
ARG TZ=UTC
ENV TZ=${TZ}
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# 更换镜像源（可选）
ARG CHANGE_SOURCE=false
RUN if [ ${CHANGE_SOURCE} = true ]; then \
    sed -i 's/archive.ubuntu.com/mirrors.aliyun.com/' /etc/apt/sources.list && \
    sed -i 's/security.ubuntu.com/mirrors.aliyun.com/' /etc/apt/sources.list \
;fi

# 安装基础软件
RUN apt-get update && apt-get install -y --no-install-recommends \
    apt-utils \
    software-properties-common \
    curl \
    wget \
    zip \
    unzip \
    git \
    vim \
    nano \
    ca-certificates \
    gnupg \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

###########################################################################
# PHP
###########################################################################
ARG PHP_VERSION=8.1
RUN add-apt-repository ppa:ondrej/php -y \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php${PHP_VERSION}-cli \
        php${PHP_VERSION}-common \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-intl \
        php${PHP_VERSION}-xml \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-pgsql \
        php${PHP_VERSION}-sqlite3 \
        php${PHP_VERSION}-gd \
        php${PHP_VERSION}-imagick \
        php${PHP_VERSION}-bcmath \
        php${PHP_VERSION}-zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

###########################################################################
# PHP Redis 扩展
###########################################################################
ARG INSTALL_PHPREDIS=true
RUN if [ ${INSTALL_PHPREDIS} = true ]; then \
    apt-get update && apt-get install -y php${PHP_VERSION}-redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
;fi

###########################################################################
# MongoDB 扩展
###########################################################################
ARG INSTALL_MONGO=false
RUN if [ ${INSTALL_MONGO} = true ]; then \
    apt-get update && apt-get install -y php${PHP_VERSION}-mongodb \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
;fi

###########################################################################
# Swoole 扩展
###########################################################################
ARG INSTALL_SWOOLE=false
RUN if [ ${INSTALL_SWOOLE} = true ]; then \
    apt-get update && apt-get install -y php${PHP_VERSION}-swoole \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
;fi

###########################################################################
# Xdebug
###########################################################################
ARG INSTALL_XDEBUG=false
ARG XDEBUG_PORT=9003
RUN if [ ${INSTALL_XDEBUG} = true ]; then \
    apt-get update && apt-get install -y php${PHP_VERSION}-xdebug \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
;fi

###########################################################################
# Composer
###########################################################################
ARG INSTALL_COMPOSER=true
ARG COMPOSER_VERSION=2
ARG COMPOSER_REPO_PACKAGIST=https://mirrors.aliyun.com/composer/

RUN if [ ${INSTALL_COMPOSER} = true ]; then \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && if [ ${COMPOSER_REPO_PACKAGIST} ]; then \
        composer config -g repo.packagist composer ${COMPOSER_REPO_PACKAGIST} \
    ;fi \
;fi

###########################################################################
# Node.js
###########################################################################
ARG INSTALL_NODE=true
ARG NODE_VERSION=20

RUN if [ ${INSTALL_NODE} = true ]; then \
    curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y nodejs \
    && npm config set registry https://registry.npmmirror.com \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
;fi

###########################################################################
# Supervisor
###########################################################################
ARG INSTALL_SUPERVISOR=false

RUN if [ ${INSTALL_SUPERVISOR} = true ]; then \
    apt-get update && apt-get install -y supervisor \
    && mkdir -p /var/log/supervisor \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
;fi

COPY supervisord.conf /etc/supervisor/supervisord.conf

###########################################################################
# Oh My Zsh (可选)
###########################################################################
ARG SHELL_OH_MY_ZSH=false

RUN if [ ${SHELL_OH_MY_ZSH} = true ]; then \
    apt-get update && apt-get install -y zsh \
    && sh -c "$(curl -fsSL https://raw.githubusercontent.com/ohmyzsh/ohmyzsh/master/tools/install.sh)" "" --unattended \
    && chsh -s $(which zsh) \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
;fi

###########################################################################
# 用户配置
###########################################################################
ARG PUID=1000
ARG PGID=1000

RUN groupadd -g ${PGID} developer \
    && useradd -u ${PUID} -g developer -m developer \
    && usermod -p "*" developer -s /bin/bash

###########################################################################
# 工作目录
###########################################################################
ARG CONTAINER_PROJECT_PATH=/develop
WORKDIR ${CONTAINER_PROJECT_PATH}

# 确保目录权限
RUN chown -R developer:developer ${CONTAINER_PROJECT_PATH}

# 默认使用 developer 用户
USER developer

# 保持容器运行
CMD ["tail", "-f", "/dev/null"]