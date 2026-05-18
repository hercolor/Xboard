# PRD — Xboard 后台前端切换到专属认证接口

## 1. 背景

Xboard 已完成后台专属认证 API 第一阶段，但后台前端编译产物仍调用共享认证接口：`/api/v2/passport/auth/login` 与 `/api/v2/user/info`，且退出登录只做本地清 token。若不完成前端切换，第一阶段新增的后台专属认证接口仍无法真正接管后台登录链路。

## 2. 目标

将后台前端的以下认证调用切换到 `/{secure_path}/auth/*`：

- 登录：`/{secure_path}/auth/login`
- 当前用户：`/{secure_path}/auth/me`
- 退出登录：`/{secure_path}/auth/logout`

并保留现有 token 存储结构与后台其它业务 API 调用方式不变。

## 3. 非目标

- 获取/修改 admin 前端源码工程
- 重写后台路由或 store 结构
- 修改忘记密码、注册、邮箱验证码等共享辅助认证链路
- 删除共享 `passport` / `user` 接口
- 调整 UI、业务页面或后台菜单

## 4. 用户与场景

### 4.1 目标用户
- 后台管理员

### 4.2 关键场景
1. 管理员在后台登录页使用 `secure_path/auth/login` 登录
2. 登录后后台初始化使用 `secure_path/auth/me` 拉取身份信息
3. 管理员点击退出时，先调用 `secure_path/auth/logout` 撤销当前 token，再清本地状态并跳转登录页

## 5. 设计原则

### 5.1 架构原则
- 仅替换认证调用点，不扩散到其它业务请求
- 请求拦截器必须对白名单新增后台登录口豁免，否则无 token 时登录请求会被阻断
- 共享认证链路继续保留，作为兼容层，不在本轮清理

### 5.2 工程原则
- 由于当前仅有 dist 子模块，采用最小字符串级补丁
- 修改点必须可静态定位、可审计、可回滚
- 不同时改动 CSS、locale、其它 worker 文件

## 6. 功能需求

### 6.1 登录请求切换
- 原：`/passport/auth/login`
- 新：`/{secure_path}/auth/login`
- 登录成功后仍保存 `auth_data` 到 `access_token`
- 登录成功后仍 dispatch 当前用户拉取 thunk

### 6.2 当前用户信息请求切换
- 原：`/user/info`
- 新：`/{secure_path}/auth/me`
- 仍由现有 `user/fetchUserInfo` thunk 驱动
- 不修改 store 字段名或 reducer 结构

### 6.3 退出登录接入服务端
- 原：仅本地清理
- 新：先调用 `/{secure_path}/auth/logout`
- 无论接口成功失败，最终都清本地 token / user state 并跳转 `/sign-in`

### 6.4 请求白名单补强
- 登录请求在无 token 场景下必须可通过 axios request interceptor
- 其余共享白名单维持原状

## 7. 交付物
- `public/assets/admin/assets/index-BdbgNvrf.js` 最小补丁
- 更新后的 `docs/admin-only-auth-task-list.md`
- Ralph context / PRD / Test Spec 工件

## 8. 风险与缓解

### 风险 1：dist patch 易误伤其它逻辑
缓解：只替换 4 个静态可定位认证触点，变更前后做字符串级 diff 审计

### 风险 2：子模块提交与主仓库提交不同步
缓解：在子模块内单独提交后，回到主仓库更新 submodule pointer 并提交

### 风险 3：无运行环境回归
缓解：使用静态调用点检查、git diff、架构复核进行补强验证

## 9. 验收标准
1. 登录请求已切到 `/{secure_path}/auth/login`
2. 当前用户请求已切到 `/{secure_path}/auth/me`
3. logout 已接入 `/{secure_path}/auth/logout`
4. 登录请求在无 token 场景下不会被 request interceptor 拦截
5. 除认证触点外，不新增其它后台 API 改动
6. 子模块与主仓库均完成本地提交

## 10. 实施顺序
1. 补齐 Ralph 工件
2. patch request interceptor 白名单逻辑
3. patch login / me / logout 三个调用点
4. 静态验证与架构复核
5. 子模块提交 + 主仓库提交
