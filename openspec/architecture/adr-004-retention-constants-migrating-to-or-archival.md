# ADR-004: Log retention lives in PHP constants today; migrating to OR archival annotations

## Status
Accepted (capturing existing decision, with in-flight migration)

## Date
2026-05-20

## Context

Log retention windows are defined as `private const` integers in three
services, all expressed in milliseconds:

- `lib/Service/JobService.php:60-61` —
  `DEFAULT_SUCCESS_LOG_RETENTION = 3600000` (1 hour),
  `DEFAULT_ERROR_LOG_RETENTION = 2592000000` (30 days).
- `lib/Service/CallService.php:66-67` — identical pair, same values.
- `lib/Service/SynchronizationService.php:83-84` — same success value,
  error value differs: `259200000` (3 days, NOT 30 days as the other two).
  This drift is captured in `openconnector-adopt-or-abstractions` as a real
  bug surfaced by the audit.

All three services lazily override the constants from
`IAppConfig::getValueString('openconnector', 'retention')` (a JSON blob with
keys `callLogRetention`, `jobLogRetention`, `successLogRetention`). The
`LogCleanUpTask` cron uses these to delete expired rows.

The 2026-05-03 OR-abstraction audit (stream 4) flagged this triplication as
the cheapest cleanup win. Per hydra ADR-024 (app manifest) + the
`x-openregister-archival` annotation, retention belongs on the log schema
declaration, not in service code.

## Decision

For NEW retention rules, declare them as `x-openregister-archival.retention`
on the relevant log schema (per hydra ADR-024). Do NOT add new PHP
`const DEFAULT_*_RETENTION` declarations.

The existing three triplicated constants are not removed in this ADR — they
are scheduled for removal in the in-flight
`openconnector-adopt-or-abstractions` change (Decision 3) and the storage
migration in `openconnector-register-storage`. Until those land, the
constants stay as-is to preserve current runtime behaviour. The
`SynchronizationService` 3-day vs 30-day error retention drift is a known
bug; the migration unifies on the schema-declared value.

## Consequences

- A developer adding a new log table must NOT copy the
  `DEFAULT_*_RETENTION` pattern; instead, set the retention on the schema
  declaration in `lib/Settings/openconnector_register.json` once the
  in-flight change lands.
- The IAppConfig override hook (`getValueString('openconnector',
  'retention')`) stays operative; admins can still tenant-tune retention
  without code changes.
- Cross-reference: hydra ADR-024 (app manifest + `x-openregister-archival`).
- Cross-reference:
  `openspec/changes/openconnector-adopt-or-abstractions/proposal.md:64-67`
  + `design.md:58-68` — the migration plan that removes the constants.
- Cross-reference:
  `openspec/changes/openconnector-register-storage/proposal.md` — the
  storage migration that lands the retention annotations.

## Evidence

- `lib/Service/JobService.php:60-61` — duplicate retention constants.
- `lib/Service/CallService.php:66-67, 91-96` — duplicate retention
  constants + `IAppConfig` override pattern.
- `lib/Service/SynchronizationService.php:83-84` — duplicate retention
  constants WITH the 3-day vs 30-day drift.
- `lib/Db/Source.php:50-51` — per-source `$logRetention` (3600s) and
  `$errorRetention` (86400s) overrides at the entity level, expressed in
  seconds NOT milliseconds; another unit-mismatch landmine.
