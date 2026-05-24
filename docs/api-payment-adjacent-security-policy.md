# Payment-Adjacent API Security Policy

Date: 2026-05-24

## Scope

This slice hardens only the authenticated payment configuration lookup used by legacy clients:

- `POST /api/v1/user/comm/getStripePublicKey`

It does **not** change checkout execution, payment callbacks, provider configuration storage, response envelopes, subscription delivery, AES, or client request paths.

## Current behavior

`CommController::getStripePublicKey` accepts a payment method `id`, verifies that the payment method uses `StripeCredit`, and returns the configured publishable Stripe key through the existing success envelope.

The returned value is a publishable key, not a secret key, but the endpoint is payment-adjacent and can be used for enumeration or polling pressure if left without a scoped budget.

## Policy

- Add a dedicated `payment-config` rate limiter.
- Default budget: `API_RATE_LIMIT_PAYMENT_CONFIG_PER_MINUTE=60`.
- Key dimension: client IP + authenticated user id.
- Preserve the global pilot kill switch: `API_RATE_LIMITS_ENABLED=false` disables this limiter.
- Apply the limiter only to `POST /api/v1/user/comm/getStripePublicKey`.

## Explicit non-goals

- Do not throttle `POST /api/v1/user/order/checkout` in this slice.
- Do not change `GET|POST /api/v1/guest/payment/notify/{method}/{uuid}` callback throttling.
- Do not inspect or reshape payment provider configs.
- Do not change the returned Stripe public key envelope.
- Do not implement AES.

## Follow-up required before checkout/callback changes

Checkout and provider callbacks need provider-aware fixtures before hardening changes:

1. Successful checkout boundary fixture per active provider class.
2. Callback request fixture per provider, including expected signature/uuid behavior.
3. Regression tests proving callback routes are not blocked by authenticated user limiters.
4. E2E smoke update that distinguishes expected provider-fixture failures from route/middleware failures.

Until those fixtures exist, checkout and callback execution should remain behavior-compatible.
