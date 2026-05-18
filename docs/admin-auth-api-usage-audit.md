# Xboard 后台认证 API 调用审计

> 时间：2026-05-19  
> 角色：架构师  
> 目标：确认当前后台前端（编译产物）实际调用的登录、鉴权、用户信息接口，为后续“仅后台模式”与“后台专属认证 API”拆分提供直接证据。

---

## 1. 一句话结论

审计结论已经足够明确：

> **当前后台前端并没有使用后台专属认证接口，而是直接复用了共享用户认证链路。**

具体表现为：

1. 后台登录直接调用：
   - `POST /api/v2/passport/auth/login`
2. 登录成功后，本地保存的是：
   - `auth_data`（Bearer Token）
3. 后台初始化用户信息时调用：
   - `GET /api/v2/user/info`
4. 后台业务 API 才走：
   - `GET/POST /api/v2/{secure_path}/...`

这意味着：

- 后台登录入口本质上仍是“共享 Passport 登录”
- 后台当前没有专属 `auth/login` / `auth/me` / `auth/logout`
- 后台与用户体系的边界仍然是 **登录后再靠 `admin` 中间件拦截业务接口**

---

## 2. 审计范围

本次审计重点查看：

### 后台前端壳层

- `resources/views/admin.blade.php`
- `public/assets/admin/index.html`
- `public/assets/admin/assets/index-BdbgNvrf.js`

### 后端相关边界

- `app/Http/Middleware/Admin.php`
- `app/Http/Routes/V2/AdminRoute.php`
- `app/Http/Routes/V2/PassportRoute.php`
- `app/Http/Routes/V2/UserRoute.php`
- `app/Services/AuthService.php`

补充说明：

- 当前后台前端源码未直接随主仓库提供
- 本次依据的是 **编译产物中的静态字符串与调用片段**
- 结论属于高可信静态结论

---

## 3. 直接证据链

## 3.1 后台请求基址固定为 `/api/v2`

在编译产物 `public/assets/admin/assets/index-BdbgNvrf.js` 中可见：

- 创建了请求实例 `TL`
- `baseURL` 取值为：
  - `window.settings.base_url + api/v2`

也就是说后台前端所有 API 都是从：

```text
/api/v2
```

开始拼接。

这和后端 `RouteServiceProvider` 中的 `V2` 路由组一致。

---

## 3.2 后台登录白名单明确包含共享 Passport 路由

编译产物中有一组无需附带已登录 Token 的白名单路径：

```text
/passport/auth/login
/passport/auth/token2Login
/passport/auth/register
/guest/comm/config
/passport/comm/sendEmailVerify
/passport/auth/forget
```

这组白名单说明两件事：

1. 后台前端启动时就知道自己要访问共享 `Passport` 和 `Guest` 接口
2. 它并不存在独立的后台登录路由白名单，如：
   - `/auth/login`
   - `/{secure_path}/auth/login`

结论：

> 后台前端当前把共享用户认证接口视作自己的登录入口。

---

## 3.3 后台登录表单直接提交到 `/passport/auth/login`

在编译产物中可直接定位到登录表单提交逻辑：

- 调用：`RL("/passport/auth/login", e)`
- 其中 `RL` 是 `POST` 包装
- 由于请求实例 `TL` 的 `baseURL = /api/v2`

所以后台登录实际请求是：

```text
POST /api/v2/passport/auth/login
```

这一点是本次审计的最关键证据。

它证明：

> 后台登录不是后台专属登录，而是直接走 `V2 PassportRoute`。

而根据此前审计：

- `V2 PassportRoute` 又直接复用 `V1 Passport AuthController`

所以进一步可得：

> 后台登录本质上复用了 `V1` 的共享登录实现。

---

## 3.4 登录成功后只保存 `auth_data`，没有后台专属会话模型

编译产物中的登录成功处理逻辑为：

1. 从登录响应中取：
   - `t.auth_data`
2. 存入本地 `access_token`
3. 更新本地用户状态中的 token
4. 再发起用户信息请求

配合后端 `AuthService::generateAuthData()` 可知：

登录成功返回结构包含：

- `token`
- `auth_data`
- `is_admin`

但当前后台前端实际最核心依赖的是：

```text
auth_data = Bearer xxxxx
```

并把它作为后续所有请求的 `Authorization` 头。

同时，从编译产物可见，登录成功分支并没有基于返回里的 `is_admin` 做前端前置拦截；它的核心动作只有：保存 `auth_data`、更新 token、拉取 `/user/info`。

这说明：

- 后台没有独立 Session 模型
- 没有后台专属 Access Token 格式
- 仍然与普通用户共用 Sanctum Token 体系

---

## 3.5 后台用户初始化请求走的是 `/api/v2/user/info`

编译产物中可直接定位到：

```text
z0e = fetchUserInfo -> GET /user/info
```

结合 `baseURL = /api/v2`，可得后台初始化用户信息请求为：

```text
GET /api/v2/user/info
```

这条路由不是 `AdminRoute`，而是：

- `app/Http/Routes/V2/UserRoute.php`
- 指向 `App\Http\Controllers\V1\User\UserController::info`

即：

> 后台在登录后读取当前用户信息，仍然走的是共享用户接口，而不是后台专属 `me` 接口。

这是第二个核心证据。

---

## 3.6 后台业务 API 才真正走 `/{secure_path}`

编译产物中可以看到：

- `OL() => window.settings.secure_path ?? ""`
- 之后大量后台业务 API 都是：
  - `OL() + "/stat/getStats"`
  - `OL() + "/config/fetch"`
  - `OL() + "/theme/getThemes"`
  - `OL() + "/server/manage/getNodes"`
  - 等等

这说明后台请求是分两层的：

### 登录层 / 初始化层

- `/api/v2/passport/auth/login`
- `/api/v2/user/info`
- `/api/v2/guest/comm/config`
- `/api/v2/passport/auth/forget`
- 等共享入口

### 后台业务层

- `/api/v2/{secure_path}/...`

结论：

> 当前系统只在“业务接口层”做了后台路径隔离，但没有在“认证层”做后台专属隔离。

---

## 3.7 Logout 是前端本地退出，不是后端专属登出

编译产物中能定位到 logout 行为：

- 清除本地 `access_token`
- 重置用户状态
- 跳转 `/sign-in`

没有发现后台专属后端登出接口调用，例如：

- `POST /api/v2/{secure_path}/auth/logout`
- `POST /api/v2/passport/auth/logout`

这说明当前退出登录更接近：

> 前端本地清 Token，而不是后端显式撤销会话。

在共享 Token 体系下，这会带来：

- token 生命周期不可控地偏长
- 后端无统一管理员会话回收点

---

## 4. 与后端结构的对应关系

## 4.1 后台中间件并不参与登录，只参与后台业务 API

`app/Http/Middleware/Admin.php` 的逻辑是：

- 读取 `sanctum` 当前用户
- 检查 `is_admin`
- 不满足则 `403`

这说明：

- `admin` 中间件只用于后台业务接口
- 登录接口本身并没有“仅管理员可登录”的专属入口保护

所以当前实际模型是：

1. 任意用户先尝试走共享登录
2. 登录成功后拿到 `auth_data`
3. 再访问后台业务 API
4. 由 `admin` 中间件决定放行或拒绝

这是一种 **后置式后台权限判定**，不是 **前置式后台专属认证**。

---

## 4.2 `V2 PassportRoute` / `V2 UserRoute` 仍然是共享兼容层

已知路由映射：

- `V2 PassportRoute` → `V1 Passport AuthController / CommController`
- `V2 UserRoute` → `V1 UserController`

所以后台当前依赖的是：

- 共享登录
- 共享忘记密码
- 共享邮箱验证码
- 共享 token 登录
- 共享用户信息接口

换句话说：

> 后台前端当前所依赖的认证层，并不属于后台域，而是共享用户域。

---

## 5. 当前风险判断

## 5.1 风险一：后台登录入口不是管理员专属入口

当前后台登录调用的是：

```text
POST /api/v2/passport/auth/login
```

该接口本身不是后台接口，理论上任何普通用户都可提交用户名密码。

即使后续后台业务接口会因 `admin` 中间件被拒绝，认证入口本身依然是共享的。

风险级别：**高**

---

## 5.2 风险二：后台登录后拿的是共享用户 Token

当前保存的是：

- `auth_data`（Bearer Token）

这与普通用户 API 体系完全兼容。

意味着：

- 认证令牌没有后台隔离语义
- 后续做权限收口、接口加密、审计区分时边界不清晰

风险级别：**高**

---

## 5.3 风险三：后台当前依赖 `/api/v2/user/info`

后台初始化当前用户信息仍走用户域接口：

```text
GET /api/v2/user/info
```

而不是：

```text
GET /api/v2/{secure_path}/auth/me
```

这意味着：

- 后台前端依赖共享用户模型字段
- 后续要拆后台专属 Auth 时，不能只替换 login，还要替换 me/info

风险级别：**中高**

---

## 5.4 风险四：退出登录不是后端显式撤销

当前 logout 只是：

- 本地删 token
- 重置状态
- 跳转登录页

没有明确服务端撤销点。

这会影响：

- 会话管理
- 审计一致性
- 强制下线 / 风险回收能力

风险级别：**中**

---

## 6. 对 admin-only 改造的直接启示

## 6.1 不能直接删 Passport 路由

因为后台当前确实在调用：

- `/api/v2/passport/auth/login`
- `/api/v2/passport/auth/token2Login`
- 以及相关共享入口白名单

如果在没有替换后台前端的前提下直接删掉这些路由：

- 后台登录会立即失效
- 甚至后台初始化流程也可能失败

所以：

> Passport 不能先删，必须先替换后台调用链。

---

## 6.2 后台专属认证至少要补三件事

建议新增后台专属接口：

```text
POST /api/v2/{secure_path}/auth/login
GET  /api/v2/{secure_path}/auth/me
POST /api/v2/{secure_path}/auth/logout
```

要求：

1. `login`
   - 只允许 `is_admin = 1`
   - 返回结构兼容现有 `auth_data`
2. `me`
   - 返回后台真正需要的当前管理员信息
3. `logout`
   - 可选撤销当前 token

---

## 6.3 后台前端迁移顺序应为

### 第一步

把后台登录调用从：

- `/api/v2/passport/auth/login`

切到：

- `/api/v2/{secure_path}/auth/login`

### 第二步

把后台初始化用户信息从：

- `/api/v2/user/info`

切到：

- `/api/v2/{secure_path}/auth/me`

### 第三步

把退出登录从：

- 纯前端清 token

升级为：

- 调后端 `auth/logout` + 前端清 token

### 第四步

确认后台前端已不再依赖共享认证后，再禁用：

- `V1 PassportRoute`
- `V2 PassportRoute`
- `V1 UserRoute`
- `V2 UserRoute`

---

## 7. 推荐任务清单

### T1：新增后台专属 Auth 审计结论落档

- [x] 确认后台登录走 `V2 Passport`
- [x] 确认后台用户初始化走 `V2 User/info`
- [x] 确认后台业务接口走 `secure_path`
- [x] 确认 logout 仅为前端本地退出

### T2：设计后台专属认证 API

- [ ] 定义 `login/me/logout` 路由
- [ ] 明确响应结构兼容旧 `auth_data`
- [ ] 明确 token 撤销策略

### T3：后台前端切换认证链路

- [ ] 登录页改调后台专属 `auth/login`
- [ ] 初始化改调后台专属 `auth/me`
- [ ] 退出改调后台专属 `auth/logout`

### T4：共享用户认证退役准备

- [ ] 审计后台是否还用 `token2Login`
- [ ] 审计后台是否真的需要 `register/forget/sendEmailVerify`
- [ ] 确认无依赖后再禁用共享入口

---

## 8. 最终结论

本次审计已经证明：

> **当前后台前端只是“业务 API 走后台 secure_path”，但“认证 API 仍然走共享用户链路”。**

这也是为什么“仅后台模式”不能只改页面入口。

真正要切到后台专属模式，第一优先级不是删前台，而是：

> **先把后台登录、当前用户、退出登录，从共享 Passport/User 路由中彻底拆出来。**

只有完成这一步，后续才可以安全地：

- 禁用会员登录
- 下线会员前台
- 清理共享认证兼容层
- 建立真正的后台专属认证边界
