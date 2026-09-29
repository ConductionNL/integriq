# exchange-jobs Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- learniq-exchange-jobs-native

## Purpose

Integriq carries another app's data exchange jobs on its own schemas: the `job` row, its
`job_log`, a `mapping` row and, for a rejected record, a `sync_item_dead_letter` row. The
owning app keeps the domain decision of whether a job may run and which records may leave, and
answers integriq's gate for it. Decision D7 (learniq round 1, 2026-09-27) and ADR-041 (typed
events for cross-app commands, no server-side HTTP between apps). The full interface is
`openspec/changes/learniq-exchange-jobs-native/contract.md`.

## ADDED Requirements

### Requirement: REQ-001: An exchange job is a tagged native job
Integriq MUST store an exchange job as a `job` row whose `jobClass` is
`OCA\Integriq\Action\ExchangeJobAction`, carrying `exchangeTarget`, `exchangeDirection`,
`ownerApp`, `ownerRef`, `exchangeScope`, `exchangeMapping` and `exchangeStatus`. It MUST accept
exactly the fourteen targets of the contract and only the directions the contract lists for
each target. A new exchange job MUST be enabled, single-run and `queued`, so the next scheduler
pass runs it once.

#### Scenario: an app asks integriq to carry a ROD export
- GIVEN integriq is installed
- WHEN learniq dispatches `ExchangeJobRequestedEvent` for target `bron-rod`, direction `export`
- THEN a `job` row exists with `jobClass` `OCA\Integriq\Action\ExchangeJobAction`, `exchangeTarget` `bron-rod`, `ownerApp` `learniq`, `singleRun` true, `isEnabled` true and `exchangeStatus` `queued`
- AND the event's `getJobId()` returns that row's uuid

#### Scenario: an unknown target is refused
- GIVEN integriq is installed
- WHEN an app dispatches `ExchangeJobRequestedEvent` for target `fax`
- THEN no job row is created
- AND the event carries the refusal code `target-unknown`

#### Scenario: a direction the target does not support is refused
- GIVEN integriq is installed
- WHEN an app requests target `leerplicht` with direction `import`
- THEN the event carries the refusal code `direction-unsupported`

### Requirement: REQ-002: A migrated job keeps its history
When `ExchangeJobRequestedEvent` carries a `history` block, integriq MUST store the recorded
status, timestamps and result on the job, and one dead letter per recorded rejection whose status
is translated by the `learniq-exchange-rejection-status` mapping. A job whose history is finished
(`succeeded`, `partial`, `failed`, or `running` when it was cut off) MUST be created disabled so it
never runs. A job that was still waiting (`queued`, `pending-parent-review`) MUST be created
`queued` and enabled, so its gate decides again. A second request with the same
`history.legacyId` MUST return the existing job's id and create nothing.

#### Scenario: a finished learniq job is migrated
- GIVEN a learniq DataExchangeJob that succeeded with one rejection in status `waived`
- WHEN learniq's repair step dispatches the event with that history
- THEN a disabled `job` row exists with `exchangeStatus` `succeeded` and `migratedFrom` equal to the legacy id
- AND one `sync_item_dead_letter` row points at it with status `discarded`

#### Scenario: a job that was waiting for parent review is migrated
- GIVEN a learniq OSO job in `pending-parent-review`
- WHEN it is migrated
- THEN the job is enabled with `exchangeStatus` `queued`, so the next pass asks learniq's gate

#### Scenario: the migration runs twice
- GIVEN a job was migrated from legacy id L
- WHEN the event is dispatched again with `history.legacyId` L
- THEN the event returns the first job's id and no new row is written

### Requirement: REQ-003: Integriq asks the owning app before a job runs, and fails closed
Before an exchange job runs, integriq MUST dispatch `ExchangeGateRequestedEvent` and MUST run
the job only when the owning app answered `allow`. It MUST refuse the run, record the refusal
as the job's `gateDecision` and set `exchangeStatus` to `refused` when the job names no owning
app, the owning app is not enabled, no listener answered, the listener threw, or the owning
app answered `refuse`. Integriq MUST NOT call the owning app over HTTP.

#### Scenario: the owning app allows the job
- GIVEN an exchange job owned by learniq and learniq answers `allow` with two records
- WHEN the scheduler runs the job
- THEN the job's `gateDecision.decision` is `allow`
- AND both records are handed to the target's handler

#### Scenario: the owning app refuses
- GIVEN learniq answers `refuse` with code `teldatum-unconfirmed` and a reason
- WHEN the scheduler runs the job
- THEN no record reaches the handler
- AND `exchangeStatus` is `refused` and `gateDecision` holds that code and reason
- AND an `ExchangeJobConcludedEvent` with status `refused` is dispatched

#### Scenario: the owning app is absent
- GIVEN the job's `ownerApp` is not enabled
- WHEN the scheduler runs the job
- THEN the job is refused with code `gate-app-absent` without dispatching the gate event

#### Scenario: nobody answers the gate
- GIVEN the owning app is enabled but registers no gate listener
- WHEN the scheduler runs the job
- THEN the job is refused with code `gate-unanswered`

#### Scenario: the gate listener throws
- GIVEN the owning app's gate listener throws
- WHEN the scheduler runs the job
- THEN the job is refused with code `gate-error` and the exception does not escape the run

### Requirement: REQ-004: The job's mapping transforms each allowed record
When a job names `exchangeMapping`, integriq MUST apply that `mapping` row to each allowed
record's `data` before the handler sees it. A record whose mapping throws MUST become a
rejection with code `mapping-failed` while the other records continue. A job that names a
mapping slug that does not exist MUST fail with `mapping-missing` before the gate is asked.

#### Scenario: a record is mapped onto the target's field names
- GIVEN the job names `learniq-bron-rod-export-learner` and a record with `givenName` "Sanne"
- WHEN the job runs
- THEN the handler receives the record with `voornamen` "Sanne"

#### Scenario: the mapping slug does not exist
- GIVEN the job names the mapping slug `learniq-nonexistent`
- WHEN the job runs
- THEN `exchangeStatus` is `failed` with the message naming `mapping-missing`

### Requirement: REQ-005: Export handlers hand records to the existing adapters
For `bron-rod`, `leerplicht`, `oso` export, `swv`, `uwlr` export, `edu-v`, `basispoort` and
`entree-content`, integriq MUST hand each record to the matching adapter service with the
kenmerk `<jobId>:<recordId>`, taking target parameters from the record's data first and the
job's scope second. A record the adapter refuses MUST become a rejection
(`translation-failed` or `send-failed`) without stopping the others. Any other target and
direction MUST fail the job with `no-handler` without asking the gate.

#### Scenario: a ROD export sends one bericht per record
- GIVEN a `bron-rod` export job whose scope has `berichtsoort` `inschrijving` and two allowed records
- WHEN the job runs
- THEN `RodService::sendBericht()` is called twice with berichtsoort `inschrijving`
- AND the job's `exchangeResult` shows 2 processed and 2 accepted and `exchangeStatus` is `succeeded`

#### Scenario: one record fails translation
- GIVEN a `leerplicht` export job with two records, one missing its `bsn`
- WHEN the job runs
- THEN one melding is sent and one rejection with code `translation-failed` exists
- AND `exchangeStatus` is `partial`

#### Scenario: a target without an adapter
- GIVEN an exchange job for `surfconext`
- WHEN the job runs
- THEN `exchangeStatus` is `failed` with `no-handler` and no gate event is dispatched

#### Scenario: an SWV hand-off without a receiver
- GIVEN an `swv` export job whose scope has no `receiverId`
- WHEN the job runs
- THEN `exchangeStatus` is `failed` with `source-missing`

### Requirement: REQ-006: A rejected record is a dead letter with a correction loop
Integriq MUST store each rejected record as a `sync_item_dead_letter` row with `exchangeJob`,
`errorCode`, `offendingFields`, `ownerRef` and `sourceKind`, and MUST NOT store the record's
data or the target's free-text message. Resubmitting a `failed` rejection MUST create a
single-record exchange job for the same target, owner and mapping, scoped to that record, and
set the rejection to `replayed`. When that job rejects the record again, the same rejection
MUST return to `failed` with an attempt appended. Waiving MUST require a non-empty reason and
set the rejection to `discarded`, stamping the actor and time. Replaying an exchange rejection
from the generic dead letter screen MUST resubmit it.

#### Scenario: resubmit a rejection
- GIVEN a rejection in status `failed` on a `bron-rod` job
- WHEN an authorised user resubmits it
- THEN a new enabled exchange job exists whose scope `recordIds` holds only that record
- AND the rejection is `replayed`

#### Scenario: the resubmission is rejected again
- GIVEN a resubmission job for rejection R
- WHEN that job's handler rejects the record again
- THEN rejection R is `failed` again with a second attempt, and no second rejection row exists

#### Scenario: waive without a reason
- GIVEN a rejection in status `failed`
- WHEN a user waives it with an empty reason
- THEN the request is refused with 400 and the rejection stays `failed`

### Requirement: REQ-007: The vocabulary ships as integriq seed rows
Integriq MUST seed, as `mapping` rows, the 23 learniq mapping profiles under the slugs listed in
the design, the rejection status translation `learniq-exchange-rejection-status`, and the error
code catalogues `learniq-exchange-error-codes-bron-rod`, `-oso`, `-leerplicht` and
`-integriq`. The read model MUST resolve a rejection's code to a label through those catalogues
and fall back to the bare code when it is not catalogued.

#### Scenario: a catalogued code gets its label
- GIVEN a rejection with code `BRON-102` on a `bron-rod` job
- WHEN the read model lists it
- THEN `errorLabel` is the catalogue's Dutch label and `severity` is `blocking`

#### Scenario: an uncatalogued code falls back
- GIVEN a rejection with code `BRON-999`
- WHEN the read model lists it
- THEN `errorLabel` is `BRON-999`

### Requirement: REQ-008: Apps read their own jobs through the read model
Integriq MUST serve `GET /api/exchange/jobs`, `GET /api/exchange/jobs/{id}`,
`GET /api/exchange/rejections` and `GET /api/exchange/targets`, each requiring the
`exchange.read` action, each requiring `ownerApp` and returning only rows owned by that app,
with at most 200 rows per call. Resubmit and waive MUST require `exchange.resubmit` and
`exchange.waive`.

#### Scenario: a user without the action
- GIVEN a user who is not granted `exchange.read`
- WHEN the user calls `GET /api/exchange/jobs?ownerApp=learniq`
- THEN the response is 403

#### Scenario: another app's job
- GIVEN a job owned by `dossiq`
- WHEN `GET /api/exchange/jobs/{id}?ownerApp=learniq` is called
- THEN the response is 404

### Requirement: REQ-009: A terminal job raises a concluded event
After a run ends `succeeded`, `partial`, `failed` or `refused`, integriq MUST dispatch
`ExchangeJobConcludedEvent` with the owner, job id, target, direction, owner reference,
status, result and gate decision. A migrated job MUST NOT raise it.

#### Scenario: a partial run concludes
- GIVEN a job run that ends `partial`
- WHEN the run finishes
- THEN one `ExchangeJobConcludedEvent` with status `partial` and the counts is dispatched

## Non-Functional Requirements

- **Performance:** one scheduler pass runs each due exchange job once; list reads are bounded to 200 rows.
- **Privacy:** no personal data is stored on the job or the dead letter (AVG data minimisation).
- **Internationalization:** every user-facing message and catalogue label MUST have English and Dutch (hydra ADR-007).

## Acceptance Criteria

- Each scenario above has a PHPUnit test naming it.
- `SchemaAuthorizationRatchetTest` stays green without adding a schema to `KNOWN_OPEN`.

## Notes

- Acknowledgement correlation (a later retour turning into a rejection) is
  `connectors-data-exchange-dispatch` D4; the kenmerk `<jobId>:<recordId>` is what it reads.
- Import landing is out of scope; those targets conclude `no-handler`.
