# HITL approvals on the shared task service

## Why

integriq carries its own human-in-the-loop machinery: `approval_request`
objects with `expiresAt`, `onTimeout` and `onReject`, a 300s
`ApprovalTimeoutSweepJob`, and an imperative approver notification. The
fleet now has one task service in OpenRegister, and wave 2 of the
consolidation moved exactly these semantics into it
(openregister `task-expiry-and-outcomes`): tasks declare `onTimeout` and
`onReject` in the same vocabulary, and the shared sweep enforces `expiresAt`.
Keeping a second copy here means two sweeps, two vocabularies and an
approval inbox nobody shares.

## What changes

This change is the ADOPTION SEAM, not the full retirement. The
`approval_request` record stays the system of record for suspend/resume
orchestration (FlowToken snapshots, resume ordering, consumption), because
that orchestration is composed by `EndpointService`, `FlowRunnerService`,
`SynchronizationService` and two controllers, and ripping it out in the same
PR that introduces the seam would be a half-delete.

- Every `ApprovalService::suspend*()` also creates ONE shared task through
  OpenRegister's `TaskService::import()`: candidate group, requester,
  `expiresAt`, `onTimeout`, `onReject` and a metadata link to the
  approval_request. The task uuid lands on the approval_request as
  `taskUuid`.
- Expiry of the mirrored task is OWNED by OpenRegister's timer sweep: the
  task declares `onTimeout`, so the shared machinery closes it. integriq's
  own sweep keeps resolving the `approval_request` record.
- `completeApproval()` and `reject()` close the mirrored task with the
  matching outcome (`approved`, `rejected`, or `dead_letter` when the
  record's `onReject` said so), so the shared inbox never shows a decided
  approval as open.
- A mirror failure never fails the approval flow: created, closed or
  skipped, the approval_request behaviour is unchanged.

## Follow-ups (tracked, not in this PR)

1. Listen to OpenRegister's `TaskTransitionedEvent` for mirrored tasks and
   resolve the approval_request from the task side, then retire
   `ApprovalTimeoutSweepJob` for mirrored rows.
2. Drive approve/reject from the shared task inbox (task-first), reducing
   `ApprovalsController` to the resume orchestration.
3. Translate the mirrored task's title and description.

## Impact

- Affected specs: hitl-on-shared-tasks (new delta), referencing
  approval-workflow.
- Affected code: `lib/Service/ApprovalService.php`, test stubs for the
  OpenRegister task service.
- Depends on: openregister `task-expiry-and-outcomes` (runtime only; tests
  stub the shared service).
