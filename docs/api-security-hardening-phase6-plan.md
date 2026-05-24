# Phase 6 API Security Hardening Plan

Date: 2026-05-24

## Objective

Improve API security for Xboard while preserving DK_Theme and hiddify-app compatibility.

This phase is **not** a response-shape rewrite and **not** an AES rollout. It should first add guardrails, tests, and low-risk protections around existing contracts.

## Non-negotiable constraints

- Do not change API paths, route names, auth token semantics, response envelopes, or client-critical field names without a compatibility shim.
- Do not change subscription delivery (`/api/v1/user/getSubscribe`, `/api/v1/client/subscribe`, `/s/{token}`) in this phase.
- Do not modify checkout/payment execution without provider fixtures and payment-specific tests.
- Do not implement AES encryption until key management, replay/debug behavior, rollout flags, and client migration are formally approved.
- Keep DK_Theme and hiddify-app working against existing legacy APIs.

## Current baseline from previous work

- Phase 5 read-path optimization is closed: `docs/frontend-app-api-phase5-wrap-up-audit.md`.
- E2E smoke currently covers admin shell, App BFF bootstrap/session/dashboard, V1/V2 auth, user info/stat/traffic, tickets, invites, orders, notices, knowledge, guest config/plan, subscription payload, Telegram webhook, and payment-notify controller boundary.
- Existing sensitive areas intentionally deferred: subscription delivery, node/server payloads, payment execution, auth mutation semantics, side-effect GET retirement.

## Execution slices

### Slice 0 — Security baseline matrix

**Goal:** Freeze the current security-sensitive API surface before changing behavior.

Tasks:

1. Extend the API matrix with security classification per endpoint:
   - public unauthenticated
   - user-authenticated read
   - user-authenticated mutation
   - admin-authenticated
   - webhook/callback
   - raw subscription delivery
2. Record current middleware/throttle coverage for each class.
3. Identify response fields that are secrets or quasi-secrets: tokens, UUIDs, subscribe URLs, auth data, payment config, provider callbacks, node credentials.

Acceptance:

- A document lists route class, middleware, throttle, sensitive fields, and allowed changes.
- No runtime behavior changes.

### Slice 1 — Sensitive-field leakage tests

**Goal:** Add regression tests before security changes.

Tasks:

1. Add source/contract tests for App BFF responses to keep sensitive fields out.
2. Add legacy-route guard tests for payment method, notices, traffic, orders, user info, and dashboard paths already covered by Phase 5.
3. Add explicit no-leak expectations for:
   - payment `config`, `uuid`, `notify_domain`
   - subscription `token`/raw URL where not intentionally exposed
   - user password/password salt/auth internals
   - node credentials outside subscription/node routes

Acceptance:

- Targeted PHPUnit passes.
- E2E smoke still passes.
- No payload changes except test-only assertions.

### Slice 2 — Rate-limit policy hardening

**Goal:** Add or normalize throttles with minimal client impact.

Candidate endpoint groups:

| Group | Risk | Initial policy direction |
| --- | --- | --- |
| Login/register/forget/email verify | brute force / mail abuse | stricter auth throttles, IP + account/email dimensions if feasible |
| App BFF read endpoints | read amplification | existing `throttle:app-read` review and consistency |
| User read endpoints | dashboard polling pressure | apply existing read throttle where missing, verify DK_Theme polling behavior |
| Support/ticket mutations | spam | mutation throttle, preserve current success/fail envelopes |
| Payment callbacks/webhooks | provider compatibility | do not throttle blindly; require provider-aware policy |

Acceptance:

- Middleware changes are covered by tests or smoke scripts.
- Legitimate DK_Theme/hiddify-app flows still pass E2E.
- Payment callbacks are not blocked by generic user throttles.

### Slice 3 — Side-effect GET retirement plan

**Goal:** Reduce unsafe GET mutations without breaking old clients.

Known routes:

- `GET /api/v1/user/resetSecurity`
- `GET /api/v1/user/invite/save`

Plan:

1. Keep legacy GET routes active initially.
2. Add POST aliases with the same response shape.
3. Add deprecation headers/logging on GET only after compatibility is verified.
4. Update DK_Theme first if it calls the GET route.

Acceptance:

- GET remains compatible during transition.
- POST alias tests pass.
- No client is forced to migrate in the same slice.

### Slice 4 — Error-envelope consistency audit

**Goal:** Document inconsistent raw responses before attempting normalization.

Do not normalize immediately. First classify:

- success envelope routes: `$this->success(...)`
- raw response routes: notice fetch, server fetch, subscription, callbacks
- redirect routes: token login
- raw subscription payloads

Acceptance:

- Inconsistency matrix exists.
- Any future normalization proposal includes client impact and migration path.

### Slice 5 — AES decision gate only

**Goal:** Decide whether response encryption is appropriate; do not implement by default.

Required questions before AES:

1. Which clients can securely store/deploy the key?
2. Is encryption per-response, per-session, or static shared key?
3. How are replay, timestamp, nonce, and signature handled?
4. How will debugging, logs, CDN/proxy behavior, and error handling work?
5. How will legacy DK_Theme/hiddify-app opt in or fall back?
6. Which endpoints must remain raw (subscription, callbacks, redirects)?

Acceptance:

- Written threat model and rollout plan.
- Explicit opt-in flag and fallback behavior specified.
- No AES implementation until approved.

## Progress

- 2026-05-24: Slice 0 security baseline matrix completed.
  - Artifact: `docs/api-security-baseline-matrix.md`.
  - Runtime behavior unchanged.
- 2026-05-24: Slice 1 sensitive-field leakage tests started/completed for App BFF and Phase 5 read-model allowlists.
  - Added `tests/Feature/ApiSensitiveFieldLeakageContractTest.php`.
  - Locked App session secret omission, payment method user allowlist, dashboard summary allowlists, and traffic log storage-column exclusions.
  - Runtime behavior unchanged.
- 2026-05-24: Slice 2 rate-limit hardening plan completed.
  - Artifact: `docs/api-rate-limit-hardening-slice2-plan.md`.
  - Decision: first implementation batch should only add `throttle:user-read` to high-confidence V1 read routes with Phase 5 coverage.
  - Runtime behavior unchanged.
- 2026-05-24: Slice 2 first route-middleware batch completed.
  - Added `throttle:user-read` to V1 `getStat`, `stat/getTrafficLog`, order read trio, notice reads, and knowledge reads.
  - Updated route-middleware contract coverage in `ApiSecurityPilotTest`.
  - Subscription delivery, server/node payloads, checkout, auth mutations, and AES remain unchanged.

- 2026-05-24: Slice 2 second route-middleware batch completed.
  - Artifact: `docs/api-rate-limit-hardening-slice2-batch2-plan.md`.
  - Added `throttle:user-read` to V1 plan fetch, invite fetch/details, and ticket fetch.
  - Added user plan fetch smoke coverage.
  - Subscription delivery, server/node payloads, checkout, auth mutations, callbacks, and AES remain unchanged.

- 2026-05-24: Auth throttle gap policy implemented.
  - Artifact: `docs/api-auth-throttle-gap-policy.md`.
  - Added scoped limiters for register, forget, and passport quick-login URL; reused `passport-email` for login-with-mail-link.
  - `token2Login`, authenticated user quick-login, subscription delivery, callbacks, checkout, and AES remain unchanged.

- 2026-05-24: User mutation throttle policy implemented.
  - Artifact: `docs/api-user-mutation-throttle-policy.md`.
  - Added `user-mutation` limiter and applied it to account/session, non-checkout order, ticket, coupon, and gift-card mutation routes.
  - Checkout, side-effect GETs, Stripe public-key lookup, subscription delivery, callbacks, server/node routes, and AES remain unchanged.

- 2026-05-24: Side-effect GET retirement compatibility aliases added.
  - Artifact: `docs/api-side-effect-get-retirement-plan.md`.
  - Added POST aliases for V1/V2 `resetSecurity` and V1 `invite/save` with `throttle:user-mutation`.
  - Legacy GET routes remain mounted unchanged for DK_Theme/hiddify-app compatibility.

- 2026-05-24: Payment-adjacent config lookup throttled.
  - Artifact: `docs/api-payment-adjacent-security-policy.md`.
  - Added dedicated `payment-config` limiter for V1 Stripe public-key lookup.
  - Checkout execution, payment callbacks, provider configs, response envelopes, subscription delivery, and AES remain unchanged.

## Recommended immediate next task

Plan checkout/callback fixture coverage before any payment execution hardening. Do not remove side-effect GET routes or change subscription/payment/server payloads without migration evidence.
