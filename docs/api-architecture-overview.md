# Xboard API 架构总览

> 角色视角：架构师  
> 目标：把当前 Xboard API 的**真实结构**讲清楚，而不是只罗列接口。  
> 用途：后续所有 API 改造、统一返回、鉴权调整、版本演进、节点通信改造，都应以这份文档作为上位边界。

---

## 1. 一句话结论

当前 Xboard 的 API 架构，本质上是一个 **“多通道、弱统一、插件可插拔、历史兼容优先”** 的系统：

- **用户前台 API**、**管理后台 API**、**节点通信 API**、**订阅输出接口** 共存
- 统一入口存在，但**统一契约不完整**
- 存在 `V2` 复用 `V1 Controller`、`GET` 执行写操作、`ANY` 路由、混合响应类型等历史包袱
- Controller 层已经有一定服务化，但尚未形成严格的 application/service boundary

这意味着：

> 它不是一个适合“直接大改”的 API 系统。  
> 正确路径是：**先分层澄清，再按通道治理，再做兼容演进。**

---

## 2. 当前 API 的四大通道

从系统职责看，当前 API 不是一条线，而是四条通道：

### A. 用户前台通道

- 版本：`/api/v1`
- 主要模块：
  - `guest`
  - `passport`
  - `user`
  - `client`
- 主要服务对象：
  - 用户前端页面
  - App / 客户端辅助配置

特点：

- 业务密度高
- 面向最终用户
- 对兼容性最敏感

### B. 管理后台通道

- 版本：`/api/v2`
- 主要模块：
  - `config`
  - `user`
  - `order`
  - `plan`
  - `payment`
  - `server/*`
  - `plugin`
  - `theme`
  - `system`

特点：

- 模块数量最多
- 查询、筛选、批量操作多
- 下载流 / 导出类接口多

### C. 节点通信通道

- 版本：
  - 传统：`/api/v1/server/*`
  - 新版：`/api/v2/server/*`
- 主要用途：
  - 节点握手
  - 用户同步
  - 配置下发
  - 流量/在线/状态/指标上报

特点：

- 对字段和鉴权高度敏感
- 面向机器，不是面向人
- 不能用普通业务 API 的改法来处理

### D. 订阅输出通道

- 路径：`routes/web.php` 中 `/s/{token}`
- 主要输出：
  - 通用 base64 节点集
  - Clash YAML
  - Clash Meta YAML
  - Sing-box JSON
  - Surge / Shadowrocket / Surfboard / QuantumultX 等专用格式

特点：

- 本质上是“配置分发接口”
- 不是标准 JSON API
- 输出协议受客户端生态约束

---

## 3. 路由层结构

### 3.1 注册机制

入口文件：

- `app/Providers/RouteServiceProvider.php`

当前采用：

- `/api/v1` 扫描 `app/Http/Routes/V1/*.php`
- `/api/v2` 扫描 `app/Http/Routes/V2/*.php`

这是一种**模块化路由装配**，优点是拆分清晰，缺点是：

- 路由规范容易漂移
- 缺少集中路由元数据
- 全局审计必须遍历所有 Route 文件

### 3.2 当前规模

本轮统计结果：

- `V1`：**56** 条路由
- `V2`：**145** 条路由

其中：

- `V1/user`：**41** 条
- `V2/user`：**12** 条
- `V2/server/manage`：**11** 条
- `V2/plugin`：**11** 条
- `V2/gift-card`：**13** 条

说明：

> 当前 API 的复杂度主要集中在：  
> **前台用户域** + **后台管理域** + **节点/插件/主题外围域**

### 3.3 路由层问题

当前路由层存在几个架构级问题：

1. **副作用 GET**
   - 如 `resetSecurity`
   - 如 `invite/save`
2. **ANY 路由过多**
   - 后台筛选、审计、列表等接口中出现
3. **V2 路由复用 V1 Controller**
   - 导致版本边界不独立

这三个问题决定了：

> 后续 API 整理不能只做“命名美化”，必须做**契约梳理与兼容层设计**。

---

## 4. 中间件层结构

### 4.1 公共 API 中间件

定义于：

- `app/Http/Kernel.php`

API 组统一经过：

- `ApplyRuntimeSettings`
- `ForceJson`
- `Language`
- `bindings`

这说明系统已经有一个“统一 API 通道入口”，它是未来做这些事的最佳位置：

- 响应统一包装
- trace_id 注入
- 统一审计
- 响应加密
- 版本协商

### 4.2 身份体系分裂

当前至少有三套身份体系：

#### 1）Sanctum 用户身份

- `user`
- `admin`

适用：

- 用户前台
- 后台管理

#### 2）订阅 token 身份

- `client`

适用：

- `/s/{token}`
- 客户端订阅配置接口

#### 3）节点/机器身份

- `server`
- `server.v2`

适用：

- 节点上报
- 配置同步
- 机器接入

架构含义：

> 当前 API 不是“一个认证体系 + 多个角色”，而是“多种 API 通道 + 多套认证模型”。

因此后续如果要做统一网关、统一响应、统一 header：

- 必须做 **channel-aware** 设计
- 不能假设所有接口共享同一套认证生命周期

---

## 5. 控制器层结构

### 5.1 现状：部分服务化，但边界不彻底

Controller 已经开始把业务下沉给 Service：

- `Auth\LoginService`
- `Auth\RegisterService`
- `OrderService`
- `PlanService`
- `UserService`
- `ServerService`
- `NodeSyncService`
- `PaymentService`

这是积极信号。

但从样例控制器看，仍然存在混合模式：

#### Controller 里仍做较多工作

- 请求校验
- 查询拼装
- DTO/响应字段整形
- 事务控制
- Hook 调用
- 部分业务判断

例如：

- `V1/User/UserController.php`
- `V1/User/OrderController.php`
- `V2/Admin/UserController.php`

架构判断：

> 当前 Controller 更像“胖协调器”，不是纯薄层。

### 5.2 复用关系带来的版本污染

当前 `V2` 部分路由复用 `V1` 控制器：

- `app/Http/Routes/V2/UserRoute.php` → `V1\User\UserController`
- `app/Http/Routes/V2/PassportRoute.php` → `V1\Passport\AuthController`

这说明：

- V2 不是完整重写
- 只是“新旧混挂”

架构后果：

1. 版本语义不纯
2. 修改 `V1` 行为会回流影响 `V2`
3. 版本演进成本上升

---

## 6. 服务层结构

### 6.1 服务层已经形成的业务域

当前可以识别出这些服务域：

#### 身份域

- `AuthService`
- `Auth\LoginService`
- `Auth\RegisterService`
- `Auth\MailLinkService`

#### 订单/支付域

- `OrderService`
- `PaymentService`
- `CouponService`
- `PlanService`

#### 用户/流量/工单域

- `UserService`
- `TrafficResetService`
- `TicketService`
- `GiftCardService`

#### 节点/配置分发域

- `ServerService`
- `NodeSyncService`
- `NodeRegistry`

#### 平台能力域

- `ThemeService`
- `PluginManager`
- `HookManager`
- `SettingService`
- `UpdateService`

### 6.2 服务层的真实角色

当前服务层并不完全是传统 DDD service，更像三种角色混合：

1. **业务服务**
   - `OrderService`
   - `PlanService`
   - `UserService`
2. **集成服务**
   - `PaymentService`
   - `TelegramService`
   - `MailService`
3. **基础设施编排服务**
   - `NodeSyncService`
   - `PluginManager`
   - `ThemeService`

这意味着后续整理时，最应该避免的是：

> 把所有“Service”继续堆在一起，最后演化成不可维护的万能层。

---

## 7. 响应层结构

### 7.1 名义上的统一响应

统一包装定义在：

- `app/Helpers/ApiResponse.php`

标准 JSON 约定是：

```json
{
  "status": "success|fail",
  "message": "...",
  "data": {},
  "error": null
}
```

### 7.2 实际上的多响应制

当前控制器存在以下并行输出路径：

1. `success/fail`
2. `response()->json(...)`
3. `response([...])`
4. `streamDownload(...)`
5. 纯文本 / YAML / 订阅串

也就是说，当前系统并不是一个统一 response contract，而是：

> **统一 JSON 包装 + 多种旁路输出**

从架构角度看，后续必须先做“响应通道分类”：

### 建议分为四类

1. **Interactive JSON**
   - 前台/后台标准业务接口
2. **Machine JSON**
   - 节点/机器通信
3. **Export Stream**
   - CSV/下载
4. **Config Delivery**
   - 订阅、YAML、客户端配置

只有把这四类分开，统一响应和统一加密才不会误伤。

---

## 8. 插件与 Hook 横切层

关键文件：

- `app/Services/Plugin/HookManager.php`
- `app/Services/Plugin/InterceptResponseException.php`

这是当前 API 架构里非常关键但容易被忽略的一层。

它带来的架构特征是：

1. **控制器响应可被插件截断**
2. **业务数据可被 filter 动态修改**
3. **Hook 是横切扩展点，不是显式依赖**

例如：

- 登录后 Hook
- 注册前后 Hook
- 订单创建前后 Hook
- 订阅响应 Hook

架构含义：

> 这套 API 并不是纯静态代码路径，而是“可被插件改写的执行流”。

所以：

- 做 API 审计时，不能只看 Controller
- 做 breaking change 时，必须考虑 Hook 名称与时机兼容性

---

## 9. 节点同步与 API 的特殊关系

节点侧不是普通 REST 客户端，它是：

- HTTP 拉配置
- HTTP 上报状态
- Redis 发布同步事件
- Workerman WebSocket 收发增量同步

关键组件：

- `V2/Server/ServerController`
- `NodeRegistry`
- `NodeSyncService`
- `ServerService`

这说明：

> 节点通道实际上是一个“HTTP + Redis PubSub + WS 内存注册表”的混合架构。

因此这部分的 API 整理，目标不应该只是“接口更漂亮”，而应该是：

- 契约稳定
- 状态同步幂等
- 事件模型清晰
- HTTP/WS 角色边界明确

---

## 10. 当前架构的主要问题清单

### P0：必须先识别的结构性问题

1. `V2` 复用 `V1 Controller`
2. 响应类型混杂
3. 副作用 GET
4. `ANY` 路由
5. 多认证通道没有清晰文档

### P1：中期必须治理的问题

1. Controller 过胖
2. FormRequest 覆盖不均匀
3. 列表筛选协议不统一
4. 导出接口与 JSON 接口混在同层
5. 订阅/节点/后台业务接口没有清晰通道边界

### P2：长期可优化问题

1. 缺少统一 DTO / Resource 策略
2. 缺少接口元数据清单
3. 缺少明确版本淘汰策略
4. 缺少面向机器接口的单独 contract 层

---

## 11. 推荐的目标架构

不是推倒重来，而是在现有基础上收敛成下面这套：

### 11.1 四通道分层

#### Channel 1：Frontend API

- 面向用户前端
- 保持 JSON envelope
- 保持强兼容

#### Channel 2：Admin API

- 面向后台管理
- 查询/批量能力独立治理
- 下载接口从标准 JSON 通道中显式分离

#### Channel 3：Node API

- 面向节点/机器
- 单独契约文档
- 单独版本策略

#### Channel 4：Subscription Delivery

- 面向客户端配置输出
- 不纳入普通 JSON API 改造

### 11.2 控制器职责收窄

目标：

- Controller 只做：
  - 鉴权上下文接入
  - Request 校验
  - Service 调用
  - Response 装配

不要再承担：

- 大量业务判断
- 大量 query DSL 构造
- 大量事务拼装
- 大量字段整形逻辑

### 11.3 Application Service 明确化

建议未来逐步引入更清晰的服务分层：

- `Application/`
  - 用例编排
- `Domain/`
  - 规则与策略
- `Infrastructure/`
  - 支付、节点、插件、缓存、外部服务

当前不需要一次性搬目录，但思路要先统一。

---

## 12. 未来改造的执行顺序

### Phase 1：架构冻结

先冻结并文档化：

1. 路由表
2. 鉴权矩阵
3. 响应类型矩阵
4. V1/V2 复用矩阵
5. 节点/订阅特殊通道清单

### Phase 2：非破坏性收敛

1. 新增文档，不删旧行为
2. 新增 Response 分类
3. 新增 FormRequest 覆盖
4. 新增 Resource/Transformer 规范

### Phase 3：版本清淤

1. 逐步取消 `V2 -> V1 Controller` 复用
2. 为副作用 GET 提供 POST 新入口
3. 为 `ANY` 路由拆分 method

### Phase 4：高风险演进

1. 节点 API 契约收敛
2. 响应统一或加密通道分类接入
3. 订阅输出协议演进

---

## 13. 当前阶段的架构原则

后面所有 API 改动建议遵守这 8 条：

1. **先分通道，再改接口**
2. **先加兼容层，再删旧行为**
3. **前台 API 兼容优先**
4. **节点 API 稳定优先**
5. **订阅输出不按普通 JSON 改**
6. **后台导出流与业务 JSON 分离看待**
7. **避免继续扩大 V1/V2 复用**
8. **Hook 兼容性视为正式契约**

---

## 14. 这份文档之后应该怎么用

后续每次改 API，都先回答四个问题：

1. 这次改动属于哪个通道？
2. 会不会影响复用 Controller？
3. 响应类型是不是标准 JSON？
4. 有没有插件 Hook / 节点 / 订阅的隐式影响？

只要这四个问题没回答清楚，就不应该直接动代码。

