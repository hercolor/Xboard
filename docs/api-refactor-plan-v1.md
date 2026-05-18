# Xboard API 整理实施方案 v1

> 角色：架构师  
> 目标：在**不破坏现有前台、节点、订阅链路**的前提下，先完成一轮低风险、可验证、可回滚的 API 规范化整理。  
> 策略：**先后台、先低风险模块、先统一规范、后逐步扩展**。

---

## 1. 本方案解决什么问题

基于前面三份审计文档：

- `docs/api-architecture-overview.md`
- `docs/api-interface-audit-prep.md`
- `docs/api-interface-matrix.md`

当前 API 的核心问题不是“缺接口”，而是：

1. 响应结构不完全统一
2. Request 校验风格不一致
3. Controller 责任偏重
4. 存在 `V1/V2` 复用污染
5. 存在副作用 GET、ANY、流式输出混用
6. 节点/订阅/后台业务接口处于同一工程，但不应同一节奏治理

所以 v1 的目标不是重写 API，而是：

> 建立**第一套可执行治理标准**，并在一批低风险后台模块中落地。

---

## 2. v1 总体目标

### 2.1 目标范围

只处理 **Admin API 首批白名单模块**：

1. `V2 plan`
2. `V2 notice`
3. `V2 knowledge`
4. `V2 coupon`
5. `V2 mail/template`

### 2.2 本轮明确不处理

以下模块 **不进入 v1 实施**：

- `V1 passport`
- `V1 user`
- `V2 user`
- `V1/V2 client`
- `V1/V2 server`
- `plugin`
- `traffic-reset`
- `gift-card`
- `system`
- `payment`

### 2.3 目标结果

v1 完成后，首批模块应达到：

1. **统一响应出口**
2. **统一 Request 校验入口**
3. **统一 Controller 职责边界**
4. **统一错误处理风格**
5. **统一命名与方法语义**
6. **不破坏旧前端行为**

---

## 3. v1 设计原则

### 原则 1：只做非破坏性整理

本轮不允许直接做这些事：

- 改字段名并删除旧字段
- 修改现有路由路径
- 取消旧行为却不给兼容层
- 改动鉴权模型
- 改动节点/订阅协议

### 原则 2：先规范，再抽象

先收敛：

- Request
- Response
- Error
- Controller 结构

之后再考虑：

- DTO
- Application Service
- 更深层目录重组

### 原则 3：先局部试点，再横向推广

先在 5 个后台模块落地，再决定是否推广到：

- `config`
- `order`
- `payment`
- `theme`
- `system`

### 原则 4：兼容优先于完美

如果“理想设计”和“现有调用兼容”冲突：

> v1 优先保兼容。

---

## 4. 首批模块选型原因

## 4.1 `plan`

优点：

- CRUD 边界清晰
- 已较多使用 `success/fail`
- 已有 `FormRequest`

适合作为：

- 返回规范试点
- 校验规范试点

## 4.2 `notice`

优点：

- 后台内部模块
- 风险中等
- 对前台只有间接影响

适合作为：

- 标准 CRUD 控制器收敛模板

## 4.3 `knowledge`

优点：

- 结构清晰
- 已有 Request 体系
- 读写逻辑相对可控

适合作为：

- 查询 + CRUD 混合模块试点

## 4.4 `coupon`

优点：

- 独立业务域
- 使用路径集中
- 不直接影响节点链路

适合作为：

- 生成/展示/启停/删除类接口规范化试点

## 4.5 `mail/template`

优点：

- 典型后台专用配置型模块
- 可验证性较高

适合作为：

- 错误处理统一
- 管理端只读/保存接口规范化试点

---

## 5. v1 统一规范

## 5.1 响应规范

### 标准 JSON 响应

继续沿用当前主规范：

```json
{
  "status": "success",
  "message": "ok",
  "data": {},
  "error": null
}
```

### 失败响应

```json
{
  "status": "fail",
  "message": "错误信息",
  "data": null,
  "error": null
}
```

### v1 规则

首批模块中：

1. 所有普通 JSON 接口必须统一走：
   - `$this->success(...)`
   - `$this->fail(...)`
2. 不允许新增裸 `response()->json(...)`
3. 不允许同一 Controller 混用两套 JSON envelope

### 分页接口规则

分页暂时保留现有结构：

```json
{
  "total": 0,
  "current_page": 1,
  "per_page": 10,
  "last_page": 1,
  "data": []
}
```

原因：

- 当前前端已经依赖
- v1 不做破坏性分页模型切换

---

## 5.2 Request 规范

### 统一要求

首批模块里：

1. 写操作必须优先进入 `FormRequest`
2. 复杂列表筛选可保留在 Controller，但基础参数必须有显式校验
3. Controller 内部临时 `$request->validate(...)` 只允许用于低复杂度读接口

### 建议分类

#### 写操作

- `SaveRequest`
- `UpdateRequest`
- `SortRequest`
- `ToggleRequest`
- `DeleteRequest`

#### 读操作

- 列表查询可保留轻量校验
- 单对象获取必须校验主键/标识

### 验证错误处理

保持 Laravel 当前机制，不在 v1 自定义新的 validation envelope。

---

## 5.3 Controller 规范

### Controller 只做 4 件事

1. 接收请求
2. 调用 Request / 基础校验
3. 调用 Service / Domain 操作
4. 返回 `success/fail`

### Controller 不再继续承担

1. 复杂事务拼装
2. 过多业务规则判断
3. 过多字段格式转换
4. 过多条件分支堆积

### 推荐模式

```php
public function update(UpdateRequest $request)
{
    $result = $this->service->update(...);
    return $this->success($result);
}
```

### v1 的现实做法

由于当前工程还没有完整 application 层，本轮允许：

- Controller 保留少量简单编排
- 但新增复杂逻辑必须下沉到 Service

---

## 5.4 Service 规范

### 首批模块中的服务策略

本轮不强制每个模块新建独立 Service 文件，但遵守：

1. 复用已有 Service 优先
2. 控制器中新增复杂业务必须抽出 Service 方法
3. 如果模块已明显具备独立业务域，允许新增专用 Service

### 触发新增 Service 的条件

满足任一条件就建议抽出：

1. 单个方法超过 40~60 行纯业务逻辑
2. 同类逻辑在多个 Controller 重复
3. 逻辑里有事务、事件、Hook、模型联动
4. 逻辑有明显“领域规则”特征

---

## 5.5 错误处理规范

### 继续沿用两种主路径

1. 业务失败：
   - `return $this->fail([...])`
2. 需要中断的场景：
   - `throw new ApiException(...)`

### v1 要求

首批模块中：

1. 同一模块尽量减少混用风格
2. 能局部返回失败的，用 `fail`
3. 能明显定义为异常流程的，用 `ApiException`
4. 不新增更多自定义错误出口分支

---

## 6. 路由治理策略

### 6.1 v1 不改现有路径

本轮不修改已有路径：

- 不换 prefix
- 不改 secure_path 结构
- 不重命名老接口

### 6.2 v1 不处理副作用 GET

虽然当前是结构问题，但本轮只做：

- 建立问题清单
- 不直接切 POST

原因：

- 改 method 是破坏性变更
- 要做兼容层，超出 v1 范围

### 6.3 v1 不处理 ANY

`ANY` 先不拆，记录为后续 Phase 2 任务。

---

## 7. 首批 5 个模块的实施要求

## 7.1 Plan 模块

### 目标

- 确保所有 JSON 统一使用 `success/fail`
- 补全 Request 一致性
- 控制器不新增复杂逻辑

### 重点检查

- `fetch`
- `save`
- `drop`
- `update`
- `sort`

### 验收

- 无裸 `response()->json`
- 失败都能稳定返回统一 envelope

---

## 7.2 Notice 模块

### 目标

- 统一写接口的校验和返回结构
- 明确 show / sort / drop 的错误出口

### 验收

- 写操作统一 Request/Fail 结构
- 没有风格漂移

---

## 7.3 Knowledge 模块

### 目标

- 列表和分类读取保持只读稳定
- 保存/展示/删除/排序规范化

### 验收

- Request 和 Controller 职责明确
- `fetch/getCategory/save/show/drop/sort` 风格一致

---

## 7.4 Coupon 模块

### 目标

- 生成、更新、启停、删除路径规范化
- 统一失败场景表达

### 验收

- 生成/删除/更新等接口都走统一 envelope
- Request 使用一致

---

## 7.5 Mail Template 模块

### 目标

- 查询/读取/保存/重置/测试发送风格一致
- 错误信息格式统一

### 验收

- `list/get/save/reset/test` 返回行为一致

---

## 8. 分阶段实施顺序

## Phase 0：冻结基线

输出基线文档，已完成：

- API 架构总览
- 审计文档
- 接口矩阵

## Phase 1：规范落地（首批 5 模块）

顺序建议：

1. `plan`
2. `notice`
3. `knowledge`
4. `coupon`
5. `mail/template`

原因：

- 从最标准模块向稍复杂模块推进

## Phase 2：扩展治理

扩展到：

- `config`
- `order`
- `payment`
- `theme`
- `system`

## Phase 3：高风险兼容治理

后续才进入：

- `V1 user`
- `V1 passport`
- `V2 user`
- `ANY` 路由拆分
- 副作用 GET 改造

## Phase 4：协议级通道治理

- `server/*`
- `client/*`
- `/s/{token}`

---

## 9. 兼容策略

### 9.1 不删字段

v1 不删除旧字段。

### 9.2 不改路径

v1 不改路径。

### 9.3 不改方法

v1 不改 GET/POST/ANY。

### 9.4 不改前端依赖分页格式

保持现有分页结构。

### 9.5 不改认证 token 结构

包括：

- Bearer token
- 订阅 token
- server/machine token

---

## 10. 验收标准

v1 完成时，应满足：

### 架构层

1. 首批模块全部进入统一治理规范
2. 文档与代码行为一致

### 代码层

1. 首批模块不新增裸 JSON 风格
2. 写操作校验入口清晰
3. Controller 复杂度下降或不再继续恶化

### 兼容层

1. 现有前端调用不报错
2. 现有后台调用不报错
3. 不影响节点、订阅、登录链路

### 验证层

1. 路由可用
2. 典型成功/失败响应一致
3. 关键写操作回归通过

---

## 11. 实施时的代码规则

后续实际改代码时，统一遵守：

1. 一次只整理一个模块
2. 每个模块单独提交
3. 先补最小验证，再改代码
4. 不在同一提交里混入无关格式化
5. 不顺手改高风险模块

---

## 12. v1 完成后的下一步

v1 结束后，立刻做一次复盘，判断是否进入：

### 路线 A：继续后台中风险模块

- `config`
- `order`
- `payment`

### 路线 B：开始版本解耦

- 处理 `V2 -> V1` 复用

### 路线 C：开始副作用 GET 兼容迁移

- 新增 POST
- 保留 GET
- 逐步弃用

---

## 13. 当前建议的直接执行顺序

从下一步起，建议按下面顺序真正进入代码整理：

1. **先做 `V2 PlanController` 规范化**
2. 再做 `V2 NoticeController`
3. 再做 `V2 KnowledgeController`
4. 再做 `V2 CouponController`
5. 最后做 `V2 MailTemplateController`

这是当前最稳的 API 整理起点。

