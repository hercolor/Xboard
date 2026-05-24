# Frontend Web/App API Optimization Plan

> Date: 2026-05-20
> Scope: Xboard backend API support for separated Web frontend (`DK_Theme`) and App client (`hiddify-app`).
> Rule: compatibility-first. Do not delete, soft-disable, encrypt, or reshape existing shared APIs in this phase.

## 1. Hard boundaries

This plan optimizes frontend-facing APIs without breaking existing consumers.

No-touch / preserve:

- DK_Theme normal member login, register, password reset, user center, orders, tickets, plans, knowledge, traffic, invite, settings.
- hiddify-app login/subscription/customer-service flow.
- Existing shared APIs under:
  - `/api/v1/passport/*`
  - `/api/v1/user/*`
  - `/api/v1/guest/*`
  - V2 compatibility routes that reuse V1 controllers.
- Subscription delivery:
  - `/s/{token}` raw subscription response
  - `/api/v1/client/subscribe?token=...`
- Node/server APIs.
- Payment notify/callback routes.
- Telegram webhook.
- Plugin hooks.

Explicitly not in this phase:

- No AES wrapping of all API responses.
- No deletion of legacy Passport/User/Guest APIs.
- No legacy dashboard reshaping; App BFF dashboard aggregation must stay additive, authenticated, read-only, and fallback-safe.
- No DK_Theme or hiddify-app migration until fallback and contract tests exist.

## 2. Current client sources

| Client | Source path | Current role |
| --- | --- | --- |
| DK_Theme Web | `/home/seven/works/new-api/DK_Theme` | separated Web frontend using Xboard V1 JSON APIs |
| hiddify-app | `/home/seven/works/hiddify-app` | App/client source using the same core Xboard auth/subscription contracts |

## 3. Current Xboard API architecture anchors

| Area | Files / route source | Notes |
| --- | --- | --- |
| route provider | `app/Providers/RouteServiceProvider.php` | current V1/V2 route files are loaded under fixed `/api/v1` and `/api/v2` prefixes |
| V1 Passport | `app/Http/Routes/V1/PassportRoute.php` | login/register/forget/email verify/quick-login style endpoints |
| V1 User | `app/Http/Routes/V1/UserRoute.php` | user center, subscription info, plan, order, ticket, knowledge, traffic, invite, settings |
| V1 Guest | `app/Http/Routes/V1/GuestRoute.php` | public config/plan/payment/Telegram callback boundary |
| V1 Client | `app/Http/Routes/V1/ClientRoute.php` | `/api/v1/client/subscribe` raw subscription channel |
| V2 Passport/User | `app/Http/Routes/V2/*` | compatibility routes; many reuse V1 controllers |
| legacy response | `app/Helpers/ApiResponse.php`, `app/Exceptions/Handler.php` | must not be globally changed for legacy APIs |

## 4. DK_Theme endpoint inventory

Evidence source:

- `/home/seven/works/new-api/DK_Theme/src/lib/api/services/*`
- `/home/seven/works/new-api/DK_Theme/src/lib/config.ts`

| Endpoint | Method | Source | Status | Allowed change |
| --- | --- | --- | --- | --- |
| `/api/v1/passport/auth/login` | POST | `auth.ts` | keep | no response break; optional future BFF fallback only |
| `/api/v1/passport/comm/sendEmailVerify` | POST | `auth.ts` | keep | no captcha/mail behavior change in this phase |
| `/api/v1/passport/auth/register` | POST | `auth.ts` | keep | preserve `auth_data` contract |
| `/api/v1/passport/auth/forget` | POST | `auth.ts` | keep | preserve existing password-reset flow |
| `/api/v1/passport/auth/reset` | POST fallback candidate | `auth.ts` | legacy gap | document only; do not fix in first slice |
| `/api/v1/passport/auth/forgetPassword` | POST fallback candidate | `auth.ts` | legacy gap | document only; do not fix in first slice |
| `/api/v1/user/info` | GET | `user.ts` | keep | preserve auth header and user payload shape |
| `/api/v1/user/getSubscribe` | GET | `user.ts` | keep | preserve subscription info payload |
| `/api/v1/user/plan/fetch` | GET | `user.ts` | keep | preserve plan list shape |
| `/api/v1/user/server/fetch` | GET | `config.ts` default node-status path | keep/no-touch | preserve protected route; do not merge into BFF first slice |
| `/api/v1/user/order/fetch` | GET | `orders.ts` | keep | optional future read BFF; legacy route unchanged |
| `/api/v1/user/order/detail?trade_no=...` | GET | `orders.ts` | keep | preserve query contract |
| `/api/v1/user/order/getPaymentMethod` | GET | `orders.ts` | keep | preserve payment method payload |
| `/api/v1/user/order/cancel` | POST | `orders.ts` | keep | no semantic change |
| `/api/v1/user/order/checkout` | POST | `orders.ts` | keep | no payment callback/checkout response rewrite |
| `/api/v1/user/order/save` | POST | `orders.ts` | keep | no semantic change |
| `/api/v1/user/knowledge/fetch?language=zh-CN` | GET | `knowledge.ts` | keep | optional future read BFF |
| `/api/v1/user/knowledge/fetch?language=zh-CN&id=...` | GET | `knowledge.ts` | keep | preserve detail query |
| `/api/v1/user/notice/fetch` | GET | `knowledge.ts` | keep | optional future read BFF |
| `/api/v1/user/invite/save` | GET then POST fallback | `invite.ts` | legacy keep | record GET side-effect; do not fix in first slice |
| `/api/v1/user/invite/fetch` | GET | `invite.ts` | keep | preserve invite payload |
| `/api/v1/user/changePassword` | POST | `settings.ts` | keep | no auth/session change |
| `/api/v1/user/update` | POST | `settings.ts` | keep | no user schema change |
| `/api/v1/user/resetSecurity` | GET | `settings.ts` | legacy keep | record GET side-effect; do not fix in first slice |
| `/api/v1/user/stat/getTrafficLog` | GET | `traffic.ts` | keep | optional future read BFF |
| `/api/v1/user/ticket/fetch` | GET | `tickets.ts` | keep | optional future read BFF |
| `/api/v1/user/ticket/fetch?id=...` | GET | `tickets.ts` | keep | preserve detail query |
| `/api/v1/user/ticket/save` | POST | `tickets.ts` | keep | no semantic change |
| `/api/v1/user/ticket/close` | POST | `tickets.ts` | keep | no semantic change |
| `/api/v1/user/ticket/reply` | POST | `tickets.ts` | keep | no semantic change |

## 5. hiddify-app endpoint inventory

Evidence source:

- `/home/seven/works/hiddify-app/AGENTS.md`
- `/home/seven/works/hiddify-app/lib/features/auth/data/login_service.dart`
- `/home/seven/works/hiddify-app/lib/features/auth/data/user_subscription_service.dart`
- `/home/seven/works/hiddify-app/lib/features/auth/data/xboard_response_parser.dart`
- `/home/seven/works/hiddify-app/test/features/auth/data/xboard_response_parser_test.dart`

| Endpoint / contract | Method | Source | Status | Allowed change |
| --- | --- | --- | --- | --- |
| `/api/v1/passport/auth/login` | POST | `login_service.dart` | keep | must keep `data.auth_data`/`auth_data`, `data.token`, optional `subscribe_url` parseability |
| `Authorization: Bearer ...` from login `auth_data` | header | `xboard_response_parser.dart` | keep | app normalizes bearer prefix; do not stop returning auth token |
| `/api/v1/user/getSubscribe` | GET | `user_subscription_service.dart` | keep | must keep subscription info and/or `subscribe_url` parseability |
| `/api/v1/client/subscribe?token=...` | GET/raw | `user_subscription_service.dart` fallback | no-touch | raw subscription channel, not App API envelope |
| `/api/v1/guest/comm/config` | GET | `user_subscription_service.dart` | keep | customer-service fallback; preserve public config contract |
| `/api/v1/passport/comm/config` | GET fallback | `user_subscription_service.dart` | keep/confirm | preserve if currently routed; if absent, app already treats as skipped fallback |
| response keys: `subscribe_url`, `subscribeUrl`, `subscription_url`, `clash_url`, `mihomo_url`, `url` | JSON fields | `xboard_response_parser.dart` | keep compatibility | new BFF may be stricter, but legacy endpoint must remain parseable |
| subscription fields: `expired_at`, `u`, `d`, `transfer_enable`, plan/device/customer-service variants | JSON fields | `xboard_response_parser.dart` | keep compatibility | do not rename/remove from legacy response |

App-specific constraints from `AGENTS.md`:

- Login API returns `authData`/`auth_data` and `subscribe_url`.
- `authData` is used for `Authorization`.
- `subscribe_url` must not be shown in normal UI.
- Do not log full authData, token, subscribe_url, node password, or full server address.

## 6. Recommended target architecture

Adopt a new additive BFF prefix:

```text
/api/app/v1/*
```

Mandatory route boundary:

- Add `RouteServiceProvider::mapAppApiRoutes()`.
- Mount independently with prefix `/api/app/v1` and middleware `api`.
- Do not place App route files under the existing `app/Http/Routes/V1` glob, because that would mount them under `/api/v1/*`.
- Regression gate: `/api/app/v1/bootstrap` exists, while `/api/v1/app/v1/bootstrap` does not exist.

New BFF response envelope only for `/api/app/v1/*`:

```json
{
  "ok": true,
  "code": "OK",
  "message": "ok",
  "data": {},
  "meta": {
    "trace_id": "...",
    "server_time": 1770000000
  }
}
```

Do not globally replace `ApiResponse`, legacy `ApiException`, or subscription/payment/raw callback response behavior.

## 7. First execution slice

Only implement Phase 0 + bootstrap skeleton first.

Deliverables:

1. Keep this document current as the canonical frontend Web/App API optimization plan.
2. Add independent `/api/app/v1` route mount.
3. Add unauthenticated `GET /api/app/v1/bootstrap`.
4. Add scoped App API response factory/trait for the new envelope.
5. Add scoped App API exception/error boundary for the new prefix only.
6. Add feature tests proving:
   - `/api/app/v1/bootstrap` returns `ok/code/message/data/meta`.
   - `/api/v1/app/v1/bootstrap` does not exist.
   - legacy Passport/User/Guest routes still work.
   - `/s/{token}` remains raw subscription output.
   - `/api/v1/client/subscribe` remains raw/no-touch.
   - V1/V2 node/server routes remain mounted.
   - payment notify raw/custom responses are not BFF-envelope wrapped.
   - Telegram webhook boundary is unchanged.
7. Run existing smoke/regression checks.
8. Stop before dashboard/session/order/ticket aggregation.

## 8. Later phases

### Phase 1 — App BFF route and error boundary hardening

- Add `/api/app/v1/session` only after bootstrap boundary is stable.
- Auth errors under `/api/app/v1/*` return the new envelope.
- Legacy `/api/v1/*` error shape remains unchanged.
- Session payload is read-only and limited to user basics, subscription status/limits, traffic overview, and reminder preferences.
- Session payload must not expose subscription token, `subscribe_url`, user UUID, node credentials, or raw auth data.

### Phase 2 — Optional read-only dashboard BFF

Planning/audit status: see `docs/app-api-dashboard-phase2-audit.md`.

- Current decision: `GET /api/app/v1/dashboard` is implemented as an additive authenticated read-only aggregate.
- Keep `GET /api/app/v1/bootstrap` reporting `data.capabilities.dashboard = true`.
- Treat `GET /api/app/v1/session` as the safest first migration surface; dashboard remains opt-in/fallback-only for clients.
- Dashboard uses services/read models, not response reuse from legacy controllers.
- Field allowlist, sensitive-field denylist, list caps, and query budget are covered by App API feature tests.

### Phase 3 — Optional client migration

- DK_Theme feature flag: `VITE_ENABLE_APP_BFF=true`.
- hiddify-app equivalent app config flag only if/when App migration begins.
- New clients may call `/api/app/v1/*` first, with fallback to legacy V1 endpoints until verified.

## 9. Verification commands

Backend syntax/target checks:

```bash
php -l app/Providers/RouteServiceProvider.php
php -l app/Http/Controllers/App/V1/BootstrapController.php
php -l app/Support/AppApiResponseFactory.php

.local/bin/php-xboard ./vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Feature/AdminOnlyShellContractTest.php tests/Feature/AppApi
.local/bin/php-xboard ./vendor/bin/phpunit --bootstrap vendor/autoload.php tests

./vendor/bin/phpstan analyse app/Providers/RouteServiceProvider.php app/Http/Controllers/App app/Support tests/Feature/AppApi tests/Feature/AdminOnlyShellContractTest.php --no-progress --memory-limit=512M

./scripts/dev-up.sh
./scripts/dev-status.sh
./scripts/e2e-smoke.sh
```

If DK_Theme changes later:

```bash
cd /home/seven/works/new-api/DK_Theme
npm run build
npm run lint
```

If hiddify-app changes later, first reread `/home/seven/works/hiddify-app/AGENTS.md`, then run the smallest relevant Flutter checks, typically:

```bash
cd /home/seven/works/hiddify-app
flutter pub get
flutter analyze
```

Do not modify hiddify-app during the first backend BFF skeleton slice.

## 10. Implementation status

### Completed

- 2026-05-20: First execution slice completed in commit `1938ca4`.
  - Added independent `/api/app/v1` mount.
  - Added unauthenticated `GET /api/app/v1/bootstrap`.
  - Added App API response factory and scoped error envelope.
  - Added route/no-touch tests and E2E smoke coverage.
- 2026-05-20: Phase 1 session endpoint completed in the current Ralph session.
  - Added authenticated `GET /api/app/v1/session`.
  - Uses existing `user` middleware.
  - Returns read-only user/subscription/traffic/preference overview.
  - Does not expose subscription token, `subscribe_url`, user UUID, or raw auth data.
  - Keeps legacy `/api/v1/user/info` and `/api/v1/user/getSubscribe` unchanged.
- 2026-05-20: Phase 2 dashboard pre-implementation audit completed.
  - Audit artifact: `docs/app-api-dashboard-phase2-audit.md`.
  - Historical decision at that time: dashboard aggregate remained absent/disabled until client migration evidence, field approval, query budget, and regression tests were ready.
  - Superseded by the 2026-05-21 additive implementation checkpoint below.
- 2026-05-20: Phase 2 read-model preparation completed.
  - Added `App\Services\App\AppSessionReadModel` as the allowlist-only read boundary for `/api/app/v1/session`.
  - Added App BFF test fixtures for sensitive-field leak checks and capped future dashboard candidate rows.
  - Added then-current regression coverage proving `/api/app/v1/dashboard` remained absent and disabled.
  - Superseded by the 2026-05-21 additive implementation checkpoint below.
- 2026-05-20: Client request-waterfall audit completed.
  - Audit artifact: `docs/app-api-dashboard-client-waterfall-audit.md`.
  - Historical decision at that time: do not implement `/api/app/v1/dashboard` yet.
  - DK_Theme has a safer first migration target in `/api/app/v1/session`; hiddify-app still depends on raw subscription download.
- 2026-05-20: Session migration compatibility plan completed.
  - Planning artifact: `docs/app-api-session-migration-compatibility-plan.md`.
  - Decision: `/api/app/v1/session` can only replace non-secret auth/session summary fields; subscription delivery remains on legacy endpoints.
  - Fallback strategy: client opt-in flag first, legacy calls remain the rollback path.
- 2026-05-20: Session and legacy fallback contract tests expanded.
  - `tests/Feature/AppApi/AppApiBootstrapTest.php` now locks `/api/app/v1/session` allowlist behavior, legacy `user/info` and `getSubscribe` route boundaries, and documented fallback field fragments without requiring a DB driver.
  - No new App session fields, no client changes, no dashboard, no AES.
- 2026-05-21: Phase 2 dashboard implementation completed as an additive App BFF route.
  - Added `GET /api/app/v1/dashboard` with `user` + `throttle:app-read` middleware.
  - Added `App\Services\App\AppDashboardReadModel` and `DashboardController`.
  - Response is allowlist-only: session/subscription/traffic summaries, capped latest orders/tickets/notices, and no subscription token/URL, auth data, UUID, node credentials, payment details, ticket messages, or knowledge bodies.
  - Updated bootstrap capability to `dashboard = true`.
  - Legacy APIs, subscription delivery, node/server APIs, payment, Telegram, plugin hooks, DK_Theme, hiddify-app, and AES remain unchanged.
- 2026-05-24: Phase 5 hotspot optimization first slice completed.
  - Extracted legacy `/api/v1/user/info` and `/api/v2/user/info` projection into `App\Services\User\LegacyUserInfoReadModel`.
  - Preserved the exact legacy user info allowlist fields and `avatar_url` fallback required by DK_Theme/hiddify-app.
  - Precomputed `/api/app/v1/session` traffic snapshot once per request while keeping the existing App BFF response shape.
  - Added source/constant contract coverage so legacy user info fields and subscription delivery boundaries remain separated.
  - No legacy API shape change, no subscription delivery change, no AES.
- 2026-05-24: Phase 5 plan-list query-budget slice completed.
  - Optimized `PlanService::getAvailablePlans()` to preload active user counts with the plan list query instead of running per-plan capacity count queries.
  - Kept `PlanResource`, guest/user plan fetch routes, purchase validation, subscription delivery, and legacy API payload shapes unchanged.
  - Added regression tests for preloaded capacity counts without requiring a database fallback.
- 2026-05-24: Phase 5 user homepage counter slice completed.
  - Extracted legacy `/api/v1/user/getStat` into `App\Services\User\LegacyUserStatReadModel`.
  - Preserved the legacy positional array `[pending_order_count, open_ticket_count, invited_user_count]`.
  - Collapsed the controller's three separate count calls behind one read-model query entry using subselects.
  - Added route/runtime smoke coverage for the three-counter array shape.
- 2026-05-24: Phase 5 ticket detail read slice completed.
  - Removed duplicate `message` relation loading from legacy `/api/v1/user/ticket/fetch?id=...`.
  - Kept `TicketResource` / `MessageResource` response shape unchanged, including `message[]` and `is_me`.
  - Selected only message columns needed by the legacy resource and pre-attached the ticket relation to avoid per-message lazy relation reads.
  - Added runtime smoke coverage for ticket create/list/detail message contract.
- 2026-05-24: Phase 5 invite fetch read slice completed.
  - Extracted legacy `/api/v1/user/invite/fetch` into `App\Services\User\LegacyInviteReadModel`.
  - Preserved the legacy `codes` resource collection and five-position `stat` array.
  - Loaded only invite-code columns used by `InviteCodeResource` and grouped invite/commission aggregates behind one read-model query entry using subselects.
  - Added runtime smoke coverage for invite save/fetch/details contracts.
- 2026-05-24: Phase 5 order read slice completed.
  - Extracted legacy `/api/v1/user/order/fetch` and `/api/v1/user/order/detail` reads into `App\Services\User\LegacyOrderReadModel`.
  - Preserved `OrderResource` payloads while preloading only `PlanResource` and payment fields used by the legacy resources.
  - Kept order mutations, checkout, payment methods, surplus order handling, and legacy route/envelope shapes unchanged.
  - Added runtime smoke coverage for order save/fetch/detail contracts.
- 2026-05-24: Phase 5 invite detail pagination slice completed.
  - Moved legacy `/api/v1/user/invite/details` pagination into `LegacyInviteReadModel`.
  - Preserved the raw `data` / `total` response shape and existing `current` / `page_size` fallback semantics.
  - Selected only `ComissionLogResource` fields for detail rows while keeping the total count query separate from ordering.
- 2026-05-24: Phase 5 knowledge render-context slice completed.
  - Reused one render context for legacy `/api/v1/user/knowledge/fetch` list/detail body rendering instead of recomputing user availability and subscribe URL per row.
  - Preserved `KnowledgeResource`, grouped list shape, detail shape, plugin hooks, placeholder replacement, and access filtering behavior.
  - Added source-contract and runtime smoke coverage for category/list/detail knowledge reads.
  - No legacy API shape change, no subscription delivery change, no AES.
- 2026-05-20: DK_Theme App BFF session adapter consensus plan approved.
  - Planning artifact: `docs/dk-theme-app-bff-session-adapter-plan.md`.
  - Decision: `VITE_ENABLE_APP_BFF` first acts as App session overlay/probe only; legacy `user/info` remains authoritative for `balance`/`commission_balance`, and legacy `getSubscribe` remains authoritative for `subscribe_url`/token.
  - Request-count note: this first slice is migration readiness, not guaranteed request reduction.

### Current next task

Recommended next backend optimization step:

```text
Audit the next read-heavy frontend/App API hotspot, likely legacy `notice/fetch` or `order/getPaymentMethod` reads, and choose one narrow safe slice: preserve legacy API shapes, keep DK_Theme and hiddify-app compatibility, avoid AES/subscription-delivery changes, then add query-budget or cache/read-model improvements with route-contract tests.
```
