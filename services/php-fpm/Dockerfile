ARG PHP_VERSION
FROM php:${PHP_VERSION}-fpm

ARG CHANGE_SOURCE=false
RUN if [ "${CHANGE_SOURCE}" == "true" ]; then \
  # 检查 sources.list 是否存在且非空
  if [ ! -f /etc/apt/sources.list ] || [ ! -s /etc/apt/sources.list ]; then \
    echo "deb http://deb.debian.org/debian bullseye main" > /etc/apt/sources.list && \
    echo "deb http://security.debian.org/debian-security bullseye-security main" >> /etc/apt/sources.list && \
    echo "deb http://deb.debian.org/debian bullseye-updates main" >> /etc/apt/sources.list; \
  fi; \
  # 检查源是否已被更改，如果没有，则更改源
  if ! grep -q "mirrors.tuna.tsinghua.edu.cn" /etc/apt/sources.list; then \
    sed -i 's/deb.debian.org/mirrors.tuna.tsinghua.edu.cn/' /etc/apt/sources.list && \
    sed -i 's/security.debian.org/mirrors.tuna.tsinghua.edu.cn/' /etc/apt/sources.list && \
    sed -i 's/security-cdn.debian.org/mirrors.tuna.tsinghua.edu.cn/' /etc/apt/sources.list; \
  fi; \
fi

# 必要的软件安装
RUN set -xe; \
    apt-get update -yqq && \
    pecl channel-update pecl.php.net && \
    apt-get install -yqq \
      apt-utils \
      gnupg2 \
      git \
      libzip-dev zip unzip && \
      if [ ${PHP_VERSION} = "7.3" ] || [ ${PHP_VERSION} = "7.4" ] || [ $(php -r "echo PHP_MAJOR_VERSION;") = "8" ]; then \
        docker-php-ext-configure zip; \
      else \
        docker-php-ext-configure zip --with-libzip; \
      fi && \
      docker-php-ext-install zip && \
      php -m | grep -q 'zip'


# 可选软件插件支持安装

###########################################################################
# xDebug:
###########################################################################
ARG INSTALL_XDEBUG=false
ARG XDEBUG_PORT=9003

RUN if [ ${INSTALL_XDEBUG} = true ]; then \
  # Install the xdebug extension
  # https://xdebug.org/docs/compat
  if [ $(php -r "echo PHP_MAJOR_VERSION;") = "8" ] || { [ $(php -r "echo PHP_MAJOR_VERSION;") = "7" ] && { [ $(php -r "echo PHP_MINOR_VERSION;") = "4" ] || [ $(php -r "echo PHP_MINOR_VERSION;") = "3" ] ;} ;}; then \
    if [ $(php -r "echo PHP_MAJOR_VERSION;") = "8" ]; then \
      pecl install xdebug-3.3.0; \
    else \
      pecl install xdebug-3.1.6; \
    fi; \
  else \
    if [ $(php -r "echo PHP_MAJOR_VERSION;") = "5" ]; then \
      pecl install xdebug-2.5.5; \
    else \
      if [ $(php -r "echo PHP_MINOR_VERSION;") = "0" ]; then \
        pecl install xdebug-2.9.0; \
      else \
        pecl install xdebug-2.9.8; \
      fi \
    fi \
  fi && \
  docker-php-ext-enable xdebug \
;fi

# xdebug 配置文件修改
COPY ./conf.d/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

RUN if [ $(php -r "echo PHP_MAJOR_VERSION;") = "8" ] || { [ $(php -r "echo PHP_MAJOR_VERSION;") = "7" ] && { [ $(php -r "echo PHP_MINOR_VERSION;") = "4" ] || [ $(php -r "echo PHP_MINOR_VERSION;") = "3" ] ;} ;}; then \
  sed -i "s/xdebug.remote_host=/xdebug.client_host=/" /usr/local/etc/php/conf.d/xdebug.ini && \
  sed -i "s/xdebug.remote_connect_back=0/xdebug.discover_client_host=false/" /usr/local/etc/php/conf.d/xdebug.ini && \
  sed -i "s/xdebug.remote_port=9000/xdebug.client_port=${XDEBUG_PORT}/" /usr/local/etc/php/conf.d/xdebug.ini && \
  sed -i "s/xdebug.profiler_enable=0/; xdebug.profiler_enable=0/" /usr/local/etc/php/conf.d/xdebug.ini && \
  sed -i "s/xdebug.profiler_output_dir=/xdebug.output_dir=/" /usr/local/etc/php/conf.d/xdebug.ini && \
  sed -i "s/xdebug.remote_mode=req/; xdebug.remote_mode=req/" /usr/local/etc/php/conf.d/xdebug.ini && \
  sed -i "s/xdebug.remote_autostart=0/xdebug.start_with_request=yes/" /usr/local/etc/php/conf.d/xdebug.ini && \
  sed -i "s/xdebug.remote_enable=0/xdebug.mode=debug/" /usr/local/etc/php/conf.d/xdebug.ini \
;else \
  sed -i "s/xdebug.remote_autostart=0/xdebug.remote_autostart=1/" /usr/local/etc/php/conf.d/xdebug.ini && \
  sed -i "s/xdebug.remote_enable=0/xdebug.remote_enable=1/" /usr/local/etc/php/conf.d/xdebug.ini \
;fi
RUN sed -i "s/xdebug.cli_color=0/xdebug.cli_color=1/" /usr/local/etc/php/conf.d/xdebug.ini

###########################################################################
# PHP REDIS EXTENSION
###########################################################################
ARG INSTALL_PHPREDIS=false

RUN if [ ${INSTALL_PHPREDIS} = true ]; then \
    # Install Php Redis Extension
    if [ $(php -r "echo PHP_MAJOR_VERSION;") = "5" ]; then \
      pecl install -o -f redis-4.3.0; \
    elif [ $(php -r "echo PHP_MAJOR_VERSION;") = "7" ] && { [ $(php -r "echo PHP_MINOR_VERSION;") = "0" ] || [ $(php -r "echo PHP_MINOR_VERSION;") = "1" ] ;}; then \
      pecl install -o -f redis-5.3.7; \
    else \
      pecl install -o -f redis; \
    fi \
    && rm -rf /tmp/pear \
    && docker-php-ext-enable redis \
;fi

###########################################################################
# Swoole EXTENSION
###########################################################################
ARG INSTALL_SWOOLE=false
RUN set -eux; \
    if [ ${INSTALL_SWOOLE} = true ]; then \
      # Install Php Swoole Extension
      if   [ $(php -r "echo PHP_VERSION_ID - PHP_RELEASE_VERSION;") = "50600" ]; then \
        pecl install swoole-2.0.10; \
      elif [ $(php -r "echo PHP_VERSION_ID - PHP_RELEASE_VERSION;") = "70000" ]; then \
        pecl install swoole-4.3.5; \
      elif [ $(php -r "echo PHP_VERSION_ID - PHP_RELEASE_VERSION;") = "70100" ]; then \
        pecl install swoole-4.5.11; \
      elif [ $(php -r "echo PHP_MAJOR_VERSION;") = "7" ]; then \
        pecl install swoole-4.8.12; \
      else \
        pecl install swoole; \
      fi; \
      docker-php-ext-enable swoole; \
      php -m | grep -q 'swoole'; \
    fi

###########################################################################
# MongoDB:
###########################################################################

ARG INSTALL_MONGO=false

RUN if [ ${INSTALL_MONGO} = true ]; then \
    # Install the mongodb extension
    if [ $(php -r "echo PHP_MAJOR_VERSION;") = "5" ]; then \
      pecl install mongo; \
      docker-php-ext-enable mongo; \
      php -m | grep -oiE '^mongo$'; \
    else \
      if [ $(php -r "echo PHP_MAJOR_VERSION;") = "7" ] && [ $(php -r "echo PHP_MINOR_VERSION;") != "4" ]; then \
        if [ $(php -r "echo PHP_MINOR_VERSION;") = "0" ] || [ $(php -r "echo PHP_MINOR_VERSION;") = "1" ]; then \
          pecl install mongodb-1.9.2; \
        else \
          pecl install mongodb-1.16.2; \
        fi; \
      else \
        pecl install mongodb; \
      fi; \
      docker-php-ext-enable mongodb; \
      php -m | grep -oiE '^mongodb$'; \
    fi; \
fi

###########################################################################
# bcmath:
###########################################################################

ARG INSTALL_BCMATH=false

RUN if [ ${INSTALL_BCMATH} = true ]; then \
    # Install the bcmath extension
    docker-php-ext-install bcmath \
;fi

###########################################################################
# PHP Memcached:
###########################################################################

ARG INSTALL_MEMCACHED=false

RUN if [ ${INSTALL_MEMCACHED} = true ]; then \
    # Install the php memcached extension
    if [ $(php -r "echo PHP_MAJOR_VERSION;") = "5" ]; then \
      echo '' | pecl -q install memcached-2.2.0; \
    else \
      echo '' | pecl -q install memcached; \
    fi \
    && docker-php-ext-enable memcached \
;fi

###########################################################################
# Opcache:
###########################################################################

ARG INSTALL_OPCACHE=false

RUN if [ ${INSTALL_OPCACHE} = true ]; then \
    docker-php-ext-install opcache \
;fi

# Copy opcache configration
COPY ./conf.d/opcache.ini /usr/local/etc/php/conf.d/opcache.ini


###########################################################################
# Mysqli Modifications:
###########################################################################

ARG INSTALL_MYSQLI=false

RUN if [ ${INSTALL_MYSQLI} = true ]; then \
    docker-php-ext-install mysqli \
;fi

###########################################################################
# Image optimizers:
###########################################################################

USER root

ARG INSTALL_IMAGE_OPTIMIZERS=false

RUN if [ ${INSTALL_IMAGE_OPTIMIZERS} = true ]; then \
    apt-get install -yqq jpegoptim optipng pngquant gifsicle \
;fi

###########################################################################
# ImageMagick:
###########################################################################

USER root

ARG INSTALL_IMAGEMAGICK=false
ARG IMAGEMAGICK_VERSION=latest
ENV IMAGEMAGICK_VERSION ${IMAGEMAGICK_VERSION}

RUN if [ ${INSTALL_IMAGEMAGICK} = true ]; then \
    apt-get update && \
    apt-get install -yqq libmagickwand-dev imagemagick && \
    if [ $(php -r "echo PHP_MAJOR_VERSION;") = "8" ]; then \
      cd /tmp && \
      if [ ${IMAGEMAGICK_VERSION} = "latest" ]; then \
        git clone https://github.com/Imagick/imagick; \
      else \
        git clone --branch ${IMAGEMAGICK_VERSION} https://github.com/Imagick/imagick; \
      fi && \
      cd imagick && \
      phpize && \
      ./configure && \
      make && \
      make install && \
      rm -r /tmp/imagick; \
    else \
      pecl install imagick; \
    fi && \
    docker-php-ext-enable imagick; \
    php -m | grep -q 'imagick' \
;fi

###########################################################################
# FFMPEG:
###########################################################################

USER root

ARG INSTALL_FFMPEG=false

RUN if [ ${INSTALL_FFMPEG} = true ]; then \
    apt-get update -y && \
    apt-get -y install ffmpeg \
;fi

###########################################################################
# Exif:
###########################################################################

ARG INSTALL_EXIF=false

RUN if [ ${INSTALL_EXIF} = true ]; then \
    # Enable Exif PHP extentions requirements
    docker-php-ext-install exif \
;fi

###########################################################################
# Phalcon:
###########################################################################

ARG INSTALL_PHALCON=false
ARG PHALCON_VERSION
ENV PHALCON_VERSION ${PHALCON_VERSION}

RUN if [ $INSTALL_PHALCON = true ]; then \
      apt-get update -yqq \
      && pecl channel-update pecl.php.net \
      && apt-get install -yqq libpcre3-dev; \
      pecl install phalcon-${PHALCON_VERSION}; \
      docker-php-ext-enable phalcon \
  ;fi

###########################################################################
# ping:
###########################################################################

USER root

ARG INSTALL_PING=false

RUN if [ ${INSTALL_PING} = true ]; then \
    apt-get -y install inetutils-ping \
;fi


###########################################################################
# PHP DECIMAL:
###########################################################################

USER root

ARG INSTALL_PHPDECIMAL=false

RUN if [ ${INSTALL_PHPDECIMAL} = true ]; then \
  if [ $(php -r "echo PHP_MAJOR_VERSION;") = "5" ]; then \
    echo 'decimal not support PHP 5.6'; \
  else \
    if [ $(php -r "echo PHP_MAJOR_VERSION;") = "8" ] && \
        [ ${PHP_VERSION} != "8.0" ]; then \
      curl -L -o /tmp/mpdecimal.tar.gz "https://www.bytereef.org/software/mpdecimal/releases/mpdecimal-2.5.1.tar.gz"; \
      mkdir -p /tmp/mpdecimal; \
      tar -C /tmp/mpdecimal -zxvf /tmp/mpdecimal.tar.gz --strip 1; \
      cd /tmp/mpdecimal; \
      ./configure && make && make install; \
    else \
      apt-get install -yqq libmpdec-dev; \
    fi; \
    pecl install decimal; \
    docker-php-ext-enable decimal; \
    php -m | grep -oiE '^decimal$'; \
  fi; \
fi

###########################################################################
# bcmath:
###########################################################################

RUN docker-php-ext-install bcmath


###########################################################################
# Check PHP version:
###########################################################################

RUN set -xe; php -v | grep -q "PHP ${PHP_VERSION}."

USER root

# Clean up
RUN apt-get clean && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* && \
    rm -f /var/log/lastlog /var/log/faillog

ARG PUID=1000
ENV PUID ${PUID}
ARG PGID=1000
ENV PGID ${PGID}


RUN groupmod -o -g ${PGID} www-data && \
    usermod -o -u ${PUID} -g www-data www-data

ARG CONTAINER_PROJECT_PATH=/develop
WORKDIR "${CONTAINER_PROJECT_PATH}"

CMD ["php-fpm"]

EXPOSE 9000