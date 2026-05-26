# Proposal: openconnector-write-once-immutable-logging

## Summary
After the OpenConnector→OpenRegister storage cutover, all four log schemas
(`synchronization_log`, `synchronization_contract_log`, `job_log`, `call_log`)
are declared `immutable: true` + `appendOnly: true` + `x-openregister-archival`
in `lib/Settings/openconnector_register.json`. But the engine still writes logs
with a **create-then-update** pattern — it persists a log row early (to obtain an
id for child-log linking), then `saveObject(..., uuid: $log->getUuid())` to fill in
results/timing/message at the end. OpenRegister now rejects every such update with
`405 SCHEMA_APPEND_ONLY`. This breaks **every synchronization run** and Newman's
`POST /synchronizations/{id}/test`. This change refactors the engine to build each
log fully in memory and persist it **exactly once** (a single append), correlating
child contract-logs to their parent sync-log via a generated run-correlation id
rather than the parent's OpenRegister object id.

## Motivation
The immutable/append-only declaration on log schemas (commit `5bf19f9d`,
2026-05-20, spec `retrofit-2026-05-24-logs-and-statistics`, per hydra ADR-024
`x-openregister-archival`) is a deliberate, accepted decision: log records are an
immutable audit/archival trail. The engine code was never migrated to honour it —
it carries the create-then-update pattern inherited from the legacy mutable
`oc_openconnector_*_logs` tables. The result is a hard functional regression:
running any synchronization returns `405 SCHEMA_APPEND_ONLY: Schema
"synchronization_log" is append-only; update operations are not permitted`. The
controller surfaces the OpenRegister exception code (405) verbatim, so the failure
is opaque. This must be fixed for the app to be functional post-cutover.

## Affected Projects
- [x] Project: `openconnector` — refactor `SynchronizationService`, `JobService`,
  `CallService` log writes to write-once; add a run-correlation id field to the
  log schema declarations so child-logs link without depending on the parent's
  OR object id.

## Scope

### In Scope
- Refactor `SynchronizationService::synchronize` / `synchronizeExternToIntern` /
  `synchronizeInternToExtern` so the `synchronization_log` is persisted exactly
  once, at the end, with complete data.
- Refactor `synchronization_contract_log` writes (currently create-then-update at
  multiple sites) to a single append per contract-log.
- Refactor `JobService` and `CallService` log writes to write-once where they
  currently create-then-update.
- Introduce a generated `executionId` (run-correlation) field on the log schemas,
  stamped on parent and child logs at the start of a run, used to correlate
  contract-logs to their sync-log without persisting the parent early.
- Keep `expires`/retention behaviour intact (already formatted via `?->format('c')`).

### Out of Scope
- Changing the immutable/append-only/archival declaration on log schemas (the
  decision stands — this change makes the engine comply, not the schema relax).
- Retention-constant unification (tracked in `openconnector-adopt-or-abstractions`,
  ADR-004).
- The event_message UUID-field fix and the `expires` DateTime-format fix (already
  on PR #991, branch `fix/e2e-post-cutover-log-queries`, on which this builds).
- Frontend journey failures (Add-dialog wiring), the Dashboard/Mappings 503s, and
  the manifest `roadmap` page-type test — separate fixes.

## Approach
Generate an `executionId` (UUID string) at the start of each run. Build the log
payload in a plain array, accumulating results/timing/message as the run
progresses (passed by reference rather than re-read from a persisted entity).
Child contract-logs reference `executionId` (a correlation field) instead of the
parent log's OR id, so they can be appended independently. The parent sync-log is
created once, at the very end, with the complete payload. Error/cancellation paths
also build-then-append-once (no early row to update). Details in design.md.

## New Dependencies
None. UUID generation uses the existing `Symfony\Component\Uid` / `\OCP` utilities
already available in the app (same mechanism OpenRegister uses).

## Impact
- `lib/Service/SynchronizationService.php` — log lifecycle restructured.
- `lib/Service/JobService.php`, `lib/Service/CallService.php` — log write-once.
- `lib/Settings/openconnector_register.json` — add `executionId` property to the
  four `*_log` schemas (additive; no immutability change).
- API behaviour: `POST /synchronizations/{id}/run` and `/test`, job runs, and HTTP
  calls now succeed and produce exactly one log row each. Log query/list endpoints
  unchanged.

## Cross-Project Dependencies
- Depends on OpenRegister enforcing `appendOnly`/`immutable` (already shipped — it
  is what surfaces the 405). No OR change required.

## Risks

### Risk 1: Behavioural regression in log content
**Severity:** Medium
**Mitigation:** The final log payload must contain the same fields the
create-then-update path produced (counts, timing, contracts, message, expires).
Newman + E2E + a focused unit assertion on the produced log object guard this.

### Risk 2: Child-log correlation gap
**Severity:** Medium
**Mitigation:** Contract-logs previously stored `synchronizationLogId = parent OR
id`. Switching to `executionId` correlation must be applied consistently on both
the parent and every child; a test asserts a run's contract-logs share the parent
sync-log's `executionId`.

### Risk 3: Error-path partial logs
**Severity:** Low
**Mitigation:** On exceptions the engine must still append one terminal log with
the error message (no early row to leave dangling). Newman's bogus-sync test (no
sourceId) exercises this path and must return a clean 400, not 405.

## Rollback Strategy
Revert the commit(s). The schema `executionId` addition is additive and harmless if
unused; no data migration is performed (logs are short-retention archival rows).

## Open Questions
None — the immutable-log decision is confirmed (Ruben, 2026-05-26).
