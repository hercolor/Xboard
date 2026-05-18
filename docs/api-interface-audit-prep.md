# Xboard API 接口审计与改造准备

> 目的：在正式修改 API 之前，先明确当前接口分层、稳定契约、复用关系、风险点与推荐改造顺序，避免误伤前台、后台、节点通信与鉴权链路。

---

## 1. 审计结论总览

当前 Xboard 的 API 不是单一整齐的 REST 层，而是一个由以下几部分拼接而成的混合体系：

1. **V1 用户前台 API**
   - 主要给用户前端使用
   - 前缀：`/api/v1`
2. **V2 后台管理 API**
   - 主要给管理后台使用
   - 前缀：`/api/v2`
3. **V2 兼容/复用 API**
   - 部分 `V2` 路由直接复用 `V1` Controller
4. **订阅/客户端接口**
   - 不完全属于标准 JSON API
   - 同时存在文本、YAML、JSON、下载流等返回形式
5. **节点通信接口**
   - 包含服务端/机器端鉴权逻辑
   - 修改风险高

**结论：**

> 后续如果要改 API，不能只盯 Controller。
> 必须同时看：`Routes`、`Middleware`、`FormRequest`、`Exception Handler`、`Response 包装`、`Sanctum 鉴权`、`节点通信协议`。

---

## 2. 当前 API 骨架

### 2.1 版本入口

定义位置：

- `app/Providers/RouteServiceProvider.php`

当前明确分为：

- `/api/v1`
- `/api/v2`

并且是通过扫描以下目录动态注册：

- `app/Http/Routes/V1/*.php`
- `app/Http/Routes/V2/*.php`

这意味着：

> 新增/修改接口时，**路由文件本身就是稳定入口**，不要只改 Controller 而忽略 route 映射。

---

## 3. 中间件与鉴权边界

### 3.1 全部 API 公共中间件

定义位置：

- `app/Http/Kernel.php`

当前 `api` 组统一经过：

- `ApplyRuntimeSettings`
- `ForceJson`
- `Language`
- `bindings`

影响：

- 默认强制 JSON 语义
- 语言包会影响错误信息
- 如果后续要做统一响应加密、统一 trace_id、统一版本头，**最佳插入点就是 `api` middleware 组**

### 3.2 主要鉴权方式

#### 用户前后台

- `user` / `admin` 中间件
- 基于 `Auth::guard('sanctum')`

关键文件：

- `app/Http/Middleware/User.php`
- `app/Http/Middleware/Admin.php`
- `app/Services/AuthService.php`

当前前端登录成功后拿到：

- `token`：订阅 token
- `auth_data`：前端后续 API 用的 Bearer Token

其中真正用于用户 API 鉴权的是：

```http
Authorization: Bearer xxxxx
```

#### 订阅/客户端

- `client` 中间件
- 使用的是 **用户订阅 token**

关键文件：

- `app/Http/Middleware/Client.php`

注意：

> `client` 和 `sanctum` 不是同一套身份体系。
> 改 API 时不要把订阅 token 和后台 Bearer token 混用。

#### 节点/机器通信

关键文件：

- `app/Http/Middleware/Server.php`
- `app/Http/Middleware/ServerV2.php`

`ServerV2` 当前支持两类鉴权：

1. `server_token + node_id`
2. `machine_id + token (+ node_id)`

这部分属于**高风险区域**：

> 一旦改字段名、校验规则或返回结构，节点侧很容易直接失联。

---

## 4. 响应结构现状

### 4.1 标准响应包装

定义位置：

- `app/Helpers/ApiResponse.php`
- `app/Http/Controllers/Controller.php`

大多数 Controller 继承基类后走：

- `$this->success(...)`
- `$this->fail(...)`

标准结构：

```json
{
  "status": "success|fail",
  "message": "...",
  "data": {},
  "error": null
}
```

分页结构例外：

```json
{
  "total": 0,
  "current_page": 1,
  "per_page": 10,
  "last_page": 1,
  "data": []
}
```

### 4.2 非统一响应现象

审计结果显示，当前并不是所有接口都严格走 `success/fail`：

存在直接：

- `response()->json([...])`
- `response([...])`
- `response()->streamDownload(...)`
- `response($content)`

的情况，集中在：

- `V2/Admin/UserController.php`
- `V2/Admin/TicketController.php`
- `V2/Admin/ConfigController.php`
- `V2/Admin/SystemController.php`
- `V2/Admin/TrafficResetController.php`
- `V2/Admin/PluginController.php`
- `V2/Admin/GiftCardController.php`

结论：

> 如果后续要做“统一 API 返回格式改造”，不能只改 `ApiResponse`。
> 必须先盘点这些直接返回的 Controller，否则会出现“半统一状态”。

---

## 5. 路由与控制器复用关系

### 5.1 V2 存在复用 V1 Controller

当前发现：

- `app/Http/Routes/V2/UserRoute.php`
  - 直接复用 `App\Http\Controllers\V1\User\UserController`
- `app/Http/Routes/V2/PassportRoute.php`
  - 直接复用 `App\Http\Controllers\V1\Passport\AuthController`
  - 直接复用 `App\Http\Controllers\V1\Passport\CommController`

这说明：

> `V2` 并不完全独立。
> 某些你以为只影响 `V1` 的改动，实际上会同步影响 `V2`。

这是后续 API 改造里最需要优先规避的隐式耦合点之一。

---

## 6. 请求校验现状

### 6.1 已使用 FormRequest 的区域

项目已经有一批 `FormRequest`：

- `app/Http/Requests/Passport/*`
- `app/Http/Requests/User/*`
- `app/Http/Requests/Admin/*`

典型已使用区域：

- 登录 / 注册 / 忘记密码
- 用户修改密码 / 更新资料 / 划转
- 工单创建 / 撤回
- 订单提交
- 部分后台配置、套餐、订单、用户管理

### 6.2 未统一使用 FormRequest 的区域

审计发现不少 Controller **没有显式 FormRequest 引用**，包括：

- 多数 `Guest` 控制器
- 多数 `Client` 控制器
- 多数 `Server` 控制器
- 一批 `V1/User` 读接口
- 一批 `V2/Admin` 控制器

这类接口的校验来源通常是：

1. Controller 内部 `$request->validate(...)`
2. 手写条件判断
3. 基本无显式校验

结论：

> 如果要改 API 参数，优先确认校验入口究竟在哪里。
> 不能默认“所有字段规则都在 FormRequest”。

---

## 7. 高风险接口设计问题

### 7.1 使用 GET 执行状态变更

已发现典型例子：

- `V1/UserRoute.php`
  - `GET /user/resetSecurity`
  - `GET /user/invite/save`
- `V2/UserRoute.php`
  - `GET /user/resetSecurity`

这意味着当前 API 中存在：

- GET 带副作用
- 不完全 RESTful

因此：

> 如果后续要“语义修正”为 POST，前端和调用方会直接受影响。
> 这类变更必须做兼容层或双路由过渡。

### 7.2 使用 `ANY`

后台若干接口使用：

- `ANY /fetch`
- `ANY /templates`
- `ANY /codes`
- `ANY /statistics`
- `ANY /getAuditLog`

风险：

- 请求语义不清晰
- 代理缓存/安全策略不友好
- 前端和第三方调用容易出现 method 不一致

### 7.3 非 JSON / 下载流响应混入 API

例如：

- CSV 导出
- 配置文件输出
- 礼品卡导出

这意味着未来如果做：

- 统一 AES 响应加密
- 统一 envelope 包装
- 统一 trace header

都必须单独排除下载流接口。

---

## 8. 异常处理边界

关键文件：

- `app/Exceptions/Handler.php`

当前明确处理：

- `ApiException`
- `InterceptResponseException`
- `ViewException`

影响：

1. 主动抛 `ApiException` 的接口，最终会被包装成统一 `fail`
2. 普通 Laravel 异常仍然可能走框架默认异常响应
3. 如果后续新增自定义异常类型，**要同步更新 `Handler`**

结论：

> API 改造不只是“返回值改造”，还包括“异常出口统一”。

---

## 9. 订阅接口不是普通业务 API

关键文件：

- `routes/web.php`
- `app/Http/Controllers/V1/Client/ClientController.php`
- `app/Protocols/*`

订阅链路当前会根据 `flag` / `User-Agent` 输出不同格式：

- 通用 base64 节点集合
- Clash YAML
- Clash Meta YAML
- Sing-box JSON
- QuantumultX / Shadowrocket / Surge / Surfboard 等专用格式

所以：

> `/s/{token}` 或客户端订阅相关接口，不应该和普通 JSON API 用同一套改造策略。

尤其不能直接套：

- JSON envelope
- 统一 AES 包装
- 通用响应字段修改

---

## 10. API 修改前的推荐边界分级

建议把后续 API 修改分成四级：

### Level 1：低风险

- 新增只读接口
- 新增返回字段（兼容旧字段）
- 新增可选参数
- 新增 header

### Level 2：中风险

- 修改后台查询接口的筛选字段
- 将零散校验收敛到 FormRequest
- 统一后台部分响应格式

### Level 3：高风险

- 修改前台用户 API 的字段名
- 修改登录返回结构
- 修改分页结构
- 修改副作用 GET 为 POST
- 修改 V1/V2 复用控制器的行为

### Level 4：极高风险

- 修改订阅接口输出协议
- 修改节点通信接口参数/鉴权
- 修改 Sanctum 鉴权方式
- 修改统一异常出口

---

## 11. 推荐改造顺序

如果后面真的要改 API，建议按下面顺序推进：

### Phase 1：建立基线

1. 冻结当前路由表
2. 冻结当前响应结构样本
3. 标注下载流/文本流/订阅流接口
4. 标注复用 V1 Controller 的 V2 路由

### Phase 2：做只增不减改造

1. 先新增字段，不删旧字段
2. 先新增校验，不立刻改语义
3. 先在后台接口试点统一响应

### Phase 3：做兼容层

1. 对需要调整 method 的接口保留旧路由
2. 对字段改名同时保留旧字段一段时间
3. 对前台接口增加版本标记或迁移说明

### Phase 4：最后再碰高风险区域

1. 节点通信
2. 订阅协议输出
3. 登录/鉴权链路

---

## 12. 下一步建议

在正式动 API 之前，建议继续补三份工件：

1. **接口清单总表**
   - 路由
   - 方法
   - 鉴权
   - Controller
   - Request
   - 响应类型

2. **破坏性变更风险表**
   - 字段改名影响面
   - method 变更影响面
   - V1/V2 复用影响面

3. **改造白名单**
   - 第一批允许改哪些接口
   - 明确不碰哪些接口

---

## 13. 本轮审计涉及的关键文件

- `app/Providers/RouteServiceProvider.php`
- `app/Http/Kernel.php`
- `app/Helpers/ApiResponse.php`
- `app/Exceptions/Handler.php`
- `app/Services/AuthService.php`
- `app/Http/Middleware/User.php`
- `app/Http/Middleware/Admin.php`
- `app/Http/Middleware/Client.php`
- `app/Http/Middleware/ServerV2.php`
- `app/Http/Routes/V1/*.php`
- `app/Http/Routes/V2/*.php`
- `app/Http/Controllers/V1/**/*`
- `app/Http/Controllers/V2/**/*`
- `app/Http/Requests/**/*`

