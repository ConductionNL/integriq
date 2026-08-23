# Discovery: hitl-approval-rule-action

## Question

Can a HITL approval action be suspended and resumed entirely within
Integriq's existing mechanisms — the rule pipeline's short-circuit
contract, the FlowToken snapshot, the ADR-031 declarative notification
dialect, and the ADR-023 action-authorization matrix — or does it require
new infrastructure (long-running processes, a new response type, imperative
notification dispatch, a second authorization system)?

## Approach Taken

- Read `lib/Service/EndpointService.php` in full around
  `doHandleRequest()` (lines 257-430) and `processRules()` (lines 1538-1661)
  to trace the exact before/dispatch/after orchestration and the existing
  `JSONResponse`/`DataDownloadResponse` short-circuit contract.
- Confirmed the live rule-type dispatch table (19 types) in `processRules()`'s
  `match` expression — the brief's "synchronization-trigger" name does not
  exist in code; the real type is `synchronization`.
- Read `openspec/specs/flow-token-helper/spec.md` (done, retrofit) to confirm
  `FlowToken::__serialize()` produces a fixed 8-key array (request/response/
  syncInput/syncOutput × original/amended) suitable as a suspension payload.
- Read `openspec/specs/synchronization-engine/spec.md` (done, retrofit) to
  find the read/map/write phase boundary (`updateTarget()` /
  `updateTargetOpenRegister()` in REQ-004) where a batch-level approval gate
  would sit.
- Read `openspec/specs/openconnector-notifications/spec.md` and grepped
  every `x-openregister-notifications` occurrence in
  `lib/Settings/integriq_register.json` (9 occurrences) to enumerate
  every `trigger`/`recipients`/`channels` shape actually in use.
- Grepped the whole `lib/` tree for `OCP\Notification` / `INotificationManager`
  usage — zero hits. Every existing notification in this app goes through
  the declarative engine; there is no precedent for imperative dispatch.
- Read `lib/Service/ActionAuthService.php` (full) and
  `lib/Repair/InitializeActions.php` to confirm the ADR-023 matrix shape:
  one global action-name → allowed-groups mapping in `IAppConfig`, `["admin"]`
  default, admin always passes.
- Grepped `lib/Settings/register.d/*.json` (eudi-wallet-credential-issuance,
  source fragments) to confirm the ADR-037 register-fragment pattern for
  adding new schemas without editing the 2000+ line base register file.
- Grepped `lib/Cron/*.php` — all five existing cron jobs `extends TimedJob`
  (NC's `OCP\BackgroundJob\TimedJob`), confirming the periodic-sweep idiom
  already used for `EventRetryJob`.

## Findings

1. **Suspension needs no new Response type.** `doHandleRequest()` already
   short-circuits on `$ruleResult instanceof JSONResponse` right after the
   `before`-phase `processRules()` call (line 354). An `approval` rule can
   return a `JSONResponse(202, ...)` from inside `processRules()`'s
   `match` dispatch and the existing contract carries it out unchanged.
2. **FlowToken is a ready-made suspension payload.** `__serialize()`
   (REQ-005 of flow-token-helper) already produces exactly what's needed to
   resume: the amended request the pipeline had built up to the approval
   rule. No new snapshot format needed. Caveat: there is no matching
   `__unserialize()` (documented gap in the flow-token-helper spec) — resume
   must reconstruct a fresh `FlowToken` via the public setters from the
   deserialized 8-key array, not rely on native unserialize.
3. **The declarative notification dialect cannot express this feature's
   primary notification.** Every one of the 9 existing
   `x-openregister-notifications` blocks uses only two recipient kinds:
   `field` (a single userId property on the created object) and `groups`
   (a **static**, schema-level list of NC group names). Neither can express
   "notify the group chosen when this specific rule was configured." None
   of the 9 blocks contain any action/button configuration — the dialect
   only carries `trigger`/`enabled`/`channels`/`recipients`/`subject`.
   Approve/reject deep-link actions are not expressible declaratively.
4. **No imperative-notification precedent exists in this app.** This is a
   green field for `OCP\Notification\IManager` in Integriq. That's a
   real behavioral departure worth calling out explicitly rather than
   quietly introducing.
5. **ADR-023's matrix is one flat mapping, not per-instance.** `requireAction()`
   checks group membership against a single app-wide entry for the action
   name. It has no concept of "the approver group for *this*
   `approval_request`." A second, object-level check is required for the
   per-rule-configured approver group; ADR-023's own docblock already
   draws this line ("Data RBAC ... is OpenRegister's job" vs. "Action RBAC
   ... is this service"), so layering a group-membership check on the
   object's own `approverGroup` field alongside `requireAction()` is
   consistent with the existing division of responsibility, not a new
   pattern.
6. **The register-fragment pattern fits cleanly.** `approval_request` is a
   brand-new schema; `lib/Settings/register.d/*.json` is the established,
   already-used mechanism (ADR-037) for adding schemas without touching the
   base register file.
7. **Cron sweep fits the existing `TimedJob` idiom.** Timeout sweeping
   (`pending` + `expiresAt < now`) is a straight fit for a new
   `ApprovalTimeoutSweepJob extends TimedJob`, mirroring `EventRetryJob`.

## Recommendation

Go with the all-existing-mechanisms approach: reuse the `JSONResponse`
short-circuit, `FlowToken::__serialize()`, the register-fragment pattern,
and `TimedJob` cron — **except** for the primary approver notification,
which must be dispatched imperatively via `OCP\Notification\IManager`
because the declarative dialect structurally cannot express a per-rule
dynamic approver group or interactive actions. Keep the imperative path
narrowly scoped to one `ApprovalService` method, and keep a declarative
`x-openregister-notifications` rule on `approval_request` for ops-visibility
(static `openconnector-ops` group) so the app's dominant notification
pattern still applies where it can. Resume approve/reject synchronously in
the approver's own request (a distinct PHP process from the suspended
original request, satisfying "no long-running process") rather than
queuing a background job for the common case — reserve background/cron
execution for timeout sweeping only, where no human is waiting on the
response.

## Risks Uncovered

- Reconstructing `FlowToken` from a serialized snapshot must go through the
  public setters (no `__unserialize()`), so the resume path in
  `ApprovalService` needs its own explicit "rehydrate" helper rather than
  `unserialize($snapshot)`.
- The imperative notification dispatch is genuinely new territory for this
  app; it should be reviewed as such (see proposal.md Risk 1), not treated
  as routine.

## Next Steps

Proceed to design.md to fix the exact suspend/resume state machine, the
`approval_request` schema shape, and the two-layer authorization model; then
to specs (new `approval-workflow` capability + deltas to `rule-pipeline` and
`synchronization-engine`).
