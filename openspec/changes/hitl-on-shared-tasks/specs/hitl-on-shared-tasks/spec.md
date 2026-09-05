# hitl-on-shared-tasks

## ADDED Requirements

### Requirement: Every suspension mirrors one shared task

Each `approval_request` created by a suspension SHALL be mirrored by exactly
one OpenRegister task, created through the shared task service's trusted
path, carrying the approver group as candidate group, the requester, the
`expiresAt`, and the record's `onTimeout` and `onReject` when they are in
the shared vocabulary. The task uuid SHALL be stored on the approval_request
as `taskUuid`. A mirror failure SHALL NOT fail the suspension.

#### Scenario: a suspension creates the linked mirror task

- **GIVEN** an endpoint rule pipeline suspending on an approval rule
- **WHEN** the approval_request is persisted
- **THEN** a shared task is created with the approver group, expiry and behaviours, and the record carries its uuid
- @e2e exclude {cross-app persistence seam; covered by unit tests against the stubbed shared service}

#### Scenario: a failing shared service does not block the suspension

- **GIVEN** a shared task service that throws on import
- **WHEN** the pipeline suspends
- **THEN** the approval_request is created and pending, without a `taskUuid`, and the failure is logged
- @e2e exclude {fault injection on a peer app; covered by unit tests}

### Requirement: A decision closes the mirrored task

Approving SHALL close the mirrored task with the `approved` outcome;
rejecting SHALL close it with the `rejected` outcome, or the dead-letter
outcome when the record's `onReject` routed the record to `dead_letter`. A
missing or already-closed mirror SHALL NOT fail the decision.

#### Scenario: an approval closes the mirror as approved

- **GIVEN** a pending approval_request carrying a `taskUuid`
- **WHEN** an authorized approver approves it
- **THEN** the mirrored task is closed with outcome `approved`, attributed to the deciding user
- @e2e exclude {cross-app close seam; covered by unit tests against the stubbed shared service}

#### Scenario: a dead-letter rejection routes the mirror the same way

- **GIVEN** a pending approval_request with `onReject: dead_letter` and a `taskUuid`
- **WHEN** an authorized approver rejects it with a comment
- **THEN** the record and the mirrored task both end dead-lettered
- @e2e exclude {cross-app close seam; covered by unit tests}

### Requirement: The shared sweep owns the mirror's expiry

The mirrored task SHALL declare its expiry behaviour so OpenRegister's timer
sweep encloses it; integriq's own sweep SHALL keep resolving the
approval_request record and SHALL NOT gain a second enforcement path for the
mirror.

#### Scenario: an expired approval converges on both sides

- **GIVEN** a pending approval_request past its `expiresAt`, mirrored with `onTimeout`
- **WHEN** both 300s sweeps have run
- **THEN** the record is `expired` (or `dead_letter`) and the task was closed by the shared sweep with the declared behaviour
- @e2e exclude {two background sweeps across apps; each side is covered by its own unit tests}
