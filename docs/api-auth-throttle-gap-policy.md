# Phase 6 Auth Throttle Gap Policy

Date: 2026-05-24

## Goal

Close the lowest-risk unauthenticated passport throttle gaps without changing auth payloads, response envelopes, routes, or DK_Theme/hiddify-app login behavior.

## Current coverage

Already throttled:

- `POST /api/v1|v2/passport/auth/login` → `throttle:passport-login`
- `POST /api/v1|v2/passport/comm/sendEmailVerify` → `throttle:passport-email`

Gaps:

- `POST /api/v1|v2/passport/auth/register`
- `POST /api/v1|v2/passport/auth/forget`
- `POST /api/v1|v2/passport/auth/getQuickLoginUrl`
- `POST /api/v1|v2/passport/auth/loginWithMailLink`

## Policy decision

Add scoped limiters:

| Limiter | Target routes | Default | Key |
| --- | --- | ---: | --- |
| `passport-register` | register | 10/min | IP + lowercased email |
| `passport-forget` | forget password | 10/min | IP + lowercased email |
| `passport-quick-login` | passport quick-login URL | 30/min | IP + hash of `auth_data` or `Authorization` |
| reuse `passport-email` | login with mail link | 3/min | IP + lowercased email |

## Explicit exclusions

- Do not throttle `token2Login` in this slice; it is a redirect/verification route and needs a separate redirect compatibility test.
- Do not change `POST /api/v1/user/getQuickLoginUrl`; it belongs to the authenticated user mutation policy.
- Do not change auth response bodies, auth token generation, register/forget validation, email verification, or AES.

## Verification

- Extend `ApiSecurityPilotTest` limiter registration and route-middleware assertions.
- Run existing auth E2E smoke: login, register, sendEmailVerify, forget, getQuickLoginUrl, token2Login redirect.
