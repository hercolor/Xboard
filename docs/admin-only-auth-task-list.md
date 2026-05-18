# Xboard 仅后台模式任务清单（认证拆分优先）

> 更新时间：2026-05-19
> 当前阶段：Phase 1 已实现后台专属认证 API，尚未切换后台前端调用与下线会员前台入口。

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

## Phase 1 — 后台专属认证 API（当前阶段）

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

### 任务

- [ ] 将后台登录请求切换为 `/{secure_path}/auth/login`
- [ ] 将后台初始化用户请求切换为 `/{secure_path}/auth/me`
- [ ] 将后台退出登录切换为 `/{secure_path}/auth/logout`
- [ ] 清理前端对白名单共享认证路径的依赖
- [ ] 回归后台登录/刷新/退出流程

---

## Phase 3 — 仅后台模式入口收口

### 目标

关闭会员 Web 前台入口，仅保留后台 SPA 与基础设施通道。

### 任务

- [ ] 处理 `/` 的行为（重定向后台或 404）
- [ ] 下线会员前台主题壳层加载
- [ ] 明确保留订阅、节点、回调、Webhook 等通道
- [ ] 回归订阅链接、支付回调、机器通信能力

---

## Phase 4 — 共享前台认证链路退役

### 目标

在后台完全切换后，再考虑下线不再需要的前台认证入口。

### 任务

- [ ] 审核 `passport/auth/register`
- [ ] 审核 `passport/auth/forget`
- [ ] 审核 `passport/comm/sendEmailVerify`
- [ ] 审核 `user/info` 等前台用户接口是否仍有依赖
- [ ] 确认无后台依赖后逐步移除/封禁

---

## 风险提示

- 当前环境无 `vendor/`，只能做静态验证，后续需在完整运行环境补功能回归。
- 旧共享认证链路当前必须保留，否则会直接打断现有后台编译产物。
