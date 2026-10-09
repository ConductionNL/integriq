# Tasks: connectors-data-exchange-dispatch

Kind: code. Size S since 2 October 2026 (see the proposal's Status). Rows
`learniq:gov-push-data-to-another-system`, `planninq:sib-learniq-att-import-a-timetable`,
`learniq:att-import-a-timetable` and `learniq:att-report-absence-to-authority` are carried by
`2026-09-29-learniq-exchange-jobs-native` (and, for the timetable, by
`2026-09-28-rostering-adapter-targets-planninq`), not by this change.

## Superseded, not built

The original Tasks 1 to 4 and 6 (`DataExchangeRequestedEvent`, its dispatcher, the import
handlers, the refusals and the learniq hand-off for that event) are superseded by
`learniq-exchange-jobs-native`: `ExchangeJobRequestedEvent`, `ExchangeTargetDispatcher`, the
`no-handler` refusal and `ExchangeTargetCatalogue` cover them, and learniq already raises that
event. Building them would give the same adapters a second entrance.

### Task 1: The acknowledged event
- **spec_ref**: openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
- **files**: `lib/Event/ExchangeJobAcknowledgedEvent.php`
- **acceptance_criteria**:
  - GIVEN the event WHEN read THEN it returns the owning app, job, record, target, accepted, signaalcode, description and receivedAt it was built with
- [x] Implement. `lib/Event/ExchangeJobAcknowledgedEvent.php` (landed with #2450).
- [x] Test. `tests/Unit/EventListener/ExchangeAcknowledgementListenerTest.php` reads every
      getter off the dispatched event (owner app, job, record, target, accepted, signaalcode,
      description, receivedAt as ISO 8601).

### Task 2: The acknowledgement listener
- **spec_ref**: openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
- **files**: `lib/EventListener/ExchangeAcknowledgementListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a `bron-rod` job owned by learniq WHEN a rejecting ROD retour with kenmerk `<jobId>:lp-9` arrives THEN one acknowledged event names the job, `lp-9` and the signaalcode, and one rejection of `lp-9` is stored, valid against the `sync_item_dead_letter` schema
  - GIVEN a `leerplicht` job WHEN an accepted Verzuimloket acknowledgement arrives THEN the event says accepted and no rejection is stored
  - GIVEN a kenmerk without a job, a job another adapter carries, or a job without an owning app WHEN the retour arrives THEN nothing is dispatched or stored
- [x] Implement. `ExchangeAcknowledgementListener` on the four adapters'
      `*AcknowledgementReceivedEvent`s, registered in `Application` per `ADAPTER_OF` (#2450).
- [x] Test (real acknowledgement event classes, real `ExchangeRejectionService`).
      `ExchangeAcknowledgementListenerTest`, 5 tests: the rejecting ROD retour (event plus a
      rejection valid against the real `sync_item_dead_letter` fragment), the accepted
      Verzuimloket melding (no rejection), OSO and UWLR, the no-job, foreign-adapter and
      no-owner paths, and the registration asserted from `Application`.

### Task 3: Hand learniq its half
- **spec_ref**: openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
- **files**: a learniq issue (drafted for Ruben; lanes file nothing on another repo)
- **acceptance_criteria**:
  - GIVEN the merged integriq change WHEN the issue is filed THEN it names the event's getters and asks learniq to listen, filter on `getOwnerApp() === 'learniq'`, and record the outcome on the record idempotently
- [ ] Implement. Drafted for Ruben (for-ruben/learniq-listen-for-exchange-acknowledgements.md);
      not filed yet, so not ticked.
- [ ] Test (the learniq issue exists and links back)

## Verification

- `openspec validate connectors-data-exchange-dispatch --type change --strict`
- On one instance: run a `bron-rod` job against the mock ROD source, post a rejecting retour
  with its kenmerk, and read the rejection on the job.
- `composer check:strict` once before push.
