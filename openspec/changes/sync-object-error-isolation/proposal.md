---
kind: code
capabilities:
  - synchronization-engine
---

# Proposal: sync-object-error-isolation

## Summary

Isolate per-object failures during synchronization so that a single
non-conforming source object no longer aborts the entire synchronization run.
Today, when one source object fails validation (for example it lacks a
schema-required field), the exception propagates out of the per-object loop and
the whole `synchronize()` run terminates with a 500. This change wraps the
per-object processing call in a try/catch, logs the failure with enough context
to identify the offending object, records it in the run result, and continues
processing the remaining objects.

## Motivation

Synchronizations pull object lists from external systems (e.g. Zaaksysteem)
whose data quality OpenConnector does not control. A single object that does not
conform to its target schema currently causes the whole run to fail: every
sibling object — including valid ones — is left unsynchronized, and the operator
sees an opaque 500 rather than a per-object diagnostic. This is fragile and hard
to operate: one bad record blocks an entire dataset.

Root cause (verified in code): the main object loop in
`lib/Service/SynchronizationService.php` (`foreach ($objectList as $object)`)
calls `processSynchronizationObject(...)` with no try/catch. That call path
reaches OpenRegister's `saveObject`, which throws
`OCA\OpenRegister\Exception\ValidationException` when required fields are
missing. The exception unwinds through the loop and aborts the run.

## Affected Projects

- [x] Project: `openconnector` — wrap the per-object synchronization call in
  try/catch, add a `failed` result bucket + `errors[]` array, log per-object
  failures, and continue the run.

## Scope

### In Scope

- Wrap the per-object `processSynchronizationObject(...)` call in try/catch.
- Catch OpenRegister's `ValidationException` specifically, with a general
  `\Throwable` fallback.
- On failure: log via `$this->logger->error(...)` with the object's originId
  (via `getOriginId`) + synchronizationId + the exception; append a
  `{originId, message}` entry to a new `$result['errors'][]` array; increment a
  new `$result['objects']['failed']` counter; then `continue` to the next
  object.
- Seed the new `failed` counter alongside the existing
  found/skipped/created/updated/deleted/invalid counters.
- Unit tests for the error-isolation path.

### Out of Scope

- Any change to OpenRegister (the fix is pure OpenConnector).
- Internal batching / paginated processing of the object list — the full list
  is fetched before the loop and that model is preserved (Zaaksysteem has no
  consistent ordering; splitting risks missed or double-synced objects).
- The contract-duplication issue, which is a separate, independent symptom.
- Retry / dead-letter handling for failed objects (only skip + log + count).

## Approach

Introduce error isolation at the granularity of a single object iteration. The
try block contains only the `processSynchronizationObject` call and its
immediate result handling; the catch block records the failure and `continue`s.
`ValidationException` is caught first for a precise "object did not conform"
message; a `\Throwable` fallback guarantees no per-object error can escape the
loop. The `failed` bucket is kept distinct from the existing `invalid` bucket,
which already means "not an array / unknown resultAction" — `failed` is reserved
exclusively for thrown exceptions.

## New Dependencies

None.

## Impact

- `lib/Service/SynchronizationService.php`: the per-object loop and the result
  map seed (`objects` counters) around lines 1434 and 1634.
- Run result shape gains `objects.failed` (int) and `errors[]` (list of
  `{originId, message}`). Consumers that read the result map should tolerate the
  new keys; no existing key changes meaning.

## Cross-Project Dependencies

None. OpenRegister's `ValidationException` is already on the call path and is
only caught, not modified.

## Risks

### Risk 1: Silent data loss if failures go unnoticed

**Severity:** Medium — **Mitigation:** every failure is logged at error level
with originId + synchronizationId and surfaced in the run result's `errors[]`
and `failed` counter, so operators can detect and triage skipped objects rather
than losing them silently.

### Risk 2: Over-broad catch masking systemic errors

**Severity:** Low — **Mitigation:** `ValidationException` is caught explicitly
and distinctly from the `\Throwable` fallback; both log the full exception, so a
systemic failure (e.g. target unreachable) still shows up in the logs per object
rather than being swallowed.

## Rollback Strategy

Revert the try/catch wrapper and the new counter/array seeding. The change is
additive and localized to `SynchronizationService.php`; removing it restores the
prior fail-fast behavior with no schema or data migration involved.
