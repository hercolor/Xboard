# RALPLAN: DK_Theme `VITE_ENABLE_APP_BFF` Session Adapter + Fallback Tests

Date: 2026-05-20
Mode: planning only; no DK_Theme, hiddify-app, or Xboard code changes in this artifact.

## Scope

Plan a future DK_Theme-only, opt-in client migration that can read Xboard `GET /api/app/v1/session` when `VITE_ENABLE_APP_BFF=true`, while preserving the current legacy hydrate path and all subscription-delivery behavior.

Primary future touchpoints in `/home/seven/works/new-api/DK_Theme`:

- `src/lib/config.ts` — add `VITE_ENABLE_APP_BFF` boolean config.
- `src/lib/api/types.ts` — add App BFF session envelope/types and/or local adapter DTOs.
- `src/lib/api/services/user.ts` — add session fetch + mapping/fallback helpers near current user services.
- `src/features/auth/auth-context.tsx` — route hydrate through a feature-flagged adapter without changing token storage.
- Tests under the existing or newly approved frontend test setup.

Explicitly out of scope:

- No Xboard backend endpoint changes unless a later approved field-extension task is created.
- No `/api/app/v1/dashboard` work.
- No AES/global response wrapping.
- No legacy `/api/v1/*`, `/api/v2/*`, raw subscription, payment, node/server, route, or store rewrites.
- No attempt to source `subscribe_url`, `token`, `uuid`, or `auth_data` from `/api/app/v1/session`.

## Evidence Used

- Context snapshot: `.omx/context/dk-theme-app-bff-session-adapter-20260520T132915Z.md`.
- Xboard docs:
  - `docs/app-api-session-migration-compatibility-plan.md`.
  - `docs/frontend-app-api-optimization-plan.md`.
  - `docs/app-api-dashboard-client-waterfall-audit.md`.
- DK_Theme inspection:
  - `auth-context.tsx` currently hydrates with `Promise.all([getUserInfo(), getSubscribeInfo()])`.
  - `user.ts` currently maps `getUserInfo()` to `/api/v1/user/info` and `getSubscribeInfo()` to `/api/v1/user/getSubscribe`.
  - `types.ts` makes `UserInfo.balance` and `SubscribeInfo.subscribe_url` required.
  - `config.ts` has `VITE_ENABLE_MOCK` but no `VITE_ENABLE_APP_BFF`.
  - `package.json` has `build` and `lint`; no declared test runner yet.

## RALPLAN-DR Summary

### Principles

1. **Additive opt-in only** — `VITE_ENABLE_APP_BFF=false` must preserve the exact current DK_Theme behavior.
2. **No secret migration through session** — `/api/app/v1/session` is summary metadata only and must never supply `subscribe_url`, `token`, `uuid`, or `auth_data`.
3. **Fallback is a product contract** — App BFF failure, missing capability, invalid envelope, or mapping gaps must fall back to legacy V1 calls.
4. **Adapter boundary over UI rewrites** — keep App-session normalization inside API/service/auth adapter code, not page components.
5. **Test the rollback path as strongly as the new path** — feature flag off, BFF success, BFF failure, and subscription delivery all need assertions.

### Decision Drivers

1. **Security/compatibility:** session endpoint deliberately excludes delivery/auth secrets, while DK_Theme still requires `subscribe_url` for client setup flows.
2. **Migration safety:** feature flag + legacy fallback enables rollback without backend deploys.
3. **Mapping correctness:** existing DK_Theme types require fields not fully present in App session (`balance`, `subscribe_url`, optional plan name), so adapter semantics must be explicit.

### Viable Options

#### Option A — Feature-flagged App session overlay/probe + legacy authoritative required fields

Flow when enabled: call App session as an opt-in safe metadata overlay/probe, but still call legacy `GET /api/v1/user/info` for the required `UserInfo` shape and legacy `GET /api/v1/user/getSubscribe` for the required `SubscribeInfo.subscribe_url` / token delivery shape.

Pros:
- Honors App-session denylist.
- Keeps `UserInfo.balance` / `commission_balance` legacy-backed, matching current DK_Theme UI and types.
- Keeps `SubscribeInfo.subscribe_url` real and legacy-backed.
- Introduces an adapter boundary and capability probe without weakening current auth context contracts.
- Simple rollback: disable `VITE_ENABLE_APP_BFF`.

Cons:
- Does not reduce the current auth-hydrate request count in this first slice; it may add an App-session probe request when enabled.
- The immediate value is migration readiness and contract validation, not performance.
- Request reduction requires a later approved backend field extension or DK_Theme type/UI split.

Recommendation: **preferred first implementation** under current constraints. Treat App session as optional safe overlay/probe only; legacy calls remain authoritative for required fields.

Request-count consequence: this first slice is not a guaranteed performance optimization. It may increase enabled-path hydrate requests by one App-session probe until `balance`/`commission_balance`/plan fields or DK_Theme summary types are separately approved.

#### Option B — App session only as a probe/capability check, then unchanged legacy hydrate

Flow when enabled: call `bootstrap`/`session` only to validate BFF availability, but continue using `Promise.all([getUserInfo(), getSubscribeInfo()])` for state.

Pros:
- Lowest behavior risk.
- Exercises backend availability and envelope parsing without affecting UI state.
- Useful if DK_Theme lacks a test runner and needs a smoke-only first step.

Cons:
- No meaningful request reduction.
- Adds complexity without user-visible benefit.
- Delays mapping decisions.

Use only if execution discovers that `balance`/subscription typing cannot be safely adapted in the first pass.

#### Option C — Replace both legacy user and subscribe calls with App session

Pros:
- Maximum apparent request reduction.

Cons:
- **Invalid for this phase:** App session forbids `subscribe_url` and `token`, while DK_Theme `SubscribeInfo.subscribe_url` is required and clients page depends on subscription delivery.
- Would force fake defaults or break client import/copy/QR flows.

Decision: **reject/invalidate** unless a separately approved subscription-delivery contract exists; do not implement in this migration.

## Mapping Gaps and Required Decisions for Execution

| DK_Theme field/use | App session source | Gap / plan |
| --- | --- | --- |
| `UserInfo.email` | `data.user.email` | Direct map. |
| `UserInfo.avatar_url` | `data.user.avatar_url` | Direct nullable map. |
| `UserInfo.expired_at` | `data.subscription.expired_at` | Cross-section map. |
| `UserInfo.transfer_enable` | `data.traffic.total` | Alias map. |
| `UserInfo.d` | `data.traffic.download` | Optional alias map. |
| `UserInfo.remind_expire` | `data.preferences.remind_expire` | Map boolean; keep type accepting boolean/number. |
| `UserInfo.remind_traffic` | `data.preferences.remind_traffic` | Map boolean; keep type accepting boolean/number. |
| `UserInfo.balance` | not present | Current DK_Theme type/UI require this field; legacy `getUserInfo()` is mandatory in this slice. Do not fake silently. |
| `UserInfo.commission_balance` | not present | Current dashboard/section-card UI consumes this optional field; legacy `getUserInfo()` remains authoritative. No backend extension without approval. |
| `UserInfo.plan` | not present | Keep legacy `getUserInfo()`/current behavior; any `plan_name` optimization requires separate backend approval. |
| `SubscribeInfo.subscribe_url` | forbidden | Must remain from `/api/v1/user/getSubscribe`. |
| `SubscribeInfo.token` | forbidden | Must remain legacy-only if used. |
| `SubscribeInfo.transfer_enable` | `data.traffic.total` | Summary alias possible, but full `SubscribeInfo` still needs legacy delivery. |
| `SubscribeInfo.d` | `data.traffic.download` | Summary alias possible. |
| `SubscribeInfo.expired_at` | `data.subscription.expired_at` | Safe alias. |
| `SubscribeInfo.plan` | not present | Keep legacy fallback or explicit future `plan_name` field approval. |

Execution guidance: the current codebase already proves `user.balance` and `subscribe.subscribe_url` are required for visible UI/auth context compatibility. Therefore the future adapter must return the existing `{ user: UserInfo, subscribe: SubscribeInfo }` shape with required fields satisfied by legacy endpoints. Summary-only `UserInfo` or `SubscribeInfo` splitting is a separate approved DK_Theme type/UI migration, not part of this plan.

## Concrete Phased Plan

### Phase 0 — Preflight and test-shape discovery

1. Inventory DK_Theme reads of `user.*` and `subscribe.*`, especially `balance`, `commission_balance`, `plan`, `subscribe_url`, and `token`, to document current consumers before editing.
2. Confirm available validation commands from `package.json`: at minimum `npm run build` and `npm run lint` (or the project package manager equivalent).
3. Decide whether to add a lightweight frontend test runner in this task. Because no test script is currently declared, prefer no new dependency unless explicitly approved; otherwise use type/build/lint plus small pure-function tests only if an existing test setup is found.

Deliverable: execution note confirming that legacy `getUserInfo()` and `getSubscribeInfo()` remain mandatory in this slice, plus what test runner/validation path exists.

### Phase 1 — Add feature flag and App-session DTO boundary

1. Add `appConfig.enableAppBff` from `VITE_ENABLE_APP_BFF`, defaulting to `false`.
2. Add App BFF envelope/session types that match `ok/code/message/data/meta` and the safe `data.user`, `data.subscription`, `data.traffic`, `data.preferences` sections.
3. Add a narrow allowlist adapter function, e.g. `extractSafeAppSessionOverlay(session)`, that returns optional safe overlay metadata only. It must not claim to produce a complete `UserInfo` or `SubscribeInfo` in this slice because required legacy fields are absent from App session.

Expected files: `src/lib/config.ts`, `src/lib/api/types.ts`, `src/lib/api/services/user.ts` or a small adjacent adapter module.

### Phase 2 — Implement fallback-aware service surface

1. Add `getAppSession()` for `GET /api/app/v1/session` using the existing `apiClient` and bearer behavior.
2. Add one hydrate helper, e.g. `getSessionSnapshot()` / `hydrateUserSession()`, that centralizes branching:
   - If mock enabled, preserve current mock behavior.
   - If `enableAppBff=false`, preserve current `Promise.all([getUserInfo(), getSubscribeInfo()])` behavior.
   - If `enableAppBff=true`, still fetch legacy `getUserInfo()` and `getSubscribeInfo()` to satisfy the current required auth context shape.
   - If `enableAppBff=true`, attempt App session as an optional safe overlay/probe. If it returns HTTP failure, `ok !== true`, malformed data, or forbidden/missing shape, ignore it and complete hydrate from legacy data without partial App-derived state.
   - A valid App session may only overlay/validate safe non-secret fields after required legacy fields are present; it must never source `balance`, `commission_balance`, `subscribe_url`, `token`, `uuid`, or `auth_data`.
3. Ensure App session response is never persisted as an auth credential; token storage remains unchanged.

Expected files: `src/lib/api/services/user.ts`, maybe an adapter module under `src/lib/api/services/`.

### Phase 3 — Wire auth context through the adapter

1. Replace direct `Promise.all([getUserInfo(), getSubscribeInfo()])` inside `syncSessionFromStorage()` with the new hydrate helper.
2. Preserve all existing no-token, login, register, logout, storage-event, and hydrated-state semantics.
3. Keep toast and `tokenStorage` behavior unchanged.

Expected file: `src/features/auth/auth-context.tsx`.

### Phase 4 — Add fallback and mapping validation

1. Unit/pure tests if a runner exists or is explicitly approved:
   - App session overlay extracts only safe fields and does not produce complete `UserInfo`/`SubscribeInfo` by itself.
   - `VITE_ENABLE_APP_BFF=false` preserves the existing legacy-only hydrate call pattern.
   - With `VITE_ENABLE_APP_BFF=true` and valid App session, legacy `getUserInfo()` is still called, legacy `getSubscribeInfo()` is still called, `user.balance` comes from legacy user info, and `subscribe.subscribe_url` comes from legacy getSubscribe.
   - Missing/invalid App envelope is ignored and hydrate still completes from legacy calls without partial App-derived state.
   - `subscribe_url` is never read from App session and remains legacy-backed.
2. If no test runner is approved, add no dependency by default; validate with:
   - `npm run lint`.
   - `npm run build`.
   - Manual/mock adapter smoke checks through existing mock mode if feasible.
3. Backend contract dependency should remain covered in Xboard by existing App API tests: session envelope, secret needle absence, legacy user/subscribe endpoints, raw subscription routes.

### Phase 5 — Rollout notes and rollback check

1. Document env usage: `VITE_ENABLE_APP_BFF=true` opts into the adapter; default/false preserves legacy.
2. Document rollback: set `VITE_ENABLE_APP_BFF=false`; no backend rollback needed.
3. Record known unresolved backend extensions (`balance`, `commission_balance`, `plan_name`) as separate approval-required tasks, not implicit adapter work.

## Test Strategy

### Preferred frontend checks

- **Config tests:** parse `VITE_ENABLE_APP_BFF` values; default false.
- **Adapter mapping tests:** fixture App session maps user summary fields; forbidden keys are ignored even if fixture accidentally includes them.
- **Fallback tests:** App session 401/403/404/5xx, `ok:false`, malformed data, and missing required mapped fields all fall back to legacy calls.
- **Subscription delivery tests:** resulting `subscribe.subscribe_url` comes from legacy `getSubscribeInfo()`; App session never satisfies delivery fields.
- **Auth-context behavior tests:** hydrated state flips in `finally`; no token clears state; login/register still resync; logout still clears and toasts.

### Minimum validation if frontend test runner remains absent

- `npm run lint` in `/home/seven/works/new-api/DK_Theme`.
- `npm run build` in `/home/seven/works/new-api/DK_Theme`.
- Type-level review of adapter return types to prevent `undefined` required fields.
- Manual network/mock smoke with flag off and flag on if local dev server is available.

### Backend regression dependency

Before/alongside client rollout, keep Xboard backend checks green for:

- `/api/app/v1/session` authenticated App envelope.
- Forbidden key absence: `token`, `subscribe_url`, `uuid`, `auth_data`.
- `bootstrap.capabilities.session === true` and `dashboard === false`.
- Legacy `/api/v1/user/info` and `/api/v1/user/getSubscribe` still mounted and legacy-shaped.
- `/api/v1/client/subscribe?token=...` and `/s/{token}` remain raw/non-App-envelope.

## Acceptance Criteria

1. With `VITE_ENABLE_APP_BFF` unset or false, DK_Theme makes the same legacy hydrate calls and exposes the same auth context shape as before.
2. With `VITE_ENABLE_APP_BFF=true` and valid App session, DK_Theme still calls legacy `getUserInfo()` and `getSubscribeInfo()` for required fields, while App session is used only as a safe overlay/probe.
3. `user.balance` and `user.commission_balance` remain sourced from legacy `/api/v1/user/info`; no fake money/plan data is introduced.
4. Full subscription delivery (`subscribe_url`, token-derived client import/copy/QR flows) remains backed by legacy `/api/v1/user/getSubscribe`.
5. Any App BFF failure or invalid envelope is ignored and legacy hydrate completes without leaving auth context partially initialized.
6. Build/lint pass, and available tests cover flag-off, flag-on success with legacy required calls, fallback failure, and subscription-delivery preservation.
7. No hiddify-app or Xboard legacy API behavior is changed by the DK_Theme migration.

## Risks and Mitigations

| Risk | Impact | Mitigation |
| --- | --- | --- |
| `UserInfo.balance` is required by current UI | App-session-only user mapping may break render/type assumptions | Inventory reads first; keep legacy `getUserInfo()` fallback for money fields unless backend extension is approved. |
| `SubscribeInfo.subscribe_url` is required | App-session replacement would break client setup flows | Never replace delivery with App session; keep legacy `getSubscribeInfo()`. |
| No frontend test runner | Fallback behavior may be under-tested | Prefer pure adapter tests only if runner exists/approved; otherwise require lint/build plus backend contract tests and manual smoke. |
| Envelope mismatch or backend capability drift | Flag-on users may fail hydrate | Treat any invalid/missing App session shape as fallback trigger. |
| Accidental secret leakage normalization | App response containing forbidden keys could be propagated | Use allowlist mapping only; tests assert ignored/absent forbidden keys. |
| Request count improvement is smaller than expected | Stakeholders may expect full waterfall removal | Document first slice as safety/compatibility adapter; dashboard/subscription delivery optimization requires later contracts. |

## Recommended Execution Staffing

- **Ralph path:** one executor owns all DK_Theme changes sequentially, with a verifier pass after lint/build/tests. Best if keeping the diff small.
- **Team path:**
  - Explorer lane: inventory `user`/`subscribe` field reads and test setup.
  - Executor lane: implement config/types/service adapter.
  - Executor lane: wire auth context after adapter interface is settled.
  - Test/verifier lane: add/adjust tests or run lint/build/manual smoke.
- **Goal-mode option:** `$ultragoal` if this should become a durable tracked migration goal; not required for a small single-PR implementation.

Available agent types for follow-up: `explore`, `executor`, `test-engineer`, `verifier`, `code-reviewer`, `ralph`, `team`, `$ultragoal`.

Suggested reasoning levels:

- Explore/inventory: low to medium.
- Adapter implementation: medium.
- Auth-context wiring: medium.
- Test/verifier/security review: high.

## Recommended Next Execution Prompt

```text
$ralph "Implement the DK_Theme-only VITE_ENABLE_APP_BFF session overlay/probe from .omx/plans/dk-theme-app-bff-session-adapter-plan.md. Stay planning-safe boundaries: do not change Xboard backend, hiddify-app, legacy API shapes, dashboard, AES, or subscription delivery. First inventory DK_Theme user/subscribe field reads and test setup, then add the feature flag, App session DTO/allowlist overlay parser, fallback-aware hydrate helper, auth-context wiring, and available fallback tests. Keep legacy /api/v1/user/info as the source of balance/commission_balance and legacy /api/v1/user/getSubscribe as the source of subscribe_url/token. Verify with DK_Theme lint/build and any available tests; report any missing test-runner gap explicitly."
```

## Stop Rule

Stop the future implementation when the flag-off path is proven unchanged, the flag-on path uses App session only as a safe overlay/probe while legacy required fields remain authoritative, malformed App session is ignored cleanly, subscription delivery remains legacy-backed, and fresh validation evidence is recorded.
