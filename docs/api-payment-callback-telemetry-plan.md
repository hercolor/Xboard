# Payment Callback Failure Telemetry Plan

Date: 2026-05-24

## Objective

Add minimal security telemetry for failed payment callback verification without changing callback responses or provider behavior.

## Implemented slice

- Add a structured warning log when provider verification returns false.
- Add the same structured warning log before existing exception logging in callback handling.
- Preserve the existing failure responses:
  - verification false: existing `verify error` envelope.
  - exception: existing `fail` envelope.

## Logged fields

The warning context intentionally avoids raw callback payload values:

- `method`
- `uuid_hash`
- `reason`
- `ip`
- `request_id`
- `payload_keys`
- exception class/code for exception paths

## Explicit non-goals

- No callback response shape changes.
- No provider plugin changes.
- No raw payload logging.
- No payment UUID logging in plaintext.
- No checkout behavior changes.
- No AES.

## Follow-up options

- Add rate-limited callback anomaly counters.
- Add admin-visible audit summaries for repeated callback verification failures.
- Add checkout idempotency design before changing checkout mutation behavior.
