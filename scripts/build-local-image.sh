#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
  cat <<'HELP'
Usage: scripts/build-local-image.sh

Build a local Xboard Docker image from current Git HEAD and initialized submodules.

Environment:
  IMAGE_BASE           Image repository, default ghcr.io/hercolor/xboard
  IMAGE_TAG            Image tag, default phase6-<short HEAD>
  EXPORT_IMAGE=1       Export image to tar.gz and update xboard-latest.tar.gz
  OUT=<file.tar.gz>    Export file name when EXPORT_IMAGE=1
  BUILD_DIR=<path>     Temporary clean build context path
  ALPINE_MIRROR_URL    Optional apk mirror, e.g. https://mirrors.aliyun.com/alpine
  COMPOSER_REPO_PACKAGIST Optional Composer mirror, e.g. https://mirrors.aliyun.com/composer/

Example:
  ALPINE_MIRROR_URL=https://mirrors.aliyun.com/alpine COMPOSER_REPO_PACKAGIST=https://mirrors.aliyun.com/composer/ EXPORT_IMAGE=1 ./scripts/build-local-image.sh
HELP
  exit 0
fi

IMAGE_BASE="${IMAGE_BASE:-ghcr.io/hercolor/xboard}"
COMMIT="${COMMIT:-$(git rev-parse --short=12 HEAD)}"
IMAGE_TAG="${IMAGE_TAG:-phase6-${COMMIT}}"
BUILD_DIR="${BUILD_DIR:-/tmp/xboard-local-build-${COMMIT}-$(date +%Y%m%d-%H%M%S)}"
EXPORT_IMAGE="${EXPORT_IMAGE:-0}"
ALPINE_MIRROR_URL="${ALPINE_MIRROR_URL:-}"
SOURCE_LABEL="${SOURCE_LABEL:-https://github.com/hercolor/Xboard}"
COMPOSER_REPO_PACKAGIST="${COMPOSER_REPO_PACKAGIST:-}"

log() { printf '[build-local-image] %s\n' "$*"; }
fail() { printf '[build-local-image] ERROR: %s\n' "$*" >&2; exit 1; }
need_cmd() { command -v "$1" >/dev/null 2>&1 || fail "missing command: $1"; }

need_cmd git
need_cmd docker
need_cmd python3

if [ -e "$BUILD_DIR" ]; then
  fail "BUILD_DIR already exists: $BUILD_DIR"
fi

log "creating clean build context: $BUILD_DIR"
mkdir -p "$BUILD_DIR"
git archive --format=tar HEAD | tar -x -C "$BUILD_DIR"

if [ -f .gitmodules ]; then
  while IFS= read -r submodule_path; do
    [ -n "$submodule_path" ] || continue
    [ -d "$submodule_path" ] || fail "submodule not initialized: $submodule_path"
    git -C "$submodule_path" rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "invalid submodule checkout: $submodule_path"
    submodule_commit="$(git -C "$submodule_path" rev-parse --short=12 HEAD)"
    log "copying submodule $submodule_path@$submodule_commit"
    mkdir -p "$BUILD_DIR/$submodule_path"
    git -C "$submodule_path" archive --format=tar HEAD | tar -x -C "$BUILD_DIR/$submodule_path"
  done < <(git config --file .gitmodules --get-regexp 'submodule\..*\.path' | awk '{print $2}')
fi

log "generating local-source Dockerfile"
python3 - "$BUILD_DIR/Dockerfile" "$BUILD_DIR/Dockerfile.local" "$ALPINE_MIRROR_URL" "$COMPOSER_REPO_PACKAGIST" <<'PY'
from pathlib import Path
import re
import sys
src = Path(sys.argv[1])
dst = Path(sys.argv[2])
mirror = sys.argv[3]
composer_repo = sys.argv[4]
text = src.read_text()
text = re.sub(
    r'\n# Add build arguments\nARG CACHEBUST=1\nARG REPO_URL=.*?\nARG BRANCH_NAME=.*?\n\nRUN echo "Attempting to clone branch:.*?git submodule update --init --recursive --force\n',
    '\nCOPY . /www\n',
    text,
    count=1,
    flags=re.S,
)
if 'git clone --depth 1' in text and 'COPY . /www' not in text:
    raise SystemExit('failed to replace remote clone block in Dockerfile')
if composer_repo:
    text = text.replace(
        'RUN composer install --no-cache --no-dev --no-security-blocking',
        'RUN composer config -g repo.packagist composer ' + composer_repo + ' && composer install --no-cache --no-dev --no-security-blocking',
        1,
    )
if mirror:
    text = text.replace(
        'FROM phpswoole/swoole:php8.2-alpine\n',
        "FROM phpswoole/swoole:php8.2-alpine\n\nRUN sed -i 's#https://dl-cdn.alpinelinux.org/alpine#" + mirror + "#g' /etc/apk/repositories\n",
        1,
    )
dst.write_text(text)
PY

log "building ${IMAGE_BASE}:${IMAGE_TAG} and ${IMAGE_BASE}:latest"
docker build -f "$BUILD_DIR/Dockerfile.local" \
  --label org.opencontainers.image.revision="$COMMIT" \
  --label org.opencontainers.image.source="$SOURCE_LABEL" \
  -t "${IMAGE_BASE}:${IMAGE_TAG}" \
  -t "${IMAGE_BASE}:latest" \
  "$BUILD_DIR"

log "verifying required admin assets inside image"
docker run --rm --entrypoint sh "${IMAGE_BASE}:${IMAGE_TAG}" -lc '
  test -x /entrypoint.sh
  test -f /www/artisan
  test -f /www/public/assets/admin/manifest.json
  test -f /www/public/assets/admin/index.html
  find /www/public/assets/admin/assets -maxdepth 1 -type f -name "*.js" | grep -q .
  find /www/public/assets/admin/assets -maxdepth 1 -type f -name "*.css" | grep -q .
  php -v >/dev/null
'

if [ "$EXPORT_IMAGE" = "1" ]; then
  out="${OUT:-xboard-hercolor-${IMAGE_TAG}-$(date +%Y%m%d-%H%M%S).tar.gz}"
  log "exporting ${IMAGE_BASE}:${IMAGE_TAG} -> $out"
  docker save "${IMAGE_BASE}:${IMAGE_TAG}" | gzip -c > "$out"
  sha256sum "$out" > "${out}.sha256"
  ln -f "$out" xboard-latest.tar.gz
  sha256sum xboard-latest.tar.gz > xboard-latest.tar.gz.sha256
  log "exported $(ls -lh "$out" | awk '{print $5, $9}')"
  cat "${out}.sha256"
fi

log "done: ${IMAGE_BASE}:${IMAGE_TAG}"
