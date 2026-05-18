# Context Snapshot — Xboard Phase 3 仅后台模式 Web 入口收口

- **Timestamp (UTC):** 2026-05-18T17:03:46Z
- **Task statement:** 继续 Phase 3，仅收口后端项目内的会员 Web 前台入口；保留已经分离前端所需 API、后台入口、订阅与基础设施通道。
- **Desired outcome:** 根路径 `/` 不再渲染主题前台，而是作为后台项目入口直接跳转到 `/{secure_path}`；`/api/v1/*`、`/api/v2/*`、`/{secure_path}`、`/s/{token}` 等现有通道不受影响。

## Known facts / evidence

1. 当前 Web 入口定义于 `routes/web.php`
   - `/` 仍渲染 `theme::<theme>.dashboard`
   - `/{secure_path}` 渲染后台 `resources/views/admin.blade.php`
   - `/{subscribe_path}/{token}` 作为订阅分发路由保留
2. 当前 API 路由与 Web 路由分离：
   - `/api/v1/*`、`/api/v2/*` 由 `app/Providers/RouteServiceProvider.php` 注册
   - 本轮不需要修改 API 层
3. `ThemeService` 及相关 `File/Log` 逻辑目前只服务于 `/` 前台主题壳层渲染
4. 用户新增约束：
   - “继续 phase3，保留 api 给前端使用（已分离）”
   - 说明后端项目不再承担用户前端壳层职责，但仍需继续提供 API 能力
5. 现有 task list 中 Phase 3 尚未完成：`docs/admin-only-auth-task-list.md`

## Constraints

- 不破坏 `/api/*` 路由
- 不破坏 `/{secure_path}` 后台入口
- 不破坏 `/s/{token}` 订阅分发
- 不修改共享认证 API、节点、支付回调、Webhook
- 当前环境仍无 vendor，仅能做静态验证

## Unknowns / open questions

- 外部分离前端最终是否部署在独立域名；本轮不需要依赖该答案
- 未来 `/` 是否要改成 404 而非后台跳转；本轮采用低风险后台跳转策略

## Likely codebase touchpoints

- `routes/web.php`
- `docs/admin-only-auth-task-list.md`
- `.omx/context/admin-only-phase3-web-entry-20260518T170346Z.md`
- `.omx/plans/prd-admin-only-phase3-web-entry.md`
- `.omx/plans/test-spec-admin-only-phase3-web-entry.md`

## Risk tradeoffs

- 直接 404 会更激进，但跳转后台风险更低、迁移更平滑。
- 仅替换 `/` 路由实现，可把 blast radius 控制到最小，同时满足“后端只保留后台入口”的目标。
