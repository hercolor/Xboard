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

## 6. Phase 2 scoped channel hardening

> Date: 2026-05-24
> Scope: additive route-level rate-limit coverage for previously isolated infrastructure channels.
> Compatibility rule: keep legacy response bodies and raw subscription/protocol outputs unchanged; only add bounded 429 protection when a caller exceeds the configured budget.

### 6.1 Added limiters

| Limiter | Default | Key | Routes | Rollback |
| --- | --- | --- | --- | --- |
| `admin-login` | 10/min | IP + normalized email | `POST /api/v2/{secure_path}/auth/login` | `API_RATE_LIMITS_ENABLED=false` or remove route middleware |
| `admin-api` | 240/min | IP + authenticated admin id | `/api/v2/{secure_path}/auth/me`, `/logout`, and protected admin API group | `API_RATE_LIMITS_ENABLED=false` or remove route middleware |
| `subscription` | 60/min | IP + SHA1(token) | `/s/{token}`, `/api/v1/client/subscribe` | `API_RATE_LIMITS_ENABLED=false` or remove route middleware |
| `server-node` | 300/min | IP + node/machine id + SHA1(token) | V1/V2 node server endpoints | `API_RATE_LIMITS_ENABLED=false` or remove route middleware |
| `server-machine` | 120/min | IP + machine id + SHA1(token) | V2 machine `nodes` and `status` endpoints | `API_RATE_LIMITS_ENABLED=false` or remove route middleware |
| `callback` | 120/min | IP + callback method | Telegram webhook and payment notify routes | `API_RATE_LIMITS_ENABLED=false` or remove route middleware |

Environment overrides:

```env
API_RATE_LIMIT_ADMIN_LOGIN_PER_MINUTE=10
API_RATE_LIMIT_ADMIN_API_PER_MINUTE=240
API_RATE_LIMIT_SUBSCRIPTION_PER_MINUTE=60
API_RATE_LIMIT_SERVER_NODE_PER_MINUTE=300
API_RATE_LIMIT_SERVER_MACHINE_PER_MINUTE=120
API_RATE_LIMIT_CALLBACK_PER_MINUTE=120
```

### 6.2 Non-goals preserved

- No AES response wrapping.
- No App BFF envelope on legacy, subscription, node/server, payment, or Telegram routes.
- No raw subscription format change.
- No node/server payload shape change.
- No payment provider response shape change.

## 7. Phase 3 request-size and trace safety slice

> Date: 2026-05-24
> Scope: additive request-size guard, API trace header, and broader audit redaction.
> Compatibility rule: do not change legacy response bodies, raw subscription payloads, node/server payload shapes, or callback controller behavior for normal-sized valid requests.

### 7.1 Request-size budgets

| Channel | Default max bytes | Applied to | Rollback |
| --- | ---: | --- | --- |
| `passport` | 65,536 | V1/V2 passport groups and admin login | `API_REQUEST_SIZE_LIMITS_ENABLED=false` or route middleware removal |
| `app` | 65,536 | `/api/app/v1/*` BFF routes | `API_REQUEST_SIZE_LIMITS_ENABLED=false` or route middleware removal |
| `admin` | 2,097,152 | protected admin API and admin auth reads | `API_REQUEST_SIZE_LIMITS_ENABLED=false` or route middleware removal |
| `server` | 1,048,576 | V1/V2 node/server and machine routes | `API_REQUEST_SIZE_LIMITS_ENABLED=false` or route middleware removal |
| `callback` | 262,144 | payment notify and Telegram webhook | `API_REQUEST_SIZE_LIMITS_ENABLED=false` or route middleware removal |

Environment overrides:

```env
API_REQUEST_SIZE_LIMITS_ENABLED=true
API_REQUEST_SIZE_PASSPORT_MAX_BYTES=65536
API_REQUEST_SIZE_APP_MAX_BYTES=65536
API_REQUEST_SIZE_ADMIN_MAX_BYTES=2097152
API_REQUEST_SIZE_SERVER_MAX_BYTES=1048576
API_REQUEST_SIZE_CALLBACK_MAX_BYTES=262144
```

### 7.2 Trace header

- API middleware now preserves a safe incoming `X-Request-Id` or generates a UUID.
- The same trace id is returned as the `X-Request-Id` response header.
- App BFF envelopes continue to expose `meta.trace_id`; legacy response bodies are unchanged.

### 7.3 Redaction expansion

Admin audit redaction now also covers access/refresh tokens, subscription tokens, node/server/machine tokens, webhook/client secrets, and private keys, including camelCase and dot/hyphen variants.
