# exchange-jobs Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- learniq-exchange-jobs-native
- exchange-import-landing

## Purpose

An import job hands what it received back to the app that owns it, and the owning app's answer
ends the job (D7).

## ADDED Requirements

### Requirement: REQ-010: an import job hands its records to the owning app
For `lvs-results` import, `oso` import and `migration-import` import, integriq MUST dispatch
`OCA\Integriq\Event\ExchangeRecordsReceivedEvent` once per run, after the gate allowed the job
and the job's mapping row ran, carrying the job id, owner app, target, direction, owner
reference, scope and the mapped records. It MUST NOT end these jobs with `no-handler`.

#### Scenario: an LVS results import is handed over
- GIVEN a queued `lvs-results` import job owned by `learniq` and a gate that allows two records
- WHEN the job runs
- THEN `ExchangeRecordsReceivedEvent` is dispatched with `ownerApp` `learniq` and both records

### Requirement: REQ-011: the owning app's answer ends the job
The first `accept(acceptedCount, rejected)` MUST count; later calls MUST be ignored. Each
rejection MUST be stored as an exchange rejection with its code and field names and never a
value. The job MUST end `succeeded` when every record is accepted, `partial` when some are,
and `failed` when none are. A rejection for an unknown record id or without a code MUST be
dropped, and the accepted count MUST be clamped to the records not rejected.

#### Scenario: the owning app accepts one and rejects one
- GIVEN an import job handing over records `a` and `b`
- WHEN the owning app calls `accept(1, [{recordId: b, errorCode: LVS-DUPLICATE}])`
- THEN the job ends `partial` with 1 of 2 accepted
- AND a rejection for `b` with code `LVS-DUPLICATE` is stored

### Requirement: REQ-012: an unanswered import ends with no-owner-answer
When no listener calls `accept()`, or a listener throws, the job MUST end `failed` with
`exchangeError` starting `no-owner-answer` and no rejections stored.

#### Scenario: learniq does not listen yet
- GIVEN an `oso` import job and no listener
- WHEN the job runs
- THEN the job ends `failed` with `no-owner-answer`

## Non-Functional Requirements

- **Performance:** one in-process event per run.
- **Accessibility:** no UI change.
- **Internationalization:** the new code has a Dutch and an English label.

## Acceptance Criteria

- Tests for dispatch, answered and unanswered.
