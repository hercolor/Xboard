# PRD — Xboard 仅后台模式第一阶段（后台专属认证 API）

## 1. 背景

Xboard 当前已具备独立后台 Web 壳层与后台业务 API 路径，但后台登录与当前用户信息接口仍复用共享用户认证链路：`/api/v2/passport/auth/login` 与 `/api/v2/user/info`。这使“仅后台模式”无法直接落地，因为只要共享认证入口还在，后台与会员前台之间的边界就不完整。

## 2. 目标

在不重做账号体系的前提下，为后台增加独立认证入口与会话接口：

- `POST /api/v2/{secure_path}/auth/login`
- `GET /api/v2/{secure_path}/auth/me`
- `POST /api/v2/{secure_path}/auth/logout`

完成后，后台可在认证层与业务层同时使用 `secure_path` 边界，为后续关闭会员 Web 登录入口提供基础。

## 3. 非目标

以下内容不在本阶段范围内：

- 删除共享 `passport` / `user` 路由
- 重写 Sanctum token 体系
- 新增独立管理员表
- 修改后台业务 API
- 修改订阅、节点、支付回调、Telegram webhook 逻辑
- 修改前端编译产物或管理员 UI
- 直接下线 `/` 或会员前台主题入口

## 4. 用户与场景

### 4.1 目标用户

- 使用后台 SPA 的管理员账号
- 当前仍存放于 `v2_user` 表中，依赖 `is_admin = 1`

### 4.2 关键场景

1. 管理员在后台登录页输入邮箱/密码后，仅管理员可成功登录
2. 后台初始化时通过后台专属 `auth/me` 获取身份头部信息
3. 管理员主动退出时，当前 token 在服务端被撤销

## 5. 设计原则

### 5.1 架构原则

- 后台认证接口必须与后台业务接口共享 `/{secure_path}` 路径边界
- 第一阶段复用现有 `LoginService` 与 `AuthService`
- 第一阶段兼容现有 `auth_data` / Bearer Token 返回格式
- 管理员准入判断前移到登录接口，避免非管理员成功创建后台会话

### 5.2 工程原则

- 仅做最小必要新增
- 优先复用现有请求校验、登录服务、响应封装
- 不修改 store、路由注册机制、数据表结构
- 保持改动小且可回滚

## 6. 功能需求

### 6.1 后台登录接口

新增 `POST /api/v2/{secure_path}/auth/login`：

- 请求参数：`email`、`password`
- 校验规则：与现有 `Passport\AuthLogin` 等价
- 处理流程：
  1. 复用 `LoginService::login()`
  2. 登录失败时沿用现有失败结构
  3. 登录成功但 `is_admin != true` 时返回 403
  4. 登录成功且为管理员时，返回 `AuthService::generateAuthData()` 结果

### 6.2 当前管理员信息接口

新增 `GET /api/v2/{secure_path}/auth/me`：

- 中间件：`user` + `admin`
- 返回字段：
  - `id`
  - `email`
  - `is_admin`
  - `is_staff`
  - `avatar_url`
  - `last_login_at`
- 不返回订阅域字段，不复用前台 `user/info` 的整套模型

### 6.3 当前管理员退出接口

新增 `POST /api/v2/{secure_path}/auth/logout`：

- 中间件：`user` + `admin`
- 处理逻辑：删除当前 Sanctum token
- 成功返回：`success(true)`

## 7. 信息架构与代码边界

### 7.1 路由

新增独立文件：`app/Http/Routes/V2/AdminAuthRoute.php`

原因：

- 认证入口与后台业务 API 生命周期不同
- 便于后续单独审计与迁移
- 避免继续扩张 `AdminRoute.php`

### 7.2 控制器

新增：`app/Http/Controllers/V2/Admin/AuthController.php`

职责：

- 管理员登录
- 当前管理员信息
- 当前管理员会话退出

### 7.3 请求校验

新增：`app/Http/Requests/Admin/AuthLogin.php`

职责：

- 复用现有登录参数规则
- 将后台登录语义从 `Passport` 名称空间中脱钩

## 8. 兼容性要求

- 返回体继续使用现有 `success/fail` 封装
- `login` 成功返回中保留 `auth_data`
- `login` 成功返回中保留 `token`、`is_admin`
- 不影响现有 `AdminRoute.php` 下业务接口
- 不影响现有前台共享认证入口

## 9. 风险与缓解

### 风险 1：后台现有编译产物仍调用旧接口

缓解：
- 本轮只新增后台专属接口，不删除旧接口
- 后续在前端切换完成后再执行下线动作

### 风险 2：无 vendor，无法做完整路由运行验证

缓解：
- 使用 `php -l` 校验新增文件
- 用静态映射校验 route/controller/request 引用关系

### 风险 3：非管理员登录成功后是否会残留 token

缓解：
- 登录成功后先做 `is_admin` 判定
- 如为非管理员，撤销刚创建的 token 或避免对外返回会话结果
- 本阶段实现应保证非管理员无法保留后台可用 token 结果

## 10. 验收标准

满足以下条件才视为本阶段完成：

1. 新增后台专属 `auth/login`、`auth/me`、`auth/logout` 三个接口
2. 接口位于 `/api/v2/{secure_path}/auth/*`
3. `login` 仅允许管理员成功
4. `me` 仅返回后台身份必要字段
5. `logout` 可撤销当前 token
6. 不修改现有共享认证与后台业务接口
7. 相关新增/修改 PHP 文件通过 `php -l`
8. 形成可复用的实现与验证文档

## 11. 实施顺序

1. 补齐 Ralph context / PRD / Test Spec
2. 新增 `AuthLogin` 请求类
3. 新增后台 `AuthController`
4. 新增 `AdminAuthRoute`
5. 静态验证与实现回顾
6. 记录结果并提交
