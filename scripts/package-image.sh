#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
  cat <<'HELP'
Usage: ./scripts/package-image.sh

One-click local Xboard image package script.

What it does:
  1. Builds ghcr.io/hercolor/xboard:<tag> and ghcr.io/hercolor/xboard:latest from current Git HEAD.
  2. Includes initialized submodules such as public/assets/admin.
  3. Verifies entrypoint, artisan, admin manifest, JS, and CSS inside the image.
  4. Exports xboard-latest.tar.gz and xboard-latest.tar.gz.sha256.
  5. Prints server load/deploy commands.

Environment overrides:
  IMAGE_BASE=ghcr.io/hercolor/xboard
  IMAGE_TAG=phase6-<short HEAD>
  OUT=xboard-hercolor-<tag>-<timestamp>.tar.gz
  ALPINE_MIRROR_URL=https://mirrors.aliyun.com/alpine
  COMPOSER_REPO_PACKAGIST=https://mirrors.aliyun.com/composer/

Example:
  ./scripts/package-image.sh
  IMAGE_TAG=prod-20260525 ./scripts/package-image.sh
HELP
  exit 0
fi

log() { printf '[package-image] %s\n' "$*"; }
fail() { printf '[package-image] ERROR: %s\n' "$*" >&2; exit 1; }
need_cmd() { command -v "$1" >/dev/null 2>&1 || fail "missing command: $1"; }

need_cmd git
need_cmd docker
need_cmd sha256sum

if ! docker info >/dev/null 2>&1; then
  fail "Docker daemon is not reachable. Start Docker first, then rerun this script."
fi

if [ -f .gitmodules ]; then
  while IFS= read -r submodule_path; do
    [ -n "$submodule_path" ] || continue
    [ -d "$submodule_path" ] || fail "submodule not initialized: $submodule_path. Run: git submodule update --init --recursive"
  done < <(git config --file .gitmodules --get-regexp 'submodule\..*\.path' | awk '{print $2}')
fi

COMMIT="$(git rev-parse --short=12 HEAD)"
IMAGE_BASE="${IMAGE_BASE:-ghcr.io/hercolor/xboard}"
IMAGE_TAG="${IMAGE_TAG:-phase6-${COMMIT}}"
ALPINE_MIRROR_URL="${ALPINE_MIRROR_URL:-https://mirrors.aliyun.com/alpine}"
COMPOSER_REPO_PACKAGIST="${COMPOSER_REPO_PACKAGIST:-https://mirrors.aliyun.com/composer/}"

log "repo: $ROOT_DIR"
log "commit: $(git log -1 --oneline)"
log "image: ${IMAGE_BASE}:${IMAGE_TAG}"
log "latest: ${IMAGE_BASE}:latest"

if [ -n "$(git status --short --untracked-files=no)" ]; then
  log "warning: tracked working tree has uncommitted changes; build script packages current Git HEAD, not unstaged edits."
fi

EXPORT_IMAGE=1 \
IMAGE_BASE="$IMAGE_BASE" \
IMAGE_TAG="$IMAGE_TAG" \
ALPINE_MIRROR_URL="$ALPINE_MIRROR_URL" \
COMPOSER_REPO_PACKAGIST="$COMPOSER_REPO_PACKAGIST" \
./scripts/build-local-image.sh

test -f xboard-latest.tar.gz || fail "missing xboard-latest.tar.gz after build"
test -f xboard-latest.tar.gz.sha256 || fail "missing xboard-latest.tar.gz.sha256 after build"
sha256sum -c xboard-latest.tar.gz.sha256

log "package ready:"
ls -lh xboard-latest.tar.gz xboard-latest.tar.gz.sha256

cat <<EOF

Next server commands:

  mkdir -p /root/xboard-image
  # Upload from local machine:
  scp xboard-latest.tar.gz xboard-latest.tar.gz.sha256 root@YOUR_SERVER:/root/xboard-image/

  # Run on server:
  cd /root/xboard-image
  sha256sum -c xboard-latest.tar.gz.sha256
  gzip -dc xboard-latest.tar.gz | docker load
  docker images ${IMAGE_BASE}
  docker compose up -d
  docker compose ps
  docker compose logs --tail=100 xboard

Compose image must be:

  image: ${IMAGE_BASE}:latest

EOF
