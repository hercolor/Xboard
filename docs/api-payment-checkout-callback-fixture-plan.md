# Payment Checkout / Callback Fixture Coverage Plan

Date: 2026-05-24

## Objective

Create a safe test-fixture path before changing payment execution security behavior.

This is a planning artifact only. It does **not** change checkout, callback, provider, route, response, subscription, or AES behavior.

## Compatibility constraints

- Keep `POST /api/v1/user/order/checkout` request and response shape unchanged.
- Keep `GET|POST /api/v1/guest/payment/notify/{method}/{uuid}` mounted and provider-compatible.
- Keep `throttle:callback` and `api.request_size:callback` on callback routes.
- Do not apply authenticated user limiters to guest provider callbacks.
- Do not change provider plugin config names, callback signatures, callback result strings, or order lifecycle semantics without provider fixtures.
- Do not expose hidden payment config fields through user-facing payment method reads.

## Current execution map

### Checkout

Route:

- `POST /api/v1/user/order/checkout`

Flow:

1. `OrderController::checkout` looks up a pending order by `trade_no`, authenticated `user_id`, and status `0`.
2. Free orders call `OrderService::paid($order->trade_no)` and return raw `{type, data}`.
3. Paid orders look up an enabled `Payment` row by `method`.
4. `PaymentService` loads payment config by id and resolves the provider plugin.
5. `PaymentService::pay` builds:
   - `notify_url`: `/api/v1/guest/payment/notify/{method}/{uuid}`
   - `return_url`: frontend order page
   - `trade_no`, `total_amount`, `user_id`, optional `stripe_token`
6. Controller returns raw `{type, data}` from the provider result.

Security implication: checkout is a mutation and payment execution boundary, but it cannot be tightened safely until tests prove provider behavior and client response shapes remain stable.

### Callback

Route:

- `GET /api/v1/guest/payment/notify/{method}/{uuid}`
- `POST /api/v1/guest/payment/notify/{method}/{uuid}`

Current middleware:

- `throttle:callback`
- `api.request_size:callback`

Flow:

1. `PaymentController::notify` resolves `PaymentService` by provider method and payment `uuid`.
2. Provider `notify($request->input())` verifies provider payload/signature.
3. Successful provider verification returns at least:
   - `trade_no`
   - `callback_no`
   - optional `custom_result`
4. Controller calls `OrderService::paid($callbackNo)` for pending orders.
5. Controller returns provider-specific `custom_result` or `success`.
6. Verification failures return the existing fail envelope.

Security implication: callbacks are public provider entrypoints. Hardening must preserve provider-specific signature requirements and response bodies.

## Provider fixture matrix

| Provider | Checkout fixture need | Callback fixture need | Notes |
| --- | --- | --- | --- |
| `EPay` | Deterministic signed redirect URL; no network required. | Deterministic signed form/query payload. | Best first real-provider fixture candidate. |
| `AlipayF2F` | QR generation depends on bundled gateway library. | Signed Alipay payload with `TRADE_SUCCESS`. | Higher effort due signing library details. |
| `MGate` | Network request in `pay`. | Deterministic MD5 callback payload. | Callback can be covered before checkout. |
| `CoinPayments` | Deterministic hosted payment URL. | HMAC payload; completed and pending cases differ. | Pending callback returns a string; must be tested as a non-paid provider result before changing controller handling. |
| `Coinbase` | Network request in `pay`. | HMAC JSON payload using raw request body. | Needs raw-body/header fixture. |
| `BTCPay` | Network request in `pay`. | HMAC raw body plus invoice detail network lookup. | Needs HTTP/network seam before full fixture. |
| Synthetic test provider | Deterministic `{type, data}` and notify result. | Deterministic success/fail/pending callback variants. | Best first integration seam because it avoids live provider dependencies. |

## Recommended fixture strategy

### Layer 1 — Synthetic provider integration fixture

Goal: prove Xboard's payment orchestration boundary without depending on any live provider.

Test seam:

1. Register an `available_payment_methods` filter that exposes a synthetic method such as `FixturePay`.
2. Bind or fake `PluginManager::getEnabledPaymentPlugins()` to return a synthetic payment plugin instance.
3. Create isolated database fixtures for:
   - user
   - plan
   - pending order
   - enabled payment row with `payment=FixturePay`
4. Synthetic plugin should implement the same `PaymentInterface` behavior:
   - `pay($order)` returns stable `{type: 1, data: "https://fixture-pay.invalid/checkout?..."}`
   - `notify($params)` returns stable `trade_no` and `callback_no`
   - invalid notify payload returns `false`
   - optional pending payload returns a provider-specific non-paid response if needed

Coverage:

- Checkout returns raw `{type, data}` without envelope changes.
- Checkout stores `payment_id` and calculated handling fee exactly as today.
- Callback success marks the order paid.
- Callback duplicate is idempotent for already-paid orders.
- Callback invalid signature/payload keeps the existing failure behavior.
- Callback remains reachable through both GET and POST where provider-compatible.

### Layer 2 — Real-provider deterministic callback fixtures

Goal: cover provider signature parsing without external network calls.

Order:

1. `EPay` callback success/failure.
2. `MGate` callback success/failure.
3. `CoinPayments` completed callback and pending callback.
4. `Coinbase` raw-body HMAC callback.
5. `AlipayF2F` after signing fixture is stable.
6. `BTCPay` only after invoice-detail HTTP lookup is faked safely.

Coverage:

- Signature pass/fail.
- `trade_no` extraction.
- `callback_no` extraction.
- `custom_result` preservation where provider defines it.
- Callback response body compatibility.

### Layer 3 — Checkout fixtures with network seams

Goal: cover provider checkout only where network calls can be safely stubbed.

Allowed before production changes:

- deterministic URL-only providers (`EPay`, `CoinPayments`)
- synthetic provider

Deferred until HTTP seam exists:

- `MGate`
- `Coinbase`
- `BTCPay`
- any provider that performs live curl/file_get_contents requests in `pay` or `notify`

## Guardrails for future hardening

Only after the fixture layers above exist should we consider:

- checkout-specific rate limiting
- idempotency keys or replay protection
- callback signature failure telemetry
- callback method narrowing per provider
- stricter payment config validation
- payment callback anomaly logging

Do not start with these production changes. First add the tests that lock current behavior.

## Proposed implementation order

1. Add test support class for a synthetic payment plugin.
2. Add checkout orchestration test with synthetic plugin.
3. Add callback success/failure/idempotency tests with synthetic plugin.
4. Add deterministic `EPay` callback tests.
5. Add deterministic `MGate` callback tests.
6. Re-run E2E smoke and confirm existing payment notify boundary behavior remains unchanged.
7. Reassess checkout/callback hardening options with fixture evidence.

## Acceptance for the next code slice

- No production behavior changes.
- New tests use isolated fixture data and do not require live provider credentials.
- Existing E2E smoke still passes.
- Existing subscription and App/DK_Theme APIs remain unchanged.
- Any discovered provider behavior gap is documented before being fixed.
