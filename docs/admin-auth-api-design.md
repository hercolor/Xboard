# Xboard 后台专属 Auth API 设计方案

> 时间：2026-05-19  
> 角色：架构师  
> 目标：设计一套后台专属认证 API，用于替换当前后台对共享 `Passport` / `User` 认证链路的依赖，并为“仅后台模式”落地提供稳定迁移路径。

---

## 1. 设计目标

这次设计要解决的不是“新增几个登录接口”这么简单，而是：

> **把后台认证从共享用户认证体系中拆出来，同时不破坏当前后台前端的登录习惯和 Token 用法。**

因此本方案必须同时满足：

1. 后台登录只允许管理员进入
2. 后台当前编译产物可平滑迁移
3. 认证返回尽量兼容现有 `auth_data`
4. 后台业务 API 继续保持 `secure_path` 边界
5. 后续可以安全下线共享 `Passport/User` 登录链路

---

## 2. 当前问题复述

根据 `docs/admin-auth-api-usage-audit.md` 已确认：

- 后台登录走：`POST /api/v2/passport/auth/login`
- 后台拉取当前用户走：`GET /api/v2/user/info`
- 后台 logout 只是前端本地清 token
- 后台业务 API 才走：`/api/v2/{secure_path}/...`

这说明当前结构是：

```text
共享认证层
├─ passport/auth/login
├─ user/info
└─ auth_data(Bearer Token)

后台业务层
└─ /api/v2/{secure_path}/*
```

架构问题在于：

- 登录入口不是后台专属
- 当前用户接口不是后台专属
- logout 没有服务端会话撤销点
- 后台认证边界晚于后台业务边界

---

## 3. 设计原则

## 3.1 路由边界与后台业务保持一致

后台认证 API 必须进入：

```text
/api/v2/{secure_path}/auth/*
```

而不是：

- `/api/v2/auth/*`
- `/api/v2/passport/*`

原因：

1. 与后台业务 API 的路径语义一致
2. 后台入口路径天然具备隐藏性
3. 后台认证链路与后台业务链路边界统一

---

## 3.2 返回结构优先兼容现有 `auth_data`

当前后台前端依赖的是：

- `auth_data`
- 作为 `Authorization: Bearer xxx`

所以第一阶段不要发明新 token 结构。

建议继续返回：

- `auth_data`
- `is_admin`
- 必要时保留 `token`

这样可以降低前端迁移成本。

---

## 3.3 第一阶段只拆认证外壳，不重写底层 Token 体系

第一阶段目标应该是：

- 后台入口独立
- 后台会话接口独立
- 管理员准入前置

而不是立刻做：

- 独立管理员表
- 独立 Sanctum Guard
- 独立 token 表
- JWT / 双 token / refresh token

原因：

> 当前项目还处在 API 收口与 admin-only 形态切换阶段，先做“边界正确”比“体系完美”更重要。

---

## 4. 目标接口设计

## 4.1 路由清单

建议新增：

| 方法 | 路径 | 说明 | 中间件 |
| --- | --- | --- | --- |
| POST | `/api/v2/{secure_path}/auth/login` | 后台管理员登录 | 无 |
| GET | `/api/v2/{secure_path}/auth/me` | 获取当前管理员信息 | `user` + `admin` |
| POST | `/api/v2/{secure_path}/auth/logout` | 退出当前管理员会话 | `user` + `admin` |
| POST | `/api/v2/{secure_path}/auth/logoutAll` | 退出所有管理员会话（可选） | `user` + `admin` |

说明：

- `login` 不走 `admin` 中间件，因为它是认证入口
- `me/logout/logoutAll` 建议同时挂：
  - `user`
  - `admin`

这样可以保持：

1. 必须先是合法 token
2. 且必须是管理员

---

## 4.2 路由归属建议

建议新增：

- `app/Http/Routes/V2/AdminAuthRoute.php`
- `app/Http/Controllers/V2/Admin/AuthController.php`
- `app/Http/Requests/Admin/AuthLogin.php`

不要把 `auth/*` 继续塞进现有 `AdminRoute.php` 的巨型文件里。

原因：

1. 登录入口和业务入口生命周期不同
2. 后续 admin-only 改造中，认证模块会频繁迭代
3. 单独路由文件更方便治理与审计

---

## 5. 接口契约设计

## 5.1 `POST /api/v2/{secure_path}/auth/login`

### 请求参数

建议沿用现有登录参数：

```json
{
  "email": "admin@example.com",
  "password": "password"
}
```

校验规则建议与现有 `Passport\AuthLogin` 保持一致：

- `email: required|email:strict`
- `password: required|min:8`

但建议新建独立请求类：

- `App\Http\Requests\Admin\AuthLogin`

原因：

- 后续后台登录规则可能与前台不同
- 避免继续绑定到 `Passport` 语义

### 登录逻辑

建议流程：

1. 复用 `LoginService::login(email, password)` 完成密码校验
2. 如果登录失败，沿用现有失败结构
3. 如果用户不是管理员：
   - 返回 `403`
   - 消息：`Unauthorized` 或更清晰的管理员专用提示
4. 如果用户是管理员：
   - 使用 `AuthService::generateAuthData()` 生成现有 Bearer Token 结构
   - 返回成功结果

### 返回结构

建议第一阶段保持兼容：

```json
{
  "status": "success",
  "message": "ok",
  "data": {
    "token": "用户订阅令牌",
    "auth_data": "Bearer xxxxx",
    "is_admin": true
  },
  "error": null
}
```

说明：

- `token` 字段虽然是用户订阅 token，但当前前端兼容期内可以保留
- 第二阶段如确认后台不需要该字段，可再收缩

---

## 5.2 `GET /api/v2/{secure_path}/auth/me`

### 用途

替换当前后台对：

```text
GET /api/v2/user/info
```

的依赖。

### 返回目标

不要直接复用前台 `UserController::info()` 的最小用户资料模型。

后台更适合返回：

```json
{
  "status": "success",
  "message": "ok",
  "data": {
    "id": 1,
    "email": "admin@example.com",
    "is_admin": true,
    "is_staff": false,
    "avatar_url": "...",
    "last_login_at": 1710000000
  },
  "error": null
}
```

### 字段策略

第一阶段建议只返回后台当前明确需要的“身份头部字段”：

- `id`
- `email`
- `is_admin`
- `is_staff`
- `avatar_url`
- `last_login_at`

不要一开始就把前台订阅域字段全部搬进来，例如：

- `transfer_enable`
- `expired_at`
- `balance`
- `plan_id`
- `discount`
- `uuid`

因为这些字段属于用户业务域，不属于后台认证域。

---

## 5.3 `POST /api/v2/{secure_path}/auth/logout`

### 用途

为后台前端提供一个服务端会话撤销点。

### 建议行为

1. 获取当前 `sanctum` token
2. 删除当前 token
3. 返回 `success(true)`

### 返回示例

```json
{
  "status": "success",
  "message": "ok",
  "data": true,
  "error": null
}
```

### 兼容建议

前端在调用成功后仍然继续执行：

- 清本地 token
- 重置用户状态
- 跳转 `/sign-in`

这样迁移成本最低。

---

## 5.4 `POST /api/v2/{secure_path}/auth/logoutAll`（可选）

可复用现有：

- `AuthService::removeAllSessions()`

用于后台账号多端同时下线。

这不是第一阶段必做项，但建议在设计中预留。

---

## 6. 控制器设计建议

## 6.1 建议新增控制器

文件：

- `app/Http/Controllers/V2/Admin/AuthController.php`

建议方法：

- `login(AuthLogin $request)`
- `me(Request $request)`
- `logout(Request $request)`
- `logoutAll(Request $request)`（可选）

---

## 6.2 可复用能力

### 可直接复用

1. `LoginService::login()`
   - 账号密码校验
   - 密码错误限流
   - 封禁检查
   - 登录时间更新

2. `AuthService::generateAuthData()`
   - Bearer Token 生成
   - `is_admin` 返回

3. `AuthService::findUserByBearerToken()`
   - 如后续需要兼容特殊 token 处理时可复用

### 不建议继续直接复用

1. `V1 Passport AuthController::login()`
   - 因为它没有“管理员前置约束”

2. `V1 UserController::info()`
   - 因为它是前台用户中心资料接口，不是后台身份接口

---

## 6.3 推荐的管理员登录判断位置

建议在 `AuthController@login()` 中完成：

```text
LoginService 校验成功
-> 判断 user->is_admin
-> 非管理员直接 fail/403
-> 管理员才 generateAuthData()
```

而不是：

- 让所有人登录成功
- 再等访问后台业务时被 `admin` 中间件拒绝

原因：

> 后台认证的核心要求，就是把“管理员准入”从业务层前移到登录层。

---

## 7. 中间件设计建议

## 7.1 `me/logout/logoutAll` 使用现有中间件即可

建议使用：

- `user`
- `admin`

理由：

- `user` 确保 token 合法
- `admin` 确保角色为管理员

无需第一阶段新增 `admin.auth` 专用中间件。

---

## 7.2 `login` 不挂 `admin`，但要做角色前置判断

登录接口本身无法依赖 `admin` 中间件，因为用户尚未登录。

所以管理员校验必须写在控制器逻辑中。

---

## 8. 前端迁移建议

## 8.1 登录请求迁移

从：

```text
POST /api/v2/passport/auth/login
```

迁移到：

```text
POST /api/v2/{secure_path}/auth/login
```

注意：

- 返回中的 `auth_data` 保持不变
- 前端本地 `access_token` 存储逻辑可不变

---

## 8.2 当前用户请求迁移

从：

```text
GET /api/v2/user/info
```

迁移到：

```text
GET /api/v2/{secure_path}/auth/me
```

同时前端 user store 应从“前台用户资料结构”切换到“后台管理员资料结构”。

如果当前编译产物只依赖少数字段，这一步风险可控。

---

## 8.3 logout 迁移

从：

- 本地清 token

迁移到：

1. `POST /api/v2/{secure_path}/auth/logout`
2. 成功后本地清 token
3. 跳转 `/sign-in`

---

## 9. 兼容策略

## 9.1 第一阶段：新增，不立即删除旧接口

第一阶段应该：

- 新增后台专属 Auth API
- 后台前端切换过去
- 保留旧 Passport/User 兼容接口

原因：

- 当前后台编译产物尚未切换
- 直接删除旧接口会导致后台先挂

---

## 9.2 第二阶段：确认后台不再依赖共享认证

确认项包括：

- 后台登录不再请求 `/passport/auth/login`
- 后台不再请求 `/user/info`
- 后台 logout 已走服务端接口

只有这三项都完成后，才进入下一步。

---

## 9.3 第三阶段：冻结共享用户认证入口兼容性

2026-05-19 范围修正：DK_Theme 仍需要共享 `Passport/User` API。后续不可按“仅后台”直接禁用这些路由，只能确认后台已不再依赖，并把它们作为分离前端 API 契约保留：

- `V1 PassportRoute`
- `V2 PassportRoute`
- `V1 UserRoute`
- `V2 UserRoute`

至少对 admin-only 模式下：

- login
- register
- forget
- sendEmailVerify
- user/info

都应禁止继续作为后台依赖，但不禁止 DK_Theme 正常调用。

---

## 10. 风险与取舍

## 10.1 为什么第一阶段仍保留 `auth_data`

因为当前后台编译产物已经围绕：

- `auth_data`
- `Authorization`
- `Bearer Token`

完成了登录态管理。

如果第一阶段同时改：

- 登录地址
- 返回结构
- token 结构
- store 字段

迁移面会显著扩大。

所以本方案明确选择：

> **先改认证边界，不改 token 消费方式。**

---

## 10.2 为什么 `me` 不直接复用 `/user/info`

因为 `/user/info` 属于用户前台域，内容包含明显的会员业务字段。

后台认证层更需要的是：

- 当前管理员是谁
- 是否管理员/员工
- 头部展示基础信息

如果继续复用 `/user/info`，后台认证域会继续绑定到前台用户域。

---

## 10.3 为什么不先做独立管理员表

因为当前系统的管理员身份已经稳定挂在 `users.is_admin` 上。

在当前阶段，真正阻碍 admin-only 的不是“管理员和用户共表”，而是：

- 登录接口共用
- 当前用户接口共用
- logout 不独立

所以先解“认证边界”，再评估是否解“数据模型边界”，是更稳的路径。

---

## 11. 推荐任务清单

### T1：新增后台专属认证实现

- [ ] 新建 `V2/Admin/AuthController`
- [ ] 新建 `V2/AdminAuthRoute`
- [ ] 新建 `Admin/AuthLogin` 请求类
- [ ] 实现 `login/me/logout`

### T2：切换后台前端登录链路

- [ ] 登录改调 `/{secure_path}/auth/login`
- [ ] 初始化改调 `/{secure_path}/auth/me`
- [ ] 退出改调 `/{secure_path}/auth/logout`

### T3：验证兼容性

- [ ] `auth_data` 仍能驱动现有请求拦截器
- [ ] 后台菜单/头部用户态正常
- [ ] 非管理员登录被前置拒绝

### T4：进入 admin-only 收口

- [ ] `/` 前台入口返回 404，不跳转后台入口
- [ ] 禁止后台继续依赖共享 Passport/User
- [ ] 再进入 DK_Theme 兼容矩阵冻结阶段，不删除前台 API

---

## 12. 最终结论

后台专属认证 API 的第一阶段设计目标非常明确：

> **不是重建认证体系，而是把后台认证的“入口、当前用户、退出登录”从共享用户域中切出来。**

最推荐的第一版接口就是：

```text
POST /api/v2/{secure_path}/auth/login
GET  /api/v2/{secure_path}/auth/me
POST /api/v2/{secure_path}/auth/logout
```

并且：

- 继续返回 `auth_data`
- 继续使用现有 Sanctum Token
- 继续用 `users.is_admin` 作为管理员身份
- 但把管理员准入判断前移到登录接口

这样能以最小破坏完成最大边界收益，也最适合作为“仅后台模式”的第一块真实落地代码。
