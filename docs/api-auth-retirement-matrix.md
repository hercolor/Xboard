# Xboard 共享认证退役矩阵（Phase 4A/4B）

> 更新时间：2026-05-19  
> 范围：仅后台模式后，审计共享前台认证链路是否可退役。  
> 结论：本阶段只冻结矩阵和后续实施队列；**不删除接口、不软封禁未知依赖、不做 AES 返回加密**。

---

## 1. 执行边界

本矩阵用于回答：哪些共享前台认证接口仍是前台 API 契约、哪些只是后台编译产物残留、哪些可以进入后续软封禁/迁移/删除 PRD。

硬规则：

1. `external frontend dependency = unknown` 时，退役决策只能是 `keep` / `no-touch` / `needs external confirmation`。
2. 订阅 `/s/{token}`、节点 API、支付回调、Webhook、插件 Hook、后台导出流不纳入普通 JSON 统一响应或 AES 加密。
3. V2 `passport/user` 仍复用 V1 controller；任何 V1/V2 单边修改都可能双版本共振。
4. admin dist 字符串命中不等于 live dependency，必须和运行路径证据分开记录。
5. `user/info` 当前不是后台初始化 blocker；后台已切到 `/{secure_path}/auth/me`，这里只验证是否仍有其他消费者。

---

## 2. 证据索引

| 证据 | 位置 | 说明 |
|---|---|---|
| 后台专属认证路由 | `app/Http/Routes/V2/AdminAuthRoute.php` | `/{secure_path}/auth/login|me|logout` |
| 后台专属认证控制器 | `app/Http/Controllers/V2/Admin/AuthController.php` | 登录限制 `is_admin`，`me` 返回后台精简画像，`logout` 删除当前 token |
| V1 Passport 路由 | `app/Http/Routes/V1/PassportRoute.php` | 共享注册、登录、邮件登录、验证码入口 |
| V2 Passport 路由 | `app/Http/Routes/V2/PassportRoute.php` | 复用 `V1\\Passport` controller，接口集合与 V1 基本一致 |
| V1 Passport Auth 控制器 | `app/Http/Controllers/V1/Passport/AuthController.php` | `register/token2Login/forget/getQuickLoginUrl/loginWithMailLink` |
| V1 Passport Comm 控制器 | `app/Http/Controllers/V1/Passport/CommController.php` | `sendEmailVerify`，含 captcha、白名单、缓存节流、发信副作用 |
| V1 User 路由 | `app/Http/Routes/V1/UserRoute.php` | `GET /user/info` 等会员用户接口 |
| V2 User 路由 | `app/Http/Routes/V2/UserRoute.php` | 只挂 `resetSecurity/info`，复用 V1 `UserController` |
| V1 User 控制器 | `app/Http/Controllers/V1/User/UserController.php` | `info` 返回会员前台画像字段 |
| 前台 API 草案 | `docs/user-frontend-api-draft.md` | 明确记录 passport 与 `user/info` 为前台 API 契约 |
| V1/V2 复用审计 | `docs/api-v2-v1-reuse-decoupling-audit.md` | 记录 V2 Passport/User 复用 V1 controller 与混合响应风险 |
| admin dist | `public/assets/admin/assets/index-BdbgNvrf.js` | 当前编译产物仍含部分 passport allowlist 字符串；同时含 `auth/me`、`auth/logout` |

---

## 3. 必审 endpoint 退役矩阵

| Endpoint | Method | Channel | Auth | Response type | V1/V2 reuse | Current admin dependency | Admin unauth allowlist | Compiled allowlist residue | External frontend dependency | Other dependency | Runtime/E2E gap | Retirement decision | Verification |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `/api/v1/passport/auth/token2Login`<br>`/api/v2/passport/auth/token2Login` | GET | Frontend API / mail-link login | guest | `redirect` when `token`; raw JSON when `verify` / error | V1 + V2 both use `V1\Passport\AuthController::token2Login` | No current admin flow evidence after Phase 2; prior audit recorded old admin risk | Residual/unknown: string appears near admin unauth compatibility list in dist; not runtime-confirmed | Yes: `public/assets/admin/assets/index-BdbgNvrf.js` contains `token2Login` | documented in `docs/user-frontend-api-draft.md` | mail-link / quick-login clients possible | Browser/admin and separated frontend E2E deferred; runtime admin smoke only proves admin shell unaffected | `keep` + `needs external confirmation`; no soft-disable | routes: `app/Http/Routes/V1/PassportRoute.php`, `app/Http/Routes/V2/PassportRoute.php`; controller: `AuthController::token2Login`; docs: frontend draft + reuse audit |
| `/api/v1/passport/auth/register`<br>`/api/v2/passport/auth/register` | POST | Frontend API / account creation | guest | `success(auth_data)` / `fail` | V1 + V2 both use `V1\Passport\AuthController::register` | No current admin dependency | Residual/unknown: dist string exists; not runtime-confirmed admin dependency | Yes: admin dist contains `auth/register` | documented in `docs/user-frontend-api-draft.md` | separated frontend registration, invite/captcha configuration via service | Separated frontend registration E2E deferred | `keep` + `needs external confirmation`; later configurable hide/disable only after PRD | routes: V1/V2 PassportRoute; request: `AuthRegister`; controller: `register`; docs: frontend draft |
| `/api/v1/passport/auth/forget`<br>`/api/v2/passport/auth/forget` | POST | Frontend API / password reset | guest | `success(true)` / `fail` | V1 + V2 both use `V1\Passport\AuthController::forget` | No current admin dependency | Residual/unknown: dist string exists; not runtime-confirmed admin dependency | Yes: admin dist contains `auth/forget` | documented in `docs/user-frontend-api-draft.md` | separated frontend password reset | Separated frontend forgot-password E2E deferred | `keep` + `needs external confirmation`; no delete | routes: V1/V2 PassportRoute; request: `AuthForget`; controller: `forget`; docs: frontend draft |
| `/api/v1/passport/comm/sendEmailVerify`<br>`/api/v2/passport/comm/sendEmailVerify` | POST | Frontend API / email verification | guest | `success(true)` / `fail`; side-effect email job | V1 + V2 both use `V1\Passport\CommController::sendEmailVerify` | No current admin dependency | Residual/unknown: dist string exists; not runtime-confirmed admin dependency | Yes: admin dist contains `sendEmailVerify` | documented in `docs/user-frontend-api-draft.md` | registration/reset/mail verification flow; captcha/cache/email settings | Email/captcha integration E2E deferred | `keep` + `needs external confirmation`; no blanket response change | routes: V1/V2 PassportRoute; request: `CommSendEmailVerify`; controller: `sendEmailVerify`; docs: frontend draft |
| `/api/v1/user/info`<br>`/api/v2/user/info` | GET | Frontend API / member profile | Sanctum User | `success(user profile)` | V1 route uses `V1\User\UserController::info`; V2 route reuses same V1 controller | No current admin blocker; admin init moved to `/{secure_path}/auth/me` | No current dist string hit in latest scan; historical old admin dependency documented | Not found in current admin dist string scan; prior audit documents historical admin use | documented in `docs/user-frontend-api-draft.md` | separated frontend user dashboard/profile | Separated frontend member dashboard/profile E2E deferred | `keep` + `remaining-consumer verification`; no delete | routes: `app/Http/Routes/V1/UserRoute.php`, `app/Http/Routes/V2/UserRoute.php`; controller: `UserController::info`; docs: frontend draft |

---

## 4. Watchlist（相邻观察面，不进入本轮退役）

| Endpoint | Method | Why watch | Evidence | Decision |
|---|---|---|---|---|
| `/api/v1/passport/auth/loginWithMailLink`<br>`/api/v2/passport/auth/loginWithMailLink` | POST | 与 `token2Login` 属于同一邮件登录链路；误删会破坏 mail-link login | route: V1/V2 PassportRoute; controller: `AuthController::loginWithMailLink`; docs: `docs/user-frontend-api-draft.md` | `watch-only`; no soft-disable/delete in this phase |
| `/api/v1/passport/auth/getQuickLoginUrl`<br>`/api/v2/passport/auth/getQuickLoginUrl` | POST | 与 token 登录和已有 bearer token 快速登录相关，失败分支有 raw 401 JSON | route: V1/V2 PassportRoute; controller: `AuthController::getQuickLoginUrl`; docs: `docs/user-frontend-api-draft.md`; reuse audit records mixed response | `watch-only`; no soft-disable/delete in this phase |

---

## 5. 后台专属认证冻结项

| Endpoint | Method | Channel | Auth | Response type | Role after Phase 3 |
|---|---|---|---|---|---|
| `/api/v2/{secure_path}/auth/login` | POST | Admin API | guest, controller checks `is_admin` | `success(auth_data)` / `fail` | 后台登录入口，替代共享 `passport/auth/login` 的后台用途 |
| `/api/v2/{secure_path}/auth/me` | GET | Admin API | `user` + `admin` middleware | `success(admin profile)` | 后台初始化当前管理员，替代旧后台对 `user/info` 的依赖 |
| `/api/v2/{secure_path}/auth/logout` | POST | Admin API | `user` + `admin` middleware | `success(true)` | 后台退出当前 token |

---

## 6. 后续实施队列

| Priority | Task | Preconditions | Allowed changes |
|---|---|---|---|
| P0 | 保持本矩阵与 `docs/api-interface-matrix.md` 同步 | 本文档已落地 | 文档、测试、smoke；不改接口 |
| P1 | 规划“取消会员登录”的配置化策略 | 分离前端依赖确认；产品确认仍需 API 登录还是只隐藏 Web 登录 | PRD/test-spec；可设计配置开关，但不直接删除 |
| P2 | 共享 Passport/User V1/V2 解耦 | retirement matrix 确认哪些 endpoint 仍保留 | 新兼容层、独立 V2 shell、deprecation note |
| P3 | 软封禁或物理删除候选 | 无 admin、分离前端、机器脚本、插件依赖；有回滚方案 | 单独 PRD + 测试 + 版本迁移说明 |
| P4 | 响应 AES / 统一 envelope | response channel matrix 完成；明确排除订阅、节点、回调、导出流 | 仅对明确业务 JSON 通道做 opt-in |

---

## 7. 验证记录口径

静态验证应覆盖：

```bash
rg -n "token2Login|passport/auth/register|passport/auth/forget|sendEmailVerify|user/info" app routes resources public docs plugins
rg -n "loginWithMailLink|getQuickLoginUrl" app routes resources public docs plugins
rg -n "auth/me|auth/login|auth/logout|secure_path" public/assets/admin app/Http/Routes app/Http/Controllers docs
```

运行验证命令：

```bash
set +e
./scripts/dev-up.sh
DEV_UP=$?
./scripts/dev-status.sh

ROOT_HEADERS="$(curl -IsS --max-time 5 http://127.0.0.1:8001/)"
printf '%s\n' "$ROOT_HEADERS" | head -20
ROOT_CODE="$(printf '%s\n' "$ROOT_HEADERS" | awk 'NR==1 {print $2}')"
ADMIN_PATH="$(printf '%s\n' "$ROOT_HEADERS" | awk 'tolower($1)=="location:" {print $2}' | tr -d '\r' | sed -E 's#^https?://[^/]+##; s#^/##; s#/$##')"
ADMIN_CODE="000"
[ -n "$ADMIN_PATH" ] && ADMIN_CODE="$(curl -sS --max-time 5 -o /dev/null -w '%{http_code}' "http://127.0.0.1:8001/${ADMIN_PATH}" 2>/dev/null || echo 000)"
API_CODE="$(curl -sS --max-time 5 -o /dev/null -w '%{http_code}' http://127.0.0.1:8001/api/v1/guest/comm/config 2>/dev/null || echo 000)"
printf 'DEV_UP=%s\nROOT_CODE=%s\nADMIN_PATH=%s\nADMIN_CODE=%s\nAPI_CODE=%s\n' "$DEV_UP" "$ROOT_CODE" "$ADMIN_PATH" "$ADMIN_CODE" "$API_CODE"
./scripts/dev-down.sh
```

运行验证 pass condition：

- `/` 返回 `302`，且存在 `Location` header。
- 从 `/` 的 `Location` 提取出的 `/${ADMIN_PATH}` 返回 `200`，这是后台入口真值。
- `/api/v1/guest/comm/config` 返回非 `5xx`，用于确认 guest API 壳层未被破坏。
- `scripts/dev-up.sh` / `scripts/dev-status.sh` 中基于 `APP_KEY` hash 推导的 `secure_path` 只能作为启动/状态辅助信息，不作为最终 pass/fail 依据。
- 本阶段若只改文档，runtime smoke 失败也必须记录原因；不得声称完整浏览器 E2E 已通过。

---

## 8. 当前结论

- 五个必审 endpoint 都仍属于前台 API 契约或剩余消费者验证项，当前没有可直接删除项。
- `token2Login` 和 watchlist 邮件/快速登录链路存在混合响应，不适合作为普通 JSON/AES 首批目标。
- `user/info` 不再阻塞后台初始化，但仍是分离前端会员资料契约，应保留。
- 下一步代码类工作应先做配置化软封禁 PRD 或 V1/V2 解耦 PRD，而不是直接删除共享路由。
