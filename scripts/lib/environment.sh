#!/usr/bin/env bash

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

is_wsl() {
    [ -n "${WSL_DISTRO_NAME:-}" ] || grep -qi microsoft /proc/version 2>/dev/null
}

read_env_value() {
    local key="$1" file="${ENV_FILE:-$ROOT_DIR/.env}"
    [ -f "$file" ] || return 0
    awk -v key="$key" '
        /^[[:space:]]*#/ { next }
        {
            pos = index($0, "=")
            if (!pos) next
            name = substr($0, 1, pos - 1)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", name)
            if (name != key) next
            value = substr($0, pos + 1)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
            quote = substr(value, 1, 1)
            if ((quote == "\"" || quote == "\047") && substr(value, length(value), 1) == quote)
                value = substr(value, 2, length(value) - 2)
            print value
            exit
        }
    ' "$file"
}

resolve_local_path() {
    local value="$1"
    [ -n "$value" ] || return 0
    if [[ "$value" =~ ^[A-Za-z]:[\\/] ]]; then
        if ! is_wsl || ! command_exists wslpath; then
            printf 'Windows path requires WSL and wslpath: %s\n' "$value" >&2
            return 1
        fi
        wslpath -u "$value"
        return $?
    fi
    value="${value//\\//}"
    case "$value" in
        '~') value="$HOME" ;;
        '~/'*) value="$HOME/${value:2}" ;;
    esac
    case "$value" in
        /*) printf '%s\n' "$value" ;;
        *) printf '%s/%s\n' "$ROOT_DIR" "$value" ;;
    esac
}

prepare_compose_environment() {
    local key value resolved
    # Only path values are exported; the dotenv file is never executed or rewritten.
    for key in DOCKER_DEVELOP_HOST_PATH HOST_PROJECT_PATH DATA_PATH \
        NGINX_HOST_LOG_PATH NGINX_SITES_PATH NGINX_SSL_PATH \
        MYSQL_HOST_DATA_PATH MYSQL_ENTRYPOINT_INITDB; do
        value="${!key:-}"
        [ -n "$value" ] || value="$(read_env_value "$key")"
        if [ "$key" = DOCKER_DEVELOP_HOST_PATH ] && [ -z "$value" ]; then value="$ROOT_DIR"; fi
        [ -n "$value" ] || continue
        resolved="$(resolve_local_path "$value")" || return 1
        export "$key=$resolved"
    done
}

get_compose_cmd() {
    if command_exists docker && docker compose version >/dev/null 2>&1; then
        COMPOSE_CMD=(docker compose)
    elif command_exists docker-compose; then
        COMPOSE_CMD=(docker-compose)
    else
        return 1
    fi
}
