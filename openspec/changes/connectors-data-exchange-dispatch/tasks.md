# Tasks: connectors-data-exchange-dispatch

Kind: code. Size M. Rows `learniq:gov-push-data-to-another-system`,
`planninq:sib-learniq-att-import-a-timetable`, `learniq:att-import-a-timetable`
and `learniq:att-report-absence-to-authority`.

### Task 1: The two events
- **spec_ref**: openspec/changes/connectors-data-exchange-dispatch/specs/data-exchange-dispatch/spec.md#requirement-a-data-exchange-job-reaches-its-adapter-through-one-typed-event-req-dxd-001
- **files**: `lib/Event/DataExchangeRequestedEvent.php`, `lib/Event/DataExchangeConcludedEvent.php`
- **acceptance_criteria**:
  - GIVEN the event WHEN a listener writes a result THEN `getResult()` returns the learniq keys and `isHandled()` is true
  - GIVEN the event WHEN a listener refuses THEN `getRefusal()` returns the code and reason and `getResult()` stays null
- [ ] Implement
- [ ] Test (PHPUnit on both event classes)

### Task 2: The dispatcher and the export handlers
- **spec_ref**: openspec/changes/connectors-data-exchange-dispatch/specs/data-exchange-dispatch/spec.md#requirement-a-data-exchange-job-reaches-its-adapter-through-one-typed-event-req-dxd-001
- **files**: `lib/Service/DataExchange/DataExchangeDispatcher.php`, `lib/Service/DataExchange/Handler/*.php` (ROD, Verzuimloket, OSO, SWV, UWLR and Edu-V), `lib/Listener/DataExchangeRequestedListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a `leerplicht` event with one record WHEN it is handled THEN `VerzuimloketService::sendMelding()` is called once with the job id as kenmerk
  - GIVEN a `bron-rod` event with one bad record of three WHEN it is handled THEN two are sent and the result counts one rejected with its reason
- [ ] Implement
- [ ] Test (integration test raising a real event against mock-mode sources)

### Task 3: The import handlers
- **spec_ref**: openspec/changes/connectors-data-exchange-dispatch/specs/data-exchange-dispatch/spec.md#requirement-a-data-exchange-job-reaches-its-adapter-through-one-typed-event-req-dxd-001
- **files**: `lib/Service/DataExchange/Handler/TimetableImportHandler.php`, `lib/Service/DataExchange/Handler/LvsImportHandler.php`
- **acceptance_criteria**:
  - GIVEN `scope.systemId` `roster-zermelo` WHEN a `timetable-import` event is handled THEN `records` holds the mock lessons
  - GIVEN an `lvs-import-contract` event WHEN it is handled THEN `records` holds the mock results
- [ ] Implement
- [ ] Test (integration test on the listener)

### Task 4: Refusals
- **spec_ref**: openspec/changes/connectors-data-exchange-dispatch/specs/data-exchange-dispatch/spec.md#requirement-a-target-integriq-cannot-handle-is-refused-with-its-reason-req-dxd-002
- **files**: `lib/Service/DataExchange/DataExchangeDispatcher.php`
- **acceptance_criteria**:
  - GIVEN target `surfconext` WHEN the event is handled THEN it is refused naming the target
  - GIVEN the SWV feature flag off WHEN an `swv` event is handled THEN it is refused naming the flag
  - GIVEN two enabled roster sources and no `systemId` WHEN an import is handled THEN it is refused naming both
- [ ] Implement
- [ ] Test (PHPUnit on the dispatcher)

### Task 5: The concluded event
- **spec_ref**: openspec/changes/connectors-data-exchange-dispatch/specs/data-exchange-dispatch/spec.md#requirement-the-authoritys-acknowledgement-is-reported-against-the-job-req-dxd-003
- **files**: `lib/Listener/DataExchangeAcknowledgementListener.php`, `lib/AppInfo/Application.php`, a record of dispatched job ids (the adapters' own audit records carry the kenmerk)
- **acceptance_criteria**:
  - GIVEN a ROD retour whose kenmerk is a dispatched job id WHEN it arrives THEN one concluded event names the job and the signaalcode
  - GIVEN a retour whose kenmerk is not a dispatched job WHEN it arrives THEN no concluded event is raised
- [ ] Implement
- [ ] Test (integration test on `RodService::receiveReturn()` with a signed fixture)

### Task 6: Hand learniq its half
- **spec_ref**: openspec/changes/connectors-data-exchange-dispatch/specs/data-exchange-dispatch/spec.md#requirement-a-data-exchange-job-reaches-its-adapter-through-one-typed-event-req-dxd-001
- **files**: an issue on ConductionNL/learniq naming the D2 contract, the two call sites and the `connections.json` entries
- **acceptance_criteria**:
  - GIVEN the merged integriq change WHEN the issue is opened THEN it quotes the event's constructor and result keys and links this change
- [ ] Implement
- [ ] Test (the learniq issue exists and links back)

## Verification

- `openspec validate connectors-data-exchange-dispatch --type change --strict`
- On one instance with learniq's half applied: run a `leerplicht` job and a
  `timetable-import` job against mock sources, and read both results on the
  job.
- `composer check:strict` and `npm run lint` once before push.
