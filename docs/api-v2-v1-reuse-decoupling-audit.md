# Xboard API V2→V1 复用解耦审计

> 时间：2026-05-18  
> 角色：架构师  
> 目标：把当前 `V2` 路由直接复用 `V1` 控制器的真实情况讲清楚，为后续 API 改造、响应加密、版本隔离、节点协议整理提供边界。

---

## 1. 一句话结论

当前 Xboard 的 `V2` 并不是完全独立版本，而是一个 **“部分新增 + 部分继承 V1 + 部分兼容迁移中”** 的版本层：

- `V2 Passport`：**完全复用** `V1 Passport`
- `V2 User`：**薄别名复用** `V1 UserController`
- `V2 Server`：**半解耦**，新旧实现并存

因此：

> 后续如果要改 API 返回结构、引入统一加密、做版本隔离，不能把 `V2` 当成“已经独立完成”的版本。  
> 正确顺序应是：**先文档化复用点，再按风险分层解耦。**

---

## 2. 审计范围与方法

### 2.1 审计范围

本次只看 `V2 -> V1` 的直接控制器复用，不讨论：

- 纯 `V1` 内部实现细节优化
- 后台 CRUD 白名单模块整理
- 前端页面改造
- 订阅输出格式本身

重点审计：

- `app/Http/Routes/V2/PassportRoute.php`
- `app/Http/Routes/V2/UserRoute.php`
- `app/Http/Routes/V2/ServerRoute.php`

以及它们实际指向的控制器：

- `app/Http/Controllers/V1/Passport/AuthController.php`
- `app/Http/Controllers/V1/Passport/CommController.php`
- `app/Http/Controllers/V1/User/UserController.php`
- `app/Http/Controllers/V1/Server/UniProxyController.php`

同时对照：

- `app/Http/Routes/V1/PassportRoute.php`
- `app/Http/Routes/V1/UserRoute.php`
- `app/Http/Routes/V1/ServerRoute.php`
- `app/Http/Middleware/User.php`
- `app/Http/Middleware/Server.php`
- `app/Http/Middleware/ServerV2.php`
- `app/Helpers/ApiResponse.php`

### 2.2 审计方法

采用 **静态代码审计 + 人工架构复核**：

1. 逐条读取 `V2 Route`
2. 确认是否直接引用 `V1 Controller`
3. 核对中间件边界
4. 核对请求校验入口
5. 核对响应输出风格
6. 给出解耦风险等级与优先级建议

### 2.3 当前验证边界

本地仍缺少 `vendor/`，因此本轮不能做：

- `php artisan route:list`
- 运行态路由分发验证
- PHPUnit / Feature Test

本结论属于：

> **高可信静态架构审计结论**，足够支撑下一阶段 API 设计与重构顺序判断。

---

## 3. 总览矩阵

| V2 路由域 | 当前实现 | 复用控制器 | 中间件边界 | 响应风格 | 风险等级 | 结论 |
| --- | --- | --- | --- | --- | --- | --- |
| `V2 Passport` | 完全复用 | `V1\Passport\AuthController` + `CommController` | 无专属中间件 | 以 `success/fail` 为主，夹杂 redirect / 裸 `response()->json()` | 中高 | 可拆，但要先锁定前端认证契约 |
| `V2 User` | 薄别名复用 | `V1\User\UserController` | `user` | `success/fail` | 中低 | 最适合作为首个解耦点 |
| `V2 Server` | 半解耦 | `V1\Server\UniProxyController` + `V2 ServerController` | `server.v2` | 混合 `response()` / `response()->json()` / `success()` / `304` | 高 | 只文档化，暂不做协议级拆分 |

---

## 4. 复用链一：V2 Passport 完全复用 V1 Passport

### 4.1 路由证据

文件：`app/Http/Routes/V2/PassportRoute.php`

直接引用：

- `App\Http\Controllers\V1\Passport\AuthController`
- `App\Http\Controllers\V1\Passport\CommController`

暴露接口：

- `POST /api/v2/passport/auth/register`
- `POST /api/v2/passport/auth/login`
- `GET /api/v2/passport/auth/token2Login`
- `POST /api/v2/passport/auth/forget`
- `POST /api/v2/passport/auth/getQuickLoginUrl`
- `POST /api/v2/passport/auth/loginWithMailLink`
- `POST /api/v2/passport/comm/sendEmailVerify`
- `POST /api/v2/passport/comm/pv`

对照 `app/Http/Routes/V1/PassportRoute.php` 可见：

> `V2 PassportRoute` 与 `V1 PassportRoute` 的接口集合完全一致，只是路径前缀从 `/api/v1` 换成了 `/api/v2`。

### 4.2 请求校验入口

`AuthController` 中：

- `register(AuthRegister $request)` → `FormRequest`
- `login(AuthLogin $request)` → `FormRequest`
- `forget(AuthForget $request)` → `FormRequest`
- `loginWithMailLink(Request $request)` → 控制器内 `validate()`
- `token2Login(Request $request)` → 无统一 `FormRequest`
- `getQuickLoginUrl(Request $request)` → 手动读取 header / input

`CommController` 中：

- `sendEmailVerify(CommSendEmailVerify $request)` → `FormRequest`
- `pv(Request $request)` → 无 `FormRequest`

### 4.3 中间件边界

- `V1 PassportRoute`：无专属中间件
- `V2 PassportRoute`：同样无专属中间件
- 共同依赖 `api` 组中间件：
  - `ApplyRuntimeSettings`
  - `ForceJson`
  - `Language`
  - `bindings`

这意味着：

> `V2 Passport` 在鉴权前链路上，并没有独立于 `V1` 的版本级隔离层。

### 4.4 响应风格

主要风格：

- 成功 / 失败多数走 `Controller -> ApiResponse -> success()/fail()`

但存在例外：

- `token2Login()` 可能返回：
  - `redirect()->to(...)`
  - `response()->json([...], 400)`
  - `response()->json(['data' => ...])`
- `getQuickLoginUrl()` 的失败分支直接返回 `401` JSON

结论：

> `Passport` 不是“纯 JSON CRUD 接口”，而是带登录跳转、token 登录、邮件链接登录的认证链路；响应形态天然比普通后台接口更复杂。

### 4.5 依赖服务

核心依赖：

- `RegisterService`
- `LoginService`
- `MailLinkService`
- `AuthService`
- `CaptchaService`
- `SendEmailJob`

说明：

> `V2 Passport` 的问题不在“代码量大”，而在“它是用户登录入口”。任何改动都直接影响用户登录、注册、邮箱验证码、邮件链路登录。

### 4.6 风险判断

风险等级：**中高**

主要原因：

1. 路由是 `V2`，实现却仍是 `V1`
2. 前台登录链路敏感，前端兼容成本高
3. 存在跳转和非标准 JSON 分支
4. 存在 header / token / redirect 等隐式契约

### 4.7 解耦建议

建议顺序：**第二优先级**

建议策略：

1. 先复制路由对应控制器到 `V2\Passport\*`
2. 第一阶段只做“壳层搬迁”，不改业务逻辑
3. 保持：
   - 路由不变
   - 请求字段不变
   - 响应结构不变
   - redirect 行为不变
4. 待 V2 壳层独立后，再谈统一响应与加密策略

---

## 5. 复用链二：V2 User 是 V1 UserController 的薄别名

### 5.1 路由证据

文件：`app/Http/Routes/V2/UserRoute.php`

直接引用：

- `App\Http\Controllers\V1\User\UserController`

暴露接口仅 2 个：

- `GET /api/v2/user/resetSecurity`
- `GET /api/v2/user/info`

对照 `app/Http/Routes/V1/UserRoute.php` 可见：

- `V1 UserRoute` 暴露大量用户域接口
- `V2 UserRoute` 只挑了 `resetSecurity` / `info` 两个能力出来

所以它不是完整用户域版本，而是：

> 只把 `V1 UserController` 的两个方法挂到了 `V2` 路由上。

### 5.2 请求校验入口

两者都没有单独 `FormRequest`：

- `info(Request $request)`
- `resetSecurity(Request $request)`

它们主要依赖：

- `user` 中间件保证登录态
- `$request->user()` 提供当前用户上下文

### 5.3 中间件边界

- `V1 UserRoute`：`middleware => user`
- `V2 UserRoute`：同样 `middleware => user`

`app/Http/Middleware/User.php` 的行为很简单：

- 依赖 `Auth::guard('sanctum')->check()`
- 未登录直接抛 `ApiException('未登录或登陆已过期', 403)`

这说明：

> `V2 User` 在鉴权上没有独立策略，只是继承 `V1` 的同一套 Sanctum 用户身份。

### 5.4 响应风格

- `info()`：标准 `success($user)`
- `resetSecurity()`：标准 `success(Helper::getSubscribeUrl($user->token))`

注意点：

- `resetSecurity` 是 **GET 执行写操作**
- 返回的是新的订阅链接字符串
- 该接口会重置：
  - `uuid`
  - `token`

因此它虽然响应简单，但副作用很强。

### 5.5 依赖服务

- `AuthService`
- `Helper`
- `User` Model

对这两个方法而言，依赖面并不大；它们不像 `V1 UserController` 其他方法那样牵涉订单、工单、转账、邀请码等域。

### 5.6 风险判断

风险等级：**中低**

主要原因：

1. 复用范围小，只有 2 个方法
2. 中间件边界清楚
3. 响应结构稳定
4. 依赖服务相对集中

唯一需要特别注意的是：

- `resetSecurity` 有强副作用
- 前端可能把它当普通 GET 调用
- 任何“语义修正”为 POST 的动作都不能直接做

### 5.7 解耦建议

建议顺序：**第一优先级**

建议策略：

1. 新增 `App\Http\Controllers\V2\User\UserController`
2. 仅迁移：
   - `info()`
   - `resetSecurity()`
3. 暂不动：
   - 路由 path
   - GET 语义
   - 返回结构
4. 把这两个方法从“直接指向 V1”改成“V2 壳层转发/复制实现”

这是当前最适合做的第一个 V2 解耦样板。

---

## 6. 复用链三：V2 Server 是半解耦状态

### 6.1 路由证据

文件：`app/Http/Routes/V2/ServerRoute.php`

当前 `V2 ServerRoute` 分成三块：

#### A. 已有 V2 自有实现

- `match(['GET', 'POST'], 'handshake', [V2\Server\ServerController::class, 'handshake'])`
- `post('report', [V2\Server\ServerController::class, 'report'])`

#### B. 仍复用 V1 UniProxyController

- `GET /api/v2/server/config`
- `GET /api/v2/server/user`
- `POST /api/v2/server/push`
- `POST /api/v2/server/alive`
- `GET /api/v2/server/alivelist`
- `POST /api/v2/server/status`

对应控制器：

- `App\Http\Controllers\V1\Server\UniProxyController`

#### C. 独立机器接口

- `POST /api/v2/server/machine/nodes`
- `POST /api/v2/server/machine/status`

对应控制器：

- `App\Http\Controllers\V2\Server\MachineController`

### 6.2 中间件边界

这里有两个重要事实：

#### 事实 1：V2 server 主链路使用 `server.v2`

`V2 /server/*` 主组挂载：

- `middleware => server.v2`

`app/Http/Middleware/ServerV2.php` 的特征：

- 支持 `server token` 认证
- 也支持 `machine_id + token` 认证
- `handshake` 场景允许 `node_id` 为空
- 把节点信息写入 `$request->attributes['node_info']`
- 把机器信息写入 `$request->attributes['machine_info']`

#### 事实 2：被复用的控制器原本属于 V1 `/server/UniProxy`

对照 `app/Http/Routes/V1/ServerRoute.php`：

- `V1 UniProxyController` 原本服务于 `/api/v1/server/UniProxy/*`
- 原中间件是 `server`
- `server` 中间件是旧实现，并且已经带 `@deprecated use ServerV2`

旧 `server` 与新 `server.v2` 的关键差异：

- 旧版要求 `node_id` + `node_type`
- 新版去掉了 `node_type`，支持机器维度认证

这意味着：

> `V2 Server` 虽然在路由层和中间件层已经升级，但核心数据下发/上报控制器仍然借用旧的 `UniProxyController`。

### 6.3 请求校验入口

`UniProxyController` 内部风格并不统一：

- `config()`：无额外 validate，依赖中间件注入 `node_info`
- `user()`：无额外 validate，依赖中间件注入 `node_info`
- `push()`：直接 `json_decode(request()->getContent(), true)`
- `alive()`：直接 `json_decode(request()->getContent(), true)`
- `alivelist()`：无额外 validate
- `status()`：控制器内 `$request->validate([...])`

而 `V2 ServerController` 中：

- `handshake()`：无字段校验，主要看中间件与 websocket 配置
- `report()`：接受聚合上报包，按字段存在性分发给 `ServerService`

这说明：

> `V2 Server` 当前不是一个统一协议层，而是“新聚合接口 + 旧拆分接口”并存。

### 6.4 响应风格

这是三条复用链里最复杂的一条。

`UniProxyController` 同时存在：

- `response($response)->header('ETag', ...)`
- `response(null, 304)`
- `response()->json(['alive' => ...])`
- `response()->json(['data' => true])`
- `$this->success(true)`
- `$this->fail([422, 'Invalid data format'])`

尤其：

- `config()` / `user()` 有 `ETag` + `304 Not Modified`
- 这说明客户端/节点侧可能依赖缓存协商

因此：

> `UniProxyController` 不是普通后台 JSON API，不能直接套用“统一 envelope + 加密返回”的后台改法。

### 6.5 旧残留信号

`app/Http/Routes/V2/ServerRoute.php` 里还有两个未使用导入：

- `ShadowsocksTidalabController`
- `TrojanTidalabController`

它们当前在该文件中没有实际路由引用，属于明确的历史残留。

这说明：

- V2 Server 迁移做过一半
- 结构已经开始收口
- 但还没有彻底完成

### 6.6 风险判断

风险等级：**高**

主要原因：

1. 涉及节点协议，不是人类前端调用
2. 既有缓存协商（`ETag` / `304`）
3. 混合旧协议与新聚合上报
4. 中间件与控制器不处于同一代设计
5. 很可能与外部节点程序实现强耦合

### 6.7 解耦建议

建议顺序：**最后处理**

当前建议：

1. 先只文档化，不改协议
2. 先为 `V2 Server` 画出完整字段契约
3. 区分两层：
   - `handshake/report` 新协议层
   - `config/user/push/alive/alivelist/status` 旧兼容层
4. 若后续必须做响应加密或统一返回，优先只对“新聚合接口”评估，不直接动 `UniProxy` 兼容链路

---

## 7. 架构级判断

### 7.1 当前的 V2 更像“路径版本”，不是“实现版本”

从三条复用链可见：

- `V2 Passport` 只是 `V1 Passport` 的新路径
- `V2 User` 只是 `V1 UserController` 的子集挂载
- `V2 Server` 只有一部分进入了新实现

所以当前版本语义更接近：

> “外部路径已经叫 `v2`，但内部实现并没有全面完成 `v2` 化。”

### 7.2 这会直接影响后续 API 改造边界

如果后续要做这些动作：

- 接口返回 AES 加密
- 统一响应 envelope
- 统一错误码
- 前后端签名协议
- API 文档整理为稳定版本契约

都必须先回答一个问题：

> 改的是“真正的 V2”，还是“会同步影响 V1 的兼容实现”？

目前答案是：

- `Passport`：**会影响 V1**
- `User`：**会影响 V1**
- `Server`：**部分会影响 V1**

---

## 8. 解耦优先级建议

### 第一优先级：`V2 UserRoute -> V1 UserController`

原因：

- 方法少
- 副作用边界清楚
- 中间件简单
- 最容易做成“V2 壳层独立”

目标：

- 先把 `info/resetSecurity` 从“直接复用”改成“V2 自有控制器”
- 不改业务行为

### 第二优先级：`V2 PassportRoute -> V1 Passport`

原因：

- 认证链路重要，但接口规模仍可控
- 适合在 `V2 User` 独立后再做
- 需要先锁定前端登录/注册/邮件链路契约

目标：

- 建立 `V2 Passport` 壳层
- 保持响应与重定向行为完全兼容

### 第三优先级：`V2 ServerRoute -> V1 UniProxyController`

原因：

- 协议风险最高
- 机器客户端兼容性要求最高
- 现有返回格式、缓存语义都很敏感

目标：

- 先做协议清单与字段契约
- 暂不做实现迁移

---

## 9. 建议任务清单

> 说明：这是当前 API 架构整理的下一阶段任务清单，先写入文档，后续按批次执行。

### T1：补齐 V2→V1 复用台账

- [x] 识别 `Passport` 复用链
- [x] 识别 `User` 复用链
- [x] 识别 `Server` 复用链
- [x] 标注中间件边界
- [x] 标注响应风格差异
- [x] 标注风险等级

### T2：做 `V2 User` 壳层解耦设计

- [ ] 新建 `V2\User\UserController`
- [ ] 迁移 `info()`
- [ ] 迁移 `resetSecurity()`
- [ ] 路由改指向 `V2` 控制器
- [ ] 保持原字段与响应不变
- [ ] 保持 GET 语义不变

### T3：做 `V2 Passport` 壳层解耦设计

- [ ] 新建 `V2\Passport\AuthController`
- [ ] 新建 `V2\Passport\CommController`
- [ ] 逐个搬迁 8 个路由入口
- [ ] 锁定 redirect / mail link / token login 契约
- [ ] 补登录链路接口文档

### T4：做 `V2 Server` 协议审计

- [ ] 输出 `server.v2` 中间件认证矩阵
- [ ] 输出 `handshake/report` 字段清单
- [ ] 输出 `UniProxy` 兼容链字段清单
- [ ] 标记 `ETag/304` 依赖点
- [ ] 判断哪些接口可安全接入统一加密

### T5：做全局 API 改造前置判断

- [ ] 判断 AES 返回加密能否分通道接入
- [ ] 区分“后台 JSON API”与“节点协议 API”
- [ ] 区分“可包裹 envelope”与“不可强包裹接口”
- [ ] 为后续 API 文档输出稳定分层

---

## 10. 最终建议

当前最合理的推进顺序不是直接改代码，而是：

1. **接受现实**：`V2` 目前并非完整独立版本
2. **先拆最简单的**：`V2 User`
3. **再拆认证入口**：`V2 Passport`
4. **最后处理节点协议**：`V2 Server`

如果跳过这一步，后面无论是：

- 改 API 返回结构
- 做 AES 加密
- 做前端接口统一
- 做版本文档

都会出现一个典型问题：

> 以为自己在改 `V2`，实际改到的是 `V1` 共享实现。

这正是当前阶段最需要避免的架构风险。
