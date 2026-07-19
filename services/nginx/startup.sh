#!/bin/bash
set -e

PHP_UPSTREAM_CONTAINER=${PHP_UPSTREAM_CONTAINER:-php83-fpm}
PHP_UPSTREAM_PORT=${PHP_UPSTREAM_PORT:-9000}
printf 'upstream php-upstream { server %s:%s; }\n' "$PHP_UPSTREAM_CONTAINER" "$PHP_UPSTREAM_PORT" > /etc/nginx/conf.d/upstream.conf

if [ ! -f /etc/nginx/ssl/default.crt ]; then
    openssl genrsa -out "/etc/nginx/ssl/default.key" 2048
    openssl req -new -key "/etc/nginx/ssl/default.key" -out "/etc/nginx/ssl/default.csr" -subj "/CN=default/O=default/C=UK"
    openssl x509 -req -days 365 -in "/etc/nginx/ssl/default.csr" -signkey "/etc/nginx/ssl/default.key" -out "/etc/nginx/ssl/default.crt"
    chmod 644 /etc/nginx/ssl/default.key
fi

# Start crond in background
crond -l 2 -b

# Start nginx in foreground
nginx
