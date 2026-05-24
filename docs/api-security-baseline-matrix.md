# API Security Baseline Matrix

Date: 2026-05-24

Purpose: freeze the current route security posture before Phase 6 hardening. This is an audit artifact only; it does not change runtime behavior.

## Rate-limit and request-size controls currently available

Evidence: `app/Providers/RouteServiceProvider.php`, `config/api_security.php`, `routes/app_api.php`, `routes/web.php`, `app/Http/Routes/**`.

| Named control | Default budget | Keying / notes |
| --- | ---: | --- |
| `throttle:passport-login` | 20/min | IP + lowercased email. |
| `throttle:passport-email` | 3/min | IP + lowercased email. |
| `throttle:user-read` | 120/min | IP + authenticated user id. Currently applied to V1/V2 `user/info` only. |
| `throttle:app-read` | 120/min | IP + authenticated user id or guest. Applied to App BFF bootstrap/session/dashboard. |
| `throttle:admin-login` | 10/min | IP + lowercased email. |
| `throttle:admin-api` | 240/min | IP + authenticated admin id. |
| `throttle:subscription` | 60/min | IP + hashed subscription token fragment. |
| `throttle:server-node` | 300/min | IP + node/machine hint + token fragment. |
| `throttle:server-machine` | 120/min | IP + machine id + token fragment. |
| `throttle:callback` | 120/min | IP + callback method. |
| `api.request_size:passport` | 64 KiB | Applied to passport route groups and admin login. |
| `api.request_size:app` | 64 KiB | Applied to App BFF routes. |
| `api.request_size:admin` | 2 MiB | Applied to admin API/auth-me/logout groups. |
| `api.request_size:server` | 1 MiB | Applied to server node/machine routes. |
| `api.request_size:callback` | 256 KiB | Applied to Telegram and payment callbacks. |

All named rate limits are controlled by `API_RATE_LIMITS_ENABLED`; request-size guards are controlled by `API_REQUEST_SIZE_LIMITS_ENABLED`.

## Route baseline

| Route class | Representative endpoints | Middleware / throttle baseline | Sensitive fields / behaviors | Allowed Phase 6 change |
| --- | --- | --- | --- | --- |
| Public web shell | `/`, `/{secure_path}` | web route; `/` returns 404; admin shell renders configured admin path. | Admin path is configurable; do not expose redirect from `/`. | No change unless admin shell tests are updated. |
| Raw subscription web route | `/{subscribe_path}/{token}` | `client`, `throttle:subscription`. | Raw node/subscription payload, subscription token, subscription-userinfo headers. | No payload/envelope/AES change; only add tests or narrowly tune throttle with compatibility evidence. |
| V1/V2 passport auth | `/api/v1/passport/auth/*`, `/api/v2/passport/auth/*` | Group `api.request_size:passport`; login has `throttle:passport-login`; email verify has `throttle:passport-email`; register/forget/quick-login/mail-link have no named throttle except group size guard. | `auth_data`, auth token, quick-login token/redirect, email verification behavior. | First add tests/matrix; candidate: extend throttles to forget/register/quick-login without payload changes. |
| App BFF public read | `/api/app/v1/bootstrap` | `throttle:app-read`, `api.request_size:app`, `api.cache_headers:bootstrap`. | Capability flags and public config only. | Keep allowlist; test no sensitive fields. |
| App BFF user read | `/api/app/v1/session`, `/api/app/v1/dashboard` | `user`, `throttle:app-read`, `api.request_size:app`, App response boundary. | Must not expose subscription token/url, auth data, UUID, node credentials, payment config. | Add leak tests or tune app-read budget; no field expansion without plan. |
| V1/V2 user info read | `/api/v1/user/info`, `/api/v2/user/info` | `user`; endpoint has `throttle:user-read`. | Legacy user payload and avatar fallback; auth headers. | Keep shape; add sensitive-field tests if missing. |
| V1 user read routes | `getSubscribe`, `getStat`, `checkLogin`, `getActiveSession`, order reads, plan fetch, invite fetch/details, notice fetch, ticket fetch, server fetch, gift-card history/detail/types, comm/config, knowledge fetch/category, traffic log | Group `user`; most have no explicit named throttle; several have Phase 5 read-model coverage. | Subscription URL/token, active sessions, order/payment details, invite commission data, ticket content, node list/ETag, gift-card rewards, support/config data, knowledge body placeholders, traffic usage. | Candidate: apply/test `user-read` to safe read endpoints in small batches; do not touch `getSubscribe` or `server/fetch` payloads without a dedicated plan. |
| V1 user mutations | `changePassword`, `update`, `transfer`, `getQuickLoginUrl`, order save/checkout/cancel, invite save, ticket save/reply/close/withdraw, coupon/gift-card checks/redeem, Stripe public key | Group `user`; no general mutation throttle in route file. | Password/account changes, balance transfer, quick-login URL, payment checkout, ticket spam, coupon/gift-card abuse, Stripe public key lookup. | Candidate: create mutation throttle policy after tests; do not change semantics/envelopes. |
| Side-effect GET legacy routes | `GET /api/v1/user/resetSecurity`, `GET /api/v1/user/invite/save`, V2 resetSecurity | Group `user`; method is GET despite mutation. | Resets subscription/security token; creates invite code. | Add POST aliases first; keep GET compatibility until clients migrate. |
| V1/V2 client subscription API | `/api/v1/client/subscribe`, `/api/v2/client/...` | `client`; V1 subscribe has `throttle:subscription`. | Raw subscription and protocol payloads; token parser compatibility. | No change in Phase 6 except tests/throttle review. |
| Guest public reads | `/api/v1/guest/plan/fetch`, `/api/v1/guest/comm/config` | plan fetch has no named throttle; comm/config has `api.cache_headers:guest-config`. | Plan/pricing/config/support fields; no auth. | Candidate: cache/throttle plan fetch only if frontend compatibility is tested. |
| Guest callbacks | `/api/v1/guest/telegram/webhook`, `/api/v1/guest/payment/notify/{method}/{uuid}` | `throttle:callback`, `api.request_size:callback`. | Provider payloads, payment callback status, Telegram updates. | Do not tighten blindly; require provider-specific fixtures. |
| V1/V2 server node API | `/api/v1/server/*`, `/api/v2/server/*` | server auth middleware plus `throttle:server-node` and `api.request_size:server`; machine routes use `throttle:server-machine`. | Node tokens, node configs, user traffic reports, protocol payloads. | No behavior change without server compatibility tests. |
| V2 admin auth | `/{secure_path}/auth/login`, `me`, `logout` | login has `throttle:admin-login`, `api.request_size:passport`; me/logout have `user`, `admin`, `throttle:admin-api`, `api.request_size:admin`. | Admin session, profile, logout. | Keep; add tests if changing budgets. |
| V2 admin API | `/{secure_path}/config/*`, plan/server/order/user/stat/notice/ticket/coupon/gift-card/knowledge/payment/system/theme/traffic-reset | Group `admin`, `log`, `throttle:admin-api`, `api.request_size:admin`. | Full system configuration, payment config, users, nodes, orders, logs, mail templates, themes. | Do not relax; candidate: sensitive-field tests for admin exports/log redaction separately. |

## Immediate gaps to address next

1. **User read throttle coverage is uneven.** Only `user/info` has `throttle:user-read`; other high-frequency user reads rely on auth only.
2. **User mutation throttle policy is absent.** Ticket/support, quick-login, transfer, checkout, and gift-card/coupon actions need a separate mutation budget design.
3. **Auth throttle coverage is partial.** Login/email verify are throttled; register, forget, quick-login, and mail-link login need review.
4. **Side-effect GET routes are known legacy risks.** They need POST aliases plus a client migration plan, not immediate removal.
5. **Subscription/server/payment callback channels are intentionally isolated.** Do not include them in broad user-read/user-mutation throttles.

## Recommended next Slice 1 target

Add sensitive-field leakage regression tests for the App BFF and already-optimized legacy reads before changing throttles. This creates a safety net for later middleware or response-boundary work.
