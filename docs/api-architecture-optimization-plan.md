# Ralplan: Xboard API Architecture Optimization Plan

> Date: 2026-05-20  
> Role: Architect / consensus planning  
> Scope: Xboard backend APIs plus separated clients DK_Theme and hiddify-app.  
> Goal: improve API security, concurrency capacity, and response speed without breaking existing clients.

## 0. Planning boundary

This is a planning artifact only. It does not implement code.

### Skip / preserve already-completed work

Skip these completed or explicitly frozen tracks:

- Admin-only Web shell: `/` remains public 404 and admin path remains configurable.
- App BFF bootstrap/session: `/api/app/v1/bootstrap` and `/api/app/v1/session` already exist.
- App BFF dashboard: `GET /api/app/v1/dashboard` remains absent and disabled until the Phase 2 audit prerequisites are met.
- First Admin API cleanup: V2 `plan`, `notice`, `knowledge`, `coupon`, `mail-template` first-pass cleanup is already done.
- Route/controller consistency gaps previously documented and patched.

### Non-breakable contracts

Do not break:

- DK_Theme legacy contracts under `/api/v1/passport/*`, `/api/v1/user/*`, `/api/v1/guest/*`.
- hiddify-app login/subscription/customer-service flow.
- `/s/{token}` raw subscription output.
- `/api/v1/client/subscribe?token=...` raw subscription output.
- V1/V2 node/server protocols.
- Payment notify/callback routes.
- Telegram webhook.
- Plugin hooks and plugin response filters.

Explicit non-goals:

- No global AES response wrapping.
- No global response-envelope replacement for legacy APIs.
- No deletion of legacy routes in the first optimization waves.
- No frontend/App migration without feature flags and fallbacks.

## 1. RALPLAN-DR Summary

### Principles

1. **Channel isolation before global policy**: user, admin, app BFF, node/server, subscription, payment, and webhook channels need different security/performance policies.
2. **Additive migration over contract mutation**: new optimized paths can be added, but existing V1/V2/shared clients must continue working.
3. **Measure before optimizing hot paths**: add observability and budgets first, then optimize the endpoints that data proves are hot.
4. **Bounded reads, async writes**: API response speed improves by capping list/query shapes and moving non-critical work to queues.
5. **Security gates must be scoped and testable**: rate limits, token hygiene, redaction, and audit logging should have explicit tests per channel.

### Decision drivers

1. **Compatibility risk**: DK_Theme and hiddify-app are already separated but still depend on legacy V1 contracts.
2. **Operational load profile**: Octane/Horizon/Redis already exist, so the best leverage is route policies, queue boundaries, and read-model/caching, not runtime replacement.
3. **Current architecture debt**: V2 still reuses V1 controllers, API throttling is disabled in the global API group, and several user/stat endpoints return unbounded or live-computed data.

### Viable options

#### Option A — Conservative layered hardening (recommended)

Add scoped security/rate-limit policies, observability, query budgets, read models, cache, and async boundaries in waves. Preserve legacy responses. Use `/api/app/v1/*` for new frontend/App BFF surfaces only after field audits.

Pros:

- Lowest breakage risk.
- Matches existing docs and completed work.
- Allows measurable improvement per endpoint.
- Keeps DK_Theme/hiddify-app stable while preparing opt-in migration.

Cons:

- More incremental work.
- Legacy route debt remains longer.
- Requires discipline to avoid mixing global and scoped changes.

#### Option B — New API gateway/BFF-first migration

Build a larger `/api/app/v1` or `/api/v3` façade with strict contracts, rate limits, and cached read models, then migrate DK_Theme/hiddify-app off legacy endpoints.

Pros:

- Cleaner final contracts.
- Easier to optimize and secure new endpoints.
- Better long-term developer experience.

Cons:

- Higher migration risk.
- Requires client changes and feature flags.
- Does not immediately reduce legacy endpoint risk because old clients still call V1/V2.

#### Option C — Global middleware rewrite

Enable global throttling, global envelope/error changes, global encryption, and global response filters across all API channels.

Pros:

- Fastest apparent centralization.
- Small number of touched files.

Cons:

- High breakage risk for subscription, payment, node/server, Telegram, and legacy clients.
- Contradicts current constraints.
- Hard to test all implicit protocol consumers.

Option C is rejected. Option A is the execution baseline; Option B remains a future migration lane after A creates safe read models and measurement.

## 2. Target architecture

```text
Internet / Clients
├─ Admin SPA
│  └─ /api/v2/{secure_path}/*
│     ├─ admin auth/rbac/rate limits
│     ├─ audit redaction
│     └─ paginated/cached admin read APIs
├─ DK_Theme Web
│  ├─ legacy /api/v1/* initially
│  └─ optional /api/app/v1/* behind feature flag later
├─ hiddify-app
│  ├─ legacy /api/v1/passport/auth/login
│  ├─ legacy /api/v1/user/getSubscribe
│  └─ raw /api/v1/client/subscribe or /s/{token}
├─ Node/server agents
│  └─ /api/v1/server/* and /api/v2/server/* protocol-specific limits
├─ Payment/Telegram callbacks
│  └─ isolated callback verification, no BFF envelope
└─ Subscription delivery
   └─ raw protocol output, cacheable where safe, never JSON-wrapped
```

Optimization layers:

1. **Policy layer**: channel-specific throttle, auth, request-size, CORS/trusted proxy, redaction.
2. **Contract layer**: legacy contracts frozen; App BFF contracts additive and audited.
3. **Read-model layer**: safe, bounded read services for session/dashboard/admin stats/user lists.
4. **Performance layer**: cache, pagination, indexes, query-count budgets, Octane/Horizon tuning.
5. **Observability layer**: trace IDs, request metrics, slow query logs, queue metrics, security event logs.

## 3. Phase plan

### Phase 0 — Baseline observability and safety budgets

Purpose: know what is slow or risky before changing behavior.

Deliverables:

1. Add a lightweight API observation design doc and test matrix:
   - endpoint group
   - auth model
   - expected response type
   - p95 target
   - max payload size
   - query budget if measurable
   - rate-limit policy
2. Add scoped request timing and query-count instrumentation in local/test/dev first:
   - do not log secrets
   - sample or disable in production unless configured
   - include `trace_id` for App BFF and optional legacy response header only
3. Define budgets:
   - App BFF session: <= 5 queries, <= 30 KB raw JSON, p95 <= 200 ms local baseline.
   - Future App dashboard: use existing Phase 2 audit: 8-12 queries target, <= 120 KB raw JSON.
   - User list endpoints: bounded page size; no unbounded list for orders/tickets/traffic logs in new endpoints.
   - Admin stats: no more than one live aggregate burst per request unless cached.
4. Establish verification commands:
   - route list snapshots by channel
   - targeted feature tests
   - E2E smoke
   - static checks
   - optional load script for selected endpoints

Acceptance criteria:

- A baseline report exists with measured route list and endpoint risk classes.
- No API behavior changes.
- Sensitive values are redacted from any new logs.


## 3.1 First executable slice guardrail

The first implementation slice after this plan must be intentionally small:

```text
Scope: Phase 0 baseline + Phase 1 security pilot only.
Allowed: docs/matrix, tests, redaction hardening, route-level rate-limit pilot on selected low-risk passport/app/user routes.
Forbidden: global API throttle, global response wrapping, AES, dashboard aggregate, legacy payload reshaping, subscription/node/payment/Telegram behavior changes.
Stop condition: compatibility smoke and targeted security tests pass, and the rate-limit pilot has an environment/config kill switch.
```

Mandatory slice controls:

- Every new security/cache/rate-limit behavior must be disableable by config or narrow route removal.
- Production observability must avoid high-cardinality logs and full payload logging by default.
- DB index work must follow slow-query evidence; do not add broad indexes speculatively.
- Each execution slice must rerun no-touch checks for raw subscription, client subscribe, node/server, payment notify, Telegram webhook, and plugin-sensitive routes.
- Client migration requires feature flag, parser/contract tests, fallback path, and rollback note before release.

### Phase 1 — Scoped security hardening

Purpose: improve security without changing response contracts.

Deliverables:

1. Channel-specific rate-limit plan, not a single global throttle:
   - passport login/register/forget/email verify: strict per IP + per email/account; preserve existing cache-based cooldowns but centralize tests.
   - authenticated user API: moderate per user + per IP.
   - admin API: stricter per admin + IP, with exemption only for long exports if needed.
   - node/server APIs: per token/machine/node, tuned for report cadence.
   - subscription raw delivery: per token/IP with cache-aware behavior; avoid blocking legitimate client refresh bursts.
   - payment/Telegram callbacks: signature/token verification first; rate-limit by provider/IP only where safe.
2. Auth/token hygiene:
   - never log full `auth_data`, subscription token, node token, payment secrets, or server host credentials.
   - add redaction tests for `RequestLog` and any new logs.
   - review token rotation endpoints (`resetSecurity`) as mutation-only and keep GET compatibility documented; add future POST alias before deprecating GET.
3. Request validation hardening:
   - continue FormRequest expansion in modules not yet cleaned.
   - bound `current`, `pageSize`, `per_page`, date windows, keyword lengths.
4. Admin audit safety:
   - keep admin audit insert non-blocking or fail-open as today, but improve redaction for nested keys (`password`, `token`, `secret`, `key`, `api_key`, `auth_data`, `subscribe_url`).
5. Callback boundaries:
   - explicitly test payment notify and Telegram webhook remain outside App BFF envelope.

Recommended first execution slice:

- Add documented rate-limit matrix and tests for selected passport/user/app routes.
- Enable route-level throttle middleware only on a narrow low-risk subset first; do not uncomment global API throttle for every route.
- Add an explicit rollback path: config flag, route-level middleware removal, or environment override for the pilot limits.

Acceptance criteria:

- Login/email verification abuse is rate-limited with tests.
- Existing DK_Theme/hiddify login/register/forget flows still pass E2E smoke.
- Subscription, payment, Telegram, and node callbacks remain callable.

### Phase 2 — Legacy read bounding and pagination compatibility

Purpose: reduce memory spikes and response size while preserving old contracts.

Deliverables:

1. Identify unbounded legacy reads that are frontend-visible:
   - `/api/v1/user/order/fetch`
   - `/api/v1/user/ticket/fetch`
   - `/api/v1/user/stat/getTrafficLog`
   - `/api/v1/user/knowledge/fetch` list body payload
   - selected admin/stat live aggregate endpoints
2. Do not silently paginate legacy endpoints if clients expect arrays. Instead:
   - add optional query parameters (`limit`, `current`, `pageSize`, `from`, `to`) with safe default caps only after source compatibility review; or
   - add App BFF read endpoints with bounded shapes and leave legacy untouched.
3. For DK_Theme, prefer opt-in feature flag migration to bounded App BFF/read endpoints rather than changing legacy payload shape.
4. For hiddify-app, keep core subscription path untouched unless app is explicitly migrated.
5. Add query-budget tests for new bounded endpoints and payload cap tests for seeded data.

Acceptance criteria:

- No legacy array-to-pagination break unless all consumers are migrated.
- New bounded read paths have list caps and sensitive-field denylist tests.
- E2E smoke confirms legacy flows still work.

### Phase 3 — Response-speed read models and cache

Purpose: improve p95 latency for high-read endpoints.

Deliverables:

1. Extract safe read models/services:
   - `AppSessionReadModel` for current `/api/app/v1/session` payload.
   - Future `AppDashboardReadModel` only after dashboard audit prerequisites are met.
   - `AdminStatsReadModel` for dashboard/stat pages.
   - `SubscriptionSummaryReadModel` that never exposes raw token unless endpoint explicitly requires it.
2. Cache policy by data class:
   - settings/config: cache until settings update invalidates.
   - plans/public notices/knowledge category lists: short TTL + invalidation on admin mutation.
   - subscription/session user-specific summaries: short TTL or no cache depending on traffic freshness requirements.
   - node/server lists: short TTL, avoid stale secrets.
   - admin stats: TTL 30-120 seconds or scheduled snapshots for expensive aggregates.
3. Add ETag/Last-Modified or explicit cache headers only where clients can safely reuse responses:
   - public config/plan/notice may be candidates.
   - authenticated sensitive responses usually should not be browser/shared cached.
   - raw subscriptions can be cached per token only if invalidation on token reset is reliable.
4. Reduce duplicated DB reads:
   - select only needed columns.
   - eager-load safe relations with selected columns.
   - replace collection counts with DB counts.
   - cap latest lists.

Acceptance criteria:

- Selected hot read endpoints show measured p95 improvement against Phase 0 baseline.
- Cache invalidation tests cover admin mutation -> read freshness.
- No token/credential leakage via cached responses.
- Every new cache has a documented TTL, invalidation trigger, and bypass/disable strategy.

### Phase 4 — Concurrency and queue boundaries

Purpose: keep request threads free and make high-write/high-report paths stable under load.

Deliverables:

1. Review all synchronous work in request path:
   - email sends already queued; keep and verify queue selection.
   - payment checkout must remain synchronous enough for provider response but non-critical side effects should queue.
   - ticket/Telegram notifications should queue.
   - node traffic/stat writes should remain batched/queued where possible.
2. Horizon topology:
   - keep separate queues: data pipeline, business, notification, stat, traffic_fetch, node_sync.
   - document recommended worker caps for `minimal`, `balanced`, `performance` profiles.
   - add queue wait-time alert thresholds.
3. Concurrency correctness:
   - keep transactions/locks for order payment, traffic reset, balance transfer.
   - add idempotency keys or duplicate protection for payment callbacks and selected write endpoints.
   - server/machine `last_seen_at` updates should be throttled/coalesced to avoid write amplification if high-frequency.
4. Octane safety:
   - audit singleton/static mutable state under Octane.
   - ensure plugin hook registry resets per worker start/request where needed.
   - avoid request-specific data in static properties.
5. Redis hot key discipline:
   - avoid `Redis::keys()` in request paths; use SCAN or background cleanup.
   - use set-based batching already present for traffic/device pushes; document limits.

Acceptance criteria:

- Load test on selected endpoints shows no worker starvation at configured profile.
- Queue backlog metrics are visible.
- Payment/order/idempotent write tests pass.
- Octane smoke passes across repeated requests.

### Phase 5 — V2/V1 version decoupling and contract cleanup

Purpose: reduce hidden coupling so future security/performance policies can differ by version.

Deliverables:

1. V2 User decoupling first:
   - create V2 controller shells that delegate to existing services/read models without response changes.
   - keep route paths and response shapes identical.
2. V2 Passport decoupling second:
   - copy shell boundary only; preserve login/register/forget/token2Login redirect behavior.
   - add contract tests before any cleanup.
3. V2 Server remains high-risk:
   - do not alter protocol payloads until separate protocol audit and node-side compatibility tests exist.
4. Mark legacy side-effect GET routes:
   - keep existing GET for compatibility.
   - add POST aliases for future clients.
   - deprecation doc only; no removal.

Acceptance criteria:

- V2 route tests prove old response shapes unchanged.
- V2 controller ownership is independent enough to add future policies without editing V1.
- No DK_Theme/hiddify/node breakage.

### Phase 6 — Optional client migration to optimized BFF/read APIs

Purpose: let separated clients benefit from optimized contracts without forcing legacy changes.

Deliverables:

1. DK_Theme:
   - add feature flag `VITE_ENABLE_APP_BFF=true` only after backend BFF endpoint exists and is tested.
   - migrate read-heavy screens first: session summary, traffic logs, notices, tickets/orders summaries.
   - keep legacy fallback.
2. hiddify-app:
   - do not migrate unless app-specific benefit is clear.
   - if migrated, keep auth token and raw subscription behavior stable.
3. App BFF dashboard:
   - implement only after `docs/app-api-dashboard-phase2-audit.md` prerequisites pass.
4. Client contract tests:
   - TS API client tests for DK_Theme parsing.
   - Dart parser tests for hiddify response compatibility.

Acceptance criteria:

- Feature flag off: old clients unchanged.
- Feature flag on: bounded BFF/read endpoints reduce request waterfall and payload size.
- Fallback path works on backend older than BFF endpoint.

## 4. Security workstream backlog

Priority order:

1. Rate-limit matrix and route-level pilot.
2. Sensitive log redaction expansion.
3. Request parameter bounds for list/date/keyword inputs.
4. Auth failure consistency per channel.
5. Idempotency for payment/order-sensitive writes.
6. POST aliases for side-effect GET endpoints.
7. Contract tests for no sensitive field exposure in App BFF/read models.

Do not start with global encryption. If response encryption is later revisited, it must be scoped to a new opt-in API version and must exclude raw subscription/payment/node callback channels.

## 5. Concurrency workstream backlog

Priority order:

1. Baseline Octane/Horizon profile validation under current Docker settings.
2. Queue wait metrics and alert thresholds.
3. Request-path synchronous side-effect audit.
4. Payment/order idempotency and lock audit.
5. Node/machine high-frequency write coalescing.
6. Redis SCAN/background cleanup replacement for request-time key scans.
7. Octane mutable state audit.

## 6. Response-speed workstream backlog

Priority order:

1. Endpoint latency/query/payload baseline.
2. New bounded App BFF/read endpoints for frontend-heavy screens.
3. Admin stats cache/snapshot read model.
4. Public config/plan/notice cache with invalidation.
5. DB index review based on actual slow-query logs.
6. Payload trimming for knowledge/traffic/order/ticket summaries.
7. Optional client migration under feature flags.

## 7. Test and verification strategy

### Contract tests

- Existing legacy endpoint shapes remain stable.
- App BFF envelope remains scoped to `/api/app/v1/*`.
- Raw subscription endpoints are never JSON-wrapped.
- Payment/Telegram callbacks remain outside BFF envelope.
- V2 routes that reuse/move controllers return the same shape before/after decoupling.

### Security tests

- Passport abuse attempts hit rate limits.
- Nested sensitive keys are redacted from admin audit logs.
- Authenticated read endpoints do not expose subscription token unless explicitly legacy-required.
- App BFF never exposes token, `subscribe_url`, UUID, auth data, node credentials, or payment secrets.

### Performance tests

- Query count tests for App BFF/read models.
- Payload-size assertions for seeded list data.
- Small load smoke for login/session/subscribe/admin stats.
- Queue backlog and worker-count smoke under minimal/balanced profiles.

### E2E smoke

Continue using `./scripts/e2e-smoke.sh` as the compatibility gate. Extend only after adding new endpoints.

## 8. Acceptance criteria for the whole program

Security:

- Channel-specific rate limits exist and are tested for at least passport, user, admin, app, node, subscription, and callbacks.
- Sensitive logging policy is enforced by tests.
- Side-effect GET endpoints have documented compatibility status and future POST aliases.

Concurrency:

- High-frequency write/report paths have queue/batch/idempotency policies.
- Horizon queue topology and worker caps are documented and verified.
- Octane repeated-request smoke shows no mutable-state leaks in touched surfaces.

Response speed:

- Baseline and post-change metrics exist for selected endpoints.
- New read/BFF endpoints use explicit query/payload budgets.
- Cache invalidation tests exist for every cached mutable data class.

Compatibility:

- DK_Theme and hiddify-app old paths still work with feature flags off.
- `/s/{token}` and `/api/v1/client/subscribe` remain raw.
- Node/server, payment notify, Telegram webhook, and plugin hooks remain green.

## 9. ADR

### Decision

Adopt Option A: conservative layered hardening with scoped policies, measurement-first performance work, read models, queue boundaries, and additive BFF/client migration.

### Drivers

- Existing external clients depend on legacy contracts.
- API channels have different protocol requirements and cannot share one global response/security policy.
- Runtime stack already supports Octane/Horizon/Redis, so optimization should focus on route policy, query boundaries, caching, and async work.

### Alternatives considered

- Option B: larger new BFF/API version first. Deferred until measurement and read models exist.
- Option C: global middleware rewrite/encryption/envelope. Rejected as too risky and incompatible with raw/protocol endpoints.

### Consequences

- Work is incremental but safer.
- Legacy debt remains during transition.
- Every execution slice needs compatibility tests.

### Follow-ups

1. Execute Phase 0 + Phase 1 pilot first.
2. Use Phase 0 data to choose the first response-speed endpoint.
3. Keep App dashboard disabled until its audit prerequisites are met.

## 10. Available agent-types roster and staffing guidance

Use Ralph for a single-owner verified slice; use Team when multiple independent lanes can run in parallel.

Available roles:

- `explore`: repo mapping and endpoint/source inventory.
- `planner`: execution sequencing and risk planning.
- `architect`: boundary review and system tradeoffs.
- `critic`: challenge assumptions and reject weak plans.
- `executor`: code implementation.
- `debugger`: diagnose failing tests/load regressions.
- `test-engineer`: contract/performance/security test design.
- `verifier`: completion evidence and claim validation.
- `code-reviewer`: final implementation review.
- `dependency-expert`: external package/runtime upgrade decisions only if needed.
- `researcher`: official docs lookup if changing framework-level behavior.

Suggested reasoning levels by lane:

- Security/rate-limit/auth: high.
- Compatibility contracts: high.
- Read-model/cache implementation: medium-high.
- Test harness additions: medium.
- Docs/matrix updates: medium.
- Route inventory: low/medium.

## 11. Execution launch hints

### Ralph first slice recommendation

```text
$ralph "执行 .omx/plans/ralplan-api-architecture-optimization.md 的 Phase 0 + Phase 1 pilot：只做观测/预算文档、rate-limit 矩阵、敏感日志 redaction 测试和低风险 passport/app/user 路由限流试点；不改旧响应结构，不做 AES，不动订阅/节点/支付/Telegram。"
```

### Team execution recommendation

Use team if implementing Phase 0 + Phase 1 in parallel:

- Lane A / security: rate-limit matrix and route-level pilot.
- Lane B / tests: contract/security regression tests.
- Lane C / observability: baseline docs and optional instrumentation design.
- Lane D / verification: E2E smoke and no-touch route checks.

Launch hint:

```text
$team "按 .omx/plans/ralplan-api-architecture-optimization.md 执行 Phase 0 + Phase 1 pilot，分安全策略、测试、观测、验证四个 lane；禁止 AES，禁止破坏旧 API/订阅/节点/支付/Telegram。"
```

### Goal-mode follow-up suggestions

- `$performance-goal`: best fit for the full security/concurrency/response-speed optimization program because it needs measurable latency/throughput/query/payload outcomes.
- `$ultragoal`: use if you want durable multi-phase delivery tracking across security, concurrency, cache, BFF, and client migration.
- `$autoresearch-goal`: only use if the next task becomes external best-practice research rather than implementation planning.

Recommended durable follow-up:

```text
$performance-goal "以 .omx/plans/ralplan-api-architecture-optimization.md 为目标，分阶段提升 Xboard API 安全、并发和响应速度；每阶段必须保留旧 API/订阅/节点/支付/Telegram 兼容，并提供测试与指标证据。"
```
