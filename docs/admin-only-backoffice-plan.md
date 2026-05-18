# Xboard 仅后台模式（Admin-Only）改造方案

> 时间：2026-05-18  
> 角色：架构师  
> 目标：将 Xboard 从“会员前台 + 管理后台”收敛为“仅后台管理系统”，保持现有前后端分离方式不变，前端继续通过 API 访问后端。

---

## 1. 需求结论

新目标不是“关闭注册”这么简单，而是：

> **取消会员登录与会员前台入口，仅保留后台管理访问。**

同时保留：

- 管理后台 SPA
- 后台 API
- 节点通信 API
- 订阅分发能力（如仍服务真实终端用户）
- 支付回调 / Telegram webhook / 机器回调等非人类访问通道

所以这是一次 **产品形态切换**：

- 从“用户面板 + 后台面板”
- 切到“后台运营面板 + 订阅/节点基础设施”

---

## 2. 当前真实结构

## 2.1 Web 入口层

当前 Web 有两个直接入口：

### A. 用户前台入口

文件：`routes/web.php`

- `/` → 渲染 `theme/Xboard/dashboard.blade.php`
- 依赖 `ThemeService`
- 加载 `theme/Xboard/assets/umi.js`

这是一套用户前台 SPA 壳层。

### B. 后台入口

文件：`routes/web.php`

- `/{secure_path}` → 渲染 `resources/views/admin.blade.php`
- 加载 `public/assets/admin/*`
- 当前已经是独立后台前端入口

结论：

> 当前系统在 Web 层已经是“双入口”：一个用户前台，一个后台。

---

## 2.2 API 通道层

根据 `app/Providers/RouteServiceProvider.php`，当前同时注册：

- `/api/v1/*`
- `/api/v2/*`

其中与“会员前台”直接相关的 API 有：

### 用户前台链路

- `app/Http/Routes/V1/GuestRoute.php`
- `app/Http/Routes/V1/PassportRoute.php`
- `app/Http/Routes/V1/UserRoute.php`
- `app/Http/Routes/V1/ClientRoute.php`
- `app/Http/Routes/V2/PassportRoute.php`
- `app/Http/Routes/V2/UserRoute.php`
- `app/Http/Routes/V2/ClientRoute.php`

### 后台链路

- `app/Http/Routes/V2/AdminRoute.php`

### 基础设施链路

- `app/Http/Routes/V1/ServerRoute.php`
- `app/Http/Routes/V2/ServerRoute.php`
- `routes/web.php` 中 `/s/{token}` 订阅分发

结论：

> 当前不是单一后台系统，而是“前台用户域 + 后台管理域 + 节点/订阅基础设施域”三者并存。

---

## 2.3 当前登录架构并未真正前后台隔离

### 事实 1：后台鉴权依赖用户表里的 `is_admin`

文件：`app/Http/Middleware/Admin.php`

行为：

- 走 `sanctum`
- 读取当前用户
- 校验 `is_admin`

也就是说：

> 后台管理员本质上仍然是 `users` 表中的用户，只是 `is_admin = 1`。

### 事实 2：当前登录返回结构是共享的

文件：`app/Services/AuthService.php`

`generateAuthData()` 返回：

- `token`
- `auth_data`
- `is_admin`

说明：

- 当前认证返回本身就带管理员标识
- 这意味着后台前端很可能和用户前台共用登录基础能力

### 事实 3：当前 Passport 登录是共享入口

文件：

- `app/Http/Routes/V1/PassportRoute.php`
- `app/Http/Routes/V2/PassportRoute.php`
- `app/Http/Controllers/V1/Passport/AuthController.php`

现在的登录/注册/找回密码/邮件登录逻辑并没有“后台专属 Auth namespace”。

所以：

> 当前后台不是“独立后台账号体系”，而是“共享用户认证体系 + 管理员角色判断”。

这正是改造成“仅后台模式”时最重要的风险点。

---

## 2.4 当前“关闭注册”能力不等于“取消会员系统”

当前代码里已有：

- `stop_register`
- `invite_force`
- `email_verify`

例如：

- `app/Services/Auth/RegisterService.php`
- `app/Http/Controllers/V2/Admin/ConfigController.php`
- `app/Http/Requests/Admin/ConfigSave.php`

但这些能力只解决：

- 是否允许注册
- 是否要求邀请码
- 是否要求邮箱验证

**它们不能解决：**

- 用户前台入口仍然存在
- 非管理员仍然可以登录
- 忘记密码 / 邮件登录 / 用户中心 API 仍然存在
- 用户前台主题仍然会被渲染

因此：

> `stop_register = 1` 只能叫“关闭注册”，不能叫“仅后台模式”。

---

## 3. 目标架构

## 3.1 目标产品形态

目标形态应当是：

```text
Browser
├─ /{secure_path}            -> Admin SPA
└─ /                         -> 不再提供会员前台（重定向或 404）

Admin SPA
└─ 仅调用后台专属 API

Infrastructure APIs
├─ subscription delivery
├─ payment notify
├─ telegram webhook
└─ server/machine callbacks
```

即：

- 管理员可以登录后台
- 普通会员不能登录 Web 前台
- 用户前台 UI 不再暴露
- 但订阅、节点、回调等基础设施通道按业务需要保留

---

## 3.2 目标分层

### 保留层

1. **Admin Web Shell**
   - `resources/views/admin.blade.php`
   - `public/assets/admin/*`

2. **Admin API**
   - `app/Http/Routes/V2/AdminRoute.php`

3. **Server / Machine API**
   - `V1 ServerRoute`
   - `V2 ServerRoute`

4. **Subscription / Client Delivery**
   - `/s/{token}`
   - `/api/v1/client/subscribe`
   - 以及必要的 token-based client config/version 能力

5. **Webhook / Notify**
   - 支付回调
   - Telegram webhook

### 退役层

1. 用户前台主题入口
   - `/`
   - `theme/Xboard/*`

2. 会员自助认证入口
   - 注册
   - 普通用户登录
   - 找回密码
   - 邮件登录
   - 邮箱验证码发送

3. 用户前台业务 API
   - 用户中心
   - 订单中心
   - 工单中心
   - 前台公告/知识库/邀请/钱包等前台自助能力

---

## 4. 路由处置矩阵

## 4.1 Web 路由

| 路径 | 当前 | 目标 | 说明 |
| --- | --- | --- | --- |
| `/` | 用户前台 SPA | 重定向到 `/{secure_path}` 或返回 404 | 建议优先重定向，后续可切 404 |
| `/{secure_path}` | 后台 SPA | 保留 | 主入口 |
| `/{subscribe_path}/{token}` | 订阅分发 | 保留 | 基础设施通道 |

### 对 `/` 的建议

短期建议：

- 直接 `redirect('/' . secure_path)`

原因：

- 对现网最温和
- 不影响运维入口记忆
- 方便快速切到“只有后台”的使用方式

中期建议：

- 支持切成 404 或简单静态 landing

---

## 4.2 API 路由

| 路由组 | 当前用途 | Admin-Only 目标 | 建议 |
| --- | --- | --- | --- |
| `V1 GuestRoute` | 前台公开配置/套餐/回调 | 仅保留 webhook/notify 必要项 | 选择性保留 |
| `V1 PassportRoute` | 前台注册/登录/找回 | 退役 | 禁用 |
| `V1 UserRoute` | 前台用户中心 | 退役 | 禁用 |
| `V1 ClientRoute` | token 订阅/客户端配置 | 按业务保留 | 保留订阅主链路 |
| `V2 PassportRoute` | 兼容登录链路 | 退役或仅保留管理员登录过渡 | 优先拆掉共享认证 |
| `V2 UserRoute` | 兼容用户信息接口 | 退役 | 禁用 |
| `V2 ClientRoute` | 客户端 token 配置 | 视客户端依赖决定 | 先保留后审计 |
| `V2 AdminRoute` | 后台管理 API | 保留 | 主业务 API |
| `V1/V2 ServerRoute` | 节点通信 | 保留 | 基础设施 API |

---

## 5. 关键架构决策

## 5.1 不能直接删除 `users` 体系

虽然要取消“会员登录”，但不能直接理解成“不再需要用户”。

当前 `User` 仍然承载：

- 订阅 token
- 套餐状态
- 流量统计
- 节点可用性
- 订单/支付结果归属
- 订阅输出身份

所以正确理解是：

> **取消的是“会员交互入口”，不是“用户数据模型”。**

`users` 仍然保留，只是不再提供自助登录门户。

---

## 5.2 后台登录必须从共享 Passport 中拆出来

这是本次改造的核心。

如果继续沿用当前共享 `Passport`：

- 登录入口仍对普通用户开放
- 注册/忘记密码/邮件登录链路依然存在
- 前后台边界永远不清楚

因此目标应是：

### 新的后台专属认证层

建议新增：

```text
/api/v2/{secure_path}/auth/login
/api/v2/{secure_path}/auth/logout
/api/v2/{secure_path}/auth/me
```

规则：

- 只允许 `is_admin = 1` 用户登录
- 返回结构可先兼容现有 `auth_data`
- 后台前端只调用这一组 API

这一步完成后，才能真正让：

- 用户登录体系退役
- 后台登录体系独立

---

## 5.3 订阅与客户端通道不应跟着会员前台一起删除

`Client` 中间件是基于 `token` 的，不是基于后台管理员登录态。

文件：`app/Http/Middleware/Client.php`

这说明：

- `/s/{token}`
- `/api/v1/client/subscribe`
- `/api/v1|v2/client/app/*`

本质上是“订阅/客户端能力”，不是“会员前台页面”。

因此：

> 即使取消会员登录，也不等于要立刻删除订阅通道。

除非产品目标进一步变成：

- 只做纯后台管理工具
- 不再对真实终端用户分发订阅

否则订阅链路应继续保留。

---

## 6. 推荐实施顺序

## Phase 0：建立开关与边界

目标：先把“仅后台模式”定义为显式系统模式，而不是分散逻辑。

建议新增：

- `admin_only_mode` 配置项（优先）
- 或环境变量 `ADMIN_ONLY_MODE=true`

用途：

- Web 路由判断
- Auth 入口判断
- Guest/Passport/User 路由判断
- Admin UI 配置页展示判断

---

## Phase 1：关闭用户前台入口

目标：用户打开域名后不再进入会员前台。

实施项：

1. `routes/web.php` 中 `/` 不再渲染 `theme::...dashboard`
2. 改为：
   - 重定向到 `/{secure_path}`
   - 或返回 404
3. 前台主题加载链路退出主运行路径：
   - `ThemeService`
   - `theme/Xboard/*`

结果：

- Web 层只剩后台入口

---

## Phase 2：拆出后台专属认证 API

目标：管理员登录不再借道共享 Passport。

实施项：

1. 新增 `V2 Admin AuthRoute / AuthController`
2. 登录只允许 `is_admin = 1`
3. 提供：
   - login
   - logout
   - me/check
4. 后台前端改为只调用后台认证 API

结果：

- 后台登录从“共享认证”升级为“后台专属认证”

---

## Phase 3：禁用会员认证与前台用户 API

目标：彻底切断普通会员交互入口。

实施项：

1. 禁用 `V1 PassportRoute`
2. 禁用 `V2 PassportRoute`
3. 禁用 `V1 UserRoute`
4. 禁用 `V2 UserRoute`
5. `V1 GuestRoute` 仅保留必要 webhook/notify

对于“禁用”的实现建议：

### 短期做法（兼容优先）

- 路由保留
- 但统一返回 403 / 410
- 响应消息明确说明：系统已切换为仅后台模式

优点：

- 不会因直接删路由导致未知调用报 404 难定位
- 便于日志审计仍有哪些旧调用存在

### 中期做法（收口）

- 清理已无引用的旧路由

---

## Phase 4：收缩后台配置与文档

目标：把后台配置面板也切换到“无会员前台”的产品语义。

需要处理的配置项主要包括：

- `frontend_theme`
- `frontend_theme_sidebar`
- `frontend_theme_header`
- `frontend_theme_color`
- `stop_register`
- `invite_force`
- `email_verify`
- 前台公开文案相关配置

建议：

1. 对这些配置做“仅后台模式下隐藏/只读”处理
2. 明确哪些配置仍然用于：
   - 邮件模板
   - 订阅文案
   - 客户端元信息
3. 不要一刀切删配置键，先做“UI 收口”，后做“底层清理”

---

## 7. 风险清单

## 7.1 最大风险：后台前端当前可能复用共享登录接口

由于后台前端是编译产物（`public/assets/admin/*`），当前源码未直接内嵌在本仓库中，不能在这一轮仅凭静态源码就 100% 证明它调用哪条登录 API。

但从现有后端结构可高概率判断：

- 它依赖共享登录返回的 `auth_data`
- 并通过 `is_admin` 区分权限

所以：

> 在未给后台前端切换到专属 Auth API 前，不能贸然删除 Passport 登录入口。

---

## 7.2 第二风险：订阅与客户端能力容易被误删

如果把“取消会员登录”误做成“删除所有用户相关 API”，会直接影响：

- `/s/{token}`
- `/api/v1/client/subscribe`
- 节点使用
- 客户端导入

所以必须明确：

- **登录前台 ≠ 订阅分发**
- **用户门户 ≠ 用户数据模型**

---

## 7.3 第三风险：邮件/支付/Telegram 回调仍可能依赖旧链路

如：

- `GuestRoute` 中的 payment notify
- telegram webhook
- 邮件模板中的登录链接

因此禁用会员前台时，必须同时审计：

- 是否还需要邮件登录链接
- 是否还需要忘记密码模板
- 支付成功后是否还会引导用户访问前台页面

---

## 8. 推荐第一批执行任务

> 这是建议的首批落地任务，优先按顺序做，避免大改时互相阻塞。

### T1：补一份后台专属认证审计

- [ ] 定位后台前端当前实际调用的登录接口
- [ ] 确认是否依赖共享 `Passport` 返回结构
- [ ] 确认是否已有 logout / me 能力

### T2：设计 admin-only 模式开关

- [ ] 增加 `admin_only_mode` 配置
- [ ] 统一注入到 Web 路由与 API 层
- [ ] 明确默认值与升级兼容策略

### T3：关闭 `/` 前台入口

- [ ] `/` 改为重定向到 `/{secure_path}`
- [ ] 或返回 404
- [ ] 前台 Theme 渲染链退出主入口

### T4：新增后台专属 Auth API

- [ ] 新增后台 `login`
- [ ] 新增后台 `logout`
- [ ] 新增后台 `me/check`
- [ ] 限制 `is_admin = 1`

### T5：禁用会员侧 Auth / User API

- [ ] 禁用 `V1 PassportRoute`
- [ ] 禁用 `V2 PassportRoute`
- [ ] 禁用 `V1 UserRoute`
- [ ] 禁用 `V2 UserRoute`
- [ ] GuestRoute 只保留必要对外回调

### T6：清理后台配置语义

- [ ] 隐藏前台主题配置
- [ ] 隐藏注册/邀请码类前台入口配置
- [ ] 审计仍被订阅/邮件使用的配置项

---

## 9. 最终建议

对这个需求，正确做法不是直接“删登录页”，而是分三层处理：

1. **入口层**：关掉用户前台 `/`
2. **认证层**：把后台登录从共享 Passport 中拆出来
3. **业务层**：禁用会员自助 API，但保留订阅/节点/回调基础设施

一句话总结：

> **Xboard 要改成“仅后台模式”，本质上不是 UI 删除，而是“共享用户认证体系”向“后台专属认证体系”的收口。**

只有先完成这个收口，后面无论是：

- 接口加密
- API 分层
- 后台前后端完全分离
- 会员前台彻底下线

才会是稳定、可维护的改造路径。
