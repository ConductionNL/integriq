# approval-workflow Specification

## Purpose
TBD - created by archiving change hitl-approval-rule-action. Update Purpose after archive.
## Requirements
### Requirement: Endpoint rule pipeline suspension on `approval` action (REQ-001)

The system MUST suspend the run when the endpoint rule pipeline
(`rule-pipeline` REQ-RULE-001) processes an `approval`-type rule during the
`before` timing phase and the rule's JSON-Logic conditions pass, instead of
continuing the pipeline: it MUST serialize the current `FlowToken` (via
`__serialize()`, per `flow-token-helper` REQ-005) with any configured
sensitive headers (at minimum `Authorization`) stripped, and persist an
`approval_request` OpenRegister object carrying `status: pending`, the
endpoint id, the approval rule id, the rule's numeric `order` as
`resumeOrder`, the stripped snapshot, the requesting user id (or null for
unauthenticated/API-key callers), the rule-configured `approverGroup`, the
rule-configured `onReject`/`onTimeout` outcomes, and an `expiresAt`
timestamp computed from the rule's configured TTL (default 24 hours). The
system MUST then short-circuit the pipeline (reusing the existing
`JSONResponse` short-circuit contract used by `error` and other
short-circuiting rules) and return HTTP 202 Accepted with the created
`approval_request` id and a status-polling URL. An `approval` rule
configured with `timing: after` MUST be rejected as invalid configuration
(the rule pipeline only supports pre-write suspension).

@e2e exclude backend suspension internals — covered by PHPUnit, not browser UI

#### Scenario: approval rule suspends the pipeline and persists an ApprovalRequest

- **GIVEN** an endpoint with a `before`-timing `approval` rule at order 20,
  configured with `approverGroup: "woo-approvers"`
- **WHEN** a request reaches the pipeline and the rule's conditions pass
- **THEN** an `approval_request` object is created with `status: pending`,
  `resumeOrder: 20`, `approverGroup: "woo-approvers"`, a serialized
  `FlowToken` snapshot, and an `expiresAt` timestamp
- **AND** the pipeline returns HTTP 202 with the `approval_request` id and a
  status-polling URL, and no later rules run

#### Scenario: sensitive headers are stripped from the persisted snapshot

- **GIVEN** an incoming request carrying an `Authorization` header
- **WHEN** the pipeline suspends on an `approval` rule
- **THEN** the persisted `approval_request.snapshot` does NOT contain the
  `Authorization` header value

#### Scenario: an `after`-timing approval rule is rejected as invalid configuration

- **GIVEN** an `approval` rule configured with `timing: after`
- **WHEN** the rule is evaluated
- **THEN** the system rejects the configuration (no suspension is
  attempted; the rule pipeline only supports pre-write approval gating)

#### Notes

- The suspension point is `EndpointService::doHandleRequest()`'s existing
  `$ruleResult instanceof JSONResponse` short-circuit check, which already
  aborts the pipeline for `error` rules — no new Response type is
  introduced (design.md Decision 2).

### Requirement: Approver notification on suspension (REQ-002)

When an `approval_request` is created, the system MUST notify the
configured `approverGroup` with an actionable notification carrying
approve/reject deep links into the Integriq Pending Approvals UI. Because
the declarative `x-openregister-notifications` dialect (ADR-031) supports
only a single-userId `field` recipient or a schema-static `groups` recipient
— neither of which can express a per-rule-configured dynamic approver group
— and carries no interactive-action syntax at all, this actionable
notification MUST be dispatched imperatively via `OCP\Notification\IManager`,
scoped to a single `ApprovalService` method. Independently, the
`approval_request` schema MUST declare a compliant, declarative
`x-openregister-notifications` `created` rule targeting a static
`openconnector-ops` group, for passive operational visibility of every
approval request regardless of its specific approver group.

@e2e exclude backend notification dispatch — covered by PHPUnit, not browser UI

#### Scenario: approver group receives an actionable notification

- **GIVEN** an `approval_request` created with `approverGroup: "woo-approvers"`
- **WHEN** the request is persisted
- **THEN** every member of the `woo-approvers` NC group receives an NC
  notification with approve/reject actions deep-linking to
  `/apps/integriq/approvals/{id}`

#### Scenario: ops group receives a passive visibility notification

- **GIVEN** any `approval_request` is created (any `approverGroup`)
- **WHEN** the declarative `x-openregister-notifications` `created` rule on
  `approval_request` evaluates
- **THEN** the `openconnector-ops` NC group receives a notification via the
  standard OpenRegister notification engine (no interactive actions)

### Requirement: Resume on approval (REQ-003)

The system MUST, when an authorized approver approves a `pending`,
non-expired `approval_request`, resume within the approver's own HTTP
request (no queued background job for this path — design.md Decision 3):
rehydrate a `FlowToken` from the persisted snapshot via the public setters
(there is no `__unserialize()` per `flow-token-helper`'s documented gap),
resume `processRules()` for the same `timing` phase starting with the first
rule whose `order` is strictly greater than the `approval_request`'s
`resumeOrder`, and — once the resumed `before`-phase rules complete without
a further short-circuit — continue `doHandleRequest()`'s normal dispatch
(schema/target write, then `after`-phase rules) exactly as an unsuspended
request would. On successful completion the system MUST set
`approval_request.status = 'approved'`, record `approverUserId`,
`approvedAt`, and optional `comment`, set `resumeResult = 'success'`, and
return the resumed pipeline's final result (not another 202).

@e2e exclude backend resume internals — covered by PHPUnit, not browser UI

#### Scenario: approval resumes the pipeline with the original context and completes

- **GIVEN** a `pending` `approval_request` at `resumeOrder: 20` for an
  endpoint whose rule chain has a `save_object` rule at order 30
- **WHEN** an authorized approver approves the request
- **THEN** the `save_object` rule at order 30 runs against the rehydrated
  FlowToken's amended request data, the object is persisted, and the
  approver's HTTP response carries the resumed pipeline's final result
- **AND** `approval_request.status` becomes `approved` with `approverUserId`,
  `approvedAt`, and `resumeResult: 'success'` recorded

#### Scenario: a resumed chain failure is recorded, not silently swallowed

- **GIVEN** an approved `approval_request` whose resumed rule chain throws
- **WHEN** the resumed pipeline fails
- **THEN** the response mirrors the rule pipeline's existing HTTP 500
  contract (`rule-pipeline` REQ-RULE-001) and `approval_request.resumeResult`
  is set to `'error'`

#### Scenario: an already-resolved request cannot be approved again

- **GIVEN** an `approval_request` with `status: approved`
- **WHEN** a second approve call is made against the same id
- **THEN** the system returns HTTP 409 and does not re-run the rule chain

### Requirement: Rejection with mandatory audit comment (REQ-004)

The system MUST, when an authorized approver rejects a `pending`,
non-expired `approval_request`, require a non-empty `comment`, set
`status: 'rejected'`, record `approverUserId`, `rejectedAt`, and the
comment, and apply the rule-configured `onReject` outcome: `error` (the
original caller's eventual status poll reflects a configured error
response), `skip` (the run is marked resolved without further rule
execution or a target write), or `dead_letter` (see REQ-005).

@e2e exclude backend rejection internals — covered by PHPUnit, not browser UI

#### Scenario: rejection without a comment is refused

- **GIVEN** a `pending` `approval_request`
- **WHEN** an authorized approver submits a reject with an empty comment
- **THEN** the system returns HTTP 400 and the request remains `pending`

#### Scenario: rejection is fully audited

- **GIVEN** a `pending` `approval_request` configured `onReject: error`
- **WHEN** an authorized approver rejects it with comment "Missing legal
  basis field"
- **THEN** `approval_request.status` becomes `rejected`, `approverUserId`,
  `rejectedAt`, and the comment are recorded, and the run resolves to the
  configured error outcome without executing further rules or writing to
  the target

### Requirement: Timeout sweeping and fallback outcomes (REQ-005)

The system MUST run a periodic cron job (`ApprovalTimeoutSweepJob`, a
`TimedJob` matching the existing `EventRetryJob` cadence/idiom) that finds
every `approval_request` with `status: pending` and `expiresAt` in the past,
and applies its configured `onTimeout` outcome (`error`, `skip`, or
`dead_letter`), setting `status: 'expired'` for `error`/`skip` outcomes or
`status: 'dead_letter'` for the `dead_letter` outcome. `approve()`/`reject()`
MUST also re-check `expiresAt` synchronously (not rely solely on the sweep
job's cadence) and refuse action on an already-expired request with HTTP
409, even if the sweep job has not yet run.

@e2e exclude backend cron sweep — covered by PHPUnit, not browser UI

#### Scenario: expired pending request is swept to its configured fallback

- **GIVEN** a `pending` `approval_request` with `expiresAt` in the past and
  `onTimeout: dead_letter`
- **WHEN** `ApprovalTimeoutSweepJob` runs
- **THEN** `approval_request.status` becomes `dead_letter`

#### Scenario: approving an already-expired request is refused

- **GIVEN** a `pending` `approval_request` with `expiresAt` in the past that
  the sweep job has not yet processed
- **WHEN** an authorized approver attempts to approve it
- **THEN** the system returns HTTP 409 and does not resume the pipeline

#### Scenario: dead-lettered requests are discoverable, not silently dropped

- **GIVEN** an `approval_request` with `status: dead_letter`
- **WHEN** an admin views the Pending Approvals list with the
  "dead-lettered" filter applied
- **THEN** the request appears with its full snapshot summary and audit
  trail (who requested it, when it was created, when it expired)

### Requirement: Two-layer authorization for approve/reject (REQ-006)

The system MUST authorize `approve`/`reject` calls in two layers: first,
the ADR-023 app-wide action matrix via `ActionAuthService::requireAction()`
for `approval.approve` / `approval.reject` (default-seeded `["admin"]`,
broadened only via the existing Action Authorization settings UI); second,
independent of the first, a per-request check that the calling user is a
member of that specific `approval_request`'s `approverGroup` (or an NC
admin). Both checks MUST pass; failing either MUST return HTTP 403 without
mutating the request.

@e2e exclude backend authorization internals — covered by PHPUnit, not browser UI

#### Scenario: unauthorized user cannot approve

- **GIVEN** a user who is in the app-wide `approval.approve` action-matrix
  group but is NOT a member of the specific `approval_request`'s
  `approverGroup` (`"woo-approvers"`)
- **WHEN** that user calls `POST /api/approvals/{id}/approve`
- **THEN** the system returns HTTP 403 and `approval_request.status` remains
  `pending`

#### Scenario: a user outside the action matrix cannot approve even if in the approver group

- **GIVEN** a non-admin user who IS a member of `"woo-approvers"` but whose
  groups do not intersect the app-wide `approval.approve` action-matrix
  entry (still default `["admin"]`)
- **WHEN** that user calls `POST /api/approvals/{id}/approve`
- **THEN** the system returns HTTP 403 (ADR-023's `requireAction()` rejects
  before the per-request group check runs)

#### Scenario: an NC admin may approve regardless of approverGroup membership

- **GIVEN** an NC admin who is not a member of `"woo-approvers"`
- **WHEN** that admin calls `POST /api/approvals/{id}/approve`
- **THEN** the approval succeeds (admin break-glass, per `ActionAuthService`'s
  existing admin-always-passes behavior)

### Requirement: Pending Approvals UI (REQ-007)

Integriq MUST provide a Pending Approvals section in its SPA where
authorized users can list approval requests (filterable by `pending`,
`approved`, `rejected`, `expired`, `dead_letter`), view a request's detail
(snapshot summary, requester, approver group, timestamps, audit trail), and
approve or reject a `pending` request with an optional (approve) or
required (reject) comment.

#### Scenario: approvals list page mounts and shows content

- GIVEN an authenticated user with access to at least one approval request
- WHEN they navigate to the Approvals section via the sidebar or direct URL
- THEN the Approvals index page renders inside the app-content area with
  content visible

#### Scenario: approve action is available from the detail page

- GIVEN a `pending` approval request the current user is authorized to act on
- WHEN they open its detail page
- THEN Approve and Reject actions are visible, and Reject requires a
  non-empty comment before it can be submitted

