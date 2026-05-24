# Payment Checkout Idempotency Plan

Date: 2026-05-24

## Objective

Reduce duplicate payment execution caused by double-clicks, retries, or concurrent checkout requests while preserving current client contracts.

This is a planning artifact only. It does **not** change checkout behavior.

## Current checkout behavior

Route:

- `POST /api/v1/user/order/checkout`

Current protections:

- authenticated user middleware
- dedicated `payment-checkout` rate limiter
- pending-order lookup by `trade_no`, authenticated `user_id`, and status `0`
- enabled payment-method check

Current duplicate risk:

- A pending order can be submitted to a provider multiple times before the provider callback marks it paid.
- Some providers return fresh payment URLs/QRs per request.
- Concurrent duplicate requests can create unnecessary provider-side sessions.

## Compatibility constraints

- Do not change request fields:
  - `trade_no`
  - `method`
  - optional `token`
- Do not change successful checkout raw response shape:
  - `{type, data}`
- Do not change existing failure envelope style.
- Do not change payment callbacks.
- Do not change provider plugin code in the same slice.
- Do not require DK_Theme or hiddify-app changes.
- Do not add AES.

## Recommended first implementation slice

### In-flight checkout lock only

Add a short-lived lock around checkout execution:

- lock key: `payment:checkout:{user_id}:{trade_no}`
- TTL: 15 seconds
- scope: authenticated checkout only
- release: always release after controller completes
- duplicate in-flight request: return existing fail envelope with a retry message

This prevents concurrent duplicate execution but does not persist checkout results or alter later retry behavior.

### Why start with in-flight lock

- Narrower than full idempotency result caching.
- Does not store provider URLs or QR codes.
- Does not require schema changes.
- Does not affect callbacks.
- Does not alter a normal single checkout request.
- Reduces the highest-risk duplicate window.

## Deferred options

### Result caching by order/payment

Potential future behavior:

- Cache successful `{type, data}` for a short period keyed by order + payment method.
- Return same provider URL/QR for immediate duplicate retries.

Deferred because:

- Some provider URLs may be single-use or expire quickly.
- Storing provider checkout URLs may need redaction/logging policy.
- Requires provider-specific fixture coverage for checkout, not just callback.

### Database idempotency columns

Potential future behavior:

- Store provider checkout session id/url on the order.

Deferred because:

- Requires schema migration.
- Requires client/provider migration review.
- Could change order lifecycle semantics.

## Test requirements before implementation

1. Unit/feature test that a normal checkout still returns raw `{type, data}`.
2. Test that a locked duplicate returns the existing fail envelope style.
3. Test that lock release happens after provider exception.
4. Test that callbacks are not affected by checkout locks.
5. E2E smoke must still pass.

## Proposed production changes

Files likely touched:

- `config/api_security.php`
- `app/Http/Controllers/V1/User/OrderController.php`
- `tests/Feature/PaymentCheckoutCallbackFixtureTest.php`
- `docs/api-security-hardening-phase6-plan.md`

Config:

- `API_PAYMENT_CHECKOUT_LOCK_ENABLED=true`
- `API_PAYMENT_CHECKOUT_LOCK_SECONDS=15`

Acceptance:

- Default enabled, but feature-flagged.
- No route/path/response success shape changes.
- No callback/provider/subscription/AES changes.
