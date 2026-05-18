# Xboard API 路由-控制器一致性审计

> 时间：2026-05-18  
> 目标：在进入第二阶段 API 整理前，先确认路由定义、控制器方法、版本复用关系是否一致，避免后续在“看起来有路由、实际上不可调用”的状态上继续叠加改动。

---

## 1. 审计方法

本次审计采用 **静态扫描 + 人工复核**：

1. 扫描目录：
   - `app/Http/Routes/V1/*.php`
   - `app/Http/Routes/V2/*.php`
2. 逐条提取路由定义中引用的：
   - `Controller::class`
   - `method`
3. 对应检查：
   - 控制器文件是否存在
   - 控制器方法是否存在
4. 人工补充复核：
   - `V2` 是否直接复用 `V1` 控制器
   - 是否存在明显的兼容层残留/死路由/死导入

### 1.1 本次审计边界

由于当前本地环境仍然缺少 `vendor/`：

- 无法执行 `php artisan route:list`
- 无法做完整运行时路由解析

因此本次结论属于：

> **高可信静态审计结论**，足够支持第二阶段整理决策，但还不是最终运行时真相快照。

---

## 2. 审计结论总览

结论很明确：

1. **已确认 4 处“路由存在，但控制器方法不存在”的硬不一致点**
2. **`V2` 仍然直接复用多处 `V1` 控制器**，版本隔离并不完整
3. **`V2 ServerRoute` 存在兼容残留导入**，说明历史迁移未完全收口
4. 当前 API 不是“结构坏掉”，而是：
   - 大多数路由是可用的
   - 但存在少量明显断点
   - 再叠加 V1/V2 复用，后续改造必须先清理映射关系

---

## 3. 已确认的硬不一致点

以下问题都属于：

> 路由文件明确暴露了接口，但控制器里没有对应方法。

这类问题如果被实际调用，结果通常是：

- 直接 500
- 或在路由分发后抛出 `BadMethodCallException`

### 3.1 `V1 User /knowledge/getCategory`

路由：
- `app/Http/Routes/V1/UserRoute.php:77-78`

```php
$router->get('/knowledge/fetch', [KnowledgeController::class, 'fetch']);
$router->get('/knowledge/getCategory', [KnowledgeController::class, 'getCategory']);
```

控制器：
- `app/Http/Controllers/V1/User/KnowledgeController.php`

现状：
- 存在 `fetch()`
- **不存在** `getCategory()`

补充判断：
- 当前 `fetchList()` 已经把文章按 `category` 分组返回
- 因此 `getCategory` 很可能是历史遗留接口，后来功能合并到了 `fetch`

风险级别：**中**

原因：
- 属于用户前台路由
- 但从仓内文本检索看，当前代码里没有直接引用该路径，疑似死路由

---

### 3.2 `V2 Admin /user/setInviteUser`

路由：
- `app/Http/Routes/V2/AdminRoute.php:128-139`
- 关键定义：`app/Http/Routes/V2/AdminRoute.php:137`

```php
$router->post('/setInviteUser', [UserController::class, 'setInviteUser']);
```

控制器：
- `app/Http/Controllers/V2/Admin/UserController.php`

现状：
- 控制器存在：
  - `fetch`
  - `update`
  - `dumpCSV`
  - `generate`
  - `sendMail`
  - `ban`
  - `destroy`
  - `resetSecret`
- **不存在** `setInviteUser()`

补充判断：
- 当前 `update()` 内已经处理了 `invite_user_email -> invite_user_id` 的邀请人写入逻辑
- `setInviteUser` 很可能是旧的拆分接口，后来逻辑并入 `update()`，但路由未清理

风险级别：**中高**

原因：
- 属于后台写操作
- 如果后台页面或脚本还调用它，会直接失败

---

### 3.3 `V2 Admin /stat/getRanking`

路由：
- `app/Http/Routes/V2/AdminRoute.php:145-153`
- 关键定义：`app/Http/Routes/V2/AdminRoute.php:151`

```php
$router->get('/getRanking', [StatController::class, 'getRanking']);
```

控制器：
- `app/Http/Controllers/V2/Admin/StatController.php`

现状：
- 控制器存在：
  - `getOverride`
  - `getStats`
  - `getServerLastRank`
  - `getServerYesterdayRank`
  - `getOrder`
  - `getStatUser`
  - `getStatRecord`
  - `getTrafficRank`
- **不存在** `getRanking()`

补充判断：
- `app/Services/StatisticalService.php:243` 中实际存在 `getRanking($type, $limit = 20)`
- 说明这条路由大概率原本计划由 `StatController` 做一层转发，但控制器适配层没落地

风险级别：**中高**

原因：
- 这是后台统计口
- 不是单纯“死名字”，它背后确实有 service 能力，说明是半截功能

---

### 3.4 `V2 Admin /notice/update`

路由：
- `app/Http/Routes/V2/AdminRoute.php:158-166`
- 关键定义：`app/Http/Routes/V2/AdminRoute.php:162`

```php
$router->post('/update', [NoticeController::class, 'update']);
```

控制器：
- `app/Http/Controllers/V2/Admin/NoticeController.php`

现状：
- 控制器存在：
  - `fetch`
  - `save`
  - `show`
  - `drop`
  - `sort`
- **不存在** `update()`

补充判断：
- 当前 `save()` 已经兼容新增/更新：
  - 无 `id` -> create
  - 有 `id` -> update
- 因此 `notice/update` 非常像旧接口残留

风险级别：**低到中**

原因：
- 这个缺口已在第一阶段整理中被确认
- 更像“可删除历史路由”而不是“缺失业务实现”

---

## 4. V1 / V2 复用关系审计

`V2` 当前并不是完全独立版本，而是仍然直接复用了若干 `V1` 控制器。

### 4.1 已确认的直接复用

#### `V2 PassportRoute` 复用 `V1 Passport`

文件：
- `app/Http/Routes/V2/PassportRoute.php:4-24`

复用：
- `App\Http\Controllers\V1\Passport\AuthController`
- `App\Http\Controllers\V1\Passport\CommController`

含义：
- `V2 /passport/*` 实际并不是一套独立实现
- 修改 `V1 Passport`，会同步影响 `V2 Passport`

---

#### `V2 UserRoute` 复用 `V1 UserController`

文件：
- `app/Http/Routes/V2/UserRoute.php:4-17`

复用：
- `App\Http\Controllers\V1\User\UserController`

含义：
- `V2 /user/resetSecurity`
- `V2 /user/info`

实际上直接落到 `V1 UserController`

---

#### `V2 ServerRoute` 复用 `V1 UniProxyController`

文件：
- `app/Http/Routes/V2/ServerRoute.php:4-26`

复用：
- `App\Http\Controllers\V1\Server\UniProxyController`

被复用的节点接口包括：
- `config`
- `user`
- `push`
- `alive`
- `alivelist`
- `status`

含义：
- `V2 server` 节点协议层并没有完全切干净
- 修改 `V1 server` 控制器会直接影响 `V2 server` 节点通道

风险级别：**高**

原因：
- 这部分属于节点协议
- 比普通后台 CRUD 更敏感

---

## 5. 次级一致性债务

### 5.1 `V2 ServerRoute` 存在未使用的 V1 控制器导入

文件：
- `app/Http/Routes/V2/ServerRoute.php:4-5`

当前导入：

```php
use App\Http\Controllers\V1\Server\ShadowsocksTidalabController;
use App\Http\Controllers\V1\Server\TrojanTidalabController;
```

但在当前路由定义中：
- 没有实际使用这两个控制器

说明：
- 这是典型历史兼容残留
- 不一定导致线上错误，但会误导后续维护者

风险级别：**低**

---

### 5.2 `V1 User /knowledge/getCategory` 与当前控制器设计不再一致

这不是“单纯漏实现”，更像设计迁移后未清理：

- 路由仍保留独立 `getCategory`
- 控制器已经只保留统一 `fetch`
- `fetchList()` 直接输出按分类聚合后的结果

这类问题说明：

> 当前项目里存在“旧路由未删，但真实职责已经并入其他接口”的现象。

这会影响后续 API 规范化，因为你不能仅通过“路由存在”判断接口还在被正式支持。

---

## 6. 修复优先级建议

### P0：先做映射修复决策，不急着写代码

建议先给每个缺口做分类：

1. **应该补实现**
2. **应该删路由**
3. **应该改路由指向已有实现**

当前判断：

- `V1 user/knowledge/getCategory` → **大概率删路由** 或改为兼容转发
- `V2 admin/user/setInviteUser` → **大概率删路由** 或改到 `update` 兼容层
- `V2 admin/stat/getRanking` → **更像应该补实现**
- `V2 admin/notice/update` → **大概率删路由**

---

### P1：先修后台，再碰前台/节点

建议修复顺序：

1. `V2 notice/update`
2. `V2 user/setInviteUser`
3. `V2 stat/getRanking`
4. `V1 user/knowledge/getCategory`
5. 最后才评估 `V2 -> V1` 复用拆分

原因：
- 后台问题更容易验证
- 前台用户口和节点口牵涉范围更大

---

### P2：复用关系不要马上硬拆

`V2 PassportRoute / UserRoute / ServerRoute` 复用 `V1` 控制器这个问题，短期建议：

- **先文档化并标记高风险**
- 不要在没有回归测试的情况下立即拆分

特别是：
- `V2 ServerRoute` 复用 `V1 UniProxyController`

这条线一旦处理不当，可能直接影响节点协议稳定性。

---

## 7. 我对下一步的建议

下一步不要立刻进入更大范围重构，建议继续按下面顺序推进：

### 第一步
做一份 **“缺失路由方法修复决策单”**：

- 哪些删
- 哪些补
- 哪些兼容转发

### 第二步
对 `V2 admin` 先落一轮实际修复：

- `notice/update`
- `user/setInviteUser`
- `stat/getRanking`

### 第三步
再决定是否进入：

- `V1 user/knowledge/getCategory`
- `V2 -> V1` 控制器解耦计划

---

## 8. 本轮审计结论

一句话总结：

> 当前 Xboard 的 API 路由层没有大面积失配，但已经确认存在 4 个明确断点，且 `V2` 对 `V1` 的复用仍然很深。第二阶段应先修映射一致性，再谈更大范围的 API 架构升级。

