# Context Snapshot — Xboard 后台前端切换到专属认证接口

- **Timestamp (UTC):** 2026-05-18T16:51:22Z
- **Task statement:** 在已新增后台专属认证 API 的基础上，继续推进第二阶段：将后台前端从共享 `/passport/auth/login` 与 `/user/info` 切换到 `/{secure_path}/auth/*`，并接入服务端 logout。
- **Desired outcome:** 后台登录、当前用户初始化、退出登录三处前端认证调用全部切到后台专属接口；保持其余后台业务请求不变；继续保留共享认证链路以兼容未迁移场景。

## Known facts / evidence

1. 第一阶段后端专属接口已实现并提交：`4975c37`
   - `POST /api/v2/{secure_path}/auth/login`
   - `GET /api/v2/{secure_path}/auth/me`
   - `POST /api/v2/{secure_path}/auth/logout`
2. 后台前端并无源码工程，当前仓库接入的是 dist 子模块：
   - `public/assets/admin`
   - submodule URL: `https://github.com/cedar2025/xboard-admin-dist.git`
3. 当前后台编译产物关键认证依赖已确认位于：
   - `public/assets/admin/assets/index-BdbgNvrf.js`
4. 已确认当前调用点：
   - 登录：`RL("/passport/auth/login", e)`
   - 当前用户：`IL("/user/info")`
   - logout：仅本地 `jf(), dispatch(resetUserState), navigate('/sign-in')`
5. 请求拦截器白名单当前仅覆盖共享登录链路：
   - `/passport/auth/login`
   - `/passport/auth/token2Login`
   - `/passport/auth/register`
   - `/guest/comm/config`
   - `/passport/comm/sendEmailVerify`
   - `/passport/auth/forget`
6. 后台业务 API 仍通过 `window.settings.secure_path` 访问，编译产物内已有 `OL=()=>window?.settings?.secure_path??""`
7. 当前无 admin 前端源码，第二阶段必须采用 **最小 dist 补丁** 策略

## Constraints

- 不能修改后台业务 API 调用
- 不能改变 token 存储键、登录态结构、路由体系
- 不能删除共享认证链路
- 仅允许在 `public/assets/admin` 子模块内做最小认证调用点切换
- 当前环境无 vendor，验证方式仍以静态审计为主

## Unknowns / open questions

- 后续是否会拿到 admin 前端源码仓库，以替代 dist patch 方式
- 忘记密码等共享 auth 辅助接口是否未来也要进入 secure_path 边界，本轮不处理

## Likely codebase touchpoints

- `public/assets/admin/assets/index-BdbgNvrf.js`
- `docs/admin-only-auth-task-list.md`
- `.omx/context/admin-auth-frontend-switch-20260518T165122Z.md`
- `.omx/plans/prd-admin-auth-frontend-switch.md`
- `.omx/plans/test-spec-admin-auth-frontend-switch.md`

## Risk tradeoffs

- 直接 patch dist 风险高于改源码，但当前仓库无更低风险替代路径。
- 只改 4 个认证触点（登录白名单、login、me、logout），可把 blast radius 压到最小。
