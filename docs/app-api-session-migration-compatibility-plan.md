# App API Session Migration Compatibility Plan

> Date: 2026-05-20  
> Scope: migration-prep only for `GET /api/app/v1/session`.  
> Status: planning artifact; no client code changes, no legacy API changes, no dashboard implementation, no AES wrapping.

## 1. Decision

Use `/api/app/v1/session` as the first optional App BFF migration target before any `/api/app/v1/dashboard` work.

Reasoning:

1. DK_Theme currently hydrates authenticated state with `GET /api/v1/user/info` plus `GET /api/v1/user/getSubscribe`; the safe overlap is user/session/subscription-summary metadata, not subscription delivery.
2. hiddify-app still requires login, subscription metadata, and raw subscription content. `/api/app/v1/session` may be useful only as an optional metadata read, not as a replacement for subscription import.
3. The existing `AppSessionReadModel` is allowlist-only and intentionally excludes `token`, `subscribe_url`, `uuid`, and `auth_data`.

## 2. Hard guardrails

Do not change these during the session migration prep:

- Legacy `/api/v1/*` and `/api/v2/*` response shapes.
- `/api/v1/user/getSubscribe` delivery fields such as `subscribe_url` and `token`.
- Raw subscription routes: `/s/{token}` and `/api/v1/client/subscribe?token=...`.
- DK_Theme or hiddify-app source code.
- `/api/app/v1/dashboard`; it stays absent and `bootstrap.capabilities.dashboard = false`.
- AES response encryption or global response wrapping.

## 3. Current `/api/app/v1/session` contract

Envelope:

```json
{
  "ok": true,
  "code": "OK",
  "message": "ok",
  "data": {
    "user": {},
    "subscription": {},
    "traffic": {},
    "preferences": {}
  },
  "meta": {
    "trace_id": "...",
    "server_time": 0
  }
}
```

Implemented data sections:

| Section | Keys | Notes |
| --- | --- | --- |
| `user` | `id`, `email`, `avatar_url`, `is_admin`, `is_staff`, `banned`, `created_at`, `last_login_at`, `telegram_bound` | Safe identity/session fields. |
| `subscription` | `status`, `active`, `plan_id`, `expired_at`, `next_reset_at`, `device_limit`, `speed_limit`, `delivery_available` | Summary only; no delivery URL/token. |
| `traffic` | `upload`, `download`, `used`, `total`, `remaining`, `usage_percent` | Derived from user traffic counters. |
| `preferences` | `remind_expire`, `remind_traffic` | Boolean preferences. |

Forbidden keys remain absent from this endpoint:

- `token`
- `subscribe_url`
- `uuid`
- `auth_data`
- raw node/server credentials
- payment, ticket messages, knowledge bodies, invite mutation outputs

## 4. DK_Theme compatibility mapping

DK_Theme source root inspected: `/home/seven/works/new-api/DK_Theme`.

Current hydrate flow:

- `src/features/auth/auth-context.tsx` calls `Promise.all([getUserInfo(), getSubscribeInfo()])`.
- `getUserInfo()` calls `GET /api/v1/user/info`.
- `getSubscribeInfo()` calls `GET /api/v1/user/getSubscribe`.

### 4.1 `UserInfo` mapping

| DK_Theme `UserInfo` key | Current source | Existing App session source | Migration status |
| --- | --- | --- | --- |
| `email` | `/api/v1/user/info.email` | `data.user.email` | Safe direct map. |
| `avatar_url` | `/api/v1/user/info.avatar_url` | `data.user.avatar_url` | Safe direct map. |
| `expired_at` | `/api/v1/user/info.expired_at` | `data.subscription.expired_at` | Safe cross-section map. |
| `transfer_enable` | `/api/v1/user/info.transfer_enable` | `data.traffic.total` | Safe alias if adapter maps name. |
| `d` | `/api/v1/user/info.d` is not selected by legacy `info`; DK_Theme type allows it | `data.traffic.download` | Optional alias only. |
| `remind_expire` | `/api/v1/user/info.remind_expire` | `data.preferences.remind_expire` | Safe cross-section map; type changes number/boolean to boolean. |
| `remind_traffic` | `/api/v1/user/info.remind_traffic` | `data.preferences.remind_traffic` | Safe cross-section map; type changes number/boolean to boolean. |
| `plan` | DK_Theme type allows string/null; legacy `info` returns `plan_id` not plan name | not present | Do not invent. Keep legacy fallback if UI needs display name. |
| `balance` | `/api/v1/user/info.balance` | not present | Optional future field; safe but money-sensitive display field, requires explicit approval. |
| `commission_balance` | `/api/v1/user/info.commission_balance` | not present | Optional future field; safe but money-sensitive display field, requires explicit approval. |

Minimum adapter shape for a DK_Theme experiment can be produced without backend changes for non-money fields:

```ts
type AppSessionResponse = {
  ok: true
  data: {
    user: { email: string; avatar_url?: string | null }
    subscription: { expired_at?: number | null }
    traffic: { total: number; download: number }
    preferences: { remind_expire: boolean; remind_traffic: boolean }
  }
}
```

If DK_Theme screens require `balance` or `commission_balance` during auth hydrate, do not fake defaults silently. Either keep `/api/v1/user/info` fallback or approve a small session-field extension with tests.

### 4.2 `SubscribeInfo` mapping

| DK_Theme `SubscribeInfo` key | Current source | Existing App session source | Migration status |
| --- | --- | --- | --- |
| `subscribe_url` | `/api/v1/user/getSubscribe.subscribe_url` | forbidden | Must stay on legacy delivery endpoint or a separately approved delivery endpoint. |
| `token` | `/api/v1/user/getSubscribe.token` | forbidden | Must not be exposed by `/api/app/v1/session`. |
| `transfer_enable` | `/api/v1/user/getSubscribe.transfer_enable` | `data.traffic.total` | Summary-only alias possible. |
| `d` | `/api/v1/user/getSubscribe.d` | `data.traffic.download` | Summary-only alias possible. |
| `expired_at` | `/api/v1/user/getSubscribe.expired_at` | `data.subscription.expired_at` | Safe alias. |
| `plan` | `/api/v1/user/getSubscribe.plan.name` or string | not present | Optional future `subscription.plan_name`; requires explicit approval and plan query budget. |

Conclusion for DK_Theme:

- `/api/app/v1/session` can reduce or replace the non-secret portion of auth hydration.
- It cannot replace `getSubscribeInfo()` wherever `subscribe_url` or `token` is needed, especially `src/pages/clients-page.tsx`.
- First DK_Theme migration should be feature-flagged and adapter-based, with legacy fallback retained.

## 5. hiddify-app compatibility mapping

hiddify-app source root inspected: `/home/seven/works/hiddify-app`.

Current critical path:

1. `POST /api/v1/passport/auth/login` for `auth_data`.
2. `GET /api/v1/user/getSubscribe` for subscription metadata and delivery URL.
3. Raw subscription download from parsed `subscribeUrl` for node import.
4. Optional customer-service fallback from guest/passport config.

### 5.1 Parser overlap

`XBoardResponseParser.parseSubscription()` already accepts both snake_case and camelCase keys for several fields:

| hiddify model key | Current parser keys | Existing App session source | Can session replace current source? |
| --- | --- | --- | --- |
| `subscribeUrl` | `subscribe_url`, `subscribeUrl`, `subscription_url`, `url`, etc. | forbidden | No. Required field for parser. |
| `expiredAt` | `expired_at`, `expiredAt`, `expire`, etc. | `data.subscription.expired_at` | Only as secondary metadata, not full parser input. |
| `upload` | `u`, `upload`, `uploaded` | `data.traffic.upload` | Alias-compatible. |
| `download` | `d`, `download`, `downloaded` | `data.traffic.download` | Alias-compatible. |
| `transferEnable` | `transfer_enable`, `transferEnable`, `transfer`, `total`, `traffic` | `data.traffic.total` | Alias-compatible. |
| `maxDevices` | `device_limit`, `deviceLimit`, etc. | `data.subscription.device_limit` | Alias-compatible. |
| `planName` | plan-name aliases or nested `plan.name` | not present | Optional future field only. |
| `customerService` | customer-service aliases | not present | Keep existing guest/passport config fallback. |

Conclusion for hiddify-app:

- Do not point hiddify-app subscription import at `/api/app/v1/session`.
- A future optional use can read `/api/app/v1/session` after login for safer UI metadata, but raw subscription download must continue using the legacy subscription URL.
- If hiddify-app adopts App session later, it should not remove `GET /api/v1/user/getSubscribe` until a dedicated subscription-metadata/delivery contract is separately approved.

## 6. Client fallback strategy

### 6.1 DK_Theme feature-flag flow

Proposed flag name from existing planning doc: `VITE_ENABLE_APP_BFF=true`.

Recommended hydrate sequence when the flag is enabled:

1. With stored bearer token, call `GET /api/app/v1/bootstrap` once during app startup or build a static capability assumption.
2. If `capabilities.session === true`, call `GET /api/app/v1/session`.
3. Convert App envelope to DK_Theme-local `UserInfo` and optional non-secret `SubscribeInfo` summary.
4. Continue calling legacy `GET /api/v1/user/getSubscribe` when a screen needs `subscribe_url`, `token`, client QR code, import schemes, or subscription reset state.
5. If App session returns 401/403/404/5xx or envelope `ok !== true`, fall back to the existing `Promise.all([getUserInfo(), getSubscribeInfo()])` path.
6. Never persist App session response as auth credential; token storage remains unchanged.

Rollback switch:

- Set `VITE_ENABLE_APP_BFF=false` to return to current legacy calls without backend change.

### 6.2 hiddify-app optional flow

Recommended only if a later app release wants non-secret session metadata:

1. Keep login endpoint unchanged.
2. Keep `GET /api/v1/user/getSubscribe` unchanged for `subscribeUrl` and subscription metadata.
3. Keep raw subscription download unchanged.
4. Optionally call `/api/app/v1/session` after login/subscription success for safe metadata display.
5. If App session fails, ignore it and continue with legacy auth/subscription state.

Rollback switch:

- App config disables App BFF metadata call; no server rollback required.

## 7. Optional backend field extensions requiring approval

These fields are not implemented in this slice. They may be considered later if client mapping proves they are required:

| Candidate field | Section | Source | Risk | Approval/test needed |
| --- | --- | --- | --- | --- |
| `balance` | `user` or `account` | `users.balance` | money display, precision expectation | explicit field approval; fixture assertion; no mutation. |
| `commission_balance` | `user` or `account` | `users.commission_balance` | money display, privacy/screening | explicit field approval; fixture assertion. |
| `plan_name` | `subscription` | `plans.name` | extra query or eager-load budget | query budget test; missing-plan fallback. |
| `reset_day` | `subscription` | `UserService::getResetDay()` | service behavior coupling | unit/feature fixture; verify no token leak. |
| `customer_service` | separate `support` section | system config | public/support data shape | decide whether app should keep guest config fallback instead. |

Do not add `subscribe_url`, `token`, `uuid`, or `auth_data` to `/api/app/v1/session`.

## 8. Contract tests before any client migration

Before changing DK_Theme or hiddify-app, add/keep backend tests that prove:

- `/api/app/v1/session` keeps the App envelope and authenticated middleware.
- Secret needles remain absent from the response body.
- `bootstrap.capabilities.session === true` and `dashboard === false`.
- Legacy `/api/v1/user/info` and `/api/v1/user/getSubscribe` still return their legacy envelopes.
- `/api/v1/client/subscribe?token=...` and `/s/{token}` stay outside the App envelope.
- Any optional field extension has explicit allowlist assertions and does not increase query count beyond the App read budget.

## 9. Backend contract test status

Completed on 2026-05-20:

- App session route and read-model tests assert the current allowlist sections and secret leak blockers.
- Legacy `GET /api/v1/user/info` and `GET /api/v1/user/getSubscribe` remain mounted outside the App envelope for client fallback.
- Legacy controller source fragments for DK_Theme fallback fields and subscription delivery fields are pinned without requiring a DB driver in this local test environment.

Verification command:

```bash
./vendor/bin/phpunit tests/Feature/AppApi tests/Feature/AdminOnlyShellContractTest.php tests/Feature/ApiSecurityPilotTest.php
```

## 10. Next recommended task

If proceeding with DK_Theme migration planning:

```text
$ralplan "设计 DK_Theme VITE_ENABLE_APP_BFF 的 session adapter 和 fallback 测试方案；不改代码，先确定 UserInfo/SubscribeInfo 映射缺口。"
```

If proceeding with backend implementation later, first approve any optional session fields from section 7; otherwise keep `/api/app/v1/session` unchanged.
