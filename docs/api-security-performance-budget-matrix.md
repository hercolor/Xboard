# API Security and Performance Budget Matrix

> Date: 2026-05-20
> Scope: Phase 0 baseline and Phase 1 security pilot for `docs/api-architecture-optimization-plan.md`.
> Rule: route-scoped controls only. Do not globally throttle, wrap, or encrypt legacy API channels.

## 1. First-slice decision

The first execution slice uses conservative route-level controls:

- Add named rate limiters with a kill switch: `API_RATE_LIMITS_ENABLED=false`.
- Apply the pilot only to selected passport, user read, and App BFF read routes.
- Keep raw subscription, node/server, payment notify, Telegram webhook, and plugin-sensitive channels outside the pilot.
- Improve admin audit log redaction recursively without changing admin API responses.

## 2. Channel budget matrix

| Channel | Current routes | Auth model | Pilot policy | Response/payload budget | Compatibility rule |
| --- | --- | --- | --- | --- | --- |
| App BFF read | `/api/app/v1/bootstrap`, `/api/app/v1/session` | public / `user` | `throttle:app-read`, default 120/min per IP+user | session target <= 5 queries, <= 30 KB raw JSON, local p95 <= 200 ms | App envelope only under `/api/app/v1/*`; dashboard remains disabled |
| Passport login | `/api/v1/passport/auth/login`, `/api/v2/passport/auth/login` | pre-auth | `throttle:passport-login`, default 20/min per IP+email | preserve legacy success/fail JSON | Required by DK_Theme and hiddify-app; no response shape changes |
| Passport email verify | `/api/v1/passport/comm/sendEmailVerify`, `/api/v2/passport/comm/sendEmailVerify` | pre-auth | `throttle:passport-email`, default 3/min per IP+email | preserve legacy success/fail JSON | Keep existing cache cooldown semantics |
| User read pilot | `/api/v1/user/info`, `/api/v2/user/info` | `user` middleware | `throttle:user-read`, default 120/min per IP+user | no payload shape change | Required by DK_Theme and V2 compatibility |
| Legacy user heavy reads | orders, tickets, traffic logs, knowledge | `user` middleware | no route change in this slice | future bounded endpoints only after compatibility review | Do not silently paginate arrays used by DK_Theme |
| Admin API | `/api/v2/{secure_path}/*` | `admin` + audit log | no rate-limit route change in this slice | future per-admin/IP policy | This slice only improves recursive redaction |
| Raw subscription | `/s/{token}`, `/api/v1/client/subscribe` | subscription token | no throttle in this pilot | raw protocol output, not JSON | Never App-envelope or AES-wrap in legacy channel |
| Node/server | `/api/v1/server/*`, `/api/v2/server/*` | server/machine token | no throttle in this pilot | protocol-specific | Needs separate node cadence budget |
| Payment/Telegram callbacks | guest notify/webhook routes | provider/token callback | no throttle in this pilot | provider-specific response | Do not wrap in App BFF envelope |

## 3. Rate-limit names and rollback

| Limiter | Default | Key | Rollback |
| --- | --- | --- | --- |
| `passport-login` | 20/min | IP + normalized email | `API_RATE_LIMITS_ENABLED=false` or remove route middleware |
| `passport-email` | 3/min | IP + normalized email | `API_RATE_LIMITS_ENABLED=false` or remove route middleware |
| `user-read` | 120/min | IP + authenticated user id | `API_RATE_LIMITS_ENABLED=false` or remove route middleware |
| `app-read` | 120/min | IP + user id/guest | `API_RATE_LIMITS_ENABLED=false` or remove route middleware |

Environment overrides:

```env
API_RATE_LIMITS_ENABLED=true
API_RATE_LIMIT_PASSPORT_LOGIN_PER_MINUTE=20
API_RATE_LIMIT_PASSPORT_EMAIL_PER_MINUTE=3
API_RATE_LIMIT_USER_READ_PER_MINUTE=120
API_RATE_LIMIT_APP_READ_PER_MINUTE=120
```

## 4. Sensitive redaction policy

Admin audit logs must redact sensitive keys recursively before writing `request_data`.

Redacted examples:

- `password`, `password_confirmation`
- `token`, `*_token`
- `secret`, `*_secret`
- `key`, `api_key`, `*_key`
- `auth_data`, `authorization`
- `subscribe_url`, `subscribeUrl`, `subscription_url`

Safe non-secret display fields such as `name`, `description`, `host`, and provider display names remain visible unless their key matches the sensitive rules.

## 5. Verification gates for this slice

Required before completion:

1. Route middleware tests prove the pilot is scoped only to selected passport/user/app routes.
2. Tests prove no-touch routes do not receive pilot throttle middleware.
3. Tests prove named rate limiters exist and the kill switch returns unlimited limits.
4. Tests prove recursive audit redaction removes nested secrets and preserves safe fields.
5. Route list still shows only `/api/app/v1/bootstrap` and `/api/app/v1/session` for App BFF.
6. Existing App/Admin/no-touch compatibility tests pass.
7. Full PHPUnit suite passes.
8. E2E smoke passes before claiming runtime compatibility.
