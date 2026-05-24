# AES Response Encryption Decision Gate

Date: 2026-05-24

## Decision

Do **not** apply AES response encryption globally to existing Xboard APIs.

If response encryption is still required, implement it only as an opt-in, versioned channel for clients that are explicitly migrated and tested, preferably under a new App/BFF route family rather than legacy shared APIs.

## Why global AES is rejected

Global encryption would break or destabilize multiple protocol classes:

- raw subscription output:
  - `/s/{token}`
  - `/api/v1/client/subscribe`
- node/server protocol APIs:
  - `/api/v1/server/*`
  - `/api/v2/server/*`
- payment callbacks:
  - `/api/v1/guest/payment/notify/{method}/{uuid}`
- Telegram/webhook callbacks.
- redirect flows:
  - token login redirects.
- admin/download/export or file-like responses.
- legacy DK_Theme / hiddify-app clients that expect existing JSON envelopes.

It would also make production debugging, provider callbacks, proxy/CDN behavior, and partial rollout harder.

## Threat model

### What AES response encryption can help with

- Make casual response inspection harder in hostile client-side environments.
- Reduce exposure from accidental logs/proxies that capture body content.
- Add a migration point for replay/tamper controls if paired with nonce/signature.

### What AES response encryption does not solve

- It does not replace HTTPS.
- It does not secure a static key embedded in a public frontend/app bundle.
- It does not prevent a rooted/jailbroken device or modified app from extracting the key.
- It does not protect raw subscription/node protocols unless those clients are also redesigned.
- It does not fix authorization, object-level access control, or sensitive-field leakage.

## Required design if encryption proceeds

### Route scope

Only allow encryption on an opt-in route group, for example:

- `/api/app/v2/*`

Do not encrypt:

- `/api/v1/*` legacy user/passport/guest APIs.
- `/api/v2/*` admin or reused legacy APIs.
- `/api/v1/client/subscribe`
- `/{subscribe_path}/{token}`
- `/api/v1/server/*`
- `/api/v2/server/*`
- `/api/v1/guest/payment/notify/*`
- `/api/v1/guest/telegram/webhook`
- redirects, downloads, streams, or plain-text protocol payloads.

### Client opt-in

Use explicit negotiation:

- request header: `X-App-Encryption: aes-gcm-v1`
- request header: `X-App-Key-Id: <key id>`
- response header: `X-App-Encryption: aes-gcm-v1`
- response header: `X-App-Key-Id: <key id>`

If the client does not opt in, return the normal JSON response for compatibility.

### Envelope

Encrypted responses should still be JSON so gateways and clients can classify them:

```json
{
  "encrypted": true,
  "alg": "AES-256-GCM",
  "kid": "app-v1",
  "nonce": "base64url-12-bytes",
  "ciphertext": "base64url",
  "tag": "base64url",
  "ts": 1770000000
}
```

The plaintext should be the existing response body JSON exactly as the server would otherwise return.

### Integrity and replay

AES-GCM provides authenticated encryption for the response body, but replay control still needs:

- timestamp window.
- nonce uniqueness.
- optional response signature or server-side nonce cache for high-risk routes.
- client rejection of stale timestamps.

### Key management

Minimum requirements:

- key id (`kid`) support.
- rotation plan with active and previous keys.
- environment/config storage, not source code.
- no single static key hardcoded into public DK_Theme web assets.
- separate keys per client family if both DK_Theme and hiddify-app opt in.

Recommended:

- mobile app key bootstrapping via authenticated session plus device/app attestation where feasible.
- web frontend encryption only if the goal is accidental-log reduction, not strong secrecy.

### Error handling

Define upfront:

- auth errors before encryption negotiation may remain normal JSON.
- validation errors after opt-in can be encrypted.
- server exceptions should avoid leaking details in encrypted or plaintext mode.
- tracing should use headers (`X-Request-Id`) and not require decrypting logs.

### Observability

Do not log plaintext encrypted payloads.

Log only:

- route name.
- status code.
- key id.
- encryption mode.
- payload size.
- trace id.
- failure reason.

## Migration plan

### Phase AES-0 — Design only

Current document.

Acceptance:

- no runtime changes.
- explicit route exclusions.
- client migration requirements documented.

### Phase AES-1 — Server skeleton behind disabled flag

Add middleware and config only:

- `API_RESPONSE_ENCRYPTION_ENABLED=false`
- opt-in route group only.
- tests proving disabled mode returns unchanged JSON.

No client rollout.

### Phase AES-2 — App/BFF pilot

Apply only to a new App/BFF endpoint pair, not legacy APIs.

Candidate:

- `/api/app/v2/session`
- `/api/app/v2/bootstrap`

Requirements:

- DK_Theme/hiddify-app fallback tests.
- encrypted + plaintext contract tests.
- trace id and error behavior tests.

### Phase AES-3 — Client opt-in

Only after clients implement:

- key id selection.
- decrypt failure fallback/reporting.
- timestamp validation.
- test fixtures.

### Phase AES-4 — Expand only if justified

Expand endpoint-by-endpoint, never globally.

## Test requirements before any implementation

- middleware disabled-mode compatibility tests.
- opt-in encrypted response shape tests.
- decrypt round-trip tests.
- excluded route tests for subscription, server, callbacks, redirects.
- DK_Theme fallback tests.
- hiddify-app fallback tests.
- E2E smoke proving legacy flows remain unchanged.

## Final recommendation

Do not implement AES on legacy APIs.

If the business still requires encryption, start with **Phase AES-1 server skeleton behind a disabled flag** and only on a new opt-in App/BFF route group.
