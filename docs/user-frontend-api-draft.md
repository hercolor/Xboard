# Xboard 用户前端 API 文档草稿

> 状态：草稿版  
> 范围：基于当前仓库代码反推的 **用户前端相关接口**，优先覆盖 `/api/v1` 下前台会直接使用的接口。  
> 不含：管理后台 `V2 Admin` 的完整接口文档。

---

## 1. 文档来源

本草稿基于以下代码位置整理：

- API 前缀定义：`app/Providers/RouteServiceProvider.php`
- V1 路由：
  - `app/Http/Routes/V1/GuestRoute.php`
  - `app/Http/Routes/V1/PassportRoute.php`
  - `app/Http/Routes/V1/UserRoute.php`
  - `app/Http/Routes/V1/ClientRoute.php`
- 统一响应格式：`app/Helpers/ApiResponse.php`
- 认证返回结构：`app/Services/AuthService.php`
- 部分关键参数校验：
  - `app/Http/Requests/Passport/*.php`
  - `app/Http/Requests/User/*.php`

---

## 2. 基础信息

## 2.1 API 前缀

用户前端接口主前缀为：

```text
/api/v1
```

定义位置：

- `app/Providers/RouteServiceProvider.php`

所以完整接口一般形如：

```text
/api/v1/guest/...
/api/v1/passport/...
/api/v1/user/...
/api/v1/client/...
```

## 2.2 鉴权方式

登录/注册成功后，后端会返回：

- `token`
- `auth_data`
- `is_admin`

其中前端实际用于后续 API 调用的鉴权字段是：

```text
Authorization: Bearer xxxxx
```

`auth_data` 由 `AuthService::generateAuthData()` 生成。

## 2.3 统一响应格式

大部分接口使用统一 JSON 包装：

```json
{
  "status": "success",
  "message": "操作成功",
  "data": {},
  "error": null
}
```

失败时一般为：

```json
{
  "status": "fail",
  "message": "错误信息",
  "data": null,
  "error": null
}
```

说明：

- HTTP 状态码来自 Laravel Response
- `code` 字段在当前实现中被注释掉了，没有直接返回

特殊情况：

- 分页接口可能直接返回：

```json
{
  "total": 100,
  "current_page": 1,
  "per_page": 10,
  "last_page": 10,
  "data": []
}
```

---

## 3. 鉴权与访客接口分组

## 3.1 Guest（未登录可访问）

前缀：

```text
/api/v1/guest
```

| 方法 | 路径 | 说明 | 鉴权 |
|---|---|---|---|
| GET | `/plan/fetch` | 获取公开可购买套餐列表 | 否 |
| POST | `/telegram/webhook` | Telegram webhook 回调 | 否 |
| GET/POST | `/payment/notify/{method}/{uuid}` | 支付回调通知 | 否 |
| GET | `/comm/config` | 获取前台公开配置 | 否 |

### `GET /api/v1/guest/comm/config`

用途：

- 登录/注册页、前台首页初始化配置

已确认返回字段包括：

- `tos_url`
- `is_email_verify`
- `is_invite_force`
- `email_whitelist_suffix`
- `is_captcha`
- `captcha_type`
- `recaptcha_site_key`
- `recaptcha_v3_site_key`
- `recaptcha_v3_score_threshold`
- `turnstile_site_key`
- `app_description`
- `app_url`
- `logo`
- `is_recaptcha`

---

## 3.2 Passport（注册/登录/找回密码）

前缀：

```text
/api/v1/passport
```

| 方法 | 路径 | 说明 | 鉴权 |
|---|---|---|---|
| POST | `/auth/register` | 用户注册 | 否 |
| POST | `/auth/login` | 用户登录 | 否 |
| GET | `/auth/token2Login` | 邮件链接/令牌登录 | 否 |
| POST | `/auth/forget` | 重置密码 | 否 |
| POST | `/auth/getQuickLoginUrl` | 通过已有 token 生成快速登录链接 | 特殊 |
| POST | `/auth/loginWithMailLink` | 发送邮件登录链接 | 否 |
| POST | `/comm/sendEmailVerify` | 发送邮箱验证码 | 否 |
| POST | `/comm/pv` | 邀请码 PV 统计 | 否 |

### `POST /api/v1/passport/auth/login`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `email` | string | 是 | 邮箱 |
| `password` | string | 是 | 密码，最少 8 位 |

成功返回示例：

```json
{
  "status": "success",
  "message": "ok",
  "data": {
    "token": "用户订阅令牌",
    "auth_data": "Bearer xxxxxxxxx",
    "is_admin": false
  },
  "error": null
}
```

### `POST /api/v1/passport/auth/register`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `email` | string | 是 | 邮箱 |
| `password` | string | 是 | 密码，最少 8 位 |

说明：

- 当前 `AuthRegister` 只明确校验了 `email` 与 `password`
- 实际注册逻辑可能还会结合配置要求邀请码、验证码等，需继续结合 `RegisterService` 深挖

### `POST /api/v1/passport/auth/forget`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `email` | string | 是 | 邮箱 |
| `password` | string | 是 | 新密码，最少 8 位 |
| `email_code` | string | 是 | 邮箱验证码 |

### `POST /api/v1/passport/comm/sendEmailVerify`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `email` | string | 是 | 邮箱 |

补充说明：

- 根据站点配置，可能还要求 captcha 参数
- Controller 内会调用 `CaptchaService`

### `POST /api/v1/passport/auth/loginWithMailLink`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `email` | string | 是 | 邮箱 |
| `redirect` | string | 否 | 登录后跳转位置 |

### `GET /api/v1/passport/auth/token2Login`

用途：

- 支持通过邮件令牌直接登录

两种模式：

1. 带 `token`：后端重定向到前端登录页
2. 带 `verify`：返回登录后的认证数据

### `POST /api/v1/passport/auth/getQuickLoginUrl`

说明：

- 需要携带已有 `auth_data`
- 可通过 body `auth_data` 或请求头 `authorization` 提交

---

## 3.3 User（登录后用户接口）

前缀：

```text
/api/v1/user
```

中间件：

```text
user
```

默认需要：

```text
Authorization: Bearer xxxxx
```

### 3.3.1 用户资料与安全

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/resetSecurity` | 重置订阅安全信息（UUID/token） |
| GET | `/info` | 获取用户基础资料 |
| POST | `/changePassword` | 修改密码 |
| POST | `/update` | 更新提醒设置 |
| GET | `/getSubscribe` | 获取订阅信息 |
| GET | `/getStat` | 获取首页统计 |
| GET | `/checkLogin` | 检查登录状态 |
| POST | `/transfer` | 佣金划转余额 |
| POST | `/getQuickLoginUrl` | 获取快速登录链接 |
| GET | `/getActiveSession` | 获取活跃会话 |
| POST | `/removeActiveSession` | 移除指定会话 |

#### `GET /api/v1/user/info`

已确认返回字段包括：

- `email`
- `transfer_enable`
- `last_login_at`
- `created_at`
- `banned`
- `remind_expire`
- `remind_traffic`
- `expired_at`
- `balance`
- `commission_balance`
- `plan_id`
- `discount`
- `commission_rate`
- `telegram_id`
- `uuid`
- `avatar_url`

#### `GET /api/v1/user/getSubscribe`

已确认返回字段包括：

- `plan_id`
- `token`
- `expired_at`
- `u`
- `d`
- `transfer_enable`
- `email`
- `uuid`
- `device_limit`
- `speed_limit`
- `next_reset_at`
- `subscribe_url`
- `reset_day`
- `plan`（当 `plan_id` 存在时）

#### `GET /api/v1/user/getStat`

当前返回是三项数组，分别代表：

1. 待支付订单数量
2. 待处理工单数量
3. 邀请注册用户数

#### `POST /api/v1/user/changePassword`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `old_password` | string | 是 | 旧密码 |
| `new_password` | string | 是 | 新密码，最少 8 位 |

#### `POST /api/v1/user/update`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `remind_expire` | int/string | 否 | 到期提醒，`0/1` |
| `remind_traffic` | int/string | 否 | 流量提醒，`0/1` |

#### `POST /api/v1/user/transfer`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `transfer_amount` | integer | 是 | 划转金额，最小 1 |

#### `POST /api/v1/user/removeActiveSession`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `session_id` | string | 是 | 要移除的会话 ID |

### 3.3.2 订单

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/order/save` | 创建订单 |
| POST | `/order/checkout` | 订单结算 |
| GET | `/order/check` | 检查订单状态 |
| GET | `/order/detail` | 获取订单详情 |
| GET | `/order/fetch` | 获取订单列表 |
| GET | `/order/getPaymentMethod` | 获取支付方式 |
| POST | `/order/cancel` | 取消订单 |

#### `POST /api/v1/user/order/save`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `plan_id` | mixed | 是 | 套餐 ID |
| `period` | string | 是 | 周期 |

`period` 允许值：

- `month_price`
- `quarter_price`
- `half_year_price`
- `year_price`
- `two_year_price`
- `three_year_price`
- `onetime_price`
- `reset_price`

### 3.3.3 套餐

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/plan/fetch` | 获取登录后套餐列表 |

### 3.3.4 邀请

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/invite/save` | 生成邀请码 |
| GET | `/invite/fetch` | 获取邀请码与邀请统计 |
| GET | `/invite/details` | 获取邀请详情 |

### 3.3.5 公告

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/notice/fetch` | 获取公告列表 |

### 3.3.6 工单

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/ticket/reply` | 回复工单 |
| POST | `/ticket/close` | 关闭工单 |
| POST | `/ticket/save` | 创建工单 |
| GET | `/ticket/fetch` | 获取工单列表/详情 |
| POST | `/ticket/withdraw` | 提现申请 |

#### `POST /api/v1/user/ticket/save`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `subject` | string | 是 | 工单主题 |
| `level` | string/int | 是 | 优先级，`0/1/2` |
| `message` | string | 是 | 工单内容 |

#### `POST /api/v1/user/ticket/withdraw`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `withdraw_method` | string | 是 | 提现方式 |
| `withdraw_account` | string | 是 | 提现账户 |

### 3.3.7 节点 / 流量 / 知识库

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/server/fetch` | 获取节点列表 |
| GET | `/knowledge/fetch` | 获取知识库内容 |
| GET | `/knowledge/getCategory` | 获取知识库分类 |
| GET | `/stat/getTrafficLog` | 获取流量日志 |

### 3.3.8 优惠券 / 礼品卡 / Telegram / 通用配置

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/coupon/check` | 校验优惠券 |
| POST | `/gift-card/check` | 校验礼品卡 |
| POST | `/gift-card/redeem` | 兑换礼品卡 |
| GET | `/gift-card/history` | 礼品卡历史 |
| GET | `/gift-card/detail` | 礼品卡详情 |
| GET | `/gift-card/types` | 礼品卡类型 |
| GET | `/telegram/getBotInfo` | 获取 Telegram 机器人信息 |
| GET | `/comm/config` | 获取登录后通用配置 |
| POST | `/comm/getStripePublicKey` | 获取 Stripe 公钥 |

#### `POST /api/v1/user/gift-card/redeem`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `code` | string | 是 | 礼品卡兑换码，8~32 位 |

#### `GET /api/v1/user/comm/config`

已确认返回字段包括：

- `is_telegram`
- `telegram_discuss_link`
- `stripe_pk`
- `withdraw_methods`
- `withdraw_close`
- `currency`
- `currency_symbol`
- `commission_distribution_enable`
- `commission_distribution_l1`
- `commission_distribution_l2`
- `commission_distribution_l3`

#### `POST /api/v1/user/comm/getStripePublicKey`

参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `id` | mixed | 是 | 支付方式 ID |

---

## 3.4 Client（客户端专用接口）

前缀：

```text
/api/v1/client
```

中间件：

```text
client
```

| 方法 | 路径 | 说明 | 鉴权 |
|---|---|---|---|
| GET | `/subscribe` | 旧式客户端订阅入口 | 客户端鉴权 |
| GET | `/app/getConfig` | 客户端配置 | 客户端鉴权 |
| GET | `/app/getVersion` | 客户端版本信息 | 客户端鉴权 |

---

## 4. 特殊非 JSON 订阅入口

除了 `/api/v1/client/subscribe` 之外，还存在一个主题外的订阅地址：

```text
/{subscribe_path}/{token}
```

默认示例：

```text
/s/{token}
```

定义位置：

- `routes/web.php`

用途：

- 直接提供订阅内容给客户端

---

## 5. 前端最常用接口清单（按页面）

## 5.1 登录/注册页

- `GET /api/v1/guest/comm/config`
- `POST /api/v1/passport/comm/sendEmailVerify`
- `POST /api/v1/passport/auth/login`
- `POST /api/v1/passport/auth/register`
- `POST /api/v1/passport/auth/forget`
- `GET /api/v1/passport/auth/token2Login`

## 5.2 Dashboard 首页

- `GET /api/v1/user/info`
- `GET /api/v1/user/getSubscribe`
- `GET /api/v1/user/getStat`
- `GET /api/v1/user/notice/fetch`
- `GET /api/v1/user/comm/config`

## 5.3 套餐与订单

- `GET /api/v1/user/plan/fetch`
- `POST /api/v1/user/order/save`
- `POST /api/v1/user/order/checkout`
- `GET /api/v1/user/order/fetch`
- `GET /api/v1/user/order/detail`
- `POST /api/v1/user/order/cancel`
- `GET /api/v1/user/order/getPaymentMethod`

## 5.4 工单与知识库

- `GET /api/v1/user/ticket/fetch`
- `POST /api/v1/user/ticket/save`
- `POST /api/v1/user/ticket/reply`
- `POST /api/v1/user/ticket/close`
- `GET /api/v1/user/knowledge/fetch`
- `GET /api/v1/user/knowledge/getCategory`

---

## 6. 当前文档缺口

这份草稿目前还缺以下内容：

1. 各接口的完整请求参数说明
2. 各接口的精确响应字段定义
3. 错误码/错误消息约定
4. 分页接口的具体筛选参数
5. `RegisterService` / `OrderController` / `TicketController` 等控制器内部更细规则

---

## 7. 下一步建议

建议按下面顺序继续补全文档：

### Phase A

补全这几个核心接口的参数与响应：

- 登录
- 注册
- 获取用户信息
- 获取订阅信息
- 获取首页统计
- 获取公告
- 创建订单

### Phase B

补全业务模块：

- 工单
- 邀请
- 礼品卡
- 知识库
- 支付

### Phase C

输出可发布版本：

- OpenAPI 草稿
- Postman Collection 草稿

