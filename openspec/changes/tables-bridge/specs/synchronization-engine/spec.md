# synchronization-engine — Delta: nextcloud-table source/target type

## Purpose

Extends the synchronization engine's source-fetch, target-write, and
deletion switches (REQ-002/REQ-004 of the base spec) with a
`nextcloud-table` branch, so `sourceType`/`targetType: nextcloud-table` is a
recognised, implemented discriminator alongside `register/schema`, `api`,
`database`, and `file`, instead of falling into the base spec's
"unsupported target type" exception or the silent no-op `@todo` branches
noted for `register/schema`/`database` sources. Full behavior (contract
mapping, coercion, permission handling) is specced in the new `tables-bridge`
capability spec; this delta only extends the base engine's dispatch points.

## ADDED Requirements

### Requirement: `nextcloud-table` source/target dispatch (REQ-014)

`SynchronizationService::getAllObjectsFromSource()` MUST dispatch
`sourceType: nextcloud-table` to the Tables source adapter (see
`tables-bridge` REQ-002) instead of falling through with no matching `case`.
`SynchronizationService::updateTarget()` MUST dispatch `targetType:
nextcloud-table` to the Tables target adapter (see `tables-bridge` REQ-001)
instead of throwing `Unsupported target type`. `SynchronizationService::
deleteInvalidObjects()` MUST dispatch `targetType: nextcloud-table` through
the same guarded deletion path described in `tables-bridge` REQ-005 — this
requirement does not itself define the deletion-safety guard (that is
`sync-safety-guardrails`'s concern); it only establishes that
`nextcloud-table` is a recognised branch of that shared dispatch, not a
type that silently no-ops or bypasses the guard.

#### Scenario: source fetch dispatches to the Tables adapter

- **GIVEN** a synchronization with `sourceType: nextcloud-table`
- **WHEN** `getAllObjectsFromSource()` runs
- **THEN** the Tables source adapter is invoked and its returned rows are
  used as the fetched objects, exactly as the `api` branch returns
  `getAllObjectsFromApi()`'s result

#### Scenario: target write dispatches to the Tables adapter instead of throwing

- **GIVEN** a synchronization with `targetType: nextcloud-table`
- **WHEN** `updateTarget()` runs
- **THEN** the Tables target adapter is invoked
- **AND** no `Unsupported target type` exception is thrown (unlike an
  unrecognised type, which still throws per the base spec's REQ-001
  `default` branch)

#### Scenario: an unrecognised type (not nextcloud-table) still throws

- **GIVEN** a synchronization with `targetType: some-future-type` that is
  neither `register/schema`, `api`, `database`, nor `nextcloud-table`
- **WHEN** `updateTarget()` runs
- **THEN** it still throws `Unsupported target type: some-future-type`,
  unchanged from the base spec's existing `default` branch behavior

## Notes

- This delta intentionally does not restate `tables-bridge`'s REQ-001
  through REQ-007 — it only extends the base engine's `switch` dispatch
  points so `nextcloud-table` stops being an unrecognised/unsupported value.
  All contract-mapping, coercion, feature-detection, and permission-handling
  behavior lives in the `tables-bridge` capability spec to avoid duplicating
  requirement text across two specs.
- REQ-002's existing Notes already flag that `register/schema` and
  `database` source branches are unimplemented `@todo` no-ops; this delta
  does not change that — `nextcloud-table` is additive alongside those
  existing gaps, not a fix for them.
