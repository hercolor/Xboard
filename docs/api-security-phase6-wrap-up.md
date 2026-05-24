# Phase 6 API Security Hardening Wrap-up

Date: 2026-05-24

## Scope completed

Phase 6 focused on behavior-compatible API security hardening for Xboard while preserving DK_Theme and hiddify-app compatibility.

Completed areas:

1. Security baseline and sensitive-field guardrails.
2. Scoped rate-limit policy for auth, user reads, user mutations, payment config, and checkout.
3. Side-effect GET compatibility aliases.
4. Payment callback/checkout fixture coverage.
5. Payment callback failure telemetry.
6. Feature-flagged checkout in-flight idempotency lock.

## Production behavior changes made

### Rate limits

Added or expanded named limiters:

- `passport-register`
- `passport-forget`
- `passport-quick-login`
- `user-mutation`
- `payment-config`
- `payment-checkout`

All respect:

- `API_RATE_LIMITS_ENABLED`

### Checkout lock

Added a feature-flagged in-flight lock around authenticated checkout:

- `API_PAYMENT_CHECKOUT_LOCK_ENABLED=true`
- `API_PAYMENT_CHECKOUT_LOCK_SECONDS=15`

Scope:

- `POST /api/v1/user/order/checkout`

Behavior:

- normal checkout keeps raw `{type, data}` response.
- concurrent duplicate checkout returns existing fail envelope style.
- provider checkout result is not cached.
- payment callbacks are unaffected.

### Callback telemetry

Payment callback verification failures now emit a structured warning log:

- no raw payload values.
- no plaintext payment UUID.
- logs method, hashed uuid, reason, request id, ip, payload keys, and exception class/code where applicable.

Callback responses remain unchanged.

## Test coverage added

Payment fixture tests:

- `tests/Support/Payment/SyntheticPaymentPlugin.php`
- `tests/Feature/SyntheticPaymentPluginContractTest.php`
- `tests/Feature/PaymentCheckoutCallbackFixtureTest.php`
- `tests/Feature/EpayPaymentCallbackFixtureTest.php`
- `tests/Feature/MgatePaymentCallbackFixtureTest.php`
- `tests/Feature/CoinPaymentsPaymentCallbackFixtureTest.php`
- `tests/Feature/CoinbasePaymentCallbackFixtureTest.php`
- `tests/Feature/AlipayF2fPaymentCallbackFixtureTest.php`
- `tests/Feature/BtcpayPaymentCallbackFixtureTest.php`

Security/route contract tests:

- `tests/Feature/ApiSecurityPilotTest.php`
- `tests/Feature/ApiSensitiveFieldLeakageContractTest.php`

## Verification evidence

Repeatedly passed during the phase:

```bash
php -l changed PHP files
git diff --check
./vendor/bin/phpunit <targeted payment/security fixture set>
./scripts/dev-up.sh && BASE_URL=http://127.0.0.1:8001 ./scripts/e2e-smoke.sh
```

Latest targeted PHPUnit set:

- 36 tests
- 388 assertions
- passing

Latest E2E smoke:

- public root 404
- admin shell 200
- App BFF bootstrap/session/dashboard
- V1/V2 auth
- user info/stat/traffic
- tickets/invites/orders/notices/knowledge
- guest config/plan
- raw subscription payload
- Telegram webhook
- payment notify controller boundary
- passing

## Known non-blocking local issue

Local CLI/E2E emits duplicate PHP module warnings:

```text
Module "sqlite3" is already loaded
Module "pdo_sqlite" is already loaded
```

Impact:

- tests and E2E passed.
- this appears to be PHP ini duplication, not application behavior.

Recommended cleanup:

- inspect CLI ini files with `php --ini`.
- remove the duplicate sqlite extension entry.
- do not mix this environment cleanup with API behavior changes.

## Explicitly not completed

### AES response encryption

Not implemented.

Before AES:

- define endpoint allow/deny list.
- exclude raw subscription, callbacks, redirects, node/server protocols where needed.
- define key management and rotation.
- define timestamp/nonce/signature/replay model.
- update DK_Theme and hiddify-app clients.
- add rollout flag and fallback behavior.

### Full provider checkout HTTP seams

Callback fixtures are now broad, but checkout paths that perform live network calls still need safe HTTP seams before deeper checkout rewrites:

- `MGate`
- `Coinbase`
- `BTCPay`
- `AlipayF2F`

### Payment callback behavior changes

Not implemented:

- callback response normalization.
- callback method narrowing.
- replay rejection.
- provider plugin rewrites.

## Recommended next decision

Choose one:

1. **Deployment packaging path**
   - build/push Xboard image from current commits.
   - deploy to server.
   - monitor checkout/callback logs.

2. **AES design path**
   - write threat model and rollout plan only.
   - do not implement encryption until client migration is approved.

3. **Payment hardening continuation**
   - add HTTP seams for checkout provider tests.
   - avoid changing provider behavior until those tests exist.
