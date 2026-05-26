# Design: openconnector-write-once-immutable-logging

## Context
`SynchronizationService` is the only service that writes log objects with a
create-then-update pattern. `JobService` (job_log @ line 504) and `CallService`
(call_log @ 449/469/513/707) already append a single create — out of scope.

The 405 originates because OpenRegister enforces `appendOnly`/`immutable` on the
log schemas and the engine issues `saveObject(..., uuid: $log->getUuid())` on a
log it already created. OpenRegister also ignores a client-supplied `uuid` on
create (it assigns its own id; `uuid` comes back null), so "pre-generate the id
and create with it" is not available — we use a separate correlation field.

## Decision

### 1. Run-correlation id (`executionId`)
Generate one `executionId` (UUID v4 string) at the top of `synchronize()`. Stamp
it on the in-memory sync-log payload and on every contract-log payload. Replace
the old child→parent link `synchronizationLogId = $log->getUuid()` with
`executionId = <run id>` on both parent and child. Add an `executionId` string
property to the four `*_log` schema declarations in
`lib/Settings/openconnector_register.json` (additive; immutability unchanged).

### 2. Sync-log: accumulate in memory, append once
- `synchronize()` builds `$logData` (array) including `executionId`, and threads
  it **by reference** into `synchronizeExternToIntern()` /
  `synchronizeInternToExtern()` instead of passing a persisted `ObjectEntity $log`.
- Those methods mutate `$logData` in place (counts, timing, result, message) and
  return; they no longer call `saveObject` for the sync-log.
- The empty-`sourceId` cancellation path sets `$logData['message']` and throws —
  no early persist (the terminal append in `synchronize()`'s catch/finally is the
  single write).
- `synchronize()` performs exactly one `saveObject($logData, schema:
  'synchronization_log')` at the end of the success path. On exception it appends
  one terminal sync-log with the error message, then rethrows (so the controller
  still maps the application code). Net: one append per run on every path.

### 3. Contract-log: accumulate in memory, append once
Inside `synchronizeContract()` replace the entity round-trips:
- Drop the initial create (old line ~1688). Keep a plain `$contractLogData` array
  and a boolean `$contractLogEnabled` (the existing `isset($contractData['uuid'])`
  gate).
- Every former `$contractLog = saveObject($d, uuid: $contractLog->getUuid())`
  update site (synchronizationLogId/expiry/target/targetResult) becomes an
  in-memory `$contractLogData[...] = ...` mutation.
- Before each of the three `return` points (skip, test, update), persist once via
  a small private helper `appendContractLog(array $data): ?array` that calls
  `saveObject($data, schema: 'synchronization_contract_log')` and returns the
  persisted object (or null when logging is disabled). The returned array fills
  the `'log'` key of the result.

### 4. Signature changes
- `synchronizeExternToIntern(ObjectEntity $log, ...)` →
  `synchronizeExternToIntern(array &$logData, string $executionId, ...)` returning
  the mutated `$logData` (array) instead of an `ObjectEntity`.
- `synchronizeInternToExtern(..., ObjectEntity $log, ...)` likewise threads
  `array &$logData, string $executionId`.
- `synchronizeContract(..., ?ObjectEntity $log=null, ...)` →
  `synchronizeContract(..., ?string $executionId=null, ...)`.
- `synchronize()` returns `$logData` (array) on success — callers already treat the
  return as `array|ObjectEntity|null` and read `getObject()`-shaped data; the
  controller wraps it in a JSONResponse, so returning the finalized array is
  compatible.

## Risks / Trade-offs
- **Return-shape compatibility.** Callers that did `$log->getObject()` must accept
  the finalized array. Mitigation: the test/contract array returns already carry
  plain arrays under `'log'`; the top-level `synchronize()` return is consumed by
  the controller as JSON. Verified against `SynchronizationsController::test/run`.
- **Error-path single log.** The cancellation/exception path must still produce one
  terminal log. Mitigation: append in the `catch` of `synchronize()` before
  rethrow; Newman's bogus-sync test guards it.

## Migration Notes
No data migration. The `executionId` schema property is additive and re-imported
via the existing repair step on install/upgrade. Existing log rows (short-retention
archival) are unaffected.

## Test Plan (summary)
- Newman: `POST /synchronizations/{id}/test` on a bogus sync → non-405 (was 405).
- Local: create a real source+sync, run it, assert one `synchronization_log` and
  that contract-logs share its `executionId`.
- Full Newman suite green (94/94); targeted regression specs unaffected.
