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

## 2. Follow-ups (tracked in the issue, NOT this PR)

- [ ] 2.1 Listen to `TaskTransitionedEvent` for mirrored tasks; resolve the
      approval_request task-first; retire `ApprovalTimeoutSweepJob` for
      mirrored rows (keep it for pre-seam rows).
- [ ] 2.2 Drive approve/reject from the shared inbox; reduce
      `ApprovalsController` to resume orchestration.
- [ ] 2.3 Delegate the approver notification to the shared task service and
      drop the imperative dispatch in `notifyApprovers()`.
- [ ] 2.4 Translate the mirrored task's title and description.
