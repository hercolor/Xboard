# App API Dashboard Phase 2 Pre-Implementation Audit

> Date: 2026-05-20
> Scope: Phase 2 audit and implementation record for `GET /api/app/v1/dashboard`.
> Decision update: `GET /api/app/v1/dashboard` is implemented as an additive, authenticated, read-only App BFF aggregate. Legacy APIs, subscription delivery, node/server APIs, callbacks, plugins, DK_Theme, and hiddify-app remain unchanged.

## 1. Scope and non-goals

This audit originally prepared Phase 2 of `docs/frontend-app-api-optimization-plan.md`; it now also records the approved implementation boundary.

In scope:

- Record the approved `/api/app/v1/dashboard` implementation boundary.
- Define the future endpoint's read-only field boundary.
- Define query, latency, and payload budgets before implementation.
- Define regression tests required before enabling the route.

Out of scope for this phase:

- No legacy `/api/v1/*` or `/api/v2/*` response shape changes.
- No AES response wrapping.
- No DK_Theme or hiddify-app migration.
- No subscription delivery changes for `/s/{token}` or `/api/v1/client/subscribe`.

## 2. Current App API route surface

Current mounted App BFF routes are intentionally small:

| Route | Auth | Purpose | Status |
| --- | --- | --- | --- |
| `GET /api/app/v1/bootstrap` | no | BFF capability discovery and stable envelope proof | implemented |
| `GET /api/app/v1/session` | `user` middleware | authenticated user/subscription/traffic summary | implemented |
| `GET /api/app/v1/dashboard` | `user` middleware | authenticated read-only user dashboard summary | implemented |

`BootstrapController` now exposes `data.capabilities.dashboard = true` for clients that explicitly opt in to the App BFF.

## 3. Is `/api/app/v1/dashboard` needed now?

Recommendation: **not required immediately**.

Reasoning:

1. **hiddify-app does not need it for the known current flow.** Its active needs are login/auth token parsing, subscription metadata, raw subscription URL consumption, and customer-service fallback. Those are already served by the existing legacy routes and partially by `/api/app/v1/session` if a future opt-in migration is approved.
2. **DK_Theme can benefit later, but only after measured need.** DK_Theme currently composes dashboard/user-center screens from multiple `/api/v1/user/*` calls. A dashboard BFF could reduce call count, but there is no request-count/latency evidence yet proving this is the next bottleneck.
3. **Session already covers the safest shared core.** `/api/app/v1/session` returns user basics, subscription status/limits, traffic overview, and preferences without exposing token, `subscribe_url`, UUID, or auth data.
4. **Dashboard aggregation has higher coupling risk.** Orders, tickets, notices, knowledge, payment, invite, and subscription areas have different security and side-effect boundaries. Aggregating them too early can accidentally leak sensitive fields or couple the new BFF to legacy controller response internals.

Implementation should wait until all prerequisites in section 11 are satisfied.

## 4. Candidate data sources for a future dashboard

Only read-only model/service access is acceptable. The future endpoint must not call legacy controllers and reuse their full responses.

| Candidate area | Existing legacy endpoint/source | Future dashboard suitability | Notes |
| --- | --- | --- | --- |
| session summary | `/api/v1/user/info`, `/api/app/v1/session` | yes | Prefer reusing a new read model shared with `SessionController`, not legacy controller output. |
| subscription summary | `/api/v1/user/getSubscribe` | partial | Must strip `token`, `subscribe_url`, `uuid`, raw plan internals, and hook-expanded secrets. |
| traffic summary | user columns, `/api/v1/user/stat/getTrafficLog` | yes, limited | Summary is safe; traffic logs need explicit date window and row cap. |
| order summary | `/api/v1/user/order/fetch` | yes, limited | Count and latest list only; no checkout/payment payloads. |
| ticket summary | `/api/v1/user/ticket/fetch` | yes, limited | Count and latest list only; no full message bodies unless explicitly requested by a detail route. |
| notices | `/api/v1/user/notice/fetch` | yes, limited | Public visible notices; cap to latest/sorted page. |
| knowledge | `/api/v1/user/knowledge/fetch` | risky | Existing list can process bodies and replace subscription placeholders. Do not include full body in dashboard. |
| support/config | `/api/v1/user/comm/config`, `/api/v1/guest/comm/config` | partial | Customer-service/display-safe fields only. Do not include Stripe keys/payment config in dashboard. |
| plans | `/api/v1/user/plan/fetch` | no by default | Plan catalog belongs on plan pages; dashboard may include current plan name only if needed. |
| servers/nodes | `/api/v1/user/server/fetch`, raw subscription | no | Node credentials/server addresses must stay out of dashboard. |

## 5. Proposed future field allowlist

If `/api/app/v1/dashboard` is later approved, start from this allowlist and reject unlisted fields by default.

```json
{
  "session_summary": {
    "user": {
      "id": 0,
      "email": "user@example.com",
      "avatar_url": "https://...",
      "banned": false,
      "is_staff": false,
      "is_admin": false,
      "created_at": 0,
      "last_login_at": 0,
      "telegram_bound": false
    }
  },
  "subscription_summary": {
    "status": "active | no_plan | expired | banned | traffic_exhausted",
    "active": true,
    "plan_id": 0,
    "plan_name": "optional safe display name",
    "expired_at": 0,
    "next_reset_at": 0,
    "device_limit": 0,
    "speed_limit": 0,
    "delivery_available": true
  },
  "traffic_summary": {
    "upload": 0,
    "download": 0,
    "used": 0,
    "total": 0,
    "remaining": 0,
    "usage_percent": 0.0
  },
  "orders_summary": {
    "unpaid_count": 0,
    "pending_count": 0,
    "latest": []
  },
  "tickets_summary": {
    "open_count": 0,
    "latest": []
  },
  "notices": [],
  "support": {
    "customer_service_url": "optional safe public link",
    "telegram_discuss_link": "optional safe public link"
  }
}
```

Suggested `latest` list item limits:

- orders: latest 3 to 5 items; fields: `trade_no`, `status`, `period`, `total_amount`, `created_at`, optional safe plan display name.
- tickets: latest 3 to 5 items; fields: `id`, `level`, `reply_status`, `status`, `subject`, `created_at`, `updated_at`.
- notices: latest 3 to 5 visible notices; fields: `id`, `title`, `content` only if already public/display-safe, `created_at`, `updated_at`.
- knowledge: omit from first dashboard version, or include category/title only after separate review.

## 6. Denylist and sensitive data boundary

Never include these fields in `/api/app/v1/dashboard`:

- Subscription `token`.
- `subscribe_url`, `subscribeUrl`, `subscription_url`, `clash_url`, `mihomo_url`, or equivalent subscription delivery URLs.
- User `uuid`.
- Login `auth_data` / `authData` / raw bearer token.
- Node/server credentials: host, port, password, method/cipher, protocol settings, private node attributes.
- Raw subscription content from `/s/{token}` or `/api/v1/client/subscribe`.
- Payment provider secrets/config, payment notify payloads, checkout provider result payloads, Stripe secret keys.
- Full ticket messages in the dashboard aggregate.
- Full knowledge article body when placeholder expansion can inject subscription URLs.
- Invite codes if the dashboard does not explicitly need them.
- Admin-only/system settings beyond display-safe public support links.

## 7. Mutation exclusions

A future dashboard endpoint must be a pure read. It must not call or reproduce behavior from these legacy mutation or side-effect endpoints:

| Legacy endpoint | Reason to exclude |
| --- | --- |
| `GET /api/v1/user/resetSecurity` | Mutates subscription token and UUID. |
| `GET /api/v1/user/invite/save` | Creates invite code despite GET method. |
| `POST /api/v1/user/changePassword` | Mutates credentials/session tokens. |
| `POST /api/v1/user/update` | Mutates user preferences. |
| `POST /api/v1/user/transfer` | Mutates balance/commission. |
| `POST /api/v1/user/order/save` | Creates order. |
| `POST /api/v1/user/order/checkout` | Triggers payment flow / may mutate order. |
| `POST /api/v1/user/order/cancel` | Mutates order state. |
| `POST /api/v1/user/ticket/save` | Creates ticket and triggers hooks. |
| `POST /api/v1/user/ticket/reply` | Creates ticket message and triggers hooks. |
| `POST /api/v1/user/ticket/close` | Mutates ticket state. |
| `POST /api/v1/user/ticket/withdraw` | Creates commission withdrawal ticket. |
| `POST /api/v1/user/coupon/check` | Coupon validation side effects must be reviewed separately. |
| `POST /api/v1/user/gift-card/check` / `redeem` | Gift-card validation/redeem side effects. |

## 8. Query, latency, and payload budgets

Set these budgets before implementation. They are intentionally strict to prevent a “mega endpoint” that silently loads whole legacy pages.

| Budget | Target | Hard guardrail |
| --- | --- | --- |
| DB queries | 8-12 queries for cold authenticated dashboard | fail review if above 15 without explicit caching/read-model design |
| raw JSON size | <= 120 KB | fail review if above 200 KB |
| compressed payload | <= 40 KB | fail review if above 70 KB |
| local baseline latency | p95 <= 300 ms | investigate if above 500 ms |
| production initial target | p95 <= 800 ms | require instrumentation if above 1000 ms |
| list size | 3-5 rows per summary list | never return unbounded orders/tickets/notices/logs |
| traffic log window | omit first version, or max 7/30 days with row cap | never return full history |

Implementation notes:

- Use model queries/read models with explicit `select(...)` columns.
- Use counts and latest lists separately; avoid loading full collections then counting in PHP.
- Use eager loading only for named safe relations and selected columns.
- Avoid calling `OrderResource::collection` or `TicketResource::collection` on unbounded legacy collections.
- Do not include knowledge bodies in the aggregate because placeholder expansion can inject subscription links.

## 9. Proposed future endpoint contract

Future route, if approved:

```text
GET /api/app/v1/dashboard
Middleware: api, AppApiResponseBoundary, user
Envelope: AppApiResponseFactory success/error shape only
Semantics: read-only aggregate, no writes, no hooks that mutate state
```

Response shape should remain under the App BFF envelope:

```json
{
  "ok": true,
  "code": "OK",
  "message": "ok",
  "data": {
    "session_summary": {},
    "subscription_summary": {},
    "traffic_summary": {},
    "orders_summary": {},
    "tickets_summary": {},
    "notices": [],
    "support": {}
  },
  "meta": {
    "trace_id": "...",
    "server_time": 1770000000
  }
}
```

Versioning rule: add fields additively only. Removing or renaming fields requires a new App BFF version or a feature flag/migration window.

## 10. Regression test plan

The earlier absence/capability tests were retired when the implementation was approved. Current required tests:

1. **Auth boundary**: unauthenticated dashboard returns App API error envelope and does not call legacy response helpers.
2. **Envelope shape**: authenticated success returns `ok/code/message/data/meta` with stable `meta.trace_id` and integer `server_time`.
3. **Allowlist shape**: response only contains approved top-level sections and approved nested keys.
4. **Sensitive data absence**: JSON string does not contain subscription token, `subscribe_url`, UUID, auth data, node credentials, payment secrets, or full subscription URLs.
5. **Read-only behavior**: request does not change user `token`, `uuid`, balances, orders, tickets, invite codes, or preference columns.
6. **Query budget**: measured query count stays within the approved budget, or the test documents why measurement is skipped in the current test harness.
7. **Payload budget**: seeded latest lists remain capped; no unbounded collections are returned.
8. **Legacy compatibility**: existing `/api/v1/user/info`, `/api/v1/user/getSubscribe`, `/api/v1/user/order/fetch`, `/api/v1/user/ticket/fetch`, `/api/v1/user/notice/fetch`, and `/api/v1/user/knowledge/fetch` keep their old shapes.
9. **No-touch channels**: `/s/{token}`, `/api/v1/client/subscribe`, payment notify/callback, Telegram webhook, node/server APIs, and plugin hooks remain outside the App BFF envelope.
10. **Client fallback**: DK_Theme/hiddify-app continue to work against legacy paths unless an explicit feature flag migration is added.

## 11. Implementation prerequisites

Do not implement the dashboard endpoint until these prerequisites are complete:

- Product decision: confirm whether the first consumer is DK_Theme, hiddify-app, or a new client.
- Field approval: approve the allowlist/denylist above and decide whether orders/tickets/notices are needed in v1.
- Measurement: capture current frontend request waterfall or runtime logs proving aggregation is worth the extra backend coupling.
- Read-model design: extract safe user/session/subscription read logic so `SessionController` and future dashboard do not duplicate business rules.
- Test harness: add query-count and sensitive-field assertions for the new route.
- Feature flag plan: define client-side opt-in/fallback before DK_Theme or hiddify-app migration.

## 12. Decision

Phase 2 has moved from planning gate to a narrow implementation checkpoint.

Current decision:

- Mount authenticated `GET /api/app/v1/dashboard`.
- Keep `bootstrap.capabilities.dashboard = true`.
- Use `App\Services\App\AppDashboardReadModel`, not legacy controller responses.
- Keep the response allowlist-only and capped: session/subscription/traffic summaries, latest orders, latest tickets, public notice titles, and empty support object.
- Continue to treat `/api/app/v1/session` as the safer first migration surface for DK_Theme session metadata.

## 13. Phase 2 follow-up preparation status

Completed on 2026-05-20 for the read-model preparation slice:

- Added `App\Services\App\AppSessionReadModel` as the allowlist-only read boundary for the existing `/api/app/v1/session` payload.
- Updated `SessionController` to delegate read payload construction to the read model instead of keeping reusable field rules inside the controller.
- Added App BFF test fixtures under `Tests\Support\AppApi\AppBffFixtures`:
  - authenticated user fixture with sensitive legacy fields for leak checks;
  - sensitive needle list shared by App BFF tests;
  - capped future dashboard candidate rows for orders, tickets, and notices.
- Added then-current regression tests that kept `GET /api/app/v1/dashboard` absent and `bootstrap.capabilities.dashboard = false`; those assertions were superseded by the 2026-05-21 implementation slice.

Completed on 2026-05-21 for the dashboard implementation slice:

- Added `App\Services\App\AppDashboardReadModel` as a read-only aggregate boundary.
- Added `App\Http\Controllers\App\V1\DashboardController`.
- Mounted authenticated `GET /api/app/v1/dashboard` under the existing App API envelope and `throttle:app-read`.
- Updated bootstrap capability discovery to report `dashboard = true`.
- Added feature tests for auth envelope, allowlist shape, sensitive-field absence, list caps, and query budget.

Still intentionally not done:

- No legacy `/api/v1/*` or `/api/v2/*` response changes.
- No AES response wrapping.
- No DK_Theme or hiddify-app migration.
- No subscription, node/server, payment, Telegram, or plugin behavior changes.

Next gate before client migration remains: DK_Theme/hiddify-app opt-in flag work, fallback testing, browser/app smoke evidence, and production latency observation.


## 14. Client waterfall evidence

See `docs/app-api-dashboard-client-waterfall-audit.md`. The implementation is now available as an additive App BFF route, but the client migration conclusion remains conservative: DK_Theme should keep its session adapter fallback, and hiddify-app still requires login, subscription metadata, and raw subscription content.

## 15. Session-first migration gate

See `docs/app-api-session-migration-compatibility-plan.md`. The approved migration-prep direction is session-first, not dashboard-first: `/api/app/v1/session` may serve non-secret DK_Theme auth/session summary fields behind a client opt-in flag, while subscription delivery (`subscribe_url`, `token`, raw subscription download) remains on legacy endpoints. Any optional session field extension such as balances or plan name requires explicit approval, allowlist tests, and query-budget checks.
