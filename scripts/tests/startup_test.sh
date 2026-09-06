#!/usr/bin/env bash
set -euo pipefail

SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
TEMP_PARENT="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
TEST_ROOT="$(mktemp -d "$TEMP_PARENT/docker-develop-test.XXXXXXXX")"
cleanup() {
    case "$TEST_ROOT" in
        "$TEMP_PARENT"/docker-develop-test.*) rm -rf -- "$TEST_ROOT" ;;
        *) printf 'Refusing to remove unexpected test path: %s\n' "$TEST_ROOT" >&2 ;;
    esac
}
trap cleanup EXIT
ROOT_DIR="$TEST_ROOT/repo with spaces"
mkdir -p "$ROOT_DIR/scripts/lib" "$TEST_ROOT/bin" "$TEST_ROOT/projects with spaces"
cp "$SOURCE_ROOT/start-panel.sh" "$SOURCE_ROOT/doctor.sh" "$SOURCE_ROOT/.env.example" "$ROOT_DIR/"
cp "$SOURCE_ROOT/scripts/init-env.sh" "$ROOT_DIR/scripts/"
cp "$SOURCE_ROOT/scripts/lib/environment.sh" "$ROOT_DIR/scripts/lib/"
source "$ROOT_DIR/scripts/lib/environment.sh"

assert_equal() {
    if [ "$1" != "$2" ]; then
        printf 'FAIL: got <%s>, expected <%s>\n' "$1" "$2" >&2
        exit 1
    fi
}

# The test never invokes the real Docker CLI or changes the user's .env.
export TEST_ROOT ROOT_DIR WSL_DISTRO_NAME=Ubuntu
export PATH="$TEST_ROOT/bin:$PATH"
unset DOCKER_DEVELOP_HOST_PATH HOST_PROJECT_PATH DATA_PATH NGINX_HOST_LOG_PATH NGINX_SITES_PATH NGINX_SSL_PATH MYSQL_HOST_DATA_PATH MYSQL_ENTRYPOINT_INITDB
cat > "$TEST_ROOT/bin/wslpath" <<'STUB'
#!/usr/bin/env bash
if [ "$1" = -u ] && [ "$2" = 'C:\My Projects' ]; then
    printf '%s/projects with spaces\n' "$TEST_ROOT"
else
    exit 1
fi
STUB
cat > "$TEST_ROOT/bin/docker" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$TEST_ROOT/docker-calls"
case "$*" in
    info|'compose version'|'compose config --quiet') exit 0 ;;
    'compose config --services') printf 'docker-panel\ngo\n'; exit 0 ;;
    'compose up -d --build docker-panel')
        [ "$HOST_PROJECT_PATH" = "$TEST_ROOT/projects with spaces" ] || exit 20
        [ "$DOCKER_DEVELOP_HOST_PATH" = "$ROOT_DIR" ] || exit 21
        [ "$DATA_PATH" = "$ROOT_DIR/./data" ] || exit 22
        exit "${TEST_BUILD_EXIT:-0}" ;;
    'compose logs '*|'compose ps docker-panel') exit 0 ;;
    *) printf 'Unexpected Docker command: %s\n' "$*" >&2; exit 99 ;;
esac
STUB
cat > "$TEST_ROOT/bin/curl" <<'STUB'
#!/usr/bin/env bash
case "$*" in
    *http://localhost:9501/api/ready*) printf '%s' "${TEST_HTTP_CODE:-200}" ;;
    *) exit 90 ;;
esac
STUB
for tool in sleep xdg-open open cmd.exe; do
    printf '#!/usr/bin/env bash\nexit 0\n' > "$TEST_ROOT/bin/$tool"
done
chmod +x "$TEST_ROOT/bin/"*

printf ' HOST_PROJECT_PATH = "C:\\My Projects"\r\nDATA_PATH=./data\r\n' > "$ROOT_DIR/.env"
assert_equal "$(read_env_value HOST_PROJECT_PATH)" 'C:\My Projects'
assert_equal "$(resolve_local_path 'C:\My Projects')" "$TEST_ROOT/projects with spaces"
assert_equal "$(resolve_local_path './data')" "$ROOT_DIR/./data"
assert_equal "$(resolve_local_path '/home/dev/My Projects')" '/home/dev/My Projects'
assert_equal "$(resolve_local_path '~/projects')" "$HOME/projects"

bash "$ROOT_DIR/start-panel.sh" > "$TEST_ROOT/success.log" 2>&1
grep -q 'Docker Develop is ready' "$TEST_ROOT/success.log"
assert_equal "$(read_env_value HOST_PROJECT_PATH)" 'C:\My Projects'
if grep -Eq '^(PHP_VERSION|DOCKER_PANEL_PHP_IMAGE|JAVA_VERSION)=' "$ROOT_DIR/.env"; then
    printf 'FAIL: legacy runtime defaults were added\n' >&2
    exit 1
fi

export TEST_BUILD_EXIT=1
if bash "$ROOT_DIR/start-panel.sh" > "$TEST_ROOT/build-failure.log" 2>&1; then
    printf 'FAIL: a failed build was accepted\n' >&2
    exit 1
fi
unset TEST_BUILD_EXIT
export TEST_HTTP_CODE=503
if bash "$ROOT_DIR/start-panel.sh" > "$TEST_ROOT/not-ready.log" 2>&1; then
    printf 'FAIL: readiness failure was accepted\n' >&2
    exit 1
fi
if grep -q 'Docker Develop is ready' "$TEST_ROOT/not-ready.log"; then exit 1; fi
printf 'PASS: WSL paths, Go startup, config preservation, build failure, readiness failure\n'
