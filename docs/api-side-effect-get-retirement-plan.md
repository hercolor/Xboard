# Phase 6 Side-effect GET Retirement Plan

Date: 2026-05-24

## Goal

Introduce mutation-safe POST aliases for legacy GET routes that perform writes, while preserving old GET routes for DK_Theme/hiddify-app compatibility.

## Routes

| Legacy GET | New POST alias | Behavior |
| --- | --- | --- |
| `GET /api/v1/user/resetSecurity` | `POST /api/v1/user/resetSecurity` | Same controller/action and response shape. |
| `GET /api/v2/user/resetSecurity` | `POST /api/v2/user/resetSecurity` | Same V2 compatibility route using the existing controller/action. |
| `GET /api/v1/user/invite/save` | `POST /api/v1/user/invite/save` | Same controller/action and response shape. |

## Constraints

- Do not remove or redirect the legacy GET routes.
- Do not change response envelopes or payloads.
- Do not rotate subscription tokens in tests unless later requests are updated to use the new token.
- Do not alter subscription delivery, server/node routes, checkout, callbacks, auth semantics, or AES.

## Current implementation decision

- Keep GET routes mounted unchanged for compatibility.
- Add POST aliases with `throttle:user-mutation`.
- Add route contract tests only; E2E continues to prove legacy GET compatibility.

## Future migration

After DK_Theme/hiddify-app move to POST:

1. Add deprecation headers/logging to GET routes.
2. Observe production logs for remaining GET callers.
3. Only then consider stricter throttling or disabling GET behind a feature flag.
