# Frontend/App API Phase 5 Wrap-up Audit

Date: 2026-05-24

## Scope

This audit closes the current read-path optimization phase for the frontend/App API work.

Hard constraints remain unchanged:

- Do not change API paths, auth, routes, response envelopes, or payload shapes.
- Do not modify subscription delivery, node/server payload generation, checkout/payment execution, AES, DK_Theme, or hiddify-app behavior in this phase.
- Prefer explicit read-model boundaries, response-column allowlists, and regression/E2E coverage over broad refactors.

## Completed read-path slices

| Area | Endpoint(s) | Result | Evidence |
| --- | --- | --- | --- |
| User info | `/api/v1/user/info`, `/api/v2/user/info` | Legacy projection moved behind `LegacyUserInfoReadModel`; App session traffic snapshot reused. | `tests/Feature/LegacyUserInfoReadModelContractTest.php`, E2E smoke |
| Plans | guest/user plan fetch | Plan capacity pressure reduced with preloaded active-user counts. | `tests/Feature/PlanServiceCapacityTest.php`, E2E smoke |
| Homepage counters | `/api/v1/user/getStat` | Three counters grouped behind `LegacyUserStatReadModel`. | `tests/Feature/LegacyUserStatReadModelContractTest.php`, E2E smoke |
| Tickets | `/api/v1/user/ticket/fetch` | Detail duplicate relation load removed; selected message columns only. | `tests/Feature/LegacyTicketFetchContractTest.php`, E2E smoke |
| Invites | `/api/v1/user/invite/fetch`, `/api/v1/user/invite/details` | Fetch aggregates and detail pagination moved into `LegacyInviteReadModel`. | `tests/Feature/LegacyInviteFetchContractTest.php`, E2E smoke |
| Orders | `/api/v1/user/order/fetch`, `/api/v1/user/order/detail` | Order reads moved into `LegacyOrderReadModel` with relation column allowlists. | `tests/Feature/LegacyOrderReadModelContractTest.php`, E2E smoke |
| Knowledge | `/api/v1/user/knowledge/fetch` | Placeholder/access render context reused for list/detail bodies. | `tests/Feature/LegacyKnowledgeReadContractTest.php`, E2E smoke |
| Notices | `/api/v1/user/notice/fetch` | Raw notice list moved into `LegacyNoticeReadModel`; response columns allowlisted. | `tests/Feature/LegacyNoticeFetchContractTest.php`, E2E smoke |
| Payment methods | `/api/v1/user/order/getPaymentMethod` | User payment-method list moved into `LegacyOrderReadModel::paymentMethods()`. | `tests/Feature/LegacyOrderReadModelContractTest.php`, E2E smoke |
| Traffic logs | `/api/v1/user/stat/getTrafficLog` | Monthly traffic log reads moved into `LegacyTrafficReadModel`; resource columns allowlisted. | `tests/Feature/LegacyTrafficLogContractTest.php`, E2E smoke |
| App BFF | `/api/app/v1/bootstrap`, `/api/app/v1/session`, `/api/app/v1/dashboard` | Additive allowlist-only App API surface with no legacy API replacement requirement. | `tests/Feature/AppApi/AppApiBootstrapTest.php`, E2E smoke |

## Remaining endpoint assessment

| Endpoint / area | Current recommendation | Reason |
| --- | --- | --- |
| `/api/v1/user/getSubscribe` | Do not optimize in Phase 5 | Subscription delivery is sensitive and used by DK_Theme/hiddify-app; shape/token behavior must not drift. |
| `/api/v1/client/subscribe?token=...` and `/s/{token}` | Do not touch | Raw subscription channel; client/parser compatibility is higher risk than read-model benefit. |
| `/api/v1/user/server/fetch` | Do not touch without a dedicated node payload plan | Already has ETag behavior; node/server payload construction is subscription-adjacent and protocol-sensitive. |
| Order checkout/save/cancel/check | Do not touch in read phase | Mutating/payment execution paths need separate payment safety tests and provider fixtures. |
| Passport login/register/forget/email | Do not touch in read phase | Auth/session behavior is security-sensitive and already locked by smoke coverage. |
| `resetSecurity`, `invite/save` GET side-effect routes | Document only; do not change silently | Method semantics are legacy-compatible but unsafe to change without client migration. |
| Gift-card endpoints | Defer | Not in the current DK_Theme/hiddify-app critical path inventory; needs separate product decision. |
| Telegram/comm/config/Stripe public key | Defer | Low read pressure or external-service/payment-adjacent behavior; optimize only with dedicated fixtures. |

## Conclusion

Phase 5 read-path optimization can stop here. The high-value frontend/App read endpoints now have explicit read boundaries, allowlisted fields where safe, and smoke coverage for legacy contracts.

Recommended next phase: **API security hardening plan**, not more opportunistic read refactors.

Suggested Phase 6 planning topics:

1. Throttle/rate-limit policy review for auth, email, support, payment, and read-heavy user endpoints.
2. Sensitive-field leakage audit for legacy V1/V2 responses and App BFF responses.
3. Side-effect GET retirement plan for `resetSecurity` and `invite/save` with compatibility shims.
4. Error envelope consistency audit without changing existing client-critical payloads.
5. Optional response encryption/AES threat-model decision, but only after client key-management and replay/debug risks are formally approved.
