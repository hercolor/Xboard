# Xboard 自有镜像构建与部署说明

> 更新时间：2026-05-25
> 范围：只说明如何用自有镜像替代官方镜像；不改变 DK_Theme API 契约，不删除共享 `Passport/User/Guest` 路由，不做 AES 响应加密。

## 1. 镜像命名

当前推荐镜像名：

```text
ghcr.io/hercolor/xboard:latest
```

本仓库已有 GitHub Actions 工作流：

```text
.github/workflows/docker-publish.yml
```

工作流会把 `${GITHUB_REPOSITORY}` 统一转成小写镜像名。对于 `hercolor/Xboard`，发布目标就是：

```text
ghcr.io/hercolor/xboard
```

## 2. 自动发布触发

工作流触发条件：

- push 到 `master`
- push 到 `new-dev`
- 手动 `workflow_dispatch`

所有触发分支都会发布：

- 分支名 tag
- 短 SHA tag
- `git describe --tags --always` 版本 tag

`master` 分支会额外发布：

- `latest`
- `new`

## 3. Dockerfile 构建来源

当前 `Dockerfile` 在构建镜像时会 clone 远程仓库。工作流已传入：

```yaml
build-args: |
  REPO_URL=https://github.com/${{ github.repository }}
  BRANCH_NAME=${{ github.ref_name }}
```

所以在 `hercolor/Xboard` 触发构建时，镜像内容来自你的仓库和当前分支，而不是官方 `cedar2025/Xboard`。


## 3.1 本地源码镜像打包流程（不依赖远程 clone）

当本地已有未发布到 GitHub Container Registry 的提交时，不要直接使用仓库根目录的默认 `Dockerfile` 做本地发布包，因为默认 `Dockerfile` 会在构建阶段 clone 远程仓库。推荐使用当前 Git HEAD 生成干净 build context，再把源码 `COPY` 到镜像：

```bash
COMMIT=$(git rev-parse --short=12 HEAD)
BUILD_DIR="/tmp/xboard-local-build-${COMMIT}-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BUILD_DIR"
git archive --format=tar HEAD | tar -x -C "$BUILD_DIR"

python3 - <<'PYLOCAL' "$BUILD_DIR/Dockerfile" "$BUILD_DIR/Dockerfile.local"
from pathlib import Path
import sys
src, dst = map(Path, sys.argv[1:3])
text = src.read_text()
text = text.replace(
    "ARG REPO_URL=https://github.com/cedar2025/Xboard\nARG BRANCH_NAME=master\nRUN git clone --depth 1 --branch ${BRANCH_NAME} ${REPO_URL} .",
    "COPY . /www",
)
dst.write_text(text)
PYLOCAL

docker build -f "$BUILD_DIR/Dockerfile.local" \
  --label org.opencontainers.image.revision="$COMMIT" \
  --label org.opencontainers.image.source="https://github.com/hercolor/Xboard" \
  -t "ghcr.io/hercolor/xboard:phase6-${COMMIT}" \
  -t "ghcr.io/hercolor/xboard:latest" \
  "$BUILD_DIR"
```

如果 Alpine 官方 CDN 在构建时出现 `Operation timed out`，可只在临时 `Dockerfile.local` 顶部加入镜像源切换，不提交业务代码：

```dockerfile
RUN sed -i 's#https://dl-cdn.alpinelinux.org/alpine#https://mirrors.aliyun.com/alpine#g' /etc/apk/repositories
```

本地导出给服务器使用：

```bash
OUT="xboard-hercolor-phase6-${COMMIT}-$(date +%Y%m%d-%H%M%S).tar.gz"
docker save "ghcr.io/hercolor/xboard:phase6-${COMMIT}" | gzip -c > "$OUT"
sha256sum "$OUT" > "${OUT}.sha256"
ln -f "$OUT" xboard-latest.tar.gz
sha256sum xboard-latest.tar.gz > xboard-latest.tar.gz.sha256
```

本次已生成并验证：

```text
镜像: ghcr.io/hercolor/xboard:phase6-1659b25cb5f3
镜像: ghcr.io/hercolor/xboard:latest
Image ID: sha256:8fe30e85f3ffc81967afcf3c716bcd612e5c2ed2330cf67c09b09b14130e70e4
导出包: xboard-hercolor-phase6-1659b25cb5f3-20260525-001826.tar.gz
SHA256: 7f7fe6066d93a899f8d21c76f821e01f32367e60725436766fabcb719831bc8b
```

服务器加载：

```bash
gzip -dc xboard-hercolor-phase6-1659b25cb5f3-20260525-001826.tar.gz | docker load
# 或者直接使用 latest 导出包
gzip -dc xboard-latest.tar.gz | docker load

docker images ghcr.io/hercolor/xboard
```

然后把 Compose 里的镜像改成：

```yaml
image: ghcr.io/hercolor/xboard:latest
```

再执行：

```bash
docker compose up -d
```

验证容器入口时，可用：

```bash
docker run --rm --entrypoint sh ghcr.io/hercolor/xboard:phase6-1659b25cb5f3 \
  -lc 'test -x /entrypoint.sh && php -v && test -f /www/artisan'
```

注意：当前源码没有 `composer.lock`，镜像构建时 Composer 会解析最新依赖版本。后续如需完全可复现构建，应单独评估是否提交锁文件。

## 4. 1Panel / Compose 替换方式

把原来的官方镜像：

```yaml
image: ghcr.io/cedar2025/xboard:latest
```

替换为自有镜像：

```yaml
image: ghcr.io/hercolor/xboard:latest
```

示例：

```yaml
services:
  xboard:
    image: ghcr.io/hercolor/xboard:latest
    restart: unless-stopped
    ports:
      - "7001:7001"
    networks:
      - default
      - 1panel-network
    volumes:
      - ./.env:/www/.env
      - ./.docker/.data/:/www/.docker/.data
      - ./storage/logs:/www/storage/logs
      - ./storage/theme:/www/storage/theme
      - ./plugins:/www/plugins
      - redis-data:/data
    environment:
      - RESOURCE_PROFILE=balanced
      - ENABLE_HORIZON=true
      - docker=true

networks:
  1panel-network:
    external: true

volumes:
  redis-data:
```

## 5. 发布前检查

发布镜像前至少确认：

```bash
.local/bin/php-xboard ./vendor/bin/phpunit --bootstrap vendor/autoload.php tests
./scripts/dev-up.sh
./scripts/dev-status.sh
./scripts/e2e-smoke.sh
```

当前应满足：

- PHPUnit 全量通过
- `/` 返回 404，不跳转后台安全路径
- 后台入口返回 200
- `/api/v1/guest/comm/config` 返回 200
- DK_Theme 所需 V1/V2 Passport/User 路由由 `tests/Feature/AdminOnlyShellContractTest.php` 保护
- `./scripts/e2e-smoke.sh` 通过后台 auth、DK_Theme API 契约、订阅、Guest config/plan、Telegram webhook 与 payment notify 路由边界 smoke

## 6. DK_Theme 兼容边界

自有镜像发布不代表关闭会员 API。以下能力必须继续保留给 DK_Theme：

- 登录：`/api/v1/passport/auth/login`
- 注册：`/api/v1/passport/auth/register`
- 找回密码：`/api/v1/passport/auth/forget`
- 邮箱验证码：`/api/v1/passport/comm/sendEmailVerify`
- 用户信息：`/api/v1/user/info`

后续如果要调整这些 API，必须先做单独 PRD、迁移方案和 DK_Theme 联调验证。
