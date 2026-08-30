# synchronization-engine Specification (delta)

**OpenSpec changes**:
- `hitl-approval-rule-action` _(in progress)_ — adds an optional
  batch-level `requiresApproval` gate on Synchronization writes, reusing the
  `approval-workflow` capability's `approval_request` schema and
  authorization model. See `design.md` Decision 6 for why this gates the
  whole batch (one `approval_request` per run) rather than per object.

## ADDED Requirements

### Requirement: Batch-level approval gate before target writes (REQ-015)

The orchestrator (REQ-001) MUST, when a Synchronization's
`sourceConfig.requiresApproval` is `true`, after source fetch and mapping
complete and before the `updateTarget()` write loop (REQ-004) begins, check for an
existing `approved` `approval_request` whose `synchronizationId` matches
this synchronization and whose approval has not yet been consumed by a
write. If none exists, the system MUST create exactly one `approval_request`
for the run (with `synchronizationId` set instead of `endpointId`/`ruleId`,
per `approval-workflow`'s schema), notify the configured `approverGroup`
(`approval-workflow` REQ-002), finalize the `synchronization_log` with a
`pending_approval` outcome, and return without writing any target object.
If an approved, unconsumed `approval_request` exists for this
synchronization, the write loop MUST proceed normally and the
`approval_request` MUST be marked consumed on completion.

@e2e exclude backend sync engine internals — covered by PHPUnit/Newman, not browser UI

#### Scenario: a gated synchronization pauses before any writes

- **GIVEN** a Synchronization with `sourceConfig.requiresApproval: true` and
  no existing `approval_request` for it
- **WHEN** `synchronize()` runs and source fetch + mapping complete
- **THEN** an `approval_request` is created with this synchronization's id,
  the approver group is notified, the `synchronization_log` records a
  `pending_approval` outcome, and no target objects are written or
  garbage-collected

#### Scenario: approval resumes the batch write via a bypass token, not a re-serialized payload

- **GIVEN** an `approval_request` for a gated synchronization is approved
- **WHEN** `ApprovalService::resume()` re-invokes `synchronize()` with
  `force: true` and the approved request's id
- **THEN** the gate check finds the approved, unconsumed `approval_request`,
  the `updateTarget()` write loop proceeds for every fetched/mapped object,
  and the `approval_request` is marked consumed on completion

#### Scenario: an ungated synchronization is unaffected

- **GIVEN** a Synchronization with `sourceConfig.requiresApproval` absent or
  `false`
- **WHEN** `synchronize()` runs
- **THEN** the write loop proceeds exactly as before this change, with no
  `approval_request` created

#### Notes

- The gate is evaluated once per synchronization run, not per object —
  garbage collection (`deleteInvalidObjects()`) and per-object contract
  writes are both part of the gated write phase and do not run until
  approval.
- Rejecting or letting a gated synchronization's `approval_request` expire
  applies the same `onReject`/`onTimeout` outcomes as the endpoint-rule
  case (`approval-workflow` REQ-004/REQ-005); a `skip` outcome for a
  synchronization means the run completes with zero writes rather than
  skipping a single rule.
