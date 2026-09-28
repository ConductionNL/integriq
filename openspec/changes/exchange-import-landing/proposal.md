---
kind: code
depends_on: []
---

# Proposal: exchange-import-landing

## Summary

Import jobs for `lvs-results`, `oso` and `migration-import` stop ending as `no-handler`.
Integriq maps the received records with the job's mapping row and hands them back to the owning
app in a typed event, `ExchangeRecordsReceivedEvent`, the mirror of
`ExchangeGateRequestedEvent`. The owning app answers with an accepted count and the rejected
records with reason codes, and that answer ends the job. When no app answers, the job ends
`failed` with the new code `no-owner-answer`.

## Motivation

D7 (Ruben, 2026-09-27) moves learniq's data exchange onto integriq's jobs, mappings and logs.
The export side landed in `learniq-exchange-jobs-native` (#2220). The import side did not: the
runner refuses every import with `no-handler`
(`lib/Service/Exchange/ExchangeJobRunner.php:104`,
`lib/Service/Exchange/ExchangeTargetDispatcher.php:149`), so LVS results, OSO dossiers and
migration files never reach learniq through integriq.

## Affected Projects

- [x] Project: `integriq`: new event, dispatcher import landing, runner outcome, one error code.
- [ ] Project: `learniq`: answers the event (a separate learniq change; the contract is in design.md).

## Scope

### In Scope

- `lib/Event/ExchangeRecordsReceivedEvent.php` with `accept(acceptedCount, rejected)`.
- `ExchangeTargetDispatcher` handles `lvs-results` import, `oso` import and `migration-import`
  import by dispatching the event, and returns the owning app's outcome.
- `ExchangeJobRunner` ends the job from that outcome; `no-owner-answer` when unanswered.
- `no-owner-answer` in the `learniq-exchange-error-codes-integriq` catalogue.

### Out of Scope

- Learniq's listener (a learniq change answers the contract in design.md).
- Fetching records from the external system. The raw records reach integriq in the gate answer,
  as they do for exports: the owning app holds the uploaded file or the received dossier.
- `uwlr` import, `hr` import and `timetable-import` stay `no-handler`; they can join the list
  with the same event later.

## Approach

See design.md.

## New Dependencies

None.

## Impact

`ExchangeTargetDispatcher`, `ExchangeJobRunner`, `ExchangeReadModel` (reports `handled.import`
true for the three targets), one register fragment row.

## Cross-Project Dependencies

Learniq must listen for `ExchangeRecordsReceivedEvent` to take the records. Until it does, the
three import jobs end `failed` with `no-owner-answer` instead of `no-handler`.

## Risks

### Risk 1: a listener takes the records but throws before answering
**Severity:** Medium. **Mitigation:** the dispatcher catches the throwable and treats the job as
unanswered (`no-owner-answer`). The design tells the owning app to answer only after its write.

### Risk 2: a retried job hands the same records again
**Severity:** Low. **Mitigation:** the contract requires the owning app to upsert by `recordId`.

## Rollback Strategy

Revert the commit. Import jobs end `no-handler` again.
