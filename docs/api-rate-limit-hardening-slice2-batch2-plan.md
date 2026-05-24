# Phase 6 Slice 2 Batch 2 User-read Throttle Plan

Date: 2026-05-24

## Scope

Apply the existing `throttle:user-read` limiter to the next low-risk authenticated read routes that already have route-contract and/or E2E smoke coverage.

## Include in batch 2

- `GET /api/v1/user/plan/fetch`
- `GET /api/v1/user/invite/fetch`
- `GET /api/v1/user/invite/details`
- `GET /api/v1/user/ticket/fetch`

## Why these routes

- They are authenticated reads.
- They do not return raw subscription payloads or node/server protocol payloads.
- They do not execute checkout/payment callbacks.
- Invite and ticket reads are already covered by E2E smoke and Phase 5 read-model tests.
- Plan reads are backed by `PlanServiceCapacityTest`; add user-plan smoke coverage in this batch.

## Explicit exclusions

Do not change these in batch 2:

- `GET /api/v1/user/getSubscribe`
- `GET /api/v1/user/server/fetch`
- `GET /api/v1/user/invite/save`
- ticket/order mutation routes
- gift-card/coupon mutation routes
- Telegram, comm/config, Stripe public key routes
- passport/auth routes
- callback/server/subscription/admin routes
- AES or response envelopes

## Verification

- Route-middleware assertions in `ApiSecurityPilotTest`.
- Targeted PHPUnit for API security and affected read contracts.
- E2E smoke including user plan fetch, invite reads, and ticket reads.
