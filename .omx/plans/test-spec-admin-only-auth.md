# Test Spec — Xboard 仅后台模式第一阶段（后台专属认证 API）

## 1. 测试目标

确认后台专属认证 API 已建立正确边界，并在不破坏当前共享认证链路的前提下完成最小可用实现。

## 2. 范围

### 2.1 在范围内

- `POST /api/v2/{secure_path}/auth/login`
- `GET /api/v2/{secure_path}/auth/me`
- `POST /api/v2/{secure_path}/auth/logout`
- 新增 route / controller / request 的静态一致性

### 2.2 不在范围内

- 会员前台入口下线
- 后台前端编译产物切换到新接口
- 共享 `passport` / `user` 路由删除
- 完整 Laravel 启动验证与功能测试

## 3. 验证分层

## 3.1 结构验证

检查点：

- `app/Http/Routes/V2/AdminAuthRoute.php` 存在
- `app/Http/Controllers/V2/Admin/AuthController.php` 存在
- `app/Http/Requests/Admin/AuthLogin.php` 存在
- V2 路由自动加载机制能够覆盖新增 route 文件

## 3.2 登录接口验证

静态检查点：

- 路径位于 `/{secure_path}/auth/login`
- 使用独立 `AuthLogin` 请求类
- 复用了 `LoginService::login()`
- 登录失败时返回现有失败结构
- 登录成功后存在 `is_admin` 判定
- 非管理员分支返回 403
- 管理员分支返回 `AuthService::generateAuthData()`

## 3.3 me 接口验证

静态检查点：

- 路径位于 `/{secure_path}/auth/me`
- 挂载 `user` + `admin` 中间件
- 返回字段最小集包含：
  - `id`
  - `email`
  - `is_admin`
  - `is_staff`
  - `avatar_url`
  - `last_login_at`
- 不直接复用前台 `user/info` 返回模型

## 3.4 logout 接口验证

静态检查点：

- 路径位于 `/{secure_path}/auth/logout`
- 挂载 `user` + `admin` 中间件
- 删除的是当前 access token，而不是全部 token
- 成功返回 `success(true)`

## 3.5 回归保护验证

检查点：

- 未修改 `app/Http/Routes/V2/PassportRoute.php`
- 未修改 `app/Http/Routes/V2/UserRoute.php`
- 未修改 `app/Http/Middleware/Admin.php`
- 未修改 `app/Http/Middleware/User.php`
- 未修改 `app/Services/Auth/LoginService.php`
- 未修改 `app/Services/AuthService.php`（除非实现必须，否则应保持原状）

## 4. 可执行验证命令

由于当前环境缺少 `vendor/`，采用以下验证方式：

1. 对新增/修改 PHP 文件执行 `php -l`
2. 用 `grep` / `sed` 做静态路由-控制器-请求类映射检查
3. 用 `git diff --stat` 确认改动面受控

## 5. 完成判定

满足以下全部条件才可进入 Ralph 完成判定：

1. Ralph context / PRD / Test Spec 已落盘
2. 三个后台专属认证接口已实现
3. 共享认证链路仍保留且未被破坏
4. 新增/修改文件通过 `php -l`
5. 静态审计能证明路由、控制器、请求类、响应结构与权限边界一致
