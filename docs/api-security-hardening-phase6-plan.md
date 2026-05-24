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

## Recommended immediate next task

Start with **Slice 0 — Security baseline matrix**.

Reason: it is read-only, lowers risk for all later security work, and prevents accidental changes to subscription, payment, auth, and client-critical legacy contracts.
