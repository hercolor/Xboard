# Phase 6 User Mutation Throttle Policy

Date: 2026-05-24

## Goal

Add a scoped throttle for authenticated user mutation endpoints without changing payloads, response envelopes, route methods, subscription delivery, checkout/payment execution, callbacks, or AES.

## Limiter

| Limiter | Default | Key |
| --- | ---: | --- |
| `user-mutation` | 60/min | IP + authenticated user id |

The existing `API_RATE_LIMITS_ENABLED` kill switch controls this limiter.

## Include in first mutation batch

Authenticated mutation routes that do not execute payment checkout or raw protocol/callback behavior:

- Account/session: `changePassword`, `update`, `transfer`, `getQuickLoginUrl`, `removeActiveSession`
- Orders: `order/save`, `order/cancel`
- Tickets: `ticket/save`, `ticket/reply`, `ticket/close`, `ticket/withdraw`
- Coupon/gift-card actions: `coupon/check`, `gift-card/check`, `gift-card/redeem`

## Explicit exclusions

Do not change these in this batch:

- `POST /api/v1/user/order/checkout` — payment execution/provider behavior.
- `GET /api/v1/user/resetSecurity` and `GET /api/v1/user/invite/save` — side-effect GETs need POST aliases and client migration first.
- `POST /api/v1/user/comm/getStripePublicKey` — payment-adjacent config lookup; handle with payment-specific policy.
- Subscription delivery, server/node APIs, guest callbacks, admin routes, passport routes, AES, response envelopes.

## Verification

- Register `user-mutation` limiter with kill-switch coverage in `ApiSecurityPilotTest`.
- Add route-middleware assertions for included routes.
- Run E2E smoke to prove existing DK_Theme/hiddify-app-compatible flows still pass.
