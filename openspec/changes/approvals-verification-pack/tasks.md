# Tasks: approvals-verification-pack

## 1. Integration test

### Task 1: Suspend → approve → resume through a real rule chain
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md`
- **files**: `tests/Integration/ApprovalRuleChainTest.php`
- **acceptance_criteria**:
  - GIVEN an endpoint with a `before`-phase `approval` rule followed by a later rule WHEN the endpoint is called THEN the response is `202` with a polling URL and an `approval_request` persists the FlowToken snapshot
  - GIVEN that request is approved WHEN `ApprovalsController::approve()` runs THEN `processRules()` resumes at the rule after the approval rule and the chain completes
  - GIVEN that request is rejected THEN the rule's configured `onReject` outcome is applied
- [ ] Implement
- [ ] Test

## 2. Newman

### Task 2: `/api/approvals*` scenarios in the Postman collection
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md`
- **files**: `tests/postman/` (existing collection)
- **acceptance_criteria**:
  - GIVEN the collection runs against a live instance THEN list, detail, approve and reject succeed for an approver-group member
  - GIVEN a non-member calls approve THEN `403`; GIVEN a missing id THEN `404`; GIVEN a double approve THEN `409`
- [ ] Implement
- [ ] Test

## 3. Playwright

### Task 3: Pending Approvals list + detail
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md`
- **files**: `tests/e2e/spec-coverage/approval-workflow.spec.ts`
- **acceptance_criteria**:
  - GIVEN a pending request WHEN an approver opens the Approvals pages THEN they can approve with a comment and the row leaves the pending list
  - GIVEN a pending request THEN reject with a comment records the rejection
- [ ] Implement
- [ ] Test

### Task 4: Rule editor `approval` action form
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md`
- **files**: `tests/e2e/spec-coverage/approval-workflow.spec.ts`
- **acceptance_criteria**:
  - GIVEN the rule editor WHEN `approval` is chosen as the action type THEN the approver-group, expiry and onReject/onTimeout fields render and persist on save
- [ ] Implement
- [ ] Test

## 4. Docs & l10n

### Task 5: Feature documentation + screenshot
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md`
- **files**: `docs/`, `docs/images/`
- **acceptance_criteria**:
  - GIVEN `docs/` THEN a page describes the `approval` rule action, the `requiresApproval` sync gate and the Pending Approvals UI, with one committed screenshot
- [ ] Implement
- [ ] Test

### Task 6: l10n catalog entries
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md`
- **files**: `l10n/`
- **acceptance_criteria**:
  - GIVEN the Approvals UI strings THEN `nl_NL` catalog entries exist (or the external localization pipeline demonstrably carries them; record which)
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] All tests pass (`composer test`, `newman run`, Playwright suite)
