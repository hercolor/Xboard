# Xboard 用户前端 UI 二开任务清单

> 文档目的：沉淀当前用户前端 UI 二开范围、约束、任务拆分、实施顺序、风险点与验收清单，供后续执行直接使用。

## 1. 背景

当前仓库中的用户前端不是标准可维护源码工程，而是通过 Laravel 模板加载编译后的前端产物：

- Laravel 模板入口：`theme/Xboard/dashboard.blade.php`
- 前端产物入口：`theme/Xboard/assets/umi.js`

基于现有产物反向分析，当前用户端实际壳层特征为：

- Vue 3
- TypeScript
- Pinia
- Vue Router
- Naive UI
- UnoCSS 风格原子类

本次二开只针对用户侧以下区域：

- Dashboard
- Sidebar
- Navbar

## 2. 硬性约束

### 2.1 允许修改

- 页面壳层编排
- 视觉层级
- 排版
- 间距
- 字体层级
- 颜色层级
- 现有组件外观参数
- 现有包裹层 class

### 2.2 禁止修改

- API
- 数据结构
- Route / Route Meta 机制
- Pinia Store 字段与行为
- Permission 逻辑
- 后端逻辑
- 组件通信方式
- 接口请求
- 业务判断条件

### 2.3 风格目标

参考方向：

- Linear
- Raycast
- Stripe Dashboard
- Notion
- iOS Settings

风格关键词：

- 极简
- 小字号
- 高密度
- 大量留白
- 弱阴影
- 几乎不用边框
- 用层次代替卡片
- 灰白基调
- typography 优先
- 动效克制

### 2.4 明确禁止的视觉方向

- 默认 shadcn dashboard 风格
- 大圆角卡片
- 蓝紫渐变
- 默认 Tailwind spacing 观感
- 多余阴影
- 过度使用 card

---

## 3. 当前实际结构边界

### 3.1 Layout 壳层

当前 Dashboard 页面结构可抽象为：

```text
Laravel Blade
└─ umi.js
   └─ /dashboard
      ├─ Layout Shell
      │  ├─ Sidebar
      │  ├─ Header / Navbar
      │  └─ AppMain(router-view)
      └─ Dashboard Page
```

### 3.2 Sidebar 实际组件边界

```text
Sidebar
├─ SideLogo
└─ SideMenu
```

关键依赖：

- app store
- permission store
- current route
- route meta

不可破坏：

- menu options 生成逻辑
- `meta.group`
- `meta.order`
- `meta.activeMenu`
- 外链判断
- mobile collapse 行为

### 3.3 Navbar 实际组件边界

```text
Navbar
├─ MenuCollapse
├─ BreadCrumb
├─ ThemeMode
├─ SwitchLang
├─ FullScreen
└─ UserAvatar
```

关键依赖：

- app store
- route.matched
- user store

不可破坏：

- breadcrumb route 投影逻辑
- dark mode 切换逻辑
- language 切换逻辑
- fullscreen 逻辑
- logout / profile 行为

### 3.4 Dashboard 实际业务区块

当前首页至少包含：

1. 顶部状态提醒区
2. 公告区
3. 我的订阅
4. 快捷入口

不可破坏：

- 状态提示的条件判断
- 订阅状态判断
- 流量阈值判断
- 公告点击行为
- 快捷入口跳转与弹层行为

---

## 4. 实施总策略

本次改造遵循以下原则：

1. 只改表现层，不改逻辑层
2. 优先复用现有组件，不新增新业务抽象
3. 先统一壳层基调，再改 Dashboard 首页
4. 先做结构和层次，再做微交互和细节
5. 所有修改必须兼容移动端与暗色模式

推荐实施顺序：

### Phase 1：壳层定调

1. Sidebar
2. Navbar

### Phase 2：首页结构重组

3. Dashboard 主容器
4. 顶部状态提醒区
5. 我的订阅

### Phase 3：质感完善

6. 快捷入口
7. 公告区
8. Dark Mode / Drawer / Mobile 微调

---

## 5. 任务清单：Sidebar

### 5.1 SideLogo

### 任务项

- 收紧品牌区高度
- 重设 logo / title / close 按钮对齐
- 标题弱品牌化，降低横向侵占
- 优化折叠态视觉，仅保留核心识别

### 不可改动

- `logo`
- `title`
- `collapsed`
- `switchCollapsed()`

### 风险等级

- 低

### 验证项

- 桌面展开/收起正常
- 移动端 close 按钮正常
- 长标题不溢出
- 暗色模式下可读

### 5.2 SideMenu 基础样式

### 任务项

- 压缩菜单项高度
- 收紧左右 padding
- 降低文字字号
- 统一图标尺寸
- 降低默认组件感
- 统一 hover 与 selected 的视觉语言

### 视觉目标

- 更像应用导航
- 更轻
- 更密
- 更克制

### 不可改动

- `options`
- `value`
- `onUpdate:value`
- route meta 解析
- menu route 跳转行为

### 风险等级

- 中

### 验证项

- 一级菜单正常
- 子菜单正常
- 分组菜单正常
- 当前页面高亮准确
- collapse 后 icon 对齐正常

### 5.3 分组层级

### 任务项

- 弱化 group 标题
- 重做组间留白
- 拉开组标题与菜单项的层次
- 清理默认 Naive 菜单组视觉

### 不可改动

- `meta.group.key`
- `meta.group.label`
- 原排序规则

### 风险等级

- 低

### 验证项

- 分组顺序不变
- 多组并存时层次清晰
- collapse 态无异常

### 5.4 Selected / Hover / Collapse 状态

### 任务项

- 将 selected 态从“背景块”改为“轻高亮层次”
- 统一 hover 反馈为轻微层次变化
- 优化 collapsed 态下 active 识别
- 减少高对比色块

### 不可改动

- active route 计算
- `meta.activeMenu`

### 风险等级

- 中

### 验证项

- 展开和折叠状态下高亮都准确
- 页面切换后高亮更新正常
- 移动端点击菜单后仍自动收起

### 5.5 Desktop Sider / Mobile Drawer

### 任务项

- 重设 Sidebar 背景层级
- 减少与主内容区之间的重边框感
- 统一 Drawer 内部 Sidebar 视觉
- 降低 Drawer 阴影存在感

### 不可改动

- Desktop / Mobile 响应式切换逻辑
- Drawer `placement`
- `collapsed-width`
- `show / collapsed` 状态关系

### 风险等级

- 中

### 验证项

- 桌面端正常
- 手机端 Drawer 正常
- Drawer 开关流畅
- 暗色模式不脏不糊

---

## 6. 任务清单：Navbar

### 6.1 Header 容器

### 任务项

- 收紧顶部高度
- 重设计左右 padding
- 降低背景纯白和边框感
- 使用更克制的层次分隔

### 不可改动

- header 高度数据来源
- 子组件行为关系

### 风险等级

- 低

### 验证项

- 所有子组件垂直居中
- 小屏不换行
- 点击热区不被压缩

### 6.2 MenuCollapse

### 任务项

- 统一图标热区
- 减少按钮感
- 弱化 hover
- 优化与 breadcrumb 的间距关系

### 不可改动

- `switchCollapsed()`

### 风险等级

- 低

### 验证项

- 点击正常收起/展开
- Hover 正常
- 桌面与移动端一致

### 6.3 BreadCrumb

### 任务项

- 降低字号
- 前级更淡、当前级更深
- 降低 icon 权重
- 弱化 separator
- 减少默认 breadcrumb 组件感

### 不可改动

- `route.matched`
- `meta.title`
- `meta.icon`
- `meta.customIcon`

### 风险等级

- 低

### 验证项

- 深层页面 breadcrumb 正常
- 中英文长度正常
- 图标文字基线对齐正常

### 6.4 右侧工具组

### 任务项

- 统一工具按钮尺寸
- 统一工具按钮间距
- 统一 hover 反馈
- 图标颜色统一到中灰体系
- 强化“系统工具区”整体感

### 不可改动

- dark mode 切换逻辑
- language 切换逻辑
- fullscreen 逻辑

### 风险等级

- 低

### 验证项

- 所有按钮热区一致
- 暗色模式对比适中
- 不发生误触

### 6.5 UserAvatar

### 任务项

- 改成更轻的 profile trigger
- 弱化 email 文本权重
- 优化头像尺寸与对齐
- 统一 dropdown 触发区节奏

### 不可改动

- 下拉菜单项
- profile 跳转
- logout confirm
- logout 行为

### 风险等级

- 中

### 验证项

- 点击头像正常弹出菜单
- 桌面 email 显示正常
- 移动端 icon 显示正常
- dropdown 定位正常

---

## 7. 任务清单：Dashboard

### 7.1 页面容器 / AppPage 节奏

### 任务项

- 重做首页纵向 spacing 体系
- 从“卡片堆叠”调整为“层次分区”
- 模块间距做节奏化而非平均化
- 弱化大块 card 观感

### 不可改动

- 页面挂载关系
- 数据来源
- 滚动行为

### 风险等级

- 中

### 验证项

- 首屏节奏清晰
- 移动端不拥挤
- 模块关系明确

### 7.2 顶部状态提醒区

当前状态来源：

- 工单处理中
- 未支付订单
- 流量过高

### 任务项

- 将 Alert 风格改为更轻的状态条
- 压缩高度
- CTA 改成更内联的操作形式
- 三类提示建立统一表达风格
- 弱化状态色侵略性

### 不可改动

- 条件判断
- CTA 跳转行为

### 风险等级

- 中

### 验证项

- 三类提示都能正常出现
- 多条并存时不乱
- 长文案不炸布局
- CTA 仍然清晰可点

### 7.3 我的订阅（核心模块）

### 任务项

- 套餐名提升为主信息
- 到期状态 / 重置时间改成副信息层
- 细化流量进度条
- 统一流量信息排版
- 弱化按钮堆叠感
- 从 card 风格改为 info block 风格

### 信息优先级

1. 当前计划名
2. 到期状态
3. 流量状态
4. 用量详情
5. CTA

### 不可改动

- `plan_id` 判断
- `expired_at` 判断
- `next_reset_at` 判断
- 购买/续费/重置逻辑

### 风险等级

- 高

### 验证项

- 无订阅
- 有订阅
- 永久订阅
- 已过期订阅
- 流量高占用
- CTA 文案与跳转都正确

### 7.4 快捷入口

当前至少包含：

- 查看教程
- 一键订阅
- 购买 / 续费订阅
- 遇到问题

### 任务项

- 改成命令式 action list
- 收紧每行高度
- 建立标题与描述两级排版
- 降低右侧 icon 存在感
- 弱化 hover 反馈

### 不可改动

- 每项点击行为
- 二维码/订阅弹层触发逻辑
- plan 跳转逻辑
- ticket 跳转逻辑

### 风险等级

- 中

### 验证项

- 四项点击都正确
- 长文案不破版
- 移动端可触控区域足够

### 7.5 公告区

### 任务项

- 降低营销 banner 感
- 缩短高度
- 降低背景图存在感
- 提升标题与日期排版质量
- 更像 updates / announcements panel

### 视觉实施方向

#### 方向 A：保留轮播视觉，降低噪音

- 适合保守改法

#### 方向 B：偏内容面板化

- 适合更产品化风格

### 不可改动

- 公告数据结构
- 轮播逻辑
- 点击详情行为

### 风险等级

- 中

### 验证项

- 有图公告正常
- 无图 fallback 正常
- 点击查看详情正常
- 移动端不过高

### 7.6 一键订阅弹层（关联验证）

### 仅允许

- 视觉统一
- 字号统一
- 间距与层级统一

### 禁止修改

- 二维码逻辑
- 协议筛选逻辑
- 订阅链接生成逻辑
- 导入行为

### 风险等级

- 中高

### 验证项

- 二维码显示正常
- 协议筛选正常
- 复制订阅链接正常

---

## 8. 统一视觉规范

### 8.1 Typography

### 要求

- 标题克制，不依赖超大字号
- 正文偏小
- 描述文本更淡
- 元信息更小更灰

### 禁止

- 大标题堆砌
- 粗重字重泛滥
- 大量高饱和彩色文字

### 8.2 Color

### 要求

- 主背景：灰白
- 分层背景：微差
- 主文本：深灰
- 次文本：中灰
- 状态色：只辅助提示，不抢主层级

### 禁止

- 蓝紫渐变
- 大面积品牌色
- 高对比 hover

### 8.3 Radius

### 要求

- 小圆角统一
- 容器圆角一致

### 禁止

- 大圆角卡片

### 8.4 Shadow

### 要求

- 极弱阴影或无阴影

### 禁止

- 多层阴影
- 浮夸悬浮卡片感

### 8.5 Spacing

### 要求

- 顶栏更紧
- 列表更紧
- 模块间距有节奏
- 不使用默认后台模板感 spacing

### 禁止

- 平均式大留白
- 默认 Tailwind dashboard 节奏

---

## 9. 风险矩阵

| 区域 | 风险等级 | 说明 |
| --- | --- | --- |
| Sidebar Logo | 低 | 基本是表现层 |
| Sidebar Menu | 中 | 容易误伤 active / collapse 视觉状态 |
| Navbar Header | 低 | 以壳层调整为主 |
| BreadCrumb | 低 | 基本是 route 投影 |
| UserAvatar | 中 | dropdown 触发区较敏感 |
| Dashboard 状态区 | 中 | 条件渲染较多 |
| Dashboard 我的订阅 | 高 | 状态组合最多 |
| Dashboard 快捷入口 | 中 | 交互入口集中 |
| Dashboard 公告区 | 中 | 轮播 / 图片 / fallback 并存 |

---

## 10. 验收清单

### 10.1 响应式验收

- 桌面端 > 950px
- 平板尺寸
- 手机端 <= 950px
- Sidebar / Drawer 切换正确
- Navbar 不换行
- Dashboard 模块不挤压

### 10.2 状态验收

- 正常登录进入首页
- 无订阅状态
- 有订阅状态
- 已过期订阅状态
- 高流量使用状态
- 有待支付订单
- 有处理中工单
- 无公告 / 有公告 / 有图公告

### 10.3 主题验收

- 浅色模式
- 深色模式

重点检查：

- 文本层级
- 分层背景
- 选中态
- 进度条可读性
- 顶栏工具对比度

### 10.4 语言验收

- 中文
- 英文

重点检查：

- Sidebar 文案长度
- Breadcrumb 长度
- Dashboard 描述排版
- Avatar 区长度占位

### 10.5 交互验收

- 菜单跳转正常
- collapse 正常
- drawer 正常
- language switch 正常
- dark mode 正常
- fullscreen 正常
- logout 正常
- Dashboard 内所有 CTA 正常

---

## 11. 建议执行顺序（开发任务单）

### 第一轮：壳层定调

1. Sidebar 背景与节奏
2. Sidebar active / hover / collapse
3. Navbar 高度与 spacing
4. Breadcrumb 与工具组统一

### 第二轮：首页主体

5. Dashboard 页面整体 spacing
6. 顶部状态提醒区
7. 我的订阅模块

### 第三轮：首页细节

8. 快捷入口区
9. 公告区
10. 一键订阅弹层视觉统一

### 第四轮：收尾

11. 暗色模式校正
12. 移动端 Drawer 校正
13. 中英文长度校正
14. 最终响应式回归

---

## 12. 后续使用方式

后续实际开始改造时，建议以本文件为唯一执行清单：

1. 先按“建议执行顺序”推进
2. 每完成一个模块，立即对照“不可改动”检查
3. 每完成一个模块，立即对照“验证项”回归
4. 所有变更统一以“只改表现层”为审查原则

如果后续拿到真正的用户前端源码工程，本清单仍然可直接复用，只需要把“产物反向分析”上下文替换为源码组件路径即可。
