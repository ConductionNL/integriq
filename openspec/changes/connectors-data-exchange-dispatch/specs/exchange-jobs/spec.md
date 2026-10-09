# exchange-jobs delta: connectors-data-exchange-dispatch

## ADDED Requirements

### Requirement: REQ-013: the authority acknowledgement for a record is reported against its job
When a ROD, Verzuimloket, OSO or UWLR and Edu-V acknowledgement arrives whose kenmerk is
`<jobId>:<recordId>` of an exchange job, and the job's target is carried by that adapter,
integriq MUST dispatch `OCA\Integriq\Event\ExchangeJobAcknowledgedEvent` with `ownerApp`,
`jobId`, `recordId`, `target`, `accepted`, `signaalcode`, `description` and `receivedAt`. A
rejecting acknowledgement MUST also be stored as a rejection of that record on the job
(REQ-006), with the signaalcode as its error code, so the correction loop applies. Integriq MUST
NOT dispatch the event, or store anything, for a kenmerk that names no exchange job, for a job
whose target another adapter carries, or for a job without an owning app. The adapter's own
acknowledgement event MUST still fire.

#### Scenario: DUO rejects a ROD bericht
- GIVEN a `bron-rod` job owned by `learniq` whose record `lp-9` was sent with kenmerk `<jobId>:lp-9`
- WHEN DUO's retour arrives with a rejecting signaalcode
- THEN integriq dispatches one `ExchangeJobAcknowledgedEvent` for that job and record with `accepted` false and the signaalcode, and stores one rejection of `lp-9` on the job with the signaalcode as its error code

#### Scenario: the Verzuimloket accepts a melding
- GIVEN a `leerplicht` job owned by `learniq` whose melding was sent with kenmerk `<jobId>:<recordId>`
- WHEN the Verzuimloket's acknowledgement arrives accepted
- THEN integriq dispatches one `ExchangeJobAcknowledgedEvent` with `accepted` true, and stores no rejection

#### Scenario: a kenmerk that is no exchange job
- GIVEN a ROD retour whose kenmerk was sent over the ROD route by another caller
- WHEN it arrives
- THEN no `ExchangeJobAcknowledgedEvent` is dispatched and no rejection is stored
