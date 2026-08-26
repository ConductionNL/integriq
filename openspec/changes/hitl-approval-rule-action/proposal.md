# Proposal: hitl-approval-rule-action

## Summary

Add a human-in-the-loop (HITL) `approval` rule action type to Integriq's
endpoint rule pipeline, plus an optional batch-level approval gate on
Synchronizations. When a pipeline run hits an `approval` rule (timing
`before` only), the run suspends: an `approval_request` OpenRegister object
persists a snapshot of the in-flight FlowToken, the target endpoint/rule
context, the configured approver group, and an expiry. The original HTTP
caller receives `202 Accepted` with a status-polling URL instead of a final
result. An approver group member approves or rejects (with comment) through
a new Pending Approvals UI; approval resumes the suspended rule chain
synchronously inside the approver's own request; rejection or timeout apply
a per-rule-configured fallback (`error`, `skip`, or `dead_letter`). This
closes a gap competitors (n8n, Windmill, Workato) already cover and is
required for flows where a human must gate data leaving/entering municipal
systems (e.g. publish to a WOO portal, outbound Berichtenbox message).

## Motivation

Integriq's rule pipeline (retrofit spec `rule-pipeline`) and
Synchronization engine (`synchronization-engine`) currently have no way to
pause a run for human sign-off — every rule type (`save_object`,
`synchronization`, `webhook_signature`, `composite_fanout`, etc., 19 types
verified at HEAD in `EndpointService::processRules()`) runs to completion or
fails within the same request. Municipal integration flows increasingly need
"a human approves before data leaves/enters" as a compliance control, not
just a business nicety. Without this, admins either skip the control
entirely or bolt it onto the target system (out of Integriq's audit
trail). Building it as a first-class rule action keeps the approval decision,
its context snapshot, and its audit trail inside Integriq, consistent
with how `audit_trail`, `locking`, and the ADR-023 action matrix already
work.

## Affected Projects

- [ ] Project: `integriq` — new `approval` rule action type, suspend/resume
  mechanics in `EndpointService`, new `approval_request` register schema
  (register.d fragment), `ApprovalService`, `ApprovalsController` +
  frontend Pending Approvals page, `ApprovalTimeoutSweepJob` cron, ADR-023
  `approval.approve`/`approval.reject` action-matrix entries, optional
  `requiresApproval` gate on Synchronization batch writes.

## Scope

### In Scope

1. New rule action type `approval` (timing `before` only): on hit, persists
   an `approval_request` OR object (context snapshot via
   `FlowToken::__serialize()`, requester, approver group, expiry,
   suspension cursor), short-circuits the pipeline with `202 Accepted` +
   a status-polling URL (reusing the existing `JSONResponse` short-circuit
   contract in `doHandleRequest()`), and notifies the approver group.
2. Approve → the approving user's own HTTP request synchronously resumes
   `processRules()` from the rule immediately after the approval rule (same
   phase, filtered by `order`), using the persisted FlowToken snapshot as
   the resumed data envelope, then continues normal dispatch (schema
   write / `after`-phase rules). Reject or timeout → configurable outcome
   per the approval rule's `onReject`/`onTimeout` config: `error` (return
   a configured error to a status poller), `skip` (mark suspended and move
   on with the pre-approval envelope), or `dead_letter` (mark the
   `approval_request` itself `status: dead_letter`, discoverable via a
   dead-lettered-approvals filter — no separate event_message row).
3. Synchronization gate: optional `requiresApproval` on a Synchronization —
   the run pauses once source fetch + mapping complete and before
   `updateTarget()` writes begin, gating the whole batch (not per-object).
   Resume re-invokes `synchronize()` with a bypass token tied to the
   approved `approval_request` id rather than re-serializing the full
   batch payload.
4. Pending Approvals UI: list page (pending / approved / rejected / expired
   / dead-lettered filters) + approve/reject action with a required comment
   on reject; full audit (who/when/comment/outcome) lives on the
   `approval_request` object via OpenRegister's existing audit trail.
5. Two-layer authorization: ADR-023 `approval.approve` / `approval.reject`
   action-matrix entries (coarse "can use approval features" gate, seeded
   `["admin"]`) plus a per-request check that the caller is a member of
   *that* `approval_request`'s configured `approverGroup` (fine-grained,
   since the approver group is chosen per-rule, not app-wide).
6. Notifications: an `x-openregister-notifications` `created` rule on
   `approval_request` for ops-visibility (declarative ADR-031 dialect,
   static `openconnector-ops` group, no interactive actions — matches the
   existing dialect's proven shape). The primary actionable approve/reject
   notification to the *rule-configured* approver group is dispatched
   imperatively by `ApprovalService` via `OCP\Notification\IManager` — see
   Risk 1 and design.md for why this can't be expressed in the declarative
   dialect alone.
7. Tests: unit tests for the `approval_request` state machine
   (pending/approved/rejected/expired/dead_letter transitions); integration
   test for suspend → approve → resume through a real endpoint rule chain.

### Out of Scope

- Nextcloud Talk approval messages (notification-only for v1; Talk
  integration is a documented follow-up).
- Multi-step / quorum approvals (single approver from the configured group
  resolves the request).
- decidesk integration.
- Replaying a dead-lettered approval against fresh source data (the
  persisted snapshot can go stale; replay needs its own staleness story —
  deferred, see Open Questions).
- `approval` on `after`-timing rules or mid-Synchronization per-object
  gating (only batch-level, pre-write gating is in scope).

## Approach

Reuse the FlowToken 8-slot snapshot (`flow-token-helper`, already the
substrate carried through the rule pipeline) as the suspension payload —
no new serialization format. Reuse the existing `JSONResponse` short-circuit
contract in `EndpointService::doHandleRequest()`/`processRules()` so
suspension doesn't require a new Response subclass. Persist suspension state
as a normal OpenRegister object (`approval_request`) via a `register.d`
fragment (ADR-037 pattern, avoids touching the 2000+-line
`integriq_register.json` directly). Resume executes synchronously
inside the approving user's own request (a separate PHP process boundary
from the original suspended request, satisfying "no long-running process"
without needing NC background-job/cron latency); only timeout sweeping runs
on a cron `TimedJob` (matching the existing `lib/Cron/EventRetryJob.php`
pattern). See design.md for the full suspend/resume state machine and the
notification-dialect decision.

## New Dependencies

None — reuses existing OpenRegister object persistence, FlowToken
serialization, `ActionAuthService`, NC's `IJobList`/`TimedJob`, and NC's
`OCP\Notification\IManager` (already a platform dependency, just not
previously called from this app).

## Impact

- `lib/Service/EndpointService.php` — new `processApprovalRule()` dispatch
  case in `processRules()`'s match expression; `doHandleRequest()` gains no
  new branches (the 202 short-circuit reuses the existing `JSONResponse`
  path).
- `lib/Service/SynchronizationService.php` — new pre-write approval gate
  check in the orchestration path (REQ-001/REQ-004 boundary).
- New: `lib/Service/ApprovalService.php`, `lib/Controller/ApprovalsController.php`,
  `lib/Cron/ApprovalTimeoutSweepJob.php`, `lib/Settings/register.d/hitl-approval-rule-action.json`.
- `lib/actions.seed.json` — add `approval.approve`, `approval.reject`.
- `src/views/Rule/RuleActionConfig.vue` — new `approval` entry in
  `ACTION_TYPES`/`ACTION_FORM_MAP`; new `src/views/Rule/actionForms/ApprovalForm.vue`.
- New: `src/views/Approvals/` (Pending Approvals list + detail).
- `appinfo/routes.php` — new approvals REST routes.

## Cross-Project Dependencies

None. Self-contained within Integriq; consumes only OpenRegister object
persistence/audit (already a dependency) and NC's notification API (already
a platform dependency).

## Risks

### Risk 1: Actionable approver notification needs imperative dispatch, breaking a clean codebase precedent
**Severity:** Medium — **Mitigation:** every existing Integriq
notification is declarative (`x-openregister-notifications` in
`lib/Settings/integriq_register.json`, verified across all 9 current
occurrences: only `channels`/`recipients` of kind `field` or `groups`/
`subject` — no interactive-action support, and `groups` recipients are
static per schema, not resolvable from a per-rule-configured field). Since
the approver group is chosen per-rule and the notification needs
approve/reject deep-link actions, `ApprovalService` dispatches imperatively
via `OCP\Notification\IManager`, scoped to one method. `hydra-gate-notification-dialect`
WARNS (non-blocking) on imperative dispatch in a leaf app — this is that
documented exception, not a violation. The declarative dialect still covers
ops-visibility notifications.

### Risk 2: Resumed rule chain re-runs inside the approver's HTTP request
**Severity:** Medium — **Mitigation:** if the resumed rule chain is slow
(e.g. it triggers a synchronization rule), the approving admin's browser
waits on that latency. Bounded by the same execution-time profile any
endpoint request already has; `approve()` returns the resume result
(success/error) directly rather than polling. A future iteration could move
resume onto a background job if this proves too slow in practice — noted as
a follow-up, not blocking v1.

### Risk 3: Stale snapshot on approval
**Severity:** Low — **Mitigation:** the resumed FlowToken snapshot reflects
the state at suspension time; source data or target schema may have
changed by the time approval happens. The `approval_request` records
`expiresAt`; an expired request cannot be approved (falls to the
timeout fallback instead), bounding the staleness window.

## Rollback Strategy

The `approval` rule action type and `requiresApproval` Synchronization flag
are both additive and opt-in — no existing rule or synchronization behavior
changes unless an admin explicitly configures one. Rollback is: stop
configuring new `approval` rules / `requiresApproval` synchronizations;
existing `approval_request` objects and the `ApprovalTimeoutSweepJob` cron
entry can be left in place (inert) or the app can be reverted to a prior
release, since the new schema and routes are additive-only (no migration of
existing data).

## Open Questions

- Should a dead-lettered `approval_request` be replayable (re-running the
  suspended chain against a fresh fetch rather than the stale snapshot)? Out
  of scope for v1 pending a staleness-handling design; tracked as a
  follow-up alongside `dead-letter-replay`'s established list/detail/replay
  UX, which this deliberately does not reuse directly (different domain:
  CloudEvent delivery vs. rule-pipeline suspension).
- Should `requireAction` gate `approval.approve`/`approval.reject` ever
  diverge from `["admin"]` default in a way that conflicts with the
  per-`approval_request` `approverGroup` check? Deferred to design.md's
  two-layer authorization model; flag if real deployments need a single
  unified matrix instead.
