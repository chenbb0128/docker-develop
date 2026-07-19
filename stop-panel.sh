#!/usr/bin/env bash
set -u

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
COMPOSE_CMD=()

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

get_compose_cmd() {
    if command_exists docker-compose; then
        COMPOSE_CMD=(docker-compose)
        return 0
    fi

    if command_exists docker && docker compose version >/dev/null 2>&1; then
        COMPOSE_CMD=(docker compose)
        return 0
    fi

    return 1
}

cd "$ROOT_DIR" || exit 1

if ! get_compose_cmd; then
    printf '没有找到 docker-compose 或 docker compose。\n'
    exit 1
fi

printf 'Stopping Docker Develop containers...\n'
"${COMPOSE_CMD[@]}" down
printf '\nDocker Develop containers stopped.\n'
