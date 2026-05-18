# Test Spec — Xboard Phase 3 仅后台模式 Web 入口收口

## 1. 测试目标

确认后端项目已停止提供用户主题前台壳层，但继续保留后台入口、API 和订阅通道。

## 2. 范围

### 2.1 在范围内
- `routes/web.php` 根路径 `/`
- `/{secure_path}` 后台入口
- `/s/{token}` 订阅分发路由
- Phase 3 任务清单状态

### 2.2 不在范围内
- `/api/*` 业务逻辑
- 后台前端 bundle
- Phase 4 API 退役

## 3. 静态验证点

### 3.1 根路径
- `/` 不再调用 `ThemeService`
- `/` 不再渲染 `theme::*.dashboard`
- `/` 改为跳转 `/{secure_path}`

### 3.2 后台入口
- `/{secure_path}` 路由仍存在
- `resources/views/admin.blade.php` 继续作为后台壳层

### 3.3 订阅通道
- `/{subscribe_path}/{token}` 路由仍存在

### 3.4 改动面控制
- 仅 `routes/web.php` 与文档发生必要改动
- 不应出现 API 路由文件改动

## 4. 可执行验证命令
- `php -l routes/web.php`
- `grep` / `sed` 检查 `/` 路由不再引用 `ThemeService`
- `git diff --stat` 检查改动面

## 5. 完成判定
1. 根路径已收口到后台
2. API 与订阅通道未改动
3. 静态验证通过
4. 架构复核通过
