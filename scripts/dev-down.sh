#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
APP_HOST="${APP_HOST:-127.0.0.1}"
APP_PORT="${APP_PORT:-8001}"
LOCAL_DIR="$ROOT_DIR/.local"
RUN_DIR="$LOCAL_DIR/run"
LARAVEL_PID="$RUN_DIR/laravel.pid"
REDIS_PID="$RUN_DIR/redis.pid"
LARAVEL_OWNER="$RUN_DIR/laravel.owner"
REDIS_OWNER="$RUN_DIR/redis.owner"

log() { printf '[dev-down] %s\n' "$*"; }

pid_cmdline() {
  local pid="$1"
  tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true
}

pid_cwd() {
  local pid="$1"
  readlink "/proc/$pid/cwd" 2>/dev/null || true
}

owner_matches() {
  local owner_file="$1" expected_type="$2"
  [ -f "$owner_file" ] || return 1
  grep -qxF "type=$expected_type" "$owner_file" || return 1
  grep -qxF "root=$ROOT_DIR" "$owner_file" || return 1
}

is_script_laravel_pid() {
  local pid="$1" cmd cwd
  owner_matches "$LARAVEL_OWNER" laravel || return 1
  cmd="$(pid_cmdline "$pid")"
  cwd="$(pid_cwd "$pid")"
  [ "$cwd" = "$ROOT_DIR" ] || return 1
  [[ "$cmd" == *"artisan serve"* ]] || return 1
  [[ "$cmd" == *"--host=$APP_HOST"* ]] || return 1
  [[ "$cmd" == *"--port=$APP_PORT"* ]] || return 1
}

is_script_redis_pid() {
  local pid="$1" cmd cwd
  owner_matches "$REDIS_OWNER" redis || return 1
  cmd="$(pid_cmdline "$pid")"
  cwd="$(pid_cwd "$pid")"
  { [ "$cwd" = "$ROOT_DIR" ] || [ "$cwd" = "$LOCAL_DIR/redis-data" ]; } || return 1
  [[ "$cmd" == *"redis-server"* ]] || return 1
}

stop_verified_pid_file() {
  local name="$1" file="$2" owner_file="$3" verifier="$4"
  if [ ! -f "$file" ]; then
    return
  fi

  local pid
  pid="$(cat "$file" 2>/dev/null || true)"
  if [ -z "$pid" ] || ! [[ "$pid" =~ ^[0-9]+$ ]]; then
    log "removing invalid $name pid file"
    rm -f "$file" "$owner_file"
    return
  fi

  if ! kill -0 "$pid" 2>/dev/null; then
    log "removing stale $name pid=$pid"
    rm -f "$file" "$owner_file"
    return
  fi

  if ! "$verifier" "$pid"; then
    log "refusing to stop unverified $name pid=$pid; removing stale owner record only"
    rm -f "$file" "$owner_file"
    return
  fi

  log "stopping script-owned $name pid=$pid"
  kill "$pid" 2>/dev/null || true
  for _ in {1..30}; do
    kill -0 "$pid" 2>/dev/null || break
    sleep 0.1
  done
  if kill -0 "$pid" 2>/dev/null; then
    log "forcing script-owned $name pid=$pid"
    kill -9 "$pid" 2>/dev/null || true
  fi
  rm -f "$file" "$owner_file"
}

stop_verified_pid_file 'Laravel server' "$LARAVEL_PID" "$LARAVEL_OWNER" is_script_laravel_pid
stop_verified_pid_file 'Redis server' "$REDIS_PID" "$REDIS_OWNER" is_script_redis_pid

log "done"
