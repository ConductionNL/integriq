# Migration: tables-bridge

## Current State

`Synchronization`, `Source`, and `SynchronizationContract` are OpenRegister
objects (register `openconnector`, schemas `synchronization`, `source`,
`synchronization_contract` — per `openconnector-direct-or-usage`, all
app-local mapper/entity classes for these were deleted; there is no
OpenConnector-owned SQL table for any of them). `Synchronization.sourceType`
/`targetType` are free-form strings (verified: `lib/Settings/
openconnector_register.json` declares them as `"type": "string"` with a
descriptive `title`, not a JSON Schema `enum`); `sourceConfig`/`targetConfig`
are free-form JSON objects. `nextcloud-table` is not currently a recognised
value anywhere in `SynchronizationService`'s dispatch `switch` statements.

## Target State

Identical schema — `nextcloud-table` becomes a recognised (but not
schema-enforced; the field stays a free string) value for
`sourceType`/`targetType`, and `sourceConfig`/`targetConfig` gain the
documented-but-not-schema-enforced keys `tableId` (integer), `viewId`
(integer, optional), and `columnMapping` (array, target-only). No new OR
register or schema is introduced.

## Migration Class

Not applicable. This change introduces no new Nextcloud database table,
column, or OpenRegister schema requiring a `lib/Migration/VersionXXXXXXXXXX.php`
class. `openconnector_register.json`'s `synchronization`/`source` schema
definitions are not modified — their `sourceConfig`/`targetConfig` fields
were already declared as open `"type": "object"` blobs before this change,
so no schema-definition edit is needed to accept the new keys either.

## Migration Steps

None. Deploying this change is a plain code deploy (new PHP service classes,
new controller routes, new Vue components) with no data transformation step
and no ordering constraint relative to existing data.

## Data Impact

Zero existing records are read, transformed, or migrated. No synchronization
currently in any environment has `sourceType`/`targetType: nextcloud-table`
(the value did not previously exist), so there is nothing to backfill.

## Rollback Procedure

Plain code revert (see proposal.md's Rollback Strategy) — no reverse
migration is needed since no forward migration ran. Any synchronization an
operator configured with `nextcloud-table` between deploy and rollback would,
post-rollback, hit the pre-existing `default` branch of the affected `switch`
statements and fail closed with `Unsupported target type` (for target) or
silently return no objects (for source, matching the existing
`register/schema`/`database` no-op precedent) — not silently misbehave.

## Validation

No schema/data validation applies. Functional validation is covered by
`test-plan.md` (TC-1 through TC-20) and `tasks.md`'s acceptance criteria —
this migration document exists to record that the "no database changes"
determination in design.md was deliberate, not an oversight.
