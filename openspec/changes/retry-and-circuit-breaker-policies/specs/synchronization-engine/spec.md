# synchronization-engine Specification Delta

## ADDED Requirements

### Requirement: Per-item isolation and dead-letter capture during extern-to-intern sync (REQ-008)

The system MUST wrap each per-object call to `processSynchronizationObject()`
inside `SynchronizationService::synchronizeExternToIntern()`'s object loop
(the `foreach ($objectList as $object)`) in a `try/catch (\Throwable)`. On a
caught exception, the system MUST persist a `sync_item_dead_letter` object
capturing: the synchronization's uuid, the best-effort `originId` (when
resolvable before the failure), the raw source `$object` as `payload`, the
exception message as `error`, `phase: 'item-processing'`, and
`status: 'failed'`; increment `result['objects']['invalid']`; and continue
processing the remaining objects in `$objectList`. A single item's failure
MUST NOT abort processing of the remaining objects in the same sync pass,
and MUST NOT prevent `synchronize()` from completing and persisting its
`synchronization_log` with a summary reflecting the partial success
(previously: an uncaught exception from `processSynchronizationObject()`
propagated through the un-guarded loop and aborted the entire pass for
every remaining object — verified absent in HEAD prior to this change).

Dead-lettered items are captured for **manual** replay only — the system
MUST NOT schedule an automatic retry sweep for `sync_item_dead_letter`
entries (unlike event delivery's `EventRetryJob`), since item transformation
and write failures are typically deterministic (mapping/config/data errors)
rather than transient.

#### Scenario: one bad item does not abort the sync pass

- **GIVEN** a synchronization fetching 10 objects from its source, where
  object #4's mapping throws an exception
- **WHEN** `synchronize()` runs
- **THEN** objects #1-3 and #5-10 SHALL be processed normally (contracts
  created/updated as applicable)
- **AND** object #4 SHALL be captured as a `sync_item_dead_letter` entry with
  `status = 'failed'`
- **AND** `result['objects']['invalid']` SHALL be incremented by 1
- **AND** the `synchronization_log` SHALL be persisted reflecting 9
  successfully-processed objects and 1 invalid

#### Scenario: dead-lettered items are not automatically retried

- **GIVEN** a `sync_item_dead_letter` entry with `status = 'failed'`
- **WHEN** the next scheduled run of the same synchronization occurs
- **THEN** no automatic re-attempt of the dead-lettered item SHALL occur
  outside of an explicit operator replay action (REQ-DLR-007/008 in
  `dead-letter-replay`)

#### Notes

- `phase` is fixed to the literal `'item-processing'` in this change —
  `processSynchronizationObject()` has no internal phase boundaries exposed
  to a caller today, and distinguishing `fetch`/`mapping`/`write` precisely
  would require refactoring internals of `SynchronizationService`
  (~6,700 lines). The field is a free-form string (not a locked enum) so a
  follow-up change can populate it more precisely without a schema
  migration. Observed limitation; flagged in design.md Open Questions.
- Fetch-stage failures (`TooManyRequestsHttpException` from
  `getAllObjectsFromSource()`) are already isolated one level higher (caught
  in `synchronizeExternToIntern()` before the object loop, per the existing
  REQ-002 rate-limit scenario) and are NOT captured as
  `sync_item_dead_letter` entries — they short-circuit the whole pass with
  `rateLimitException`, which is a distinct pre-existing behavior this
  change does not alter.
