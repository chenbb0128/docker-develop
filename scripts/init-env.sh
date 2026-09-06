#!/usr/bin/env bash
set -eu

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

source "$ROOT_DIR/scripts/lib/environment.sh"

set_env_value() {
    local key="$1"
    local value="$2"
    local tmp
    tmp="$(mktemp)"
    if [ -f "$ENV_FILE" ]; then
        ENV_VALUE="$value" awk -v key="$key" '
            BEGIN { done = 0; value = ENVIRON["ENV_VALUE"] }
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
set_env_default BASE_IMAGE_REGISTRY '' "$overwrite"
set_env_default GO_VERSION '1.25' "$overwrite"
set_env_default GOPROXY 'https://goproxy.cn,direct' "$overwrite"

latest_host="$(read_env_value HOST_PROJECT_PATH)"
latest_container="$(read_env_value CONTAINER_PROJECT_PATH)"
latest_go="$(read_env_value GO_VERSION)"

if [ "$latest_host" = './data' ]; then ensure_dir './data'; fi
mkdir -p "$(dirname "$INIT_FLAG")"
date '+%Y-%m-%dT%H:%M:%S' > "$INIT_FLAG"

printf '\nLocal config is ready.\n'
printf '  .env: %s\n' "$ENV_FILE"
printf '  HOST_PROJECT_PATH=%s\n' "$latest_host"
printf '  CONTAINER_PROJECT_PATH=%s\n' "$latest_container"
printf '  GO_VERSION=%s\n\n' "$latest_go"
