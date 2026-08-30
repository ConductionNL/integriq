# Test Plan: hitl-approval-rule-action

## Test Cases

### TC-1: approval rule suspends the pipeline and persists an ApprovalRequest
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-001-endpoint-rule-pipeline-suspension-on-approval-action`
- **type**: api
- **persona**: N/A
- **preconditions**: an endpoint has a `before`-timing `approval` rule at order 20 configured with `approverGroup: "woo-approvers"`
- **steps**: send a request that reaches the pipeline and passes the rule's conditions
- **expected result**: HTTP 202 with `approvalRequestId` + status-polling URL; an `approval_request` object exists with `status: pending`, `resumeOrder: 20`, `approverGroup: "woo-approvers"`, a serialized snapshot, and `expiresAt`
- **test command**: /test-api

### TC-2: sensitive headers are stripped from the persisted snapshot
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-001-endpoint-rule-pipeline-suspension-on-approval-action`
- **type**: security
- **persona**: N/A
- **preconditions**: as TC-1, request carries an `Authorization` header
- **steps**: trigger suspension
- **expected result**: `approval_request.snapshot` does not contain the `Authorization` header value
- **test command**: /test-security

### TC-3: after-timing approval rule is rejected as invalid configuration
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-001-endpoint-rule-pipeline-suspension-on-approval-action`
- **type**: api
- **persona**: N/A
- **preconditions**: an `approval` rule configured with `timing: after`
- **steps**: attempt to save/use the rule configuration
- **expected result**: configuration is rejected; no suspension is ever attempted at runtime
- **test command**: /test-api

### TC-4: approver group receives an actionable notification
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-002-approver-notification-on-suspension`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin) — verifies the notification and its deep link are trustworthy and actionable
- **preconditions**: an `approval_request` created with `approverGroup: "woo-approvers"`; a test user is a member of that group
- **steps**: log in as the approver-group member, check NC notifications
- **expected result**: a notification with Approve/Reject actions deep-linking to `/apps/integriq/approvals/{id}` is present
- **test command**: /test-functional

### TC-5: ops group receives a passive visibility notification
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-002-approver-notification-on-suspension`
- **type**: functional
- **persona**: N/A
- **preconditions**: any `approval_request` is created
- **steps**: log in as a member of `openconnector-ops`, check NC notifications
- **expected result**: a non-interactive notification is present, matching the declarative dialect shape used by `openconnector-notifications`
- **test command**: /test-functional

### TC-6: approval resumes the pipeline with the original context and completes
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-003-resume-on-approval`
- **type**: api
- **persona**: N/A
- **preconditions**: a `pending` `approval_request` at `resumeOrder: 20` for an endpoint whose chain has a `save_object` rule at order 30
- **steps**: `POST /api/approvals/{id}/approve` as an authorized approver
- **expected result**: the order-30 rule runs against the rehydrated context, the object is persisted, the response carries the resumed pipeline's final result, `status` becomes `approved`, `resumeResult: success`
- **test command**: /test-api

### TC-7: a resumed chain failure is recorded, not silently swallowed
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-003-resume-on-approval`
- **type**: api
- **persona**: N/A
- **preconditions**: an `approval_request` whose resumed rule chain is configured to throw (e.g. a downstream rule referencing a now-invalid target)
- **steps**: approve the request
- **expected result**: HTTP 500 per the existing rule-pipeline contract; `resumeResult: error` recorded on the `approval_request`
- **test command**: /test-api

### TC-8: an already-resolved request cannot be approved again
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-003-resume-on-approval`
- **type**: api
- **persona**: N/A
- **preconditions**: an `approval_request` with `status: approved`
- **steps**: `POST /api/approvals/{id}/approve` a second time
- **expected result**: HTTP 409; the chain is not re-run
- **test command**: /test-api

### TC-9: rejection without a comment is refused
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-004-rejection-with-mandatory-audit-comment`
- **type**: api
- **persona**: N/A
- **preconditions**: a `pending` `approval_request`
- **steps**: `POST /api/approvals/{id}/reject` with an empty comment
- **expected result**: HTTP 400; request remains `pending`
- **test command**: /test-api

### TC-10: rejection is fully audited
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-004-rejection-with-mandatory-audit-comment`
- **type**: functional
- **persona**: Annemarie (VNG Standards Architect) — verifies audit completeness for compliance review
- **preconditions**: a `pending` `approval_request` configured `onReject: error`
- **steps**: reject with comment "Missing legal basis field" via the Approvals UI
- **expected result**: `status: rejected`, `approverUserId`/`rejectedAt`/comment recorded and visible on the detail page's audit trail; configured error outcome resolves, no further rules run, no target write
- **test command**: /test-persona-annemarie

### TC-11: expired pending request is swept to its configured fallback
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-005-timeout-sweeping-and-fallback-outcomes`
- **type**: functional
- **persona**: N/A
- **preconditions**: a `pending` `approval_request` with `expiresAt` in the past and `onTimeout: dead_letter`
- **steps**: run `ApprovalTimeoutSweepJob` (via `occ background-job:execute` or cron trigger)
- **expected result**: `status` becomes `dead_letter`
- **test command**: /test-functional

### TC-12: approving an already-expired request is refused
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-005-timeout-sweeping-and-fallback-outcomes`
- **type**: api
- **persona**: N/A
- **preconditions**: a `pending` `approval_request` with `expiresAt` in the past, sweep job not yet run
- **steps**: `POST /api/approvals/{id}/approve`
- **expected result**: HTTP 409; pipeline not resumed
- **test command**: /test-api

### TC-13: dead-lettered requests are discoverable, not silently dropped
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-005-timeout-sweeping-and-fallback-outcomes`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: at least one `approval_request` with `status: dead_letter`
- **steps**: open the Pending Approvals list, apply the "dead-lettered" filter
- **expected result**: the request appears with its snapshot summary and full audit trail
- **test command**: /test-persona-noor

### TC-14: unauthorized user cannot approve (approverGroup mismatch)
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-006-two-layer-authorization-for-approvereject`
- **type**: security
- **persona**: N/A
- **preconditions**: a user is in the app-wide `approval.approve` action-matrix group but NOT a member of `"woo-approvers"`
- **steps**: `POST /api/approvals/{id}/approve`
- **expected result**: HTTP 403; `status` remains `pending`
- **test command**: /test-security

### TC-15: a user outside the action matrix cannot approve even if in the approver group
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-006-two-layer-authorization-for-approvereject`
- **type**: security
- **persona**: N/A
- **preconditions**: a non-admin user is a member of `"woo-approvers"` but not in the `approval.approve` action-matrix group (still default `["admin"]`)
- **steps**: `POST /api/approvals/{id}/approve`
- **expected result**: HTTP 403
- **test command**: /test-security

### TC-16: an NC admin may approve regardless of approverGroup membership
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-006-two-layer-authorization-for-approvereject`
- **type**: security
- **persona**: N/A
- **preconditions**: an NC admin not in `"woo-approvers"`
- **steps**: `POST /api/approvals/{id}/approve`
- **expected result**: approval succeeds (admin break-glass)
- **test command**: /test-security

### TC-17: approvals list page mounts and shows content
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-007-pending-approvals-ui`
- **type**: functional
- **persona**: Mark (MKB Software Vendor) — an integrator configuring/monitoring flows
- **preconditions**: at least one approval request exists the current user can see
- **steps**: navigate to the Approvals section via sidebar and direct URL
- **expected result**: index page renders inside app-content with visible content
- **test command**: /test-persona-mark

### TC-18: approve/reject actions are available and gated correctly from the detail page
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-007-pending-approvals-ui`
- **type**: accessibility
- **persona**: N/A
- **preconditions**: a `pending` request the current user is authorized to act on
- **steps**: open detail page, inspect Approve/Reject controls with a screen reader / keyboard-only navigation
- **expected result**: Approve and Reject are standard NC button components (not icon-only), reachable via keyboard; Reject is disabled until a non-empty comment is entered
- **test command**: /test-accessibility

### TC-19: a gated synchronization pauses before any writes
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/synchronization-engine/spec.md#req-006-batch-level-approval-gate-before-target-writes`
- **type**: api
- **persona**: N/A
- **preconditions**: a Synchronization with `sourceConfig.requiresApproval: true`, no existing `approval_request`
- **steps**: trigger `synchronize()` (via `run` endpoint or cron)
- **expected result**: an `approval_request` (with `synchronizationId` set) is created, approver group notified, `synchronization_log` records `pending_approval`, zero target objects written or garbage-collected
- **test command**: /test-api

### TC-20: approval resumes the batch write via a bypass token
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/synchronization-engine/spec.md#req-006-batch-level-approval-gate-before-target-writes`
- **type**: api
- **persona**: N/A
- **preconditions**: TC-19's `approval_request` approved
- **steps**: `ApprovalService::resume()` re-invokes `synchronize(force: true)` with the approved request id
- **expected result**: the gate passes, all fetched/mapped objects are written, `approval_request` marked consumed
- **test command**: /test-api

### TC-21: an ungated synchronization is unaffected (regression)
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/specs/synchronization-engine/spec.md#req-006-batch-level-approval-gate-before-target-writes`
- **type**: regression
- **persona**: N/A
- **preconditions**: an existing Synchronization with `sourceConfig.requiresApproval` absent or `false`
- **steps**: run the full existing synchronization-engine test suite against it
- **expected result**: write behavior is byte-for-byte unchanged from before this change; no `approval_request` created
- **test command**: /test-regression

### TC-22: approval rule action type in the Rule editor UI
- **spec_ref**: `openspec/changes/hitl-approval-rule-action/tasks.md#task-13`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator) — configures a new rule end-to-end
- **preconditions**: an existing rule object
- **steps**: open the rule detail page, pick action type "Approval", fill in approver group / TTL / onReject / onTimeout
- **expected result**: `ApprovalForm.vue` renders and the saved rule configuration round-trips correctly
- **test command**: /test-persona-priya

## Coverage Summary

| Requirement | Covered by | Status |
|---|---|---|
| approval-workflow REQ-001 (suspension) | TC-1, TC-2, TC-3 | covered |
| approval-workflow REQ-002 (notifications) | TC-4, TC-5 | covered |
| approval-workflow REQ-003 (resume) | TC-6, TC-7, TC-8 | covered |
| approval-workflow REQ-004 (rejection audit) | TC-9, TC-10 | covered |
| approval-workflow REQ-005 (timeout sweep) | TC-11, TC-12, TC-13 | covered |
| approval-workflow REQ-006 (two-layer authz) | TC-14, TC-15, TC-16 | covered |
| approval-workflow REQ-007 (Approvals UI) | TC-17, TC-18 | covered |
| rule-pipeline REQ-RULE-008 (approval dispatch) | TC-1, TC-3 (dispatch-level assertions folded into these) | covered |
| synchronization-engine REQ-015 (batch gate) | TC-19, TC-20, TC-21 | covered |
| Rule editor `approval` action form | TC-22 | covered |

## Out of Scope

- Talk-message approvals — no test cases (feature is notification-only, per proposal.md Out of Scope).
- Multi-step/quorum approvals — no test cases (single-approver model only).
- Dead-lettered approval replay — no test cases (explicitly deferred, proposal.md Open Questions).
- decidesk integration — no test cases (out of scope for this change).
