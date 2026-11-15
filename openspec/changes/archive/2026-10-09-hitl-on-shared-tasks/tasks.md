# Tasks: hitl-on-shared-tasks

## 1. The seam (this PR)

- [x] 1.1 `ApprovalService` gains the nullable shared task service and a
      `mirrorIntoSharedTask()` called from all four suspend paths, linking
      `taskUuid` onto the approval_request; failures logged, never thrown.
- [x] 1.2 `completeApproval()`/`reject()` close the mirror with the
      matching outcome through the shared outcome path.
- [x] 1.3 Test stubs for `OCA\OpenRegister\Service\Task\TaskService` and
      `OCA\OpenRegister\Db\Task` with the real signatures, registered in
      the bootstrap.
- [x] 1.4 Unit tests: mirror created and linked, decision closes it,
      failures never gate the approval flow.

## 2. Follow-ups (built 9 Oct 2026, build/openspecs-1)

- [x] 2.1 Listen to `TaskTransitionedEvent` for mirrored tasks; resolve the
      approval_request task-first; retire `ApprovalTimeoutSweepJob` for
      mirrored rows (keep it for pre-seam rows).
- [x] 2.2 Drive approve/reject from the shared inbox; reduce
      `ApprovalsController` to resume orchestration.
- [x] 2.3 Delegate the approver notification to the shared task service and
      drop the imperative dispatch in `notifyApprovers()`.
- [x] 2.4 Translate the mirrored task's title and description.

Evidence: 2.1 `lib/EventListener/SharedApprovalTaskListener.php` (registered on
OpenRegister's `TaskTerminalEvent`, committed dispatch only) and
`ApprovalService::expireFromSharedTask()`/`sharedSweepOwns()`; 2.2
`lib/Service/ApprovalDecisionService.php` carries the resume paths the
controller used to compose, so both decision surfaces share them; 2.3
`ApprovalService::offerMirrorToPool()` (OpenRegister's `taskOfferedToPool`
rule notifies; integriq's own notification is the fallback); 2.4
`ApprovalService::translate()` with en and nl entries. Tests:
`tests/Unit/EventListener/SharedApprovalTaskListenerTest.php`,
`tests/Unit/Service/ApprovalServiceTaskFirstTest.php`, and
`tests/Unit/Controller/ApprovalsControllerTest.php` through the real
decision service.
