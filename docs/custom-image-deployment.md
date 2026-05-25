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


## 3.0 常用命令流程：本地打包镜像并部署到服务器

> 推荐以后每次本地改完 Xboard 后，都按本节执行。脚本会自动把 `public/assets/admin` 这类 submodule 静态资源打进镜像，避免服务器镜像缺少 JS/CSS。

### A. 本地构建并导出镜像

在本地 Xboard 仓库执行：

```bash
cd /home/seven/works/Xboard

# 可选：先确认工作区只包含本次要发布的代码变更
git status --short
git log -1 --oneline

# 构建 ghcr.io/hercolor/xboard:<phase-tag> 和 ghcr.io/hercolor/xboard:latest，
# 同时导出 xboard-latest.tar.gz 与 sha256 校验文件。
ALPINE_MIRROR_URL=https://mirrors.aliyun.com/alpine \
COMPOSER_REPO_PACKAGIST=https://mirrors.aliyun.com/composer/ \
EXPORT_IMAGE=1 \
./scripts/build-local-image.sh
```

构建完成后，本地会生成：

```text
xboard-latest.tar.gz
xboard-latest.tar.gz.sha256
xboard-hercolor-<tag>-<timestamp>.tar.gz
xboard-hercolor-<tag>-<timestamp>.tar.gz.sha256
```

### B. 本地验证镜像资源是否完整

```bash
docker run --rm --entrypoint sh ghcr.io/hercolor/xboard:latest -lc '
  test -x /entrypoint.sh
  test -f /www/artisan
  test -f /www/public/assets/admin/manifest.json
  test -f /www/public/assets/admin/index.html
  find /www/public/assets/admin/assets -maxdepth 1 -type f -name "*.js" | grep -q .
  find /www/public/assets/admin/assets -maxdepth 1 -type f -name "*.css" | grep -q .
  php -v >/dev/null
'
```

如果这里失败，不要上传服务器；先修复镜像缺失内容。

### C. 上传到服务器

把下面命令中的服务器地址改成实际服务器：

```bash
scp xboard-latest.tar.gz xboard-latest.tar.gz.sha256 root@YOUR_SERVER:/root/xboard-image/
```

### D. 服务器加载镜像

在服务器执行：

```bash
cd /root/xboard-image

# 校验文件完整性
sha256sum -c xboard-latest.tar.gz.sha256

# 导入镜像到服务器 Docker
gzip -dc xboard-latest.tar.gz | docker load

# 确认镜像存在
docker images ghcr.io/hercolor/xboard
```

### E. 更新 compose 并重启

确认 `compose.yaml` 使用自有镜像：

```yaml
services:
  xboard:
    image: ghcr.io/hercolor/xboard:latest
```

然后重启：

```bash
docker compose up -d
docker compose ps
docker compose logs --tail=100 xboard
```

### F. 服务器基础验收

把 `12345678` 换成实际后台安全路径：

```bash
curl -i http://127.0.0.1:7001/ | head
curl -i http://127.0.0.1:7001/12345678 | head
curl -i http://127.0.0.1:7001/api/v1/guest/comm/config | head
```

期望：

- `/` 返回 404；
- 后台安全路径返回 200；
- `/api/v1/guest/comm/config` 返回 200。

### G. 回滚方式

如果新镜像异常，可以把服务器 `compose.yaml` 的镜像改回上一版 tag，或重新加载上一版 tar 包：

```bash
gzip -dc xboard-previous.tar.gz | docker load
docker compose up -d
```

如果服务器仍保留旧容器镜像，也可以先查看可用镜像：

```bash
docker images ghcr.io/hercolor/xboard
```

## 3.1 本地源码镜像打包流程（包含 submodule assets）

`public/assets/admin` 是 Git submodule，里面包含后台前端的 `manifest.json`、JS、CSS、字体和 locales。只用 `git archive HEAD` 打包主仓库时不会包含 submodule 内容，会导致镜像中 `/www/public/assets/admin/assets/*.js` 和 `*.css` 缺失。

本地构建必须使用脚本：

```bash
# 如 Alpine 官方源超时，可带国内镜像源
ALPINE_MIRROR_URL=https://mirrors.aliyun.com/alpine \
COMPOSER_REPO_PACKAGIST=https://mirrors.aliyun.com/composer/ \
EXPORT_IMAGE=1 \
./scripts/build-local-image.sh
```

脚本会自动执行：

1. 用当前 Git HEAD 创建干净 build context；
2. 逐个把 `.gitmodules` 中的 submodule 内容 archive 到 build context；
3. 生成临时 `Dockerfile.local`，把默认远程 clone 改成 `COPY . /www`；
4. 可选切换 Alpine 与 Composer 镜像源，降低网络超时概率；
5. 构建：
   - `ghcr.io/hercolor/xboard:phase6-<commit>`
   - `ghcr.io/hercolor/xboard:latest`
6. 在镜像内验证：
   - `/entrypoint.sh` 可执行；
   - `/www/artisan` 存在；
   - `/www/public/assets/admin/manifest.json` 存在；
   - `/www/public/assets/admin/assets/*.js` 存在；
   - `/www/public/assets/admin/assets/*.css` 存在；
7. `EXPORT_IMAGE=1` 时导出 tar.gz，并更新：
   - `xboard-latest.tar.gz`
   - `xboard-latest.tar.gz.sha256`

手动验证镜像中的 assets：

```bash
docker run --rm --entrypoint sh ghcr.io/hercolor/xboard:latest -lc '
  ls -la /www/public/assets/admin
  ls -la /www/public/assets/admin/assets | head
  test -f /www/public/assets/admin/manifest.json
  find /www/public/assets/admin/assets -maxdepth 1 -type f -name "*.js" | grep -q .
  find /www/public/assets/admin/assets -maxdepth 1 -type f -name "*.css" | grep -q .
'
```

服务器加载：

```bash
gzip -dc xboard-latest.tar.gz | docker load
docker images ghcr.io/hercolor/xboard
docker compose up -d
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
