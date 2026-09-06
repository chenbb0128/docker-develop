#!/usr/bin/env bash
set -u

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
COMPOSE_CMD=()

source "$ROOT_DIR/scripts/lib/environment.sh"

open_panel() {
    local url='http://localhost:9501'
    if is_wsl && command_exists cmd.exe; then
        (cd /mnt/c && cmd.exe /c start "" "$url") >/dev/null 2>&1 &
        return 0
    fi
    if command_exists open; then
        open "$url" >/dev/null 2>&1 &
        return 0
    fi
    if command_exists xdg-open; then
        xdg-open "$url" >/dev/null 2>&1 &
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
    printf 'WSL2: enable Docker Desktop > Settings > Resources > WSL Integration for this distribution.\n'
    exit 1
fi

printf '[1/6] Checking Docker Engine...\n'
if ! docker info >/dev/null 2>&1; then
    printf 'Docker Engine is not ready.\n'
    printf '请先启动 Docker Desktop，或启动 WSL/Linux Docker 服务。\n'
    printf 'WSL2: also enable this distribution under Docker Desktop > Settings > Resources > WSL Integration.\n'
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

prepare_compose_environment || exit 1
host_project_path="${HOST_PROJECT_PATH:-}"
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
if ! "${COMPOSE_CMD[@]}" up -d --build docker-panel; then
    printf '\nFailed to start docker-panel.\n'
    printf '\nCommon cause:\n'
    printf 'Check the build error above: golang:1.25-alpine, alpine:3.20, Go modules or system packages may be unreachable.\n'
    printf '\nFix:\n'
    printf '1. Configure Docker Engine registry-mirrors in Docker Desktop or Docker daemon, then restart Docker.\n'
    printf '2. Confirm Docker Desktop WSL Integration is enabled and the configured host paths exist.\n'
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
        http_code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 3 http://localhost:9501/api/ready 2>/dev/null || true)"
        if [ "$http_code" = 200 ]; then
            printf 'Panel HTTP is ready: %s\n' "$http_code"
            panel_ready=true
            break
        fi
        printf 'Waiting for panel... %s/30\n' "$i"
        i=$((i + 1))
        sleep 2
    done
else
    printf 'curl not found; checking readiness from the panel container.\n'
    for i in {1..30}; do
        if "${COMPOSE_CMD[@]}" exec -T docker-panel wget -q -O /dev/null http://127.0.0.1:9501/api/ready; then
            panel_ready=true
            break
        fi
        sleep 2
    done
fi

if [ "$panel_ready" != true ]; then
    printf 'Panel did not respond in time. Recent docker-panel logs:\n'
    "${COMPOSE_CMD[@]}" logs --tail=120 docker-panel || true
    printf 'Panel readiness failed. Check Docker socket access, state directory permissions and projects.json.\n'
    exit 1
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
