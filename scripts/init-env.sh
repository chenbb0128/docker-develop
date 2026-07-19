#!/usr/bin/env bash
set -u

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
ENV_FILE="$ROOT_DIR/.env"
EXAMPLE_FILE="$ROOT_DIR/.env.example"
INIT_FLAG="$ROOT_DIR/data/.docker-develop-initialized"
FORCE=false
HOST_PROJECT_PATH_ARG=""
CONTAINER_PROJECT_PATH_ARG=""

while [ $# -gt 0 ]; do
    case "$1" in
        --force) FORCE=true ;;
        --host-project-path) shift; HOST_PROJECT_PATH_ARG="${1:-}" ;;
        --container-project-path) shift; CONTAINER_PROJECT_PATH_ARG="${1:-}" ;;
    esac
    shift || true
done

read_env_value() {
    local key="$1"
    [ -f "$ENV_FILE" ] || return 0
    awk -F= -v key="$key" '
        /^[[:space:]]*#/ { next }
        $1 == key {
            value = substr($0, index($0, "=") + 1)
            gsub(/^[[:space:]\"]+|[[:space:]\"]+$/, "", value)
            print value
            exit
        }
    ' "$ENV_FILE"
}

set_env_value() {
    local key="$1"
    local value="$2"
    local tmp
    tmp="$(mktemp)"
    if [ -f "$ENV_FILE" ]; then
        awk -v key="$key" -v value="$value" '
            BEGIN { done = 0 }
            $0 ~ "^[[:space:]]*" key "[[:space:]]*=" { print key "=" value; done = 1; next }
            { print }
            END { if (!done) { print ""; print key "=" value } }
        ' "$ENV_FILE" > "$tmp"
    else
        printf '%s=%s\n' "$key" "$value" > "$tmp"
    fi
    mv "$tmp" "$ENV_FILE"
}

set_env_default() {
    local key="$1"
    local value="$2"
    local overwrite="$3"
    local current
    current="$(read_env_value "$key")"
    if [ "$overwrite" = true ] || [ -z "$current" ]; then
        set_env_value "$key" "$value"
    fi
}

ensure_dir() {
    local path_value="$1"
    [ -n "$path_value" ] || return 0
    case "$path_value" in
        /*) mkdir -p "$path_value" ;;
        *) mkdir -p "$ROOT_DIR/$path_value" ;;
    esac
}

cd "$ROOT_DIR" || exit 1
created=false
if [ ! -f "$ENV_FILE" ]; then
    if [ ! -f "$EXAMPLE_FILE" ]; then
        printf 'Missing .env and .env.example.\n'
        exit 1
    fi
    cp "$EXAMPLE_FILE" "$ENV_FILE"
    created=true
    printf 'Created .env from .env.example.\n'
fi

ensure_dir data
ensure_dir logs
ensure_dir logs/nginx
ensure_dir services/nginx/ssl

overwrite=false
if [ "$FORCE" = true ] || [ "$created" = true ]; then overwrite=true; fi

host_value="$HOST_PROJECT_PATH_ARG"
[ -n "$host_value" ] || host_value="$(read_env_value HOST_PROJECT_PATH)"
[ -n "$host_value" ] || host_value='./data'
[ -z "$HOST_PROJECT_PATH_ARG" ] || overwrite=true

container_value="$CONTAINER_PROJECT_PATH_ARG"
[ -n "$container_value" ] || container_value="$(read_env_value CONTAINER_PROJECT_PATH)"
[ -n "$container_value" ] || container_value='/develop'
[ -z "$CONTAINER_PROJECT_PATH_ARG" ] || overwrite=true

set_env_default HOST_PROJECT_PATH "$host_value" "$overwrite"
set_env_default CONTAINER_PROJECT_PATH "$container_value" "$overwrite"
set_env_default DATA_PATH './data' "$overwrite"
set_env_default TIMEZONE 'Asia/Shanghai' "$overwrite"
set_env_default CHANGE_SOURCE 'true' "$overwrite"
set_env_default DOCKER_PANEL_PHP_IMAGE 'php:8.3-cli-alpine' "$overwrite"
set_env_default DOCKER_PANEL_COMPOSER_IMAGE 'composer:2' "$overwrite"
set_env_default PHP_VERSION '8.3' "$overwrite"
set_env_default WORKSPACE_PHP_VERSION '8.3' "$overwrite"
set_env_default NGINX_PHP_UPSTREAM_CONTAINER 'php83-fpm' "$overwrite"
set_env_default NGINX_PHP_UPSTREAM_PORT '9000' "$overwrite"
set_env_default WORKSPACE_COMPOSER_REPO_PACKAGIST 'https://mirrors.aliyun.com/composer/' "$overwrite"
set_env_default GOPROXY 'https://goproxy.cn,direct' "$overwrite"

latest_host="$(read_env_value HOST_PROJECT_PATH)"
latest_container="$(read_env_value CONTAINER_PROJECT_PATH)"
latest_php="$(read_env_value PHP_VERSION)"
latest_composer="$(read_env_value WORKSPACE_COMPOSER_REPO_PACKAGIST)"

if [ "$latest_host" = './data' ]; then ensure_dir './data'; fi
mkdir -p "$(dirname "$INIT_FLAG")"
date '+%Y-%m-%dT%H:%M:%S' > "$INIT_FLAG"

printf '\nLocal config is ready.\n'
printf '  .env: %s\n' "$ENV_FILE"
printf '  HOST_PROJECT_PATH=%s\n' "$latest_host"
printf '  CONTAINER_PROJECT_PATH=%s\n' "$latest_container"
printf '  PHP_VERSION=%s\n' "$latest_php"
printf '  Composer=%s\n\n' "$latest_composer"