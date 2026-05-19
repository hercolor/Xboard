#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
APP_HOST="${APP_HOST:-127.0.0.1}"
APP_PORT="${APP_PORT:-8001}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"
LOCAL_DIR="$ROOT_DIR/.local"
RUN_DIR="$LOCAL_DIR/run"
PHP_BIN="$LOCAL_DIR/bin/php-xboard"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php)"
REDIS_CLI="$LOCAL_DIR/redis-root/usr/bin/redis-cli"
[ -x "$REDIS_CLI" ] || REDIS_CLI="$(command -v redis-cli 2>/dev/null || true)"
REDIS_LIB_DIR="$LOCAL_DIR/lib-root/usr/lib/x86_64-linux-gnu"

status_line() { printf '%-18s %s\n' "$1" "$2"; }
http_code() { local out; out="$(curl -sS --max-time 3 -o /dev/null -w '%{http_code}' "$1" 2>/dev/null || true)"; printf '%s' "${out:-000}"; }
head_code() { local out; out="$(curl -IsS --max-time 3 "$1" 2>/dev/null | awk 'NR==1 {print $2}' || true)"; printf '%s' "${out:-000}"; }
secure_path() { "$PHP_BIN" -r '$key=""; foreach (file(".env", FILE_IGNORE_NEW_LINES) as $line) { if (str_starts_with($line, "APP_KEY=")) { $key=substr($line, 8); break; } } echo hash("crc32b", trim($key));' 2>/dev/null || true; }
redis_ping() {
  [ -n "$REDIS_CLI" ] || return 1
  if [ -d "$REDIS_LIB_DIR" ]; then
    env LD_LIBRARY_PATH="$REDIS_LIB_DIR" "$REDIS_CLI" -h "$REDIS_HOST" -p "$REDIS_PORT" ping 2>/dev/null | grep -q PONG
  else
    "$REDIS_CLI" -h "$REDIS_HOST" -p "$REDIS_PORT" ping 2>/dev/null | grep -q PONG
  fi
}

secure="$(secure_path)"
root_code="$(head_code "http://$APP_HOST:$APP_PORT/")"
admin_code="000"
[ -n "$secure" ] && admin_code="$(http_code "http://$APP_HOST:$APP_PORT/$secure")"
api_code="$(http_code "http://$APP_HOST:$APP_PORT/api/v1/guest/comm/config")"

printf 'Xboard local development status\n'
printf '================================\n'
status_line 'Root' "$ROOT_DIR"
status_line 'PHP' "$($PHP_BIN -v 2>/dev/null | head -1 || true)"
status_line 'DB extensions' "$($PHP_BIN -m 2>/dev/null | grep -E 'pdo_sqlite|sqlite3|pdo_mysql' | paste -sd ',' - || true)"
status_line 'Installed' "$(grep -E '^INSTALLED=' .env 2>/dev/null | cut -d= -f2- || echo unknown)"
status_line 'DB' "$(grep -E '^DB_CONNECTION=|^DB_DATABASE=' .env 2>/dev/null | tr -d '\r' | paste -sd ' ' - || true)"
if redis_ping; then status_line 'Redis' "OK ($REDIS_HOST:$REDIS_PORT)"; else status_line 'Redis' "FAIL ($REDIS_HOST:$REDIS_PORT)"; fi
status_line 'Laravel PID' "$(cat "$RUN_DIR/laravel.pid" 2>/dev/null || echo unknown)"
status_line 'Secure path' "${secure:-unknown}"
status_line 'HTTP /' "${root_code:-000}"
status_line 'HTTP admin' "$admin_code"
status_line 'HTTP API' "$api_code"
