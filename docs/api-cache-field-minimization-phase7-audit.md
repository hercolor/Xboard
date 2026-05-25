# Phase 7.1 API Cache and Field Minimization Audit

> Date: 2026-05-25
> Scope: planning/audit slice for API cache headers, server-side read caching, response field minimization, and query-budget guardrails.
> Decision: documentation only in this slice. Do not change legacy API behavior, response shapes, auth semantics, subscription delivery, callbacks, node/server channels, plugins, DK_Theme, or hiddify-app clients.

## 1. Objective

Phase 7.1 prepares the next API optimization work after the Phase 6 security hardening and local image packaging work.

The target is to improve API safety and speed without breaking the existing separated frontend and app clients:

1. **Security:** reduce accidental sensitive-field exposure through allowlist read models and route-specific policy.
2. **Concurrency:** avoid wasteful repeated reads on stable public data and keep high-fanout reads bounded.
3. **Response speed:** add cache/header/query-budget improvements only where the response contract is stable.

This document freezes the current boundary before implementation. It is intentionally conservative because DK_Theme and hiddify-app still depend on legacy `/api/v1/*` and `/api/v2/*` shapes.

## 2. Non-goals and hard compatibility rules

Do not implement these in Phase 7.1:

- No global AES response encryption.
- No App BFF envelope on legacy APIs.
- No legacy `/api/v1/*` or `/api/v2/*` field removal.
- No subscription output format changes for `/s/{token}` or `/api/v1/client/subscribe`.
- No payment callback response normalization.
- No node/server protocol response changes.
- No plugin interface changes.
- No DK_Theme or hiddify-app route/path migrations.

If a future optimization requires fewer fields, implement it through an additive App BFF/read-model endpoint first, then migrate clients explicitly.

## 3. Current API surface snapshot

| Surface | Route examples | Current state | Phase 7.1 rule |
| --- | --- | --- | --- |
| App BFF reads | `GET /api/app/v1/bootstrap`, `/session`, `/dashboard` | Additive envelope, `throttle:app-read`, request-size guard; `bootstrap` has public cache headers | Preferred place for field minimization and aggregate read models |
| Legacy passport | `/api/v1/passport/*`, `/api/v2/passport/*` | Response shape preserved with route-scoped throttles | Do not reshape; only add tests/policy if needed |
| Legacy user reads | `/api/v1/user/info`, `/order/fetch`, `/ticket/fetch`, `/plan/fetch`, etc. | Mixed route-specific throttles; legacy payloads preserved | Do not trim fields in place; audit route budgets first |
| Public guest reads | `/api/v1/guest/comm/config`, `/api/v1/guest/plan/fetch` | `comm/config` has short public cache headers | Evaluate short TTL headers only for stable public reads |
| Raw subscription | `/s/{token}`, `/api/v1/client/subscribe` | Raw protocol payload; subscription throttle exists | Never JSON-wrap, AES-wrap, or field-minimize |
| Node/server APIs | `/api/v1/server/*`, `/api/v2/server/*` | Machine/protocol payloads; server throttles exist | Separate node cadence budget only |
| Payment/Telegram callbacks | `/api/v1/guest/payment/notify/*`, `/telegram/webhook` | Callback throttles and request-size guard | Do not cache or wrap; preserve provider boundary |
| Admin API | `/api/v2/{secure_path}/*` | Admin shell/API separated from retired built-in frontend | Out of scope for this user/app optimization slice |

## 4. Cache and header candidates

### 4.1 Already active

| Route/component | Mechanism | Default TTL | Notes |
| --- | --- | ---: | --- |
| `GET /api/app/v1/bootstrap` | `api.cache_headers:bootstrap` | env-driven | Stable capability metadata for opted-in App BFF clients. |
| `GET /api/v1/guest/comm/config` | `api.cache_headers:guest-config` | env-driven | Public support/download/display config with short TTL. |
| App dashboard notices | `Cache::remember('app_api:v1:dashboard:notices', ttl)` | `APP_API_DASHBOARD_NOTICES_CACHE_TTL=60` | Stores only `id`, `title`, `created_at`, `updated_at`; DB fallback on cache failure. |

### 4.2 Safe future candidates after tests

| Candidate | Possible policy | Required test before change | Risk |
| --- | --- | --- | --- |
| `GET /api/v1/guest/plan/fetch` | short public cache header, possibly `stale-while-revalidate` | assert no auth/session data and exact legacy body unchanged | Plan/pricing/admin edits may need quick propagation; keep TTL short. |
| `GET /api/v1/user/comm/config` | private/no-store or short private cache decision | assert no Stripe secret/payment private config leaks | Authenticated display config can vary by user/settings. |
| App BFF dashboard public subqueries | internal cache for public notices/support only | query-budget and sensitive-field tests | Never cache the full user dashboard across users. |

### 4.3 Not safe for cache headers in this phase

- Auth mutations and passport flows.
- `GET /api/v1/user/order/check` and payment-adjacent endpoints.
- Raw subscription outputs.
- Node/server machine endpoints.
- Payment/Telegram callbacks.
- User-specific legacy full payloads unless a private/no-store policy is defined and tested.

## 5. Field minimization boundary

### 5.1 App BFF is the approved minimization layer

The current App BFF read models already use allowlists:

- `AppSessionReadModel` returns only `user`, `subscription`, `traffic`, and `preferences`.
- `AppDashboardReadModel` returns capped summaries for orders, tickets, and notices.
- Sensitive legacy delivery fields are intentionally excluded: `token`, `uuid`, `subscribe_url`, `auth_data`, node credentials, payment provider secrets, full ticket messages, and knowledge bodies.

Future minimization should extend this pattern instead of trimming legacy responses.

### 5.2 Legacy APIs must preserve response shape

Legacy endpoints remain the compatibility layer for DK_Theme and hiddify-app. Do not remove or rename fields in these endpoints without an explicit client migration plan:

- `/api/v1/user/info`
- `/api/v1/user/getSubscribe`
- `/api/v1/user/server/fetch`
- `/api/v1/user/order/*`
- `/api/v1/user/ticket/*`
- `/api/v1/user/notice/fetch`
- `/api/v1/user/knowledge/*`
- V2 compatibility endpoints

If a legacy endpoint exposes too much data for a new app screen, create an additive `/api/app/v1/*` endpoint with a strict allowlist.

## 6. Query-budget and N+1 audit targets

| Priority | Endpoint/source | Why it matters | Proposed guardrail |
| --- | --- | --- | --- |
| P0 | `GET /api/app/v1/dashboard` | App aggregate can become a mega endpoint | Keep query budget test at `<= 8` unless new fields justify a documented budget. |
| P1 | `GET /api/v1/user/order/fetch` | Common account page read; can grow with history | Add fixture-backed query and pagination/limit audit before rewrites. |
| P1 | `GET /api/v1/user/ticket/fetch` | App feedback/support path will use tickets | Audit list + latest-message loading; avoid full message bodies in aggregates. |
| P1 | `GET /api/v1/user/notice/fetch` | High-fanout public-ish read | Prefer public notice cache/read model if body is display-safe. |
| P2 | `GET /api/v1/user/knowledge/fetch` | Bodies may include placeholder expansion and subscription links | Do not include bodies in dashboard; audit before cache or BFF use. |
| P2 | `GET /api/v1/user/plan/fetch` and guest plan fetch | Plan catalog affects conversion screens | Cache only with short TTL and exact-shape regression tests. |
| P2 | `GET /api/v1/user/getSubscribe` | Subscription metadata/token-adjacent | Do not cache or field-trim until client contract is frozen. |

## 7. Rate-limit gap review before concurrency changes

Many legacy reads already use `throttle:user-read`. Remaining authenticated GET routes that should be reviewed before broad concurrency testing:

| Route | Current issue | Phase 7 recommendation |
| --- | --- | --- |
| `GET /api/v1/user/getSubscribe` | unthrottled, token-adjacent | Add contract test first; consider `throttle:user-read` only if no client polling risk. |
| `GET /api/v1/user/checkLogin` | unthrottled auth check | Candidate for `throttle:user-read` after DK_Theme/app fallback tests. |
| `GET /api/v1/user/getActiveSession` | unthrottled session list | Candidate for `throttle:user-read`. |
| `GET /api/v1/user/server/fetch` | unthrottled node list | Needs separate sensitivity review; avoid field changes. |
| `GET /api/v1/user/gift-card/history` | unthrottled read | Candidate for `throttle:user-read`. |
| `GET /api/v1/user/gift-card/detail` | unthrottled read | Candidate for `throttle:user-read`. |
| `GET /api/v1/user/gift-card/types` | unthrottled read | Candidate for `throttle:user-read` or public cache review if safe. |
| `GET /api/v1/user/telegram/getBotInfo` | unthrottled config read | Candidate for `throttle:user-read` and possible short cache if public-safe. |
| `GET /api/v1/user/comm/config` | unthrottled config read | Candidate for `throttle:user-read`; cache policy needs Stripe/payment key check. |
| `GET /api/v1/user/order/check` | unthrottled payment-adjacent read | Keep separate from generic user-read until checkout polling behavior is tested. |

Compatibility side-effect GET aliases still exist and should not be optimized as reads:

- `GET /api/v1/user/resetSecurity`
- `GET /api/v1/user/invite/save`

These can only be retired behind a documented migration because POST aliases already exist but legacy clients may still call GET.

## 8. Execution slices

### Slice 7.1A — audit freeze (current)

- Create this document.
- No code behavior changes.
- Verify documentation consistency and current route/test anchors.

### Slice 7.1B — route matrix tests only

- Add/extend tests that classify cache/throttle/no-touch routes.
- Prove current unthrottled candidates are intentionally documented.
- No middleware changes yet.

### Slice 7.1C — selected read throttles

- Add `throttle:user-read` to low-risk authenticated reads only after 7.1B passes.
- Avoid `order/check`, subscription token-adjacent, and side-effect GETs until separate tests exist.

### Slice 7.1D — cache policy pilot

- Evaluate short public cache headers for guest plan/config-like stable data.
- Add exact-body regression tests and env rollback notes.
- Do not cache full authenticated user payloads.

### Slice 7.1E — query-budget tests

- Add query-budget tests for the highest-fanout read models/controllers.
- Prefer BFF/read-model boundaries over controller response rewrites.

## 9. Verification plan

For the current doc-only slice:

```bash
git diff --check
.local/bin/php-xboard ./vendor/bin/phpunit --bootstrap vendor/autoload.php \
  tests/Feature/ApiSecurityPilotTest.php \
  tests/Feature/AppApi \
  tests/Feature/ApiSensitiveFieldLeakageContractTest.php \
  tests/Feature/AdminOnlyShellContractTest.php
```

Before any later behavior change, also run the runtime smoke once local services are available:

```bash
./scripts/dev-up.sh
BASE_URL=http://127.0.0.1:8001 ./scripts/e2e-smoke.sh
```

## 10. Stop condition for Phase 7.1

Phase 7.1 is complete when:

- the audit document exists and matches current route/read-model behavior;
- no implementation changes are included in the audit slice;
- verification passes;
- a commit records the audit boundary for later Phase 7 implementation slices.
