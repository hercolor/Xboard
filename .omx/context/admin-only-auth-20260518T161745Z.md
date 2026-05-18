# Context Snapshot — Xboard 仅后台模式第一阶段（后台专属认证 API）

- **Timestamp (UTC):** 2026-05-18T16:17:45Z
- **Task statement:** 为 Xboard 的“仅后台模式”改造落地第一阶段实现：先将后台认证从共享用户认证链路中拆出，新增后台专属认证 API，并保持现有 Sanctum Token / `auth_data` 兼容。
- **Desired outcome:** 在不破坏现有后台业务 API、数据结构、权限系统与共享用户表的前提下，为后台提供独立的 `auth/login`、`auth/me`、`auth/logout` 接口，作为后续下线会员前台登录入口的实现基础。

## Known facts / evidence

1. 已形成总体架构方案文档：`docs/admin-only-backoffice-plan.md`
   - 目标不是单纯关闭注册，而是切换为“仅后台管理 + 基础设施通道保留”的产品形态。
2. 已审计后台编译产物：`docs/admin-auth-api-usage-audit.md`
   - 当前后台登录使用 `POST /api/v2/passport/auth/login`
   - 当前后台初始化用户信息使用 `GET /api/v2/user/info`
   - 后台 logout 仅前端本地清 token
   - 后台业务 API 才走 `/{secure_path}`
3. 已形成后台专属认证 API 设计：`docs/admin-auth-api-design.md`
   - 建议新增 `POST /api/v2/{secure_path}/auth/login`
   - 建议新增 `GET /api/v2/{secure_path}/auth/me`
   - 建议新增 `POST /api/v2/{secure_path}/auth/logout`
4. 当前后台业务鉴权已存在：`app/Http/Middleware/Admin.php`
   - 使用 Sanctum 当前用户
   - 使用 `users.is_admin` 进行管理员判定
5. 当前共享登录逻辑可复用：`app/Services/Auth/LoginService.php`
   - 已包含密码校验、封禁校验、最后登录时间更新
6. 当前 token 生成逻辑可复用：`app/Services/AuthService.php`
   - `generateAuthData()` 返回 `token`、`auth_data`、`is_admin`
7. 当前 V2 路由通过 `app/Providers/RouteServiceProvider.php` 自动加载 `app/Http/Routes/V2/*.php`
8. 当前仓库缺少 `vendor/`
   - 无法进行完整 Laravel 启动验证、`artisan route:list` 或 PHPUnit
   - 可执行的验证以 `php -l`、静态代码审计、编译产物/路由映射检查为主

## Constraints

- 不能破坏现有后台业务 API 路径与权限边界
- 不能修改共享用户数据结构与 Token 体系
- 不能先删掉 `passport` / `user` 共享认证链路，否则会直接打断当前后台前端
- 必须先满足 Ralph planning gate：本轮需补齐 PRD 与 Test Spec 后再进入实现
- 不自动 push，仅本地提交

## Unknowns / open questions

- 后台前端源码是否后续会同步改成调用新接口，目前主仓库内仅能从编译产物反推依赖
- 当前后台前端对 `/user/info` 字段的最小真实依赖还需后续前端改造时进一步收口
- logout 是否未来需要 `logoutAll` 能力，本轮先实现最小必要集

## Likely codebase touchpoints

- `app/Http/Routes/V2/AdminAuthRoute.php`
- `app/Http/Controllers/V2/Admin/AuthController.php`
- `app/Http/Requests/Admin/AuthLogin.php`
- `app/Services/Auth/LoginService.php`（复用，不预期修改）
- `app/Services/AuthService.php`（复用，不预期修改）
- `app/Providers/RouteServiceProvider.php`（通常无需改动，仅作为自动加载依据）
- `docs/admin-auth-api-design.md`
- `.omx/plans/prd-admin-only-auth.md`
- `.omx/plans/test-spec-admin-only-auth.md`

## Risk tradeoffs

- 本轮先拆“后台认证外壳”，不重写底层 token 体系，可显著降低对现有后台与节点基础设施的冲击。
- 共享 `passport/user` 路由会暂时保留，直到后台前端切换完成并验证稳定后，才能进入真正的前台入口下线阶段。
