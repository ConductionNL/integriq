# Tasks: hitl-approval-rule-action

## Implementation Tasks

### Task 1: Declare the `approval_request` register schema
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/design.md#database-changes`
- **files**: `lib/Settings/register.d/hitl-approval-rule-action.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the app boots THEN `lib/Repair/InitializeRegister.php` merges the fragment and the `approval_request` schema exists on the `openconnector` register with the fields listed in design.md's Database Changes table
  - GIVEN the fragment JSON WHEN parsed THEN it is valid JSON and does not redeclare any existing schema slug
- [x] Implement
- [x] Test (tests/Unit/Settings/HitlApprovalRegisterFragmentTest.php)

### Task 2: Seed ADR-023 action-matrix entries
- **spec_ref**: `openspec/specs/approval-workflow/spec.md#req-006-two-layer-authorization-for-approvereject`
- **files**: `lib/actions.seed.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN `InitializeActions` runs THEN `approval.approve` and `approval.reject` exist in the matrix, both defaulting to `["admin"]`
  - GIVEN an existing non-empty matrix (upgrade case) WHEN the repair step runs THEN existing customized entries are left untouched (matches `InitializeActions`'s documented preserve-on-upgrade behavior)
- [x] Implement
- [x] Test (tests/Unit/Settings/HitlApprovalRegisterFragmentTest.php::testActionMatrixSeedDeclaresApprovalActionsDefaultingToAdmin — preserve-on-upgrade behavior itself is InitializeActions's pre-existing, already-tested contract, not re-tested here)

### Task 3: `ApprovalService::suspend()` — persist ApprovalRequest and short-circuit
- **spec_ref**: `openspec/specs/approval-workflow/spec.md#req-001-endpoint-rule-pipeline-suspension-on-approval-action`
- **files**: `lib/Service/ApprovalService.php`
- **acceptance_criteria**:
  - GIVEN a `before`-timing `approval` rule whose conditions pass WHEN `suspend()` runs THEN an `approval_request` object is created with `status: pending`, `resumeOrder` set to the rule's `order`, a stripped `FlowToken` snapshot (no `Authorization` header), `approverGroup`, `onReject`/`onTimeout`, and `expiresAt`
  - GIVEN an `approval` rule configured with `timing: after` WHEN it is evaluated THEN the configuration is rejected (no suspension attempted)
- [x] Implement
- [x] Test (tests/Unit/Service/ApprovalServiceTest.php::testSuspendPersistsPendingAndStripsSensitiveHeaders; the timing:after guard itself lives in EndpointService::processApprovalRule and is covered there by testProcessApprovalRuleAfterThrows)

### Task 4: Wire `approval` into `EndpointService::processRules()`
- **spec_ref**: `openspec/specs/rule-pipeline/spec.md#req-rule-008-approval-rule-action-type-suspends-the-pipeline`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN an endpoint with rules at order 10/20(`approval`)/30 WHEN the before-phase pipeline runs and order 20's conditions pass THEN order 10 runs, order 20 suspends via `ApprovalService::suspend()` returning `JSONResponse(202, ...)` through the existing short-circuit check in `doHandleRequest()`, and order 30 does not run in this request
- [x] Implement
- [x] Test (tests/Unit/Service/EndpointServiceTest.php::testProcessApprovalRuleBeforeSuspendsWith202/testProcessApprovalRuleAfterThrows — dispatch-level only; no test constructs a full 10/20/30 multi-rule chain in one run. The "earlier rule runs, later rule doesn't" behavior reuses the pre-existing `error`-rule short-circuit contract as-is (design.md Decision 2, verified by code inspection of transformError()'s 2xx pass-through), not re-proven end-to-end here)

### Task 5: `ApprovalService::notifyApprovers()` — imperative actionable notification
- **spec_ref**: `openspec/specs/approval-workflow/spec.md#req-002-approver-notification-on-suspension`
- **files**: `lib/Service/ApprovalService.php`
- **acceptance_criteria**:
  - GIVEN an `approval_request` created with `approverGroup: "woo-approvers"` WHEN `notifyApprovers()` runs THEN every member of that NC group receives a notification with approve/reject actions deep-linking to `/apps/openconnector/approvals/{id}`
- [x] Implement
- [x] Test (tests/Unit/Service/ApprovalServiceTest.php::testNotifyApproversNotifiesEachGroupMemberWithActionableDeepLinks)

### Task 6: Declarative ops-visibility notification on `approval_request`
- **spec_ref**: `openspec/specs/approval-workflow/spec.md#req-002-approver-notification-on-suspension`
- **files**: `lib/Settings/register.d/hitl-approval-rule-action.json`
- **acceptance_criteria**:
  - GIVEN any `approval_request` is created WHEN the declarative `x-openregister-notifications` `created` rule evaluates THEN the `openconnector-ops` group receives a notification via the OpenRegister notification engine, matching the shape already used by `openconnector-notifications`'s existing rules
- [x] Implement
- [x] Test (tests/Unit/Settings/HitlApprovalRegisterFragmentTest.php::testDeclarativeOpsVisibilityNotificationTargetsOpsGroup — asserts the fragment's declarative shape; actual OpenRegister notification-engine delivery is that engine's own pre-existing, already-tested contract, not re-tested here)

### Task 7: `ApprovalService::rehydrateFlowToken()` and `resume()` — approval path
- **spec_ref**: `openspec/specs/approval-workflow/spec.md#req-003-resume-on-approval`
- **files**: `lib/Service/ApprovalService.php`
- **acceptance_criteria**:
  - GIVEN a `pending`, non-expired `approval_request` at `resumeOrder: 20` for an endpoint whose chain has a `save_object` rule at order 30 WHEN an authorized approver approves it THEN the FlowToken is rehydrated via public setters (no native `unserialize`), rules with `order > 20` in the same phase run, the schema/target dispatch and `after`-phase rules complete normally, `status` becomes `approved`, and `resumeResult` is set to `success`/`error` accordingly
  - GIVEN an `approval_request` with `status: approved` WHEN a second approve call targets the same id THEN the system returns HTTP 409 and does not re-run the chain
- [x] Implement
- [x] Test (tests/Unit/Service/ApprovalServiceTest.php::testRehydrateFlowTokenUsesPublicSetters; tests/Unit/Controller/ApprovalsControllerTest.php::testApproveEndpointHappyPathResumesAndCompletes/testApproveAlreadyResolvedReturns409)

### Task 8: `ApprovalsController::approve()` / `reject()` — two-layer authorization
- **spec_ref**: `openspec/specs/approval-workflow/spec.md#req-006-two-layer-authorization-for-approvereject`
- **files**: `lib/Controller/ApprovalsController.php`
- **acceptance_criteria**:
  - GIVEN a user in the app-wide `approval.approve` action-matrix group but NOT a member of the request's `approverGroup` WHEN they call approve THEN HTTP 403 and `status` remains `pending`
  - GIVEN a non-admin user in `approverGroup` but not in the `approval.approve` action-matrix group WHEN they call approve THEN HTTP 403 (matrix check fails first)
  - GIVEN an NC admin not in `approverGroup` WHEN they call approve THEN the approval succeeds
  - GIVEN a reject call with an empty comment WHEN submitted THEN HTTP 400 and the request remains `pending`
- [x] Implement
- [x] Test (tests/Unit/Controller/ApprovalsControllerTest.php::testApproveDeniedByActionMatrixLayer/testApproveDeniedByPerObjectGroupLayer/testRejectEmptyCommentReturns400; tests/Unit/Service/ApprovalServiceTest.php::testAuthorizedApproverAdminAlwaysPasses/testAuthorizedApproverGroupMemberPasses/testAuthorizedApproverNonMemberDenied)

### Task 9: Reject flow — mandatory comment, `onReject` outcomes
- **spec_ref**: `openspec/specs/approval-workflow/spec.md#req-004-rejection-with-mandatory-audit-comment`
- **files**: `lib/Service/ApprovalService.php`, `lib/Controller/ApprovalsController.php`
- **acceptance_criteria**:
  - GIVEN a `pending` request configured `onReject: error` WHEN an authorized approver rejects with a comment THEN `status` becomes `rejected`, `approverUserId`/`rejectedAt`/`comment` are recorded, and the configured error outcome resolves without further rule execution or target write
- [x] Implement
- [x] Test (tests/Unit/Service/ApprovalServiceTest.php::testRejectRequiresComment/testRejectErrorOutcomeRecordsRejected/testRejectDeadLetterOutcomeSetsDeadLetter)

### Task 10: `ApprovalTimeoutSweepJob` cron + synchronous expiry re-check
- **spec_ref**: `openspec/specs/approval-workflow/spec.md#req-005-timeout-sweeping-and-fallback-outcomes`
- **files**: `lib/Cron/ApprovalTimeoutSweepJob.php`, `lib/AppInfo/Application.php`, `lib/Controller/ApprovalsController.php`
- **acceptance_criteria**:
  - GIVEN a `pending` request with `expiresAt` in the past and `onTimeout: dead_letter` WHEN `ApprovalTimeoutSweepJob` runs THEN `status` becomes `dead_letter`
  - GIVEN a `pending` request with `expiresAt` in the past that the sweep job has not yet processed WHEN an approver attempts to approve it THEN HTTP 409 and the pipeline is not resumed
- [x] Implement
- [x] Test (tests/Unit/Cron/ApprovalTimeoutSweepJobTest.php; tests/Unit/Service/ApprovalServiceTest.php::testSweepExpiredAppliesTimeoutOutcome/testAssertActionableRejectsExpiredPending; tests/Unit/Controller/ApprovalsControllerTest.php::testApproveExpiredReturns409)

### Task 11: Synchronization batch approval gate
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#req-006-batch-level-approval-gate-before-target-writes`
- **files**: `lib/Service/SynchronizationService.php`, `lib/Service/ApprovalService.php`
- **acceptance_criteria**:
  - GIVEN a Synchronization with `sourceConfig.requiresApproval: true` and no existing `approval_request` WHEN `synchronize()` runs and fetch+mapping complete THEN an `approval_request` (with `synchronizationId` set) is created, the approver group is notified, the `synchronization_log` records a `pending_approval` outcome, and no target writes/garbage-collection occur
  - GIVEN that request is approved WHEN `ApprovalService::resume()` re-invokes `synchronize(force: true)` with the approved request's id THEN the gate passes, all writes proceed, and the request is marked consumed
  - GIVEN `sourceConfig.requiresApproval` is absent/false WHEN `synchronize()` runs THEN behavior is unchanged from before this change
- [x] Implement
- [x] Test (tests/Unit/Service/SynchronizationServiceApprovalGateTest.php::testGatedSyncPausesBeforeWrites/testUngatedSyncNeverTouchesApprovalService/testResolveApprovalForSynchronizationBypassToken)

### Task 12: `ApprovalsController` REST routes
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/design.md#api-design`
- **files**: `appinfo/routes.php`, `lib/Controller/ApprovalsController.php`
- **acceptance_criteria**:
  - GIVEN the routes are registered WHEN `GET /api/approvals`, `GET /api/approvals/{id}`, `POST /api/approvals/{id}/approve`, `POST /api/approvals/{id}/reject` are called by an authenticated user THEN each resolves to the corresponding controller method with standard NC CSRF protection applied (no `#[NoCSRFRequired]`)
- [x] Implement
- [x] Test (`composer check:routes` mechanically verifies all 4 routes resolve to existing ApprovalsController methods — "PASS — all 134 routes point at existing controller methods"; tests/Unit/Controller/ApprovalsControllerTest.php exercises index/show/approve/reject behavior directly; CSRF posture confirmed by code inspection — no `#[NoCSRFRequired]` present, not independently asserted by a test)

### Task 13: `approval` rule action type in the Rule editor UI
- **spec_ref**: `openspec/specs/approval-workflow/spec.md#req-007-pending-approvals-ui`
- **files**: `src/views/Rule/RuleActionConfig.vue`, `src/views/Rule/actionForms/ApprovalForm.vue`
- **acceptance_criteria**:
  - GIVEN the Rule detail page WHEN an admin picks action type "Approval" THEN `ApprovalForm.vue` renders fields for approver group (NC group picker), TTL, `onReject`, and `onTimeout`, matching the existing per-type form convention (`ACTION_TYPES`/`ACTION_FORM_MAP`)
- [x] Implement
- [ ] Test — no Vue unit test (no jest/vitest spec for ApprovalForm.vue exists in this app); coverage is Playwright/live-instance only per test-plan.md TC-22 (persona Priya), not run in this finalization pass

### Task 14: Pending Approvals list + detail pages
- **spec_ref**: `openspec/specs/approval-workflow/spec.md#req-007-pending-approvals-ui`
- **files**: `src/views/Approvals/ApprovalsIndex.vue`, `src/views/Approvals/ApprovalDetail.vue`, router wiring
- **acceptance_criteria**:
  - GIVEN an authenticated user with access to at least one approval request WHEN they navigate to the Approvals section THEN the index page renders inside the app-content area with content visible, filterable by `pending`/`approved`/`rejected`/`expired`/`dead_letter`
  - GIVEN a `pending` request the current user is authorized to act on WHEN they open its detail page THEN Approve and Reject actions are visible and Reject is disabled until a non-empty comment is entered
- [x] Implement
- [ ] Test — no Vue unit test for ApprovalsIndex.vue/ApprovalDetail.vue; coverage is Playwright/live-instance only per test-plan.md TC-17/TC-18 (persona Mark, accessibility), not run in this finalization pass

### Task 15: Seed data
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/design.md#seed-data`
- **files**: `lib/Settings/register.d/hitl-approval-rule-action.json` (or `openconnector_seed_data.json`, per existing seed convention)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the app boots THEN three `approval_request` seed objects exist (pending/approved/rejected) matching design.md's Seed Data table
- [x] Implement
- [x] Test (tests/Unit/Settings/HitlApprovalRegisterFragmentTest.php::testSeedDataDeclaresThreeRepresentativeStates)

## Verification
- [ ] All tasks checked off — Tasks 13/14's Test boxes remain open (Playwright/live-instance only, not run this pass)
- [x] `openspec validate` passes (scoped: `openspec validate approval-workflow --type spec --strict`, `rule-pipeline`, `synchronization-engine` — all three valid)
- [ ] Manual testing against acceptance criteria — no live-instance browser walkthrough performed in this finalization pass; recommend before merge
- [x] Code review against spec requirements — every implementation task verified against design.md/specs/*.md and the actual code in this finalization pass (see PR/commit history for findings and fixes)

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for the `approval_request` state machine (pending → approved/rejected/expired/dead_letter transitions, invalid-transition rejections) in `tests/Unit/Service/ApprovalServiceTest.php`
- [ ] PHPUnit integration test for suspend → approve → resume through a real endpoint rule chain (`tests/Integration/` or equivalent, per existing `rule-pipeline` test conventions) — not added; suspend/approve/resume is covered at the unit level with mocked collaborators across ApprovalServiceTest/EndpointServiceTest/ApprovalsControllerTest, not as one real-rule-chain integration test
- [ ] Newman/Postman tests for `/api/approvals*` endpoints (list, detail, approve, reject, 403/404/409 error paths) — not added; `tests/postman/openconnector.postman_collection.json` has zero approval-related changes
- [ ] Browser tests (Playwright MCP) for the Pending Approvals list/detail pages and the Rule editor's new `approval` action form — not run in this finalization pass (no live-instance session)
- [ ] All tests pass (`composer test`, `newman run`) — PHPUnit is green (1009/1009); `newman run` was not executed (no approval scenarios exist in the collection, per above)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` describing the `approval` rule action type, the Synchronization `requiresApproval` gate, and the Pending Approvals UI — not done; zero `docs/` changes in this diff
- [ ] Screenshot captured and committed to `docs/images/` (Pending Approvals list + detail with Approve/Reject) — not done; requires a live-instance walkthrough

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added for: the Approvals UI, the `ApprovalForm.vue` rule-editor fields, and the declarative ops-visibility notification's `subject.nl`/`subject.en` — the notification's `subject.nl`/`subject.en` ARE present in the register fragment (see Task 6); UI strings use `t('openconnector', ...)` inline (extractable via standard NC l10n tooling) but no compiled `l10n/*.json` catalog entries were added/updated in this pass
