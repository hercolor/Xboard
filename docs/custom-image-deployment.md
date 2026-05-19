# Xboard 自有镜像构建与部署说明

> 更新时间：2026-05-19
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
```

当前应满足：

- PHPUnit 全量通过
- `/` 返回 302 并跳转后台安全路径
- 后台入口返回 200
- `/api/v1/guest/comm/config` 返回 200
- DK_Theme 所需 V1/V2 Passport/User 路由由 `tests/Feature/AdminOnlyShellContractTest.php` 保护

## 6. DK_Theme 兼容边界

自有镜像发布不代表关闭会员 API。以下能力必须继续保留给 DK_Theme：

- 登录：`/api/v1/passport/auth/login`
- 注册：`/api/v1/passport/auth/register`
- 找回密码：`/api/v1/passport/auth/forget`
- 邮箱验证码：`/api/v1/passport/comm/sendEmailVerify`
- 用户信息：`/api/v1/user/info`

后续如果要调整这些 API，必须先做单独 PRD、迁移方案和 DK_Theme 联调验证。
