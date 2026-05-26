# logs-and-statistics Specification

**Status**: in-progress
**Scope**: openconnector
**OpenSpec changes**:
- openconnector-write-once-immutable-logging

## Purpose
The synchronization engine produces audit log records (`synchronization_log`,
`synchronization_contract_log`) that are stored in OpenRegister. Per the cutover
decision (commit `5bf19f9d`, hydra ADR-024 `x-openregister-archival`), these log
schemas are `immutable: true` + `appendOnly: true` — OpenRegister rejects any
update to an existing log object with `405 SCHEMA_APPEND_ONLY`. This change makes
the engine comply: each log record is built fully in memory and persisted with a
single append, instead of the legacy create-then-update pattern that 405s.

## ADDED Requirements

### Requirement: Synchronization log is persisted exactly once per run
The engine MUST persist the `synchronization_log` for a run with exactly one
append (a single create with no subsequent update). The engine MUST NOT call
`saveObject(..., uuid: <log uuid>)` against the `synchronization_log` schema.
All result fields (object counts, timing, contracts, message, `expires`) MUST be
accumulated in memory and written in the terminal append. This applies to the
success path, the rate-limit path, and the validation/cancellation path
(e.g. empty `sourceId`).

#### Scenario: bogus synchronization test returns a clean error, not 405
- GIVEN a synchronization with no `sourceId`
- WHEN `POST /api/synchronizations/{id}/test` is called
- THEN the engine appends exactly one `synchronization_log` with the cancellation message
- AND the response status is 400 (or another non-405 application code)
- AND no `405 SCHEMA_APPEND_ONLY` error is raised

#### Scenario: successful run writes one finalized log
- GIVEN a synchronization with a valid source
- WHEN it runs to completion
- THEN exactly one `synchronization_log` row exists for that run
- AND that row contains the final counts, timing, message and `expires`

### Requirement: Contract logs correlate via an execution id, not the parent log's OR id
The engine MUST generate one `executionId` (UUID string) per run, stamp it on the
`synchronization_log` and on every `synchronization_contract_log` produced during
that run, and use `executionId` to correlate child contract-logs to their parent
sync-log. The engine MUST NOT depend on the parent `synchronization_log`'s
OpenRegister object id at contract-log creation time (the parent is not persisted
until the run ends, and OpenRegister assigns ids on create — a client-supplied
uuid is ignored). Each `synchronization_contract_log` MUST be persisted with a
single append.

#### Scenario: contract logs share the run's executionId
- GIVEN a run that processes one or more objects
- WHEN the run completes
- THEN every `synchronization_contract_log` for that run carries the same `executionId`
- AND the `synchronization_log` for that run carries that same `executionId`
- AND each contract-log was written with a single append (no update)

## Non-Functional Requirements

- **Performance:** Write-once reduces per-run log writes (one create vs. 2–5
  create+update round-trips per log), so it MUST NOT regress sync throughput.
- **Internationalization:** No user-facing copy added; existing nl/en messages
  unchanged (hydra ADR-007).

## Acceptance Criteria

- [ ] Newman `POST /synchronizations/{id}/test` no longer returns 405 (bogus sync → clean 4xx).
- [ ] All four `*_log` schemas remain `immutable: true` + `appendOnly: true`.
- [ ] A run produces exactly one `synchronization_log` and N `synchronization_contract_log` appends.
- [ ] `synchronization_log` and its contract-logs share one `executionId`.

## Notes
- Decision confirmed by Ruben (2026-05-26): fix the engine, do NOT relax the
  immutable/append-only schema declaration. Cross-ref ADR-004 (archival retention),
  hydra ADR-024 (`x-openregister-archival`).
- `JobService` and `CallService` already persist their logs write-once (single
  create, no update) — out of scope.
