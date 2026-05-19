# Xboard API 接口总清单矩阵

> 目标：把当前 API 从“接口列表”提升为“可改造清单”。  
> 维度：**通道 / 版本 / 前缀 / 鉴权 / 控制器 / 校验方式 / 响应类型 / 风险等级 / 首批可改造性**。

---

## 1. 全局总览

### 1.1 路由规模

当前统计：

- `V1`：**56** 条
- `V2`：**145** 条
- 合计：**201** 条 API 路由

除此之外，还存在：

- Web 订阅路由：`/s/{token}`

### 1.2 4 条 API / 输出通道

| 通道 | 说明 | 主要入口 |
|---|---|---|
| Frontend API | 用户前台业务 API | `/api/v1/*`、少量 `/api/v2/*` |
| Admin API | 后台管理 API | `/api/v2/{secure_path}/*` |
| Node API | 节点 / 机器通信 API | `/api/v1/server/*`、`/api/v2/server/*` |
| Subscription Delivery | 订阅/配置分发 | `/s/{token}`、`/api/v1/client/subscribe` |

### 1.3 身份体系矩阵

| 鉴权方式 | 适用范围 | 说明 |
|---|---|---|
| 无鉴权 | guest / passport / payment notify | 公开入口 |
| Sanctum User | `middleware=user` | 前台已登录用户 |
| Sanctum Admin | `middleware=admin` | 后台管理 |
| Subscription Token | `middleware=client` | 订阅 token 身份 |
| Server Token | `middleware=server` / `server.v2` | 节点 token |
| Machine Token | `server.v2` 中 `machine_id + token` | 机器级接入 |

---

## 2. 模块级接口矩阵

> 说明：这一层是**架构视图**，不是逐个 endpoint 的参数文档。  
> 后续决定“先改哪一块 API”，以这张表为入口。

## 2.1 Frontend API 矩阵

| 通道 | 版本 | 前缀/模块 | 路由数 | 鉴权 | 控制器 | 校验入口 | 响应类型 | 风险等级 | 首批建议 |
|---|---|---:|---:|---|---|---|---|---|---|
| Frontend | V1 | `guest` | 4 | 无 | `Guest/*` | Controller 内 / 少量隐式 | `success/fail` 为主，含 notify 特例 | 中 | 可审慎改 |
| Frontend | V1 | `passport` | 8 | 无 | `Passport/*` | `FormRequest` + Service | `mixed` | 高 | 暂不首改 |
| Frontend | V1 | `user` | 41 | Sanctum User | `User/*` | `FormRequest` + `request->validate` 混合 | `success/fail` 为主，部分 `mixed/raw` | 高 | 仅选读接口先改 |
| Frontend | V1 | `client` | 3 | Subscription Token | `Client/*` | Controller 内 | `text_config/raw` | 极高 | 不作为普通 API 改 |
| Frontend | V2 | `passport` | 7 | 无 | **复用 `V1\Passport`** | 同 V1 | 同 V1 | 高 | 暂不动 |
| Frontend | V2 | `user` | 2 | Sanctum User | **复用 `V1\User`** | 同 V1 | 同 V1 | 高 | 暂不动 |
| Frontend | V2 | `client` | 2 | Subscription Token | `V2\Client\AppController` | Controller 内 | `mixed` | 高 | 非首批 |

### 备注

- `passport` 是登录/注册链路，动了就会影响 Bearer token 获取
- `user` 是前端业务核心区，字段兼容要求最高
- `client` 实际上偏“客户端配置分发”，不应和普通 JSON API 一起治理

---

## 2.2 Admin API 矩阵

| 通道 | 版本 | 模块 | 路由数 | 鉴权 | 控制器 | 校验入口 | 响应类型 | 风险等级 | 首批建议 |
|---|---|---:|---:|---|---|---|---|---|---|
| Admin | V2 | `auth` | 3 | 登录无鉴权；`me/logout` 使用 Sanctum User + Admin | `V2\Admin\AuthController` | `FormRequest(Admin\AuthLogin)` + `user/admin` middleware | `success/fail`，兼容 `auth_data` | 中 | 已完成后台认证拆分，冻结 |
| Admin | V2 | `config` | 6 | Sanctum Admin + Log | `ConfigController` | `FormRequest(ConfigSave)` + 内部判断 | `mixed` | 中高 | 第二批 |
| Admin | V2 | `mail/template` | 5 | Sanctum Admin + Log | `MailTemplateController` | 内部判断为主 | `success/fail` | 中 | 可首批 |
| Admin | V2 | `plan` | 5 | Sanctum Admin + Log | `PlanController` | `FormRequest` | `success/fail` | 中 | 可首批 |
| Admin | V2 | `server/group` | 3 | Sanctum Admin + Log | `Server\GroupController` | 内部判断 | `success/fail` | 中高 | 第二批 |
| Admin | V2 | `server/route` | 3 | Sanctum Admin + Log | `Server\RouteController` | 内部判断 | `success/fail` | 中高 | 第二批 |
| Admin | V2 | `server/manage` | 11 | Sanctum Admin + Log | `Server\ManageController` | `FormRequest(ServerSave)` + 内部判断 | `success/fail` | 高 | 非首批 |
| Admin | V2 | `server/machine` | 8 | Sanctum Admin + Log | `Server\MachineController` | 内部判断 | `success/fail` | 高 | 非首批 |
| Admin | V2 | `order` | 6 | Sanctum Admin + Log | `OrderController` | `FormRequest` + 内部判断 | `success/fail` | 中高 | 第二批 |
| Admin | V2 | `user` | 12 | Sanctum Admin + Log | `UserController` | `FormRequest` + Query DSL | `stream/mixed` | 高 | 非首批 |
| Admin | V2 | `stat` | 9 | Sanctum Admin + Log | `StatController` | 内部判断 | `success/fail` | 中 | 第二批 |
| Admin | V2 | `notice` | 6 | Sanctum Admin + Log | `NoticeController` | `FormRequest` + 内部判断 | `success/fail` | 中 | 可首批 |
| Admin | V2 | `ticket` | 3 | Sanctum Admin + Log | `TicketController` | 内部判断 | `mixed` | 中高 | 第二批 |
| Admin | V2 | `coupon` | 5 | Sanctum Admin + Log | `CouponController` | `FormRequest(CouponGenerate)` + 内部判断 | `success/fail` | 中 | 可首批 |
| Admin | V2 | `gift-card` | 13 | Sanctum Admin + Log | `GiftCardController` | 内部判断为主 | `stream` + `success/fail` | 高 | 非首批 |
| Admin | V2 | `knowledge` | 6 | Sanctum Admin + Log | `KnowledgeController` | `FormRequest` | `success/fail` | 中 | 可首批 |
| Admin | V2 | `payment` | 7 | Sanctum Admin + Log | `PaymentController` | 内部判断为主 | `success/fail` | 中高 | 第二批 |
| Admin | V2 | `system` | 6 | Sanctum Admin + Log | `SystemController` | 内部判断 | `mixed` | 中高 | 第二批 |
| Admin | V2 | `theme` | 5 | Sanctum Admin + Log | `ThemeController` | 内部判断 | `success/fail` | 中高 | 第二批 |
| Admin | V2 | `plugin` | 11 | Sanctum Admin + Log | `PluginController` | 内部判断 | `raw_json_or_response` | 高 | 非首批 |
| Admin | V2 | `traffic-reset` | 4 | Sanctum Admin + Log | `TrafficResetController` | 内部判断 | `raw_json_or_response` | 高 | 非首批 |
| Admin | V2 | `update` | 2 | Sanctum Admin + Log | `UpdateController` | 内部判断 | `success/fail` | 中高 | 非首批 |

### 首批建议可改造模块

优先级最高、最适合先做标准化的模块：

1. `plan`
2. `notice`
3. `knowledge`
4. `coupon`
5. `mail/template`

原因：

- 业务边界相对独立
- 多数已接近 `success/fail`
- 对前台/节点影响相对小
- 适合作为统一响应、统一 Request、统一错误模型的试点

---

## 2.3 Node API 矩阵

| 通道 | 版本 | 模块 | 路由数 | 鉴权 | 控制器 | 校验入口 | 响应类型 | 风险等级 | 首批建议 |
|---|---|---:|---:|---|---|---|---|---|---|
| Node | V1 | `server/UniProxy` | 6 | `server` | `V1\Server\UniProxyController` | middleware + 内部判断 | `mixed` | 极高 | 冻结 |
| Node | V1 | `server/ShadowsocksTidalab` | 2 | `server:shadowsocks` | `V1\Server\ShadowsocksTidalabController` | middleware + 内部判断 | `raw_json_or_response` | 极高 | 冻结 |
| Node | V1 | `server/TrojanTidalab` | 3 | `server:trojan` | `V1\Server\TrojanTidalabController` | middleware + 内部判断 | `mixed` | 极高 | 冻结 |
| Node | V2 | `server` | 8 | `server.v2` | `V2\Server\ServerController` + 复用 `V1\Server\UniProxyController` | middleware + `request->validate` | `raw_json_or_response` / mixed | 极高 | 冻结 |
| Node | V2 | `server/machine` | 2 | machine token（route 层未显式 middleware） | `V2\Server\MachineController` | Controller / 服务内 | `raw_json_or_response` | 极高 | 冻结 |

### 节点通道架构判断

这一层不是普通业务 API，而是：

- 节点握手
- 配置拉取
- 流量/状态/在线/指标上报
- Redis + WebSocket 同步链路的外围入口

因此：

> **第一批 API 整理不应触碰 Node API 契约。**

---

## 2.4 Subscription / Config Delivery 矩阵

| 通道 | 路径 | 鉴权 | 控制器/协议 | 输出类型 | 风险等级 | 首批建议 |
|---|---|---|---|---|---|---|
| Subscription Delivery | `/s/{token}` | Subscription Token | `V1\Client\ClientController` + `app/Protocols/*` | base64/plain/YAML/JSON | 极高 | 冻结 |
| Subscription Delivery | `/api/v1/client/subscribe` | Subscription Token | `V1\Client\ClientController` | 文本订阅输出 | 极高 | 冻结 |
| Subscription Delivery | `/api/v1/client/app/getConfig` | Subscription Token | `V1\Client\AppController` | Clash YAML | 极高 | 冻结 |
| Subscription Delivery | `/api/v1/client/app/getVersion` | Subscription Token | `V1\Client\AppController` | JSON | 高 | 非首批 |
| Subscription Delivery | `/api/v2/client/app/getConfig` | Subscription Token | `V2\Client\AppController` | 配置输出 | 高 | 非首批 |
| Subscription Delivery | `/api/v2/client/app/getVersion` | Subscription Token | `V2\Client\AppController` | JSON | 高 | 非首批 |

---

## 3. V1 / V2 复用矩阵

> 这是后续改 API 时最需要优先警惕的隐式耦合点。

| V2 路由 | 实际 Controller | 风险 |
|---|---|---|
| `V2/UserRoute` | `App\Http\Controllers\V1\User\UserController` | 改 V1 会联动影响 V2 |
| `V2/PassportRoute` | `App\Http\Controllers\V1\Passport\AuthController` | 登录/注册链路双版本共振 |
| `V2/PassportRoute` | `App\Http\Controllers\V1\Passport\CommController` | 邮件验证码等行为共振 |
| `V2/ServerRoute` 部分 | `App\Http\Controllers\V1\Server\UniProxyController` | 节点协议兼容性风险极高 |

### 3.1 Phase 4 auth-focused delta refresh（2026-05-19）

本节只冻结 Phase 3 之后和 DK_Theme 兼容相关的认证增量事实，不重盘全仓 API：

- 后台认证已拆到 `POST /api/v2/{secure_path}/auth/login`、`GET /api/v2/{secure_path}/auth/me`、`POST /api/v2/{secure_path}/auth/logout`，路由落点为 `app/Http/Routes/V2/AdminAuthRoute.php`，控制器为 `app/Http/Controllers/V2/Admin/AuthController.php`。
- 共享前台认证链路仍同时存在于 V1/V2 `passport`：`register/login/token2Login/forget/getQuickLoginUrl/loginWithMailLink/sendEmailVerify`；V2 仍复用 V1 `Passport\AuthController` 与 `Passport\CommController`。
- `GET /api/v2/user/info` 仍由 `V2/UserRoute` 复用 `V1\User\UserController::info`；当前后台初始化已改为 `/{secure_path}/auth/me`，因此 `user/info` 是“剩余消费者验证项”，不是当前后台 blocker。
- 共享认证保留/兼容决策的单一来源见 `docs/api-auth-retirement-matrix.md`。
- `external frontend dependency = unknown` 时禁止删除和软封禁；仓库没有外部分离前端源码时，必须把前台 API 文档契约视为保留证据。
- `tests/Feature/AdminOnlyShellContractTest.php` 已补充 booted Laravel 路由契约测试，保护 DK_Theme 依赖的 V1/V2 Passport/User、V1 Guest、订阅与后台壳层路由挂载状态。

### 结论

> 任何声称“只改 V1”或“只改 V2”的方案，先检查这张表。  
> 否则很容易出现无意破坏另一个版本的情况。

---

## 4. 高风险接口清单（逐项）

## 4.1 使用 GET 执行写操作

| 路径 | 版本 | 说明 | 风险 |
|---|---|---|---|
| `/api/v1/user/resetSecurity` | V1 | 重置订阅 token / uuid | 高 |
| `/api/v1/user/invite/save` | V1 | 生成邀请码 | 中高 |
| `/api/v2/user/resetSecurity` | V2 | 复用 V1 行为 | 高 |
| `/api/v2/{secure_path}/server/manage/generateEchKey` | V2 Admin | 生成密钥材料 | 中高 |

## 4.2 使用 ANY

| 路径 | 版本 | 模块 | 风险 |
|---|---|---|---|
| `/api/v2/{secure_path}/order/fetch` | V2 Admin | order | 中 |
| `/api/v2/{secure_path}/user/fetch` | V2 Admin | user | 中 |
| `/api/v2/{secure_path}/stat/getStatUser` | V2 Admin | stat | 中 |
| `/api/v2/{secure_path}/ticket/fetch` | V2 Admin | ticket | 中 |
| `/api/v2/{secure_path}/coupon/fetch` | V2 Admin | coupon | 中 |
| `/api/v2/{secure_path}/gift-card/templates` | V2 Admin | gift-card | 中高 |
| `/api/v2/{secure_path}/gift-card/codes` | V2 Admin | gift-card | 中高 |
| `/api/v2/{secure_path}/gift-card/usages` | V2 Admin | gift-card | 中高 |
| `/api/v2/{secure_path}/gift-card/statistics` | V2 Admin | gift-card | 中高 |
| `/api/v2/{secure_path}/system/getAuditLog` | V2 Admin | system | 中高 |

## 4.3 下载/流式特例

| 模块 | 控制器 | 类型 | 风险 |
|---|---|---|---|
| `gift-card` | `GiftCardController` | `streamDownload` / 文件导出 | 高 |
| `user` | `V2\Admin\UserController` | CSV 导出 | 高 |

### 结论

如果后续做：

- 统一响应 envelope
- 全站 AES 返回加密
- 全局 response middleware

必须优先排除这些接口。

---

## 5. 响应风格矩阵

| 风格 | 代表模块 | 说明 |
|---|---|---|
| `success/fail` | `plan` / `notice` / `knowledge` / `coupon` | 最接近标准化，可优先治理 |
| `mixed` | `passport` / `order` / `config` / `system` | 同一控制器里混用统一包装和原始 response |
| `raw_json_or_response` | `plugin` / `traffic-reset` / `server` | 自定义 JSON 直接输出较多 |
| `stream` | `gift-card` / `admin user export` | 下载流，不能纳入普通 envelope 方案 |
| `text_config` | `client app getConfig` / 订阅协议 | 配置分发，不是普通 JSON API |

---

## 6. 首批 API 整理白名单

> 这里的“整理”是指：统一 Request、统一返回、补齐文档、收敛命名、补兼容层。  
> 不是直接做 breaking change。

### 允许第一批进入治理

| 模块 | 原因 |
|---|---|
| `V2 plan` | 边界清晰、已接近统一响应 |
| `V2 notice` | 风险中等、前后台耦合低 |
| `V2 knowledge` | 结构清晰、适合先规范化 |
| `V2 coupon` | 业务独立、回归成本可控 |
| `V2 mail/template` | 后台专用、适合作为试点 |

### 第二批可进入治理

| 模块 | 原因 |
|---|---|
| `V2 config` | 配置面广，需谨慎 |
| `V2 order` | 影响交易链路，需更多验证 |
| `V2 payment` | 外部支付联动，需谨慎 |
| `V2 system` | 涉及监控与审计 |
| `V2 theme` | 牵涉主题配置 |

### 当前不建议作为首批治理对象

| 模块 | 原因 |
|---|---|
| `V1 passport` | 登录注册链路，风险高 |
| `V1 user` | 前台核心域，兼容要求高 |
| `client/*` | 实际属于配置分发接口 |
| `server/*` | 节点协议稳定性优先 |
| `plugin` | 响应风格特殊、插件生命周期复杂 |
| `traffic-reset` | 响应不统一且业务副作用强 |
| `gift-card` | 含下载流和多形态接口 |

---

## 7. 修改 API 时的评审模板

后续每次要改某组 API，都先回答：

1. 它属于哪个通道？
2. 鉴权方式是什么？
3. 是标准 JSON 还是下载流 / 配置输出？
4. 有没有 V1 / V2 复用？
5. 有没有 Hook / 节点 / 订阅隐式影响？
6. 它在不在首批白名单里？

只有这 6 个问题都清楚，才进入代码修改。
