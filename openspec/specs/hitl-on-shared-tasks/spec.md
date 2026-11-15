# hitl-on-shared-tasks Specification

## Purpose
Integriq approvals live on OpenRegister's shared task service: each approval request is mirrored by one shared task that is offered to the approver group, a decision on either side resolves both, and the shared sweep owns the mirror's expiry.

## Requirements

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
sweep closes it. For a mirrored row whose `onTimeout` travelled with the
mirror, integriq's own sweep SHALL leave the row alone, and the shared
sweep's close SHALL resolve the record as `expired`, or `dead_letter` when
`onTimeout` is `dead_letter`. Pre-seam rows and rows whose behaviour stayed
app-local SHALL keep being resolved by integriq's own sweep.

#### Scenario: an expired mirrored approval is resolved from the task side

- **GIVEN** a pending approval_request past its `expiresAt`, mirrored with `onTimeout: dead_letter`
- **WHEN** integriq's sweep runs and then OpenRegister's sweep closes the mirror as `dead_letter`
- **THEN** integriq's sweep skipped the row, and the record ends `dead_letter` once the task closed
- @e2e exclude {two background sweeps across apps; each side is covered by unit tests}

#### Scenario: an unmirrored approval is still swept locally

- **GIVEN** a pending approval_request past its `expiresAt` without a `taskUuid`
- **WHEN** integriq's sweep runs
- **THEN** the record ends `expired`
- @e2e exclude {background sweep; covered by unit tests}

### Requirement: A decision taken on the shared task resumes the run

When a member of the approver group completes the mirrored task in the
shared inbox with outcome `approved` or `rejected`, integriq SHALL resolve
the approval_request through the same decision path as its own Pending
Approvals screen, so the suspended run resumes or stops the same way. The
completer SHALL pass both authorization layers (the action matrix and the
approver group); otherwise the record SHALL stay pending. A rejection
without a comment SHALL carry the fixed reason "Rejected in the shared task
inbox". A record that is no longer pending, a task of another app, and a
cancelled mirror SHALL change nothing.

#### Scenario: an approval in the shared inbox resumes the run

- **GIVEN** a pending approval_request with a mirrored task
- **WHEN** a member of its approver group claims the task and completes it as `approved`
- **THEN** the suspended run resumes and the record is `approved`, attributed to that member
- @e2e exclude {cross-app decision seam; covered by unit tests through the real decision service}

#### Scenario: a completer outside the approver group decides nothing

- **GIVEN** a pending approval_request with a mirrored task
- **WHEN** the task is completed by a user who is not in the approver group, or whom the action matrix refuses
- **THEN** the record stays pending and the refusal is logged
- @e2e exclude {cross-app decision seam; covered by unit tests}

### Requirement: The approver group is notified once

The mirror SHALL be offered to the approver group by the requester, so
OpenRegister's pool notification announces it. Integriq's own notification
SHALL only be sent when there is no offered mirror (no shared task service,
a failed mirror, no requester, or a refused offer), so approvers are never
left unnotified and never notified twice.

#### Scenario: an offered mirror replaces the imperative notification

- **GIVEN** a suspension whose mirror is created and offered to the approver group
- **WHEN** the approval_request is announced
- **THEN** integriq sends no notification of its own
- @e2e exclude {cross-app notification seam; covered by unit tests}

#### Scenario: a refused offer falls back to integriq's notification

- **GIVEN** a shared task service that refuses the offer
- **WHEN** the approval_request is announced
- **THEN** integriq notifies every member of the approver group itself
- @e2e exclude {fault injection on a peer app; covered by unit tests}

### Requirement: The mirror speaks the user's language

The mirrored task's title and description SHALL be translated through
integriq's catalogue, in every locale the app ships.

#### Scenario: a Dutch instance sees a Dutch mirror

- **GIVEN** a suspension created in a Dutch session
- **WHEN** the mirror is created
- **THEN** its title and description are the Dutch catalogue entries
- @e2e exclude {cross-app persistence seam; covered by unit tests}
