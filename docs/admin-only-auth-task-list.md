# Xboard 仅后台模式任务清单（DK_Theme API 保留）

> 更新时间：2026-05-19
> 当前阶段：Phase 5 已完成测试环境修复、完整 PHPUnit、本地运行 smoke 与自有镜像发布准备；剩余浏览器级后台登录、真实订阅 token、DK_Theme 联调和回调类 smoke。

---

## Phase 0 — 架构审计与方案沉淀

- [x] 梳理仅后台模式总体方案
- [x] 审计后台当前认证调用链路
- [x] 形成后台专属 Auth API 设计方案
- [x] 补齐 Ralph context / PRD / Test Spec

关联文档：

- `docs/admin-only-backoffice-plan.md`
- `docs/admin-auth-api-usage-audit.md`
- `docs/admin-auth-api-design.md`
- `.omx/plans/prd-admin-only-auth.md`
- `.omx/plans/test-spec-admin-only-auth.md`

---

## Phase 1 — 后台专属认证 API

### 目标

把后台认证从共享 `passport/user` 链路中拆出，但暂不删除旧链路。

### 任务

- [x] 新增 `POST /api/v2/{secure_path}/auth/login`
- [x] 新增 `GET /api/v2/{secure_path}/auth/me`
- [x] 新增 `POST /api/v2/{secure_path}/auth/logout`
- [x] 保持复用 `LoginService` 与 `AuthService`
- [x] 保持 `auth_data` / Bearer Token 兼容
- [x] 在登录口前移 `is_admin` 准入判断
- [x] 完成静态语法校验（`php -l`）

### 代码落点

- `app/Http/Routes/V2/AdminAuthRoute.php`
- `app/Http/Controllers/V2/Admin/AuthController.php`
- `app/Http/Requests/Admin/AuthLogin.php`

---

## Phase 2 — 后台前端切换到专属认证接口

### 目标

让后台前端不再依赖：

- `/api/v2/passport/auth/login`
- `/api/v2/user/info`

### 说明

- 当前仓库没有后台前端源码，本阶段通过 `public/assets/admin` dist 子模块做最小认证补丁完成切换。

### 任务

- [x] 将后台登录请求切换为 `/{secure_path}/auth/login`
- [x] 将后台初始化用户请求切换为 `/{secure_path}/auth/me`
- [x] 将后台退出登录切换为 `/{secure_path}/auth/logout`
- [x] 清理前端对白名单共享认证路径的依赖（登录入口已放行 `secure_path/auth/login`，共享辅助白名单保留兼容）
- [ ] 回归后台登录/刷新/退出流程（待完整浏览器运行环境）

---

## Phase 3 — 仅后台模式入口收口

### 目标

关闭会员 Web 前台入口，仅保留后台 SPA 与基础设施通道。

### 说明

- 当前后端项目不再承担会员前台壳层渲染职责，外部分离前端继续通过 API 使用后端能力。

### 任务

- [x] 处理 `/` 的行为（当前按隐藏后台入口要求返回 404，不跳转 `/{secure_path}`）
- [x] 下线会员前台主题壳层加载（`routes/web.php` 不再渲染 `theme::*.dashboard`）
- [x] 明确保留订阅、节点、回调、Webhook 等通道（本轮未改 `/api/*`、`/s/{token}` 与基础设施通道）
- [ ] 回归订阅链接、支付回调、机器通信能力（待完整运行环境）

---

## Phase 4 — DK_Theme 兼容矩阵冻结

### 目标

确认并冻结 DK_Theme 对共享 `Passport/User/Guest` API 的依赖，防止后续把“关闭 Xboard 内置前台壳层”误做成“禁用会员 API”。

### 执行边界

- 本阶段只做审计、矩阵冻结和后续实施队列。
- 不删除接口，不做 AES 返回加密，不修改订阅、节点、支付回调、Webhook、插件 Hook 通道。
- DK_Theme 的登录、注册、找回密码、邮箱验证码、`user/info` 必须保持可用。
- 分离前端依赖为 `unknown` 时，视为删除/软封禁阻断条件。
- `user/info` 当前不再是后台初始化 blocker；它是剩余消费者验证项。

### 任务

- [x] 刷新 `docs/api-interface-matrix.md` 中认证、通道、V1/V2 复用相关增量事实
- [x] 建立 `docs/api-auth-retirement-matrix.md` 作为共享认证保留/兼容决策矩阵
- [x] 审核 `passport/auth/token2Login`
- [x] 审核 `passport/auth/register`
- [x] 审核 `passport/auth/forget`
- [x] 审核 `passport/comm/sendEmailVerify`
- [x] 审核 `user/info` 等前台用户接口是否仍有依赖
- [x] 将 `passport/auth/loginWithMailLink`、`passport/auth/getQuickLoginUrl` 列为 watchlist，避免后续误删相邻邮件/快速登录入口
- [x] 产出后续实施队列；只有在确认无后台、分离前端、机器脚本、插件依赖后，才允许进入后续软封禁/删除 PRD

### 验证证据

- `docs/api-auth-retirement-matrix.md` 已记录 DK_Theme 源码依赖证据与 `keep` 决策。
- `tests/Feature/AdminOnlyShellContractTest.php` 已用 booted Laravel route collection 锁定：
  - `/` 返回 404，不暴露后台安全路径
  - 后台壳层路由仍挂载
  - `/s/{token}` 订阅路由仍挂载
  - V1/V2 Passport 登录、注册、找回密码、邮箱验证码、token/mail-link/quick-login 路由仍挂载
  - V1/V2 `user/info` 仍挂载并保留 `user` middleware
  - V1 Guest config/plan/payment notify/telegram webhook 仍挂载
- 目标测试通过：`./vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Feature/AdminOnlyShellContractTest.php` → `2 tests, 28 assertions`。

---

## Phase 5 — 完整运行环境回归与镜像发布准备

### 目标

在不改变 DK_Theme API 契约的前提下，完成可运行环境验证，并为后续使用自有镜像替代官方镜像做准备。

### 执行边界

- 不删除、不软封禁共享 `Passport/User/Guest` API。
- 不做 AES 返回加密。
- 不修改订阅、节点、支付回调、Webhook、插件 Hook 通道。
- 镜像发布准备只产出脚本/文档/配置建议；真正推送镜像前需具备仓库 token 与 GHCR 权限。

### 任务

- [x] 修复本地测试环境缺失 sqlite PDO driver 的问题，或明确改用可用测试数据库。
- [x] 跑完整 PHPUnit，确认新增路由契约测试与既有 ServerHandshake 测试都通过。
- [x] 启动 Xboard 完整运行环境。
- [x] 回归 `/` → 404，且 `/{secure_path}` 后台入口仍可访问。
- [x] 回归后台登录、刷新 `auth/me`、退出登录。
- [x] 回归 `/s/{token}` 订阅链路。
- [x] 回归 DK_Theme 登录、注册、找回密码、邮箱验证码、`user/info` 的后端 API 契约。
- [x] 回归 V1 Guest payment notify/telegram webhook 的 smoke 级可达性。
- [x] 回归 V1 Guest config/plan 的 smoke 级可达性。
- [x] 梳理自有镜像命名与 tag 策略，默认候选：`ghcr.io/hercolor/xboard:latest`。
- [x] 增加或更新构建/发布文档，说明 compose 中如何从官方镜像切换到自有镜像。
- [x] 如需自动发布，新增 GitHub Actions 构建 GHCR 镜像的方案或工作流。


### 已完成验证记录

- 测试环境：`.local/bin/php-xboard` 已加载 `pdo_mysql,pdo_sqlite,sqlite3`。
- 完整 PHPUnit：`.local/bin/php-xboard ./vendor/bin/phpunit --bootstrap vendor/autoload.php tests` → `9 tests, 40 assertions`。
- 本地运行环境：`./scripts/dev-up.sh` 通过，输出 `/ -> 404`、`/{secure_path} -> 200`、guest config API `200`。
- `./scripts/dev-status.sh` 确认 Redis OK、SQLite DB、后台入口 `200`、API `200`。
- 额外 smoke：`/api/v1/guest/comm/config` → `200`，`/api/v1/guest/plan/fetch` → `200`，空 payload 访问 `passport/auth/login` 返回 `422`，未登录访问 `user/info` 返回 `403`，证明路由可达且仍保留校验/鉴权边界。

- E2E smoke 脚本：`./scripts/e2e-smoke.sh` 会创建临时 admin/member/plan/group/shadowsocks 节点 fixture，验证后自动清理。
- E2E smoke 覆盖：
  - `/` → `404`，`/{secure_path}` → `200`（`secure_path` 读取系统配置）。
  - 后台 `POST /api/v2/{secure_path}/auth/login`、`GET /auth/me`、`POST /auth/logout`，并确认 logout 后 token 失效。
  - DK_Theme 后端 API 契约：V1/V2 `passport/auth/login`、V1 `passport/auth/register`、V1 `passport/auth/forget`、V1 `passport/comm/sendEmailVerify`、V1/V2 `user/info`、`getQuickLoginUrl`、`token2Login` redirect。
  - 订阅链路：临时可用用户 + 临时 SS 节点，`/{subscribe_path}/{token}` 返回 `200`、带 `subscription-userinfo`，base64 解码后包含 `ss://` 节点。
  - Guest：`comm/config`、`plan/fetch` 返回 `200`；Telegram 空 update webhook 返回 `200`；payment notify 路由到达 controller 边界，因无支付插件/provider fixture 返回预期失败而非 404/405。
- E2E smoke 结果：`./scripts/e2e-smoke.sh` → `E2E smoke passed`，fixture 清理后 `xboard-e2e-*` 用户/节点/套餐/分组计数均为 `0`。
- 自有镜像文档：`docs/custom-image-deployment.md`。
- GHCR workflow：`.github/workflows/docker-publish.yml` 统一小写镜像名，并在 metadata 前生成 version。

---

## 风险提示

- 当前环境已有 `vendor/`；通过 `.local/bin/php-xboard` 加载本地 DB 扩展后，完整 PHPUnit 已通过。
- 旧共享认证链路当前必须保留，否则会打断 DK_Theme 登录、注册、找回密码、邮箱验证码与 `user/info`。
