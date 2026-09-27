# data-exchange-dispatch Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- connectors-data-exchange-dispatch

## Purpose

A sibling app hands integriq a data-exchange job as one typed event, and
integriq routes it to the adapter for its target, answers with the result
in the same call, and reports the authority's acknowledgement later. Rows
`learniq:gov-push-data-to-another-system`,
`planninq:sib-learniq-att-import-a-timetable`,
`learniq:att-import-a-timetable` and
`learniq:att-report-absence-to-authority`.

## ADDED Requirements

### Requirement: A data-exchange job reaches its adapter through one typed event (REQ-DXD-001)

Integriq MUST publish `OCA\Integriq\Event\DataExchangeRequestedEvent`
carrying `sourceApp`, `jobId`, `target`, `direction`, `payload`, `scope`
and `tenantId`, and MUST register a listener that routes the event to the
adapter for its target: `bron-rod`, `leerplicht`, `oso`, `swv`, `uwlr`,
`edu-v`, `basispoort`, `entree-content`, `timetable-import` and
`lvs-import-contract`. The listener MUST write a result with `runId`,
`status`, `recordsProcessed`, `recordsAccepted`, `recordsRejected`,
`validationReport` and `artefactRef`, and for an import also `records`.

#### Scenario: a leerplicht report reaches the Verzuimloket adapter
- GIVEN learniq moves a `leerplicht` data-exchange job for one pupil to running, and the Verzuimloket source runs in mock mode
- WHEN learniq raises the event with that job
- THEN one melding is handed to the Verzuimloket adapter with the job id as kenmerk, and the result reports one processed and one accepted record
- @e2e exclude an in-process event between two apps; covered by an integration test that raises the event with a real DataExchangeRequestedEvent

#### Scenario: a timetable import returns lessons
- GIVEN an enabled `roster-zermelo` source in mock mode
- WHEN learniq raises the event with target `timetable-import` and scope `systemId` `roster-zermelo`
- THEN the result carries the lessons as `records`, each with subject, start, end, room, teacher and group references
- @e2e exclude an in-process event; covered by an integration test on the listener

#### Scenario: one bad record does not stop the batch
- GIVEN a `bron-rod` export of three records, one without a berichtsoort
- WHEN integriq handles the event
- THEN two berichten are sent, one record is counted rejected, and the validation report names why
- @e2e exclude a server-side batch; covered by PHPUnit on the dispatcher

### Requirement: A target integriq cannot handle is refused with its reason (REQ-DXD-002)

When the target has no handler, its source is disabled, its adapter's
feature flag is off, or an import names no single source, the listener MUST
refuse on the event with a code and a reason naming the target, and MUST
NOT send anything.

#### Scenario: an unknown target is named
- GIVEN learniq raises the event with target `surfconext`
- WHEN integriq handles it
- THEN the event carries a refusal naming `surfconext` as a target integriq has no adapter for, and nothing is sent
- @e2e exclude an in-process event; covered by PHPUnit on the listener

#### Scenario: two roster sources and no choice
- GIVEN enabled `roster-zermelo` and `roster-untis-oneroster` sources
- WHEN a `timetable-import` event arrives without `scope.systemId`
- THEN the event is refused naming both sources
- @e2e exclude an in-process event; covered by PHPUnit on the dispatcher

### Requirement: The authority's acknowledgement is reported against the job (REQ-DXD-003)

When a ROD, Verzuimloket, OSO or UWLR acknowledgement arrives whose kenmerk
is a job id from a `DataExchangeRequestedEvent`, integriq MUST dispatch
`OCA\Integriq\Event\DataExchangeConcludedEvent` with `sourceApp`, `jobId`,
`target`, `accepted`, `signaalcode`, `description` and `receivedAt`. It MUST
NOT dispatch it for an acknowledgement that belongs to no such job.

#### Scenario: DUO rejects a ROD bericht
- GIVEN a `bron-rod` job whose bericht was sent with the job id as kenmerk
- WHEN DUO's signed retour arrives with a rejecting signaalcode
- THEN integriq dispatches the concluded event for that job with `accepted` false and the signaalcode, and the existing ROD acknowledgement event still fires
- @e2e exclude an inbound signed retour and an in-process event; covered by an integration test on RodService::receiveReturn
