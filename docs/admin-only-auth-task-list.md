# Xboard 仅后台模式任务清单（DK_Theme API 保留）

> 更新时间：2026-05-19
> 当前阶段：Phase 3 已完成后台认证切换与 Web 前台入口收口；下一阶段只冻结 DK_Theme 依赖矩阵与内置壳层后续清理项，不删除共享会员 API。

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

- [x] 处理 `/` 的行为（当前采用重定向到 `/{secure_path}` 的低风险策略）
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

- [ ] 刷新 `docs/api-interface-matrix.md` 中认证、通道、V1/V2 复用相关增量事实
- [ ] 建立 `docs/api-auth-retirement-matrix.md` 作为共享认证保留/兼容决策矩阵
- [ ] 审核 `passport/auth/token2Login`
- [ ] 审核 `passport/auth/register`
- [ ] 审核 `passport/auth/forget`
- [ ] 审核 `passport/comm/sendEmailVerify`
- [ ] 审核 `user/info` 等前台用户接口是否仍有依赖
- [ ] 将 `passport/auth/loginWithMailLink`、`passport/auth/getQuickLoginUrl` 列为 watchlist，避免后续误删相邻邮件/快速登录入口
- [ ] 产出后续实施队列；只有在确认无后台、分离前端、机器脚本、插件依赖后，才允许进入后续软封禁/删除 PRD

---

## 风险提示

- 当前环境无 `vendor/`，只能做静态验证，后续需在完整运行环境补功能回归。
- 旧共享认证链路当前必须保留，否则会直接打断现有后台编译产物。
