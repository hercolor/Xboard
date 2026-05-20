# App API Dashboard Client Waterfall Audit

> Date: 2026-05-20  
> Scope: read-only audit of DK_Theme and hiddify-app API call surfaces before deciding whether to implement `GET /api/app/v1/dashboard`.  
> Decision: do not implement dashboard yet. Keep `/api/app/v1/dashboard` absent and `bootstrap.capabilities.dashboard = false` until the field/budget/feature-flag plan is explicitly approved.

## 1. Guardrails

This is a planning and evidence artifact only.

Allowed in this slice:

- Inspect DK_Theme and hiddify-app source for current backend calls.
- Record endpoint waterfalls and candidate aggregation value.
- Update Xboard planning docs.

Forbidden in this slice:

- No `/api/app/v1/dashboard` route, controller, resource, service, or client usage.
- No legacy `/api/v1/*` or `/api/v2/*` response shape changes.
- No AES response wrapping.
- No subscription delivery changes for `/s/{token}` or `/api/v1/client/subscribe`.
- No node/server, payment, Telegram, plugin, or client-code changes.

## 2. DK_Theme evidence

Source root inspected: `/home/seven/works/new-api/DK_Theme`.

### 2.1 Auth/session bootstrap waterfall

`src/features/auth/auth-context.tsx` hydrates authenticated state with two parallel calls:

| Client call | Backend endpoint | Notes |
| --- | --- | --- |
| `getUserInfo()` | `GET /api/v1/user/info` | Current user/profile contract. |
| `getSubscribeInfo()` | `GET /api/v1/user/getSubscribe` | Contains subscription delivery data used by clients page. |

Relevant source:

- `src/features/auth/auth-context.tsx` calls `Promise.all([getUserInfo(), getSubscribeInfo()])`.
- `src/lib/api/services/user.ts` maps those calls to `/api/v1/user/info` and `/api/v1/user/getSubscribe`.

Impact:

- Existing `/api/app/v1/session` can eventually replace the non-secret subset of this waterfall.
- DK_Theme still needs legacy `getSubscribe` or a separate safe delivery flow because the clients page uses `subscribe.subscribe_url` for QR/copy/client schemes.
- A dashboard aggregate must not replace subscription delivery unless a separate migration plan is approved.

### 2.2 Dashboard page waterfall

`src/pages/dashboard-page.tsx` makes one dashboard-specific API call:

| Client call | Backend endpoint | Notes |
| --- | --- | --- |
| `getTrafficLogs()` | `GET /api/v1/user/stat/getTrafficLog` | Used for chart/weekly traffic visualization. |

Relevant source:

- `src/pages/dashboard-page.tsx` uses `useQuery({ queryKey: ['traffic-logs'], queryFn: getTrafficLogs })`.
- `src/lib/api/services/traffic.ts` maps that call to `/api/v1/user/stat/getTrafficLog`.

Impact:

- A future dashboard BFF could include a small traffic summary, but should not include full traffic logs by default.
- If traffic chart migration is desired, add an explicit window/cap, for example latest 7 or 30 days, and preserve the existing traffic-log endpoint until DK_Theme migrates.

### 2.3 Other DK_Theme page waterfalls

These pages are independent feature pages, not initial dashboard blockers:

| Page | Calls | Dashboard suitability |
| --- | --- | --- |
| Plans | `/api/v1/user/plan/fetch`, `/api/v1/user/order/fetch`, `POST /api/v1/user/order/save` | Plan catalog and mutations stay outside dashboard. |
| Orders | `/api/v1/user/order/fetch`, `/api/v1/user/order/detail`, `/api/v1/user/order/getPaymentMethod`, order mutations | Dashboard may later include counts/latest orders only; payment methods and checkout stay excluded. |
| Tickets | `/api/v1/user/ticket/fetch`, ticket detail, ticket mutations | Dashboard may later include counts/latest tickets only; full messages/mutations stay excluded. |
| Knowledge | `/api/v1/user/knowledge/fetch`, `/api/v1/user/notice/fetch` | Notices may be capped; knowledge bodies stay excluded due placeholder/subscription-link risk. |
| Invite | `/api/v1/user/invite/fetch`, `/api/v1/user/invite/save` | Invite code generation is a mutation and must stay out. |
| Node status | configurable path default `/api/v1/user/server/fetch` | Node/server credentials and server details stay out. |
| Settings | `/api/v1/user/info`, password/update/resetSecurity mutations | Mutations and `resetSecurity` stay out. |

## 3. hiddify-app evidence

Source root inspected: `/home/seven/works/hiddify-app`.

### 3.1 Login and subscription waterfall

hiddify-app currently relies on a narrow login/subscription flow:

| Client call | Backend endpoint | Notes |
| --- | --- | --- |
| Login | `POST /api/v1/passport/auth/login` | Parses `auth_data`, optional subscribe token/url. |
| Subscription metadata | `GET /api/v1/user/getSubscribe` | Uses bearer auth; parses subscribe URL, expiry, traffic, plan, devices, support link. |
| Customer service fallback | `GET /api/v1/guest/comm/config`, fallback `GET /api/v1/passport/comm/config` | Used only if subscription response lacks support link. |
| Raw node subscription | subscribe URL, usually `/api/v1/client/subscribe?token=...` or configured raw URL | Downloads raw subscription content for node sync. |

Relevant source:

- `lib/features/auth/data/login_service.dart` posts to `/api/v1/passport/auth/login`.
- `lib/features/auth/data/user_subscription_service.dart` gets `/api/v1/user/getSubscribe`, then may read guest/passport config for support link.
- `lib/features/auth/notifier/auth_notifier.dart` downloads the parsed `subscription.subscribeUrl` and imports nodes.
- `lib/features/auth/data/xboard_response_parser.dart` parses both legacy snake_case and App-style camelCase keys for subscription-related fields.

Impact:

- hiddify-app does not currently need `/api/app/v1/dashboard`.
- Its critical path is login + subscription metadata + raw subscription download; dashboard aggregation would not reduce its required raw subscription call.
- Any future App BFF adoption should start with `/api/app/v1/session` or a dedicated subscription-metadata endpoint, not dashboard.

## 4. Request-count implications

| Client flow | Current request count | Dashboard BFF value | Recommendation |
| --- | ---: | --- | --- |
| DK_Theme auth hydrate | 2 parallel requests | Medium, but only for non-secret user/subscription summary | Consider `/api/app/v1/session` migration first. |
| DK_Theme dashboard page | 1 page-specific traffic-log request plus existing auth context | Low-to-medium | Dashboard aggregate is not urgent; traffic summary/window can be separately designed. |
| DK_Theme orders/tickets/notices pages | Page-specific requests only when visited | Low for initial dashboard | Keep feature pages separate; future dashboard may include counts/latest only. |
| hiddify-app login bootstrap | login + subscription metadata + raw subscription download | Low | Dashboard does not remove raw subscription dependency. |

## 5. Decision

Do not implement `/api/app/v1/dashboard` yet.

Reasoning:

1. DK_Theme has some aggregation opportunity, but the first safe migration target is existing `/api/app/v1/session`, not a larger dashboard endpoint.
2. hiddify-app's critical path requires raw subscription content, so dashboard would not reduce its main backend dependency.
3. Orders, tickets, notices, traffic logs, and knowledge have different field/security budgets and should not be bundled without product approval.
4. The current read-model preparation already created `AppSessionReadModel`; that is the correct reusable boundary for the next migration experiment.

## 6. Next safe task

Before any dashboard implementation, run a planning gate that approves all of the following:

1. First consumer: DK_Theme dashboard, DK_Theme auth/session, hiddify-app, or another client.
2. Field allowlist: choose exact sections and keys from `docs/app-api-dashboard-phase2-audit.md`.
3. Query budget: decide whether traffic/order/ticket/notice summaries fit within the 8-12 query target and hard cap of 15.
4. Payload budget: approve caps for latest lists and traffic windows.
5. Feature flag/fallback: define opt-in client behavior and legacy fallback.

Recommended next execution if optimizing without dashboard:

```text
$ralph "迁移前准备：为 /api/app/v1/session 增加可选兼容字段映射文档和客户端 fallback 方案；不改 DK_Theme/hiddify-app 代码，不实现 dashboard，不改旧 API，不做 AES。"
```
