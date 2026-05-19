#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

APP_HOST="${APP_HOST:-127.0.0.1}"
APP_PORT="${APP_PORT:-8001}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"
ADMIN_ACCOUNT="${ADMIN_ACCOUNT:-admin@demo.com}"
LOCAL_DIR="$ROOT_DIR/.local"
RUN_DIR="$LOCAL_DIR/run"
PHP_WRAPPER="$LOCAL_DIR/bin/php-xboard"
REDIS_BIN="$LOCAL_DIR/redis-root/usr/bin/redis-server"
REDIS_CLI="$LOCAL_DIR/redis-root/usr/bin/redis-cli"
REDIS_LIB_DIR="$LOCAL_DIR/lib-root/usr/lib/x86_64-linux-gnu"
REDIS_CONF="$LOCAL_DIR/redis.conf"
LARAVEL_PID="$RUN_DIR/laravel.pid"
REDIS_PID="$RUN_DIR/redis.pid"
LARAVEL_OWNER="$RUN_DIR/laravel.owner"
REDIS_OWNER="$RUN_DIR/redis.owner"

log() { printf '[dev-up] %s\n' "$*"; }
fail() { printf '[dev-up] ERROR: %s\n' "$*" >&2; exit 1; }
need_cmd() { command -v "$1" >/dev/null 2>&1 || fail "missing command: $1"; }

add_local_exclude() {
  mkdir -p .git/info
  grep -qxF '.local/' .git/info/exclude 2>/dev/null || printf '\n.local/\n' >> .git/info/exclude
}

php_has_extensions() {
  "$1" -m | grep -qx 'pdo_sqlite' && \
  "$1" -m | grep -qx 'sqlite3' && \
  "$1" -m | grep -qx 'pdo_mysql'
}

prepare_php() {
  [ -x /usr/bin/php8.3 ] || fail "missing PHP binary: /usr/bin/php8.3"
  mkdir -p "$LOCAL_DIR/bin" "$LOCAL_DIR/php-conf.d" "$LOCAL_DIR/php-ext-debs" "$LOCAL_DIR/php-ext-root"

  if php_has_extensions /usr/bin/php8.3; then
    cat > "$PHP_WRAPPER" <<'WRAP'
#!/usr/bin/env bash
exec /usr/bin/php8.3 "$@"
WRAP
    chmod +x "$PHP_WRAPPER"
    return
  fi

  need_cmd apt
  need_cmd dpkg-deb
  if ! ls "$LOCAL_DIR/php-ext-root/usr/lib/php/20230831"/{pdo_sqlite.so,sqlite3.so,pdo_mysql.so,mysqli.so,mysqlnd.so} >/dev/null 2>&1; then
    log "downloading local PHP database extensions"
    (cd "$LOCAL_DIR/php-ext-debs" && apt download php8.3-sqlite3 php8.3-mysql >/dev/null)
    rm -rf "$LOCAL_DIR/php-ext-root"
    mkdir -p "$LOCAL_DIR/php-ext-root"
    for deb in "$LOCAL_DIR"/php-ext-debs/*.deb; do
      dpkg-deb -x "$deb" "$LOCAL_DIR/php-ext-root"
    done
  fi

  cat > "$LOCAL_DIR/php-conf.d/90-xboard-local-db.ini" <<INI
extension=$LOCAL_DIR/php-ext-root/usr/lib/php/20230831/sqlite3.so
extension=$LOCAL_DIR/php-ext-root/usr/lib/php/20230831/pdo_sqlite.so
extension=$LOCAL_DIR/php-ext-root/usr/lib/php/20230831/mysqlnd.so
extension=$LOCAL_DIR/php-ext-root/usr/lib/php/20230831/mysqli.so
extension=$LOCAL_DIR/php-ext-root/usr/lib/php/20230831/pdo_mysql.so
INI
  cat > "$PHP_WRAPPER" <<WRAP
#!/usr/bin/env bash
set -e
export PHP_INI_SCAN_DIR="/etc/php/8.3/cli/conf.d:$LOCAL_DIR/php-conf.d"
exec /usr/bin/php8.3 "\$@"
WRAP
  chmod +x "$PHP_WRAPPER"
  php_has_extensions "$PHP_WRAPPER" || fail "PHP wrapper did not load required DB extensions"
}

prepare_composer_node() {
  if [ ! -d vendor ]; then
    need_cmd composer
    log "installing Composer dependencies"
    COMPOSER_ALLOW_SUPERUSER=1 "$PHP_WRAPPER" "$(command -v composer)" install --prefer-dist --no-interaction
  fi
  if [ -f package.json ] && [ ! -d node_modules ]; then
    need_cmd npm
    log "installing Node dependencies"
    npm install --package-lock=false
  fi
}

prepare_redis_binaries() {
  mkdir -p "$LOCAL_DIR/redis-debs" "$LOCAL_DIR/redis-root" "$LOCAL_DIR/lib-debs" "$LOCAL_DIR/lib-root" "$RUN_DIR" "$LOCAL_DIR/redis-data"
  if command -v redis-server >/dev/null 2>&1 && command -v redis-cli >/dev/null 2>&1; then
    REDIS_BIN="$(command -v redis-server)"
    REDIS_CLI="$(command -v redis-cli)"
    REDIS_LIB_DIR=""
    return
  fi

  need_cmd apt
  need_cmd dpkg-deb
  if [ ! -x "$REDIS_BIN" ] || [ ! -x "$REDIS_CLI" ]; then
    log "downloading local Redis runtime"
    (cd "$LOCAL_DIR/redis-debs" && apt download redis-server redis-tools >/dev/null)
    rm -rf "$LOCAL_DIR/redis-root"
    mkdir -p "$LOCAL_DIR/redis-root"
    for deb in "$LOCAL_DIR"/redis-debs/*.deb; do
      dpkg-deb -x "$deb" "$LOCAL_DIR/redis-root"
    done
  fi
  if [ ! -f "$REDIS_LIB_DIR/libjemalloc.so.2" ] || [ ! -e "$REDIS_LIB_DIR/liblzf.so.1" ]; then
    log "downloading local Redis shared libraries"
    (cd "$LOCAL_DIR/lib-debs" && apt download liblzf1 libjemalloc2 >/dev/null)
    rm -rf "$LOCAL_DIR/lib-root"
    mkdir -p "$LOCAL_DIR/lib-root"
    for deb in "$LOCAL_DIR"/lib-debs/*.deb; do
      dpkg-deb -x "$deb" "$LOCAL_DIR/lib-root"
    done
    ln -sf liblzf.so.1.5 "$REDIS_LIB_DIR/liblzf.so.1"
  fi
}

redis_ping() {
  if [ -n "${REDIS_LIB_DIR:-}" ]; then
    env LD_LIBRARY_PATH="$REDIS_LIB_DIR" "$REDIS_CLI" -h "$REDIS_HOST" -p "$REDIS_PORT" ping 2>/dev/null | grep -q PONG
  else
    "$REDIS_CLI" -h "$REDIS_HOST" -p "$REDIS_PORT" ping 2>/dev/null | grep -q PONG
  fi
}

start_redis() {
  prepare_redis_binaries
  if redis_ping; then
    log "Redis already reachable at $REDIS_HOST:$REDIS_PORT"
    return
  fi

  cat > "$REDIS_CONF" <<CONF
bind $REDIS_HOST
port $REDIS_PORT
dir $LOCAL_DIR/redis-data
pidfile $REDIS_PID
logfile $RUN_DIR/redis.log
daemonize no
save ""
appendonly no
CONF
  log "starting Redis at $REDIS_HOST:$REDIS_PORT"
  if [ -n "${REDIS_LIB_DIR:-}" ]; then
    setsid env LD_LIBRARY_PATH="$REDIS_LIB_DIR" "$REDIS_BIN" "$REDIS_CONF" >"$RUN_DIR/redis-stdout.log" 2>&1 < /dev/null &
  else
    setsid "$REDIS_BIN" "$REDIS_CONF" >"$RUN_DIR/redis-stdout.log" 2>&1 < /dev/null &
  fi
  echo $! > "$REDIS_PID"
  printf 'type=redis\nroot=%s\nconf=%s\nport=%s\n' "$ROOT_DIR" "$REDIS_CONF" "$REDIS_PORT" > "$REDIS_OWNER"

  for _ in {1..30}; do
    redis_ping && return
    sleep 0.2
  done
  tail -n 80 "$RUN_DIR/redis.log" "$RUN_DIR/redis-stdout.log" 2>/dev/null || true
  fail "Redis did not become reachable"
}

ensure_env_and_install() {
  [ -f .env ] || cp .env.example .env
  mkdir -p .docker/.data storage/logs storage/framework/{cache,sessions,views}

  if ! grep -qE '^INSTALLED=(1|true)$' .env; then
    log "running Xboard installer with SQLite"
    printf '\n\n\n' | ENABLE_SQLITE=true ADMIN_ACCOUNT="$ADMIN_ACCOUNT" "$PHP_WRAPPER" artisan xboard:install
  fi
}

app_pid_alive() {
  [ -f "$LARAVEL_PID" ] && kill -0 "$(cat "$LARAVEL_PID")" 2>/dev/null
}

app_http_ok() {
  curl -fsS --max-time 2 "http://$APP_HOST:$APP_PORT/" >/dev/null 2>&1 || \
  curl -IsS --max-time 2 "http://$APP_HOST:$APP_PORT/" >/dev/null 2>&1
}

start_laravel() {
  if app_pid_alive && app_http_ok; then
    log "Laravel server already running at http://$APP_HOST:$APP_PORT"
    return
  fi
  if app_http_ok; then
    log "HTTP server already responds at http://$APP_HOST:$APP_PORT"
    return
  fi

  log "starting Laravel server at http://$APP_HOST:$APP_PORT"
  APP_ENV=local APP_DEBUG=true REDIS_HOST="$REDIS_HOST" REDIS_PORT="$REDIS_PORT" \
    setsid "$PHP_WRAPPER" artisan serve --host="$APP_HOST" --port="$APP_PORT" >"$RUN_DIR/laravel.log" 2>&1 < /dev/null &
  echo $! > "$LARAVEL_PID"
  printf 'type=laravel\nroot=%s\nhost=%s\nport=%s\n' "$ROOT_DIR" "$APP_HOST" "$APP_PORT" > "$LARAVEL_OWNER"

  for _ in {1..60}; do
    app_http_ok && return
    sleep 0.25
  done
  tail -n 120 "$RUN_DIR/laravel.log" 2>/dev/null || true
  fail "Laravel server did not become reachable"
}

secure_path() {
  "$PHP_WRAPPER" -r '$key=""; foreach (file(".env", FILE_IGNORE_NEW_LINES) as $line) { if (str_starts_with($line, "APP_KEY=")) { $key=substr($line, 8); break; } } echo hash("crc32b", trim($key));'
}

smoke() {
  local secure http_root http_admin http_api
  secure="$(secure_path)"
  http_root="$(curl -IsS --max-time 5 "http://$APP_HOST:$APP_PORT/" | awk 'NR==1 {print $2}')"
  http_admin="$(curl -sS --max-time 5 -o /dev/null -w '%{http_code}' "http://$APP_HOST:$APP_PORT/$secure")"
  http_api="$(curl -sS --max-time 5 -o /dev/null -w '%{http_code}' "http://$APP_HOST:$APP_PORT/api/v1/guest/comm/config")"
  [ "$http_root" = "404" ] || fail "root smoke expected 404, got $http_root"
  [ "$http_admin" = "200" ] || fail "admin smoke expected 200, got $http_admin"
  [ "$http_api" = "200" ] || fail "api smoke expected 200, got $http_api"
  log "smoke OK: / -> 404, /$secure -> 200, guest config API -> 200"
  printf '\nXboard local runtime is ready:\n'
  printf '  URL:   http://%s:%s/%s\n' "$APP_HOST" "$APP_PORT" "$secure"
  printf '  Redis: %s:%s\n' "$REDIS_HOST" "$REDIS_PORT"
}

main() {
  need_cmd curl
  need_cmd setsid
  add_local_exclude
  prepare_php
  prepare_composer_node
  start_redis
  ensure_env_and_install
  start_laravel
  smoke
}

main "$@"
