# 前后端契约对照表（DK_Theme ↔ Xboard）

> 角色视角：架构师
> 目标：把 DK_Theme 前端与 Xboard 后端的**真实对接关系**固化为基准。
> 用途：后续前端二开（契约重构 / 功能增删改 / 品牌视觉）的全部决策，都先查这张表。
>
> 维护规则：**前端只通过 `src/lib/api/` 这一层与后端耦合。** 这张表描述的就是那一层与后端路由的真实映射。改任何 `services/*` 或 `types.ts`，都要回这里同步。

---

## 1. 一句话结论

前端所有调用走两条通道：

- **`/api/v1`** — 用户态主通道（兼容底座，强兼容优先）
- **`/api/app/v1`** — App BFF 聚合通道（**叠加层，非替代层**，失败必须回落 `/api/v1`）

整体契约对齐度高，**无死链**。前后端是协同演进的，不是各写各的。

前端只认 2 样东西：
- `apiClient`（`src/lib/api/client.ts` 的 axios 实例，自动注入 `Authorization` token）
- `ApiEnvelope<T>` = `{ data, message?, status? }`（标准信封）

> 只要后端保持这个信封格式，前端可以随意重构 UI。

---

## 2. 契约对照表

### 2.1 认证通道（`services/auth.ts` ↔ `Routes/V1/PassportRoute`）

| 前端函数 | 后端端点 | 备注 |
|---|---|---|
| `login` | `POST /api/v1/passport/auth/login` | 字段 `account` / `password` |
| `register` | `POST /api/v1/passport/auth/register` | 字段 `email` / `email_code` / `password` / `invite_code?` |
| `sendRegisterEmailVerify` | `POST /api/v1/passport/comm/sendEmailVerify` | |
| `sendForgotPasswordVerify`（手机） | `POST /api/v1/passport/comm/sendPhoneVerify` | |
| `resetForgotPassword` | `POST /api/v1/passport/auth/forget` | ✅ 已焊死单端点（见 §5） |

> 后端 `AuthForget` 校验字段：`account` / `phone` / `email` / `password`(必填,≥8) / `email_code` / `phone_code` / `code`。前端传 `account` + `email`(邮箱时) + `email_code`/`phone_code` + `password`，完全匹配。

### 2.2 用户域（`services/user.ts` / `services/settings.ts` ↔ `Routes/V1/UserRoute`）

| 前端函数 | 后端端点 | 备注 |
|---|---|---|
| `getUserInfo` | `GET /api/v1/user/info` | 前端 `normalizeUserInfo` 整形 `plan` 字段 |
| `getSubscribeInfo` | `GET /api/v1/user/getSubscribe` | 前端 `normalizeSubscribeInfo` 兼容 plan 为 string/对象 |
| `getPlans` | `GET /api/v1/user/plan/fetch` | |
| `changePassword` | `POST /api/v1/user/changePassword` | |
| `updateReminderSettings` | `POST /api/v1/user/update` | `remind_expire` / `remind_traffic` |
| `sendPhoneVerify` | `POST /api/v1/user/phone/sendVerify` | |
| `bindPhone` | `POST /api/v1/user/phone/bind` | |

### 2.3 订单 / 工单 / 邀请 / 流量 / 节点

| 前端函数 | 后端端点 | 备注 |
|---|---|---|
| `getOrders` | `GET /api/v1/user/order/fetch` | |
| `getOrderDetail` | `GET /api/v1/user/order/detail?trade_no=` | |
| `getPaymentMethods` | `GET /api/v1/user/order/getPaymentMethod` | |
| `cancelOrder` | `POST /api/v1/user/order/cancel` | |
| `checkoutOrder` | `POST /api/v1/user/order/checkout` | 前端 `normalizeCheckoutUrl` 兼容 string/对象返回 |
| `createOrder` | `POST /api/v1/user/order/save` | |
| `getTickets` | `GET /api/v1/user/ticket/fetch` | |
| `getTicketDetail` | `GET /api/v1/user/ticket/fetch?id=` | |
| `createTicket` | `POST /api/v1/user/ticket/save` | |
| `closeTicket` | `POST /api/v1/user/ticket/close` | |
| `replyTicket` | `POST /api/v1/user/ticket/reply` | |
| `getInviteStat` | `GET /api/v1/user/invite/fetch` | 前端 `normalizeInviteStat` 解析 stat 元组 |
| `generateInviteCode` | `POST /api/v1/user/invite/save` | ✅ 已焊死 POST（见 §5），后端带 `throttle:user-mutation` |
| `getTrafficLogs` | `GET /api/v1/user/stat/getTrafficLog` | 前端 `normalizeTrafficLog` |
| `getNodeStatuses` | `GET /api/v1/user/server/fetch`（`VITE_NODE_STATUS_API_PATH` 可配） | 前端 `normalizeNodeStatus` 吃 10+ 种字段名 |

### 2.4 App BFF 通道（`services/app-dashboard.ts` / `services/client-version.ts` ↔ `routes/app_api.php`）

| 前端函数 | 后端端点 | 备注 |
|---|---|---|
| `getAppDashboardOverlay` | `GET /api/app/v1/dashboard` | 信封是 `AppApiEnvelope` = `{ ok, data?, message? }`；失败返回 `null`，UI 回落 `/api/v1` |
| `getClientVersionCatalog` | `GET /api/app/v1/client-version` | 失败返回 `fallbackCatalog()`，不阻塞页面 |

> App BFF 受 `appConfig.enableAppBff` 开关控制，且 mock 模式下短路。**它是性能叠加层，不能因为用它就删 V1 接口。**

---

## 3. 关键事实（决定二开边界）

1. **唯一耦合点是 `src/lib/api/`。** UI 层（`pages/*`）只调 service 函数，不碰 HTTP 细节。改 UI 永远不影响后端。

2. **`normalize*` 是资产，不是负债。** `node-status.ts` 能吃 `online`/`is_online`/`available` 等多种字段名，`invite.ts` 能吃 boolean 或 number 的 status。**这层容错是改后端字段时 UI 不碎的安全气囊，重构契约时务必保留。**

3. **App BFF 是叠加层。** 所有 BFF service 都有容错降级到 `/api/v1` 的设计。V1 仍是兼容底座，不可删。

4. **信封有两种：** `/api/v1` 用 `ApiEnvelope`（`status`/`message`/`data`），`/api/app/v1` 用 `AppApiEnvelope`（`ok`/`data`/`message`）。新增接口时跟随所在通道的信封格式。

---

## 4. 二开边界规则

| 类别 | 边界 |
|---|---|
| **品牌视觉** | 改 `DESIGN.md` / `index.css` / `pages/*` / `components/*`，碰不到本表任何一行 → 零风险 |
| **加功能** | 后端加 Controller 方法 + Route 一行；前端加 `services/x.ts`（带 normalize）+ 页面。不动现有项 |
| **删功能** | 前端删页面/路由即可；**后端接口先留着**（可能被 App 或其他端用着） |
| **改功能** | 改前回本表确认牵动的端点，并 grep 后端 `NodeSyncService::notify*` / `HookManager` 触发点，排查隐式副作用 |
| **不要碰** | axios 拦截器、token 存储、路由守卫 `ProtectedLayout`、所有 `normalize*` 层 |

---

## 5. 已完成的契约修正

| 日期 | 改动 | 文件 | 说明 |
|---|---|---|---|
| 2026-06-10 | 焊死密码重置 | `services/auth.ts` | 删掉 `forget`/`reset`/`forgetPassword` 三端点瞎猜循环，直连 `POST /auth/forget`（后端仅此一个端点存在，字段已确认匹配 `AuthForget`） |
| 2026-06-10 | 焊死邀请码生成 | `services/invite.ts` | 删掉 GET→POST 兜底循环 + 死代码 `requestInviteCode`，直连 `POST /invite/save`（后端带限流，配合副作用 GET 退役方向） |

> 这两处是"前端不确定后端长什么样"留下的疤。修正后契约从"猜"变成"焊死"。
