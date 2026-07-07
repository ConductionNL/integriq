# Synchronization Engine Specification

**Status**: in-progress
**Scope**: openconnector
**OpenSpec changes**:
- sync-object-error-isolation

## Purpose

Add per-object error isolation to the synchronization run loop so that a single
non-conforming source object is skipped, logged, and counted instead of aborting
the entire run. This extends the ADR-005 Source → Synchronization →
SynchronizationContract triad's per-object processing with fault containment,
keeping the existing full-list-before-loop fetch model intact.

## ADDED Requirements

### Requirement: Per-object failure isolation during synchronization

The synchronization run loop MUST isolate failures at the granularity of a
single object. When processing one source object throws an exception, the
system MUST record the failure and continue with the remaining objects rather
than aborting the whole run.

Specifically the system MUST:

- Catch `OCA\OpenRegister\Exception\ValidationException` thrown while processing
  a single object, and MUST additionally catch a general `\Throwable` as a
  fallback so no per-object error escapes the loop.
- Log each failure via the logger at error level, including the object's
  originId (resolved via the existing origin-id resolution), the
  synchronizationId, and the thrown exception.
- Append a `{ originId, message }` entry to the run result's `errors` list.
- Increment a `failed` counter in the run result's `objects` map. The `failed`
  bucket MUST be distinct from the existing `invalid` bucket (which means "not
  an array / unknown resultAction"); `failed` is reserved exclusively for
  thrown exceptions.
- Continue to the next object.

The `objects` result map MUST seed a `failed` counter alongside the existing
found / skipped / created / updated / deleted / invalid counters.

#### Scenario: non-conforming object is skipped while siblings still sync

- GIVEN a synchronization whose fetched object list contains one object that
  lacks a schema-required field and several valid objects
- WHEN the synchronization run processes the object list
- THEN the non-conforming object is skipped rather than aborting the run
- AND the valid sibling objects are synchronized normally
- AND the run completes successfully instead of returning a 500

#### Scenario: failure is logged, counted, and recorded in the result

- GIVEN a source object whose processing throws a ValidationException
- WHEN the run loop catches the exception
- THEN an error-level log entry is written containing the object's originId,
  the synchronizationId, and the exception
- AND the run result `objects.failed` counter is incremented
- AND an entry `{ originId, message }` is appended to the run result `errors`
  list

#### Scenario: failed bucket is distinct from invalid bucket

- GIVEN a run that encounters both an object that throws an exception and an
  object that is not an array / has an unknown resultAction
- WHEN the run completes
- THEN the thrown-exception object is counted under `objects.failed`
- AND the not-an-array / unknown-resultAction object is counted under
  `objects.invalid`
- AND the two counters are not conflated

#### Scenario: unexpected throwable is also contained

- GIVEN a source object whose processing throws a non-ValidationException
  `\Throwable`
- WHEN the run loop reaches the general fallback catch
- THEN the error is logged with the object's originId and the synchronizationId
- AND the object is counted as failed and the run continues with the next object

## Non-Functional Requirements

- **Performance:** The full object list continues to be fetched before the loop;
  no internal batching or paginated re-fetch is introduced, so ordering
  guarantees are unchanged.
- **Accessibility:** N/A — backend-only change with no user-facing UI surface.
- **Internationalization:** N/A — no new user-facing strings; log messages and
  error entries are diagnostic, not localized UI copy.

## Acceptance Criteria

- [ ] A schema-nonconforming object is skipped, logged at error level, and
  counted under `objects.failed` while valid sibling objects still synchronize
- [ ] The run result exposes an `errors[]` list of `{ originId, message }` and a
  populated `objects.failed` counter
- [ ] A run containing at least one failing object completes instead of
  returning a 500
- [ ] The `failed` counter is seeded with the other object counters and is
  distinct from `invalid`

## Notes

- Fix is pure OpenConnector; OpenRegister is not modified. `ValidationException`
  is already on the `processSynchronizationObject` → `synchronizeContract` →
  OpenRegister `saveObject` call path and is only caught here.
- Related ADR: ADR-005 (Source / Synchronization / SynchronizationContract data
  triad). ADR-003 (CallLog as primary observability surface) informs the
  logging expectation.
- The contract-duplication issue is an independent symptom and is out of scope.
