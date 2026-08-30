# Discovery: api-product-gateway

## Question

The context brief assumes gateway latency percentiles can be "computed from
existing CallLog inbound entries" and that per-tier rate-limit policy should
"extend the existing `InboundRateLimitService`". Both are underspecified at
HEAD: does `call_log` already carry enough inbound rows/fields for
percentiles, and what's the correct extension seam on
`InboundRateLimitService` that doesn't fork it?

## Approach Taken

- Read `lib/Service/RateLimit/InboundRateLimitService.php` in full.
- Read the `consumer-management`, `endpoint-runtime`, and `prometheus-metrics`
  specs in full, plus ADR-003 (CallLog is the primary observability surface).
- Grepped every `'direction' =>` write site in `lib/` to find every place
  that produces an inbound `call_log` row.
- Read `lib/Service/EndpointService.php`'s `enforceInboundRateLimit()` /
  `recordInboundThrottle()` / `handleRequest()` (the RateLimit-header
  choke point).
- Read the `call_log` schema block in `lib/Settings/openconnector_register.json`
  and the outbound `responseTime` write site in `CallService::buildResponseData()`.
- Read `openspec/specs/openconnector-storage-migration/spec.md` to confirm
  `call_log` is now an OpenRegister object (not the legacy `lib/Db/CallLog.php`
  entity, which no longer exists on disk).
- Read the archived `2026-07-14-consumer-apikey-enforcement` proposal and
  `lib/Service/AuthorizationService::getResolvedConsumer()` to confirm how a
  request's Consumer is resolved today.
- Read `lib/Service/ApprovalService.php` and the archived
  `2026-07-15-hitl-approval-rule-action` `approval-workflow` spec in full to
  find a subject-agnostic (non-FlowToken) approval seam.
- Read `lib/Observability/OpenConnectorMetricsProvider.php` and
  `src/manifest.json`'s `observability.metrics` block to find the declarative
  vs. escape-hatch split for Prometheus gauges.
- Read `lib/Settings/register.d/hitl-approval-rule-action.json` and
  `register.d/99-source-secrets-writeonly.json` to confirm the register
  fragment mechanism can both add new schemas and deep-merge new fields onto
  an existing schema.
- Read `src/manifest.json`'s `pages` array to confirm the SPA is
  manifest-driven (index/detail/custom page types), not hand-routed Vue views.

## Findings

1. **`call_log` inbound rows are NOT a general request log today — brief's
   assumption is wrong.** The only code path that writes a `direction:
   inbound` `call_log` row is `EndpointService::recordInboundThrottle()`,
   called exclusively from `enforceInboundRateLimit()` on the 429 branch
   (`REQ-CON-RL-004`). Every *successful* inbound endpoint request writes
   nothing to `call_log`. There is no `responseTime`, `endpoint`, or
   `product` field on the schema at all — only `statusCode`, `statusMessage`,
   `direction`, `created`. Computing latency percentiles "from existing
   CallLog inbound entries" is therefore not possible without first adding
   general-purpose inbound logging with a duration field. **Deviation from
   brief, followed the code**: this change adds inbound `call_log` writes
   (with a new `responseTime` field) for every request dispatched through an
   `api_product`-scoped endpoint — not every endpoint, to bound volume
   growth (see design.md Decision 3 and proposal.md Risk 1).

2. **`InboundRateLimitService::enforce()` is already policy-agnostic** — it
   takes a `consumerKey` string and plain `rateLimit`/`quota` arrays, with no
   knowledge of Consumer, Endpoint, or any product concept. The correct
   "extend, don't fork" seam is one level up, in
   `EndpointService::enforceInboundRateLimit()`, which today derives its
   `$key`/`$rateLimit`/`$quota` from the resolved Consumer. Adding a
   tier-resolution step ahead of that derivation (falling back to the
   existing Consumer-level values when no product/tier applies) requires
   zero changes to `InboundRateLimitService` itself.

3. **`ApprovalService::suspend()` cannot be reused as-is for subscription
   approval.** It's tightly coupled to a `FlowToken` snapshot and an
   in-flight endpoint rule-pipeline suspension (`EndpointService::
   doHandleRequest()`'s `JSONResponse` short-circuit). But
   `ApprovalService::suspendForSynchronization()` already establishes the
   pattern this change needs: create a `pending` `approval_request` with an
   empty `snapshot`, no FlowToken, notify the `approverGroup`, and let a
   *different* subject (a Synchronization batch gate there; an
   `api_product_subscription` here) resolve on `approve()`/`reject()`.
   `completeApproval()`, `reject()`, `isAuthorizedApprover()`,
   `assertActionable()`, `sweepExpired()`, and `notifyApprovers()` are
   already fully generic (no FlowToken coupling). Only the *creation*
   method needs a subscription-specific sibling of
   `suspendForSynchronization()`.

4. **Prometheus gauges split cleanly into two existing mechanisms.**
   `src/manifest.json`'s declarative `observability.metrics[].source.kind:
   "tableCount"` (with `groupBy`) already produces `calls_total{status,
   direction}` from `call_log` — extending `groupBy` to include a `product`
   label is a manifest-only change once `call_log.product` exists. But a
   *percentile* is not a row count — it requires sorting/indexing values
   within a group — so it cannot be expressed by the declarative
   `tableCount`/`objectCount`/`orAvailable` `source.kind` vocabulary. This is
   exactly the situation `circuit_breaker_state` already solved: it uses
   `source.kind: "provider"`, resolved to `OpenConnectorMetricsProvider`
   (`OCA\OpenRegister\AppHost\IMetricsProvider::openconnector` container
   alias). Latency percentiles will use the same escape hatch.

5. **The register fragment mechanism (ADR-037,
   `lib/Settings/register.d/*.json`) supports both new-schema declaration
   and deep-merge onto an existing schema's `properties`** —
   `99-source-secrets-writeonly.json` deep-merges `writeOnly: true` onto five
   existing `source` properties without touching the monolith. This is the
   documented reason to avoid editing `openconnector_register.json` directly
   (a `SchemaMapper` `$ref`-resolution bug on re-parse). The new
   `api_product`/`api_product_subscription` schemas and the three new
   `call_log` fields (`product`, `endpoint`, `responseTime`) will all ship in
   one fragment for this change, following the `hitl-approval-rule-action`
   precedent of one fragment per change.

6. **The SPA is manifest-driven**, not hand-routed Vue views — `src/
   manifest.json`'s `pages` array declares `index` (generic list),
   `detail` (generic detail), and `custom` (bespoke component) page types.
   `Consumers` is a plain `index` page over the `consumer` schema; `Approvals`
   is `custom` because it needs non-CRUD actions (approve/reject) and a
   scoped API. `API Products` needs the same: a plain index for browsing, but
   the detail view needs endpoint-picker + tier editor + analytics panel +
   subscription-approval actions that a generic CRUD detail can't express —
   it will be `custom`, mirroring `ApprovalDetail`.

## Recommendation

Proceed with the approach in proposal.md/design.md: extend
`EndpointService`'s inbound-rate-limit call site (not the service), extend
`ApprovalService` with one new creation method (not the FlowToken-coupled
`suspend()`), add general-but-scoped inbound `call_log` logging restricted to
product-attached endpoints, and split analytics across the declarative
`groupBy` mechanism (counts) and the `IMetricsProvider` escape hatch
(percentiles). All four extension seams already exist in the codebase for
an analogous purpose — none require inventing a new pattern.

## Risks Uncovered

- Scoping inbound logging to product-attached endpoints only (rather than
  every endpoint) means a consumer's plain (non-product) endpoint calls stay
  invisible to analytics — acceptable per proposal.md's in-scope framing
  ("API Products GROUP them"; analytics is a product-level, not
  endpoint-level, capability), but worth flagging: a future "make analytics
  available to every endpoint" change would need to revisit the volume
  trade-off in Risk 1.

## Next Steps

Proceed to design.md and the spec deltas.
