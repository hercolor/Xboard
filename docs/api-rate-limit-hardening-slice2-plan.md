# Phase 6 Slice 2 Rate-limit Hardening Plan

Date: 2026-05-24

## Goal

Harden API concurrency/abuse controls without changing DK_Theme/hiddify-app contracts.

This slice is planning-only. No middleware is changed here.

## Current baseline

Evidence: `docs/api-security-baseline-matrix.md`.

- Existing named limiters are registered and kill-switchable.
- App BFF routes use `throttle:app-read` and request-size guards.
- Admin and server/callback/subscription channels already use dedicated scoped throttles.
- V1/V2 `user/info` uses `throttle:user-read`.
- Most V1 user read/mutation routes are authenticated but do not have explicit named throttles.

## Route groups for hardening

### Group A — safe user reads, candidate for `throttle:user-read`

These are authenticated reads with no payment execution, no raw subscription delivery, and Phase 5 or existing smoke coverage.

Candidate endpoints:

- `GET /api/v1/user/getStat`
- `GET /api/v1/user/stat/getTrafficLog`
- `GET /api/v1/user/order/fetch`
- `GET /api/v1/user/order/detail`
- `GET /api/v1/user/order/getPaymentMethod`
- `GET /api/v1/user/invite/fetch`
- `GET /api/v1/user/invite/details`
- `GET /api/v1/user/notice/fetch`
- `GET /api/v1/user/knowledge/fetch`
- `GET /api/v1/user/knowledge/getCategory`
- `GET /api/v1/user/ticket/fetch`
- `GET /api/v1/user/plan/fetch`
- `GET /api/v1/user/checkLogin`
- `GET /api/v1/user/getActiveSession`
- `GET /api/v2/user/info` already covered; keep.

First implementation batch should be smaller than this full list. Recommended first batch:

1. `getStat`
2. `stat/getTrafficLog`
3. order read trio
4. notice/knowledge reads

Reason: these already have strong E2E/contract coverage from Phase 5.

### Group B — defer user reads

Do not add broad throttles yet:

- `GET /api/v1/user/getSubscribe` — subscription delivery/client parser sensitive.
- `GET /api/v1/user/server/fetch` — node/server payload and ETag behavior are subscription-adjacent.
- Gift-card history/detail/types — not in current DK_Theme/hiddify-app critical path; needs product decision.
- `GET /api/v1/user/telegram/getBotInfo` — external Telegram call; may need a separate lower budget/fallback policy.
- `GET /api/v1/user/comm/config` and `POST /api/v1/user/comm/getStripePublicKey` — config/payment-adjacent; handle separately.

### Group C — auth throttles, candidate for dedicated policies

Existing:

- login: `throttle:passport-login`
- email verify: `throttle:passport-email`

Potential future additions:

- register
- forget password
- quick-login URL
- mail-link login

Do not reuse `passport-login` blindly; the key dimensions and budgets differ.

### Group D — user mutations, needs new limiter design

Candidate routes need a new named limiter such as `user-mutation` or narrower per-domain limiters:

- ticket save/reply/close/withdraw
- order save/cancel, but **not checkout** until payment behavior is separately tested
- coupon/gift-card check/redeem
- transfer
- user update/change password
- quick-login URL

### Group E — no-touch channels

Do not include in broad user/auth throttles:

- raw subscription routes
- server node/machine routes
- guest payment notify callbacks
- Telegram webhook callbacks
- admin routes already under `admin-api`

## Test strategy before middleware changes

For each implementation batch:

1. Add route-middleware contract assertions to `ApiSecurityPilotTest`.
2. Run targeted PHPUnit:
   - `tests/Feature/ApiSecurityPilotTest.php`
   - `tests/Feature/ApiSensitiveFieldLeakageContractTest.php`
   - affected legacy read contract tests
3. Run E2E smoke to verify DK_Theme/hiddify-app-compatible legacy flow still passes.
4. Keep `API_RATE_LIMITS_ENABLED=false` kill-switch behavior covered.

## Recommended immediate implementation slice

Add `throttle:user-read` only to the small high-confidence read batch:

- V1 `getStat`
- V1 `stat/getTrafficLog`
- V1 order read trio
- V1 notice/knowledge reads

Do not change budgets, response shapes, request methods, subscription delivery, server fetch, auth mutations, checkout, or AES in that slice.
