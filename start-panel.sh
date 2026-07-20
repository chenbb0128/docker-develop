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

read_env_value() {
    local key="$1"
    local file="$ROOT_DIR/.env"
    [ -f "$file" ] || return 0
    awk -F= -v key="$key" '
        /^[[:space:]]*#/ { next }
        $1 == key {
            value = substr($0, index($0, "=") + 1)
            gsub(/^[[:space:]\"]+|[[:space:]\"]+$/, "", value)
            print value
            exit
        }
    ' "$file"
}

open_panel() {
    local url='http://localhost:9501'
    if command_exists open; then
        open "$url" >/dev/null 2>&1 &
        return 0
    fi
    if command_exists xdg-open; then
        xdg-open "$url" >/dev/null 2>&1 &
        return 0
    fi
    if grep -qi microsoft /proc/version 2>/dev/null && command_exists cmd.exe; then
        cmd.exe /c start "" "$url" >/dev/null 2>&1 &
        return 0
    fi
    return 0
}

printf '============================================\n'
printf 'Docker Develop startup\n'
printf '============================================\n\n'

cd "$ROOT_DIR" || exit 1

if ! get_compose_cmd; then
    printf '没有找到 docker-compose 或 docker compose。\n'
    printf '请先安装 Docker Desktop，或在 WSL/Linux 中安装 Docker Engine + Compose。\n'
    exit 1
fi

printf '[1/6] Checking Docker Engine...\n'
if ! docker info >/dev/null 2>&1; then
    printf 'Docker Engine is not ready.\n'
    printf '请先启动 Docker Desktop，或启动 WSL/Linux Docker 服务。\n'
    exit 1
fi
printf 'Docker Engine is ready.\n\n'

printf '[2/6] Preparing local config...\n'
if [ -f "$ROOT_DIR/scripts/init-env.sh" ]; then
    if ! bash "$ROOT_DIR/scripts/init-env.sh"; then
        printf 'Failed to initialize local config. Please check .env.example and scripts/init-env.sh.\n'
        exit 1
    fi
else
    printf 'scripts/init-env.sh not found.\n'
    exit 1
fi

host_project_path="$(read_env_value HOST_PROJECT_PATH)"
container_project_path="$(read_env_value CONTAINER_PROJECT_PATH)"
printf 'HOST_PROJECT_PATH=%s\n' "$host_project_path"
printf 'CONTAINER_PROJECT_PATH=%s\n\n' "$container_project_path"
printf '[3/6] Running environment doctor...\n'
if [ -f "$ROOT_DIR/doctor.sh" ]; then
    if ! bash "$ROOT_DIR/doctor.sh" --skip-panel-http; then
        printf '\nEnvironment doctor found blocking issues. Please fix the items above, then run this script again.\n'
        exit 1
    fi
else
    printf 'doctor.sh not found, skip.\n'
fi

printf '\n[4/6] Starting docker-panel service...\n'
if ! "${COMPOSE_CMD[@]}" up -d docker-panel; then
    printf '\nFailed to start docker-panel.\n'
    printf '\nCommon cause:\n'
    printf 'Docker cannot pull base images from Docker Hub, for example php:8.3-cli-alpine or composer:2.\n'
    printf '\nFix:\n'
    printf '1. Configure Docker Engine registry-mirrors in Docker Desktop or Docker daemon, then restart Docker.\n'
    printf '2. Or set DOCKER_PANEL_PHP_IMAGE and DOCKER_PANEL_COMPOSER_IMAGE in .env to a reachable image proxy.\n'
    printf '3. Rerun bash ./start-panel.sh.\n\n'
    printf 'Recent docker-panel logs:\n'
    "${COMPOSE_CMD[@]}" logs --tail=80 docker-panel || true
    exit 1
fi

printf '\n[5/6] Waiting for panel HTTP...\n'
panel_ready=false
if command_exists curl; then
    i=1
    while [ "$i" -le 30 ]; do
        http_code="$(curl -fsS -o /tmp/docker-develop-panel.html -w '%{http_code}' --max-time 3 http://localhost:9501 2>/dev/null || true)"
        if [ -n "$http_code" ] && [ "$http_code" -ge 200 ] && [ "$http_code" -lt 500 ]; then
            printf 'Panel HTTP is ready: %s\n' "$http_code"
            panel_ready=true
            break
        fi
        printf 'Waiting for panel... %s/30\n' "$i"
        i=$((i + 1))
        sleep 2
    done
else
    printf 'curl not found, skip HTTP wait.\n'
    panel_ready=true
fi

if [ "$panel_ready" != true ]; then
    printf 'Panel did not respond in time. Recent docker-panel logs:\n'
    "${COMPOSE_CMD[@]}" logs --tail=120 docker-panel || true
    printf 'The container may still be installing Composer dependencies. Retry http://localhost:9501 in a moment.\n'
fi

printf '\n[6/6] Current service status:\n'
"${COMPOSE_CMD[@]}" ps docker-panel || true

printf '\n============================================\n'
printf 'Docker Develop is ready.\n'
printf 'URL: http://localhost:9501\n'
printf 'Auth: disabled for local development\n'
printf 'Project root: %s -> %s\n' "$host_project_path" "$container_project_path"
printf '============================================\n\n'

open_panel
