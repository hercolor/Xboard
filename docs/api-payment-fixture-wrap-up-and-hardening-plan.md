# Payment Fixture Wrap-up and First Hardening Plan

Date: 2026-05-24

## Objective

Summarize the payment fixture coverage now in place and define the first safe production hardening slice.

This document is a decision gate. It does **not** change checkout, callback, provider, route, response, subscription, or AES behavior.

## Fixture coverage now available

| Area | Coverage | Test file |
| --- | --- | --- |
| Synthetic provider checkout | Raw `{type, data}` response, notify URL, return URL, handling fee, payment id persistence | `tests/Feature/PaymentCheckoutCallbackFixtureTest.php` |
| Synthetic provider callback | Success, invalid payload, duplicate/idempotent callback behavior | `tests/Feature/PaymentCheckoutCallbackFixtureTest.php` |
| Synthetic plugin contract | Stable checkout payload and notify success/failure without DB | `tests/Feature/SyntheticPaymentPluginContractTest.php` |
| `EPay` | Callback signature success/failure and signed checkout URL generation | `tests/Feature/EpayPaymentCallbackFixtureTest.php` |
| `MGate` | Callback signature success/failure and parameter-order-independent verification | `tests/Feature/MgatePaymentCallbackFixtureTest.php` |
| `CoinPayments` | Completed callback, pending provider response body, HMAC failure, deterministic checkout URL | `tests/Feature/CoinPaymentsPaymentCallbackFixtureTest.php` |
| `Coinbase` | Raw-body HMAC success/failure and trimmed raw-body signing behavior | `tests/Feature/CoinbasePaymentCallbackFixtureTest.php` |
| `AlipayF2F` | RSA2 callback signature success/failure and non-success trade status | `tests/Feature/AlipayF2fPaymentCallbackFixtureTest.php` |
| `BTCPay` | Raw-body HMAC success/failure, trimmed raw-body signing behavior, invoice-detail lookup seam | `tests/Feature/BtcpayPaymentCallbackFixtureTest.php` |

## Behavior intentionally unchanged

- `POST /api/v1/user/order/checkout`
- `GET|POST /api/v1/guest/payment/notify/{method}/{uuid}`
- payment provider plugin implementation
- callback response bodies, including provider-specific strings
- raw subscription delivery
- DK_Theme / hiddify-app legacy API shapes
- AES response encryption

## Remaining fixture gaps

These are not blockers for a narrow checkout rate-limit slice, but they are blockers for deeper payment execution rewrites:

1. Checkout paths that make live HTTP/curl requests still need a production-safe HTTP seam before deterministic checkout tests:
   - `MGate`
   - `Coinbase`
   - `BTCPay`
   - `AlipayF2F`
2. Full payment callback controller integration with real provider fixtures is not yet complete for every provider.
3. E2E smoke still checks the payment notify controller boundary, not successful provider-specific paid-order transitions.
4. Local E2E currently emits duplicate `sqlite3` / `pdo_sqlite` module warnings. Tests pass, but the PHP ini duplication should be cleaned separately.

## First safe production hardening slice

### Slice name

`payment-checkout` scoped limiter.

### Goal

Reduce checkout abuse and accidental repeated payment execution without affecting provider callbacks.

### Proposed behavior

- Add a dedicated named limiter: `payment-checkout`.
- Default budget: `API_RATE_LIMIT_PAYMENT_CHECKOUT_PER_MINUTE=30`.
- Key dimension: client IP + authenticated user id.
- Apply only to:
  - `POST /api/v1/user/order/checkout`
- Preserve global kill switch:
  - `API_RATE_LIMITS_ENABLED=false`
- Do **not** apply this limiter to:
  - payment callbacks
  - subscription routes
  - server/node routes
  - passport/auth routes
  - `getStripePublicKey` because it already has `payment-config`

### Why checkout limiter is the first production slice

- Checkout is authenticated and user-scoped, unlike provider callbacks.
- Checkout response shape can remain unchanged.
- The synthetic checkout fixture already locks the raw `{type, data}` contract.
- Existing E2E already covers legacy order creation and order reads.
- Provider callbacks keep their current `callback` limiter and are not affected.

### Acceptance criteria

- `payment-checkout` limiter registered and kill-switch aware.
- `POST /api/v1/user/order/checkout` has only the new checkout limiter added.
- `ApiSecurityPilotTest` asserts route middleware coverage.
- Payment fixture tests still pass.
- E2E smoke still passes.
- No provider callback route gains authenticated user throttles.
- No checkout/callback response shape changes.

### Explicit non-goals

- No idempotency key implementation in the same slice.
- No callback replay protection in the same slice.
- No provider plugin rewrite.
- No payment callback method narrowing.
- No AES.
- No subscription changes.

## Recommended implementation order

1. Add config value:
   - `api_security.rate_limits.payment_checkout_per_minute`
2. Add `RateLimiter::for('payment-checkout', ...)`.
3. Apply `throttle:payment-checkout` to `POST /api/v1/user/order/checkout`.
4. Extend route middleware contract tests.
5. Run targeted payment/security PHPUnit.
6. Run E2E smoke.
7. Commit as a narrow behavior-compatible hardening slice.
