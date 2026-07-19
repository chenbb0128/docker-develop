#!/usr/bin/env bash

SKIP_PANEL_HTTP=false
for arg in "$@"; do
    case "$arg" in
        --skip-panel-http|-SkipPanelHttp) SKIP_PANEL_HTTP=true ;;
    esac
done

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
FAIL_COUNT=0
WARN_COUNT=0
COMPOSE_CMD=()

write_check() {
    local status="$1"
    local title="$2"
    local message="${3:-}"
    local suggestion="${4:-}"

    case "$status" in
        FAIL) FAIL_COUNT=$((FAIL_COUNT + 1)) ;;
        WARN) WARN_COUNT=$((WARN_COUNT + 1)) ;;
    esac

    printf '[%s] %s\n' "$status" "$title"
    [ -n "$message" ] && printf '     %s\n' "$message"
    [ -n "$suggestion" ] && printf '     建议：%s\n' "$suggestion"
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
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

resolve_local_path() {
    local path_value="$1"
    if [ -z "$path_value" ]; then
        return 0
    fi

    case "$path_value" in
        ~*) path_value="$HOME${path_value#~}" ;;
    esac

    case "$path_value" in
        /*) printf '%s\n' "$path_value" ;;
        *) printf '%s/%s\n' "$ROOT_DIR" "$path_value" ;;
    esac
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

invoke_compose() {
    "${COMPOSE_CMD[@]}" "$@"
}

platform_name() {
    local kernel
    kernel="$(uname -s 2>/dev/null || echo unknown)"
    case "$kernel" in
        Darwin) echo 'macOS' ;;
        Linux)
            if grep -qi microsoft /proc/version 2>/dev/null; then
                echo 'WSL'
            else
                echo 'Linux'
            fi
            ;;
        *) echo "$kernel" ;;
    esac
}

is_windows_drive_path() {
    printf '%s' "$1" | grep -Eq '^[A-Za-z]:[\\/]'
}

port_is_listening() {
    local port="$1"
    if command_exists lsof; then
        lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1
        return $?
    fi
    if command_exists ss; then
        ss -ltn 2>/dev/null | grep -Eq "[:.]$port[[:space:]]"
        return $?
    fi
    if command_exists netstat; then
        netstat -an 2>/dev/null | grep -E "[.:]$port[[:space:]].*(LISTEN|LISTENING)" >/dev/null 2>&1
        return $?
    fi
    return 2
}

printf '\nDocker Develop 环境诊断\n'
printf '项目目录：%s\n' "$ROOT_DIR"
printf '运行平台：%s\n\n' "$(platform_name)"

cd "$ROOT_DIR" || exit 1

if command_exists docker; then
    write_check OK 'Docker CLI' '已找到 docker 命令。'
else
    write_check FAIL 'Docker CLI' '没有找到 docker 命令。' '安装 Docker Desktop，或在 WSL/Linux 中安装 Docker Engine。'
fi

if get_compose_cmd; then
    write_check OK 'Docker Compose' "使用命令：${COMPOSE_CMD[*]}"
else
    write_check FAIL 'Docker Compose' '没有找到 docker-compose 或 docker compose。' '安装 Docker Compose v2，或安装 docker-compose 兼容命令。'
fi

if command_exists docker; then
    docker_info="$(docker info 2>&1)"
    if [ $? -eq 0 ]; then
        write_check OK 'Docker Engine' 'Docker 引擎正在运行。'
    else
        write_check FAIL 'Docker Engine' "$docker_info" '确认 Docker Desktop 已启动，或 WSL/Linux Docker 服务已运行。'
    fi
fi

if [ -e /var/run/docker.sock ]; then
    write_check OK 'Docker Socket' '/var/run/docker.sock 已存在，面板容器可以挂载 Docker socket。'
else
    write_check WARN 'Docker Socket' '/var/run/docker.sock 不存在。Docker Desktop for macOS/WSL 通常会提供它；如果面板不能管理容器，请检查 Docker context。'
fi

if [ -f "$ROOT_DIR/.env" ]; then
    write_check OK '.env 文件' '已找到本机配置。'
else
    write_check FAIL '.env 文件' '缺少 .env。' '复制 .env.example 为 .env，或运行 bash ./start-panel.sh 自动创建。'
fi

host_project_path="$(read_env_value HOST_PROJECT_PATH)"
container_project_path="$(read_env_value CONTAINER_PROJECT_PATH)"

if [ -n "$host_project_path" ]; then
    if is_windows_drive_path "$host_project_path"; then
        write_check FAIL 'HOST_PROJECT_PATH' "$host_project_path" '在 WSL/Linux/macOS 终端中不能使用 D:\Develop 这种路径；WSL 请用 /mnt/d/Develop，macOS 请用 /Users/you/Develop。'
    else
        host_full_path="$(resolve_local_path "$host_project_path")"
        if [ -d "$host_full_path" ]; then
            write_check OK 'HOST_PROJECT_PATH' "$host_project_path -> $host_full_path"
        else
            write_check FAIL 'HOST_PROJECT_PATH' "目录不存在：$host_full_path" '把 .env 里的 HOST_PROJECT_PATH 改成业务项目共同父目录，或先创建该目录。'
        fi
    fi
else
    write_check FAIL 'HOST_PROJECT_PATH' '.env 中没有配置 HOST_PROJECT_PATH。' '建议先使用 HOST_PROJECT_PATH=./data，接入真实项目时再改成 /mnt/d/Develop、/home/you/Develop 或 /Users/you/Develop。'
fi

if printf '%s' "$container_project_path" | grep -q '^/'; then
    write_check OK 'CONTAINER_PROJECT_PATH' "$container_project_path"
else
    write_check FAIL 'CONTAINER_PROJECT_PATH' '容器路径必须以 / 开头。' '推荐使用 /develop。'
fi

change_source="$(read_env_value CHANGE_SOURCE)"
if [ "$change_source" = 'true' ]; then
    write_check OK '系统软件源' 'CHANGE_SOURCE=true，构建时会优先使用国内镜像。'
else
    write_check WARN '系统软件源' 'CHANGE_SOURCE 不是 true。' '国内网络建议设置 CHANGE_SOURCE=true。'
fi

composer_repo="$(read_env_value WORKSPACE_COMPOSER_REPO_PACKAGIST)"
if printf '%s' "$composer_repo" | grep -Eqi 'aliyun|npmmirror|packagist'; then
    write_check OK 'Composer 源' "${composer_repo:-已使用默认源}"
else
    write_check WARN 'Composer 源' '没有检测到 WORKSPACE_COMPOSER_REPO_PACKAGIST。' '国内网络建议配置为 https://mirrors.aliyun.com/composer/。'
fi

go_proxy="$(read_env_value GOPROXY)"
if printf '%s' "$go_proxy" | grep -Eqi 'goproxy|direct'; then
    write_check OK 'Go Proxy' "$go_proxy"
else
    write_check WARN 'Go Proxy' '没有检测到 GOPROXY。' 'Go 项目建议设置 GOPROXY=https://goproxy.cn,direct。'
fi

if [ ${#COMPOSE_CMD[@]} -gt 0 ]; then
    config_output="$(invoke_compose config --quiet 2>&1)"
    if [ $? -eq 0 ]; then
        write_check OK 'docker-compose 配置' 'docker-compose config --quiet 通过。'
    else
        write_check FAIL 'docker-compose 配置' "$config_output" '优先修复 .env 或 docker-compose.yml 中的路径、端口和变量。'
    fi

    services_output="$(invoke_compose config --services 2>&1)"
    if [ $? -eq 0 ]; then
        missing=''
        for service in docker-panel nginx redis php83-fpm; do
            if ! printf '%s\n' "$services_output" | grep -qx "$service"; then
                missing="$missing $service"
            fi
        done
        if [ -z "$missing" ]; then
            write_check OK '核心服务' '已识别：docker-panel, nginx, redis, php83-fpm'
        else
            write_check FAIL '核心服务' "缺少：$missing" '检查 docker-compose.yml 是否被误删。'
        fi
    fi
fi

panel_port=9501
port_is_listening "$panel_port"
port_status=$?
if [ $port_status -eq 0 ]; then
    write_check WARN '面板端口' "端口 $panel_port 已被占用。如果 docker-panel 已在运行，这是正常的。" '如果启动失败，先确认占用端口的进程是不是 docker-panel。'
elif [ $port_status -eq 2 ]; then
    write_check WARN '面板端口' '没有 lsof/ss/netstat，跳过端口占用检查。'
else
    write_check OK '面板端口' "端口 $panel_port 当前未被占用。"
fi

if [ "$SKIP_PANEL_HTTP" != true ]; then
    if command_exists curl; then
        http_code="$(curl -fsS -o /tmp/docker-develop-panel.html -w '%{http_code}' --max-time 5 http://localhost:9501 2>/tmp/docker-develop-panel.err)"
        if [ $? -eq 0 ] && [ "$http_code" -ge 200 ] && [ "$http_code" -lt 500 ]; then
            write_check OK '面板 HTTP' "http://localhost:9501 返回 $http_code"
        else
            message="$(cat /tmp/docker-develop-panel.err 2>/dev/null)"
            write_check WARN '面板 HTTP' "${message:-HTTP 检查失败。}" '如果面板尚未启动，可以先运行 bash ./start-panel.sh。'
        fi
    else
        write_check WARN '面板 HTTP' '没有 curl，跳过 HTTP 检查。'
    fi
fi

printf '\n'
if [ "$FAIL_COUNT" -gt 0 ]; then
    printf '诊断完成：%s 个失败，%s 个警告。\n' "$FAIL_COUNT" "$WARN_COUNT"
    exit 1
fi

if [ "$WARN_COUNT" -gt 0 ]; then
    printf '诊断完成：0 个失败，%s 个警告。\n' "$WARN_COUNT"
    exit 0
fi

printf '诊断完成：环境看起来可以启动。\n'
exit 0
