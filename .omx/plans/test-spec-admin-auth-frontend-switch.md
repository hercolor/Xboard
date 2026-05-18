# Test Spec — Xboard 后台前端切换到专属认证接口

## 1. 测试目标

确认后台前端已从共享认证接口切换到后台专属认证接口，且改动面仅限认证触点。

## 2. 范围

### 2.1 在范围内
- 请求拦截器白名单
- 登录调用点
- 当前用户信息调用点
- logout 调用点
- `docs/admin-only-auth-task-list.md` 阶段状态更新

### 2.2 不在范围内
- 后台 UI 改造
- 其它后台业务 API
- 共享认证链路删除
- 完整浏览器运行验证

## 3. 静态验证点

### 3.1 白名单
- 无 token 时 `/{secure_path}/auth/login` 可通过 request interceptor
- 原有共享白名单条目仍保留

### 3.2 登录
- `RL("/passport/auth/login", ...)` 已不存在
- 新调用点使用 `/{secure_path}/auth/login`

### 3.3 当前用户
- `IL("/user/info")` 已不存在
- 新调用点使用 `/{secure_path}/auth/me`

### 3.4 Logout
- 原本地 logout 逻辑前增加 `/{secure_path}/auth/logout` 调用
- 最终仍会清 token、reset user state、跳转 `/sign-in`

### 3.5 改动面
- 仅 `public/assets/admin/assets/index-BdbgNvrf.js` 发生认证调用切换
- 不应波及 CSS、locale、worker、manifest

## 4. 可执行验证命令
- `grep`/`python3` 检查关键字符串是否消失/出现
- `git diff --stat` 检查改动面
- 架构复核确认边界

## 5. 完成判定
1. 4 个认证触点全部切换完成
2. 其余业务 API 保持不变
3. 子模块可形成最小提交
4. 主仓库可形成 submodule pointer + 文档更新提交
