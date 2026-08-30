# Migration: nextcloud-event-hub

## Current State
`lib/Settings/openconnector_register.json`'s `event_subscription` schema has no `action` or `retryPolicy`
properties; every subscription delivers via `sink` (implicit webhook) with the hardcoded backoff constants
in `EventService` (`RETRY_BASE_SECONDS=60`, `RETRY_FACTOR=4`, `RETRY_CAP_SECONDS=21600`, `maxRetries` default
parameter `5`). No Nextcloud SQL table or column exists for these fields — OR-managed schemas are stored as
JSON in OpenRegister's generic object table, not app-owned SQL tables, so there is nothing for a
`lib/Migration/VersionXXXXXXXXXX.php` class to alter.

## Target State
`event_subscription` gains two new, fully optional properties in the register descriptor:
`action` (`{kind, sink?, synchronizationId?, jobId?}`) and `retryPolicy`
(`{baseSeconds?, factor?, capSeconds?, maxRetries?}`). Every existing subscription row is valid against the
new schema unchanged (both properties absent ⇒ existing default behaviour, per `design.md` Decision 3 and
Decision 4).

## Migration Class
No `lib/Migration/VersionXXXXXXXXXX.php` is introduced. This is a register-descriptor content change only,
not a Nextcloud database schema migration.

```
No PHP migration class — schema-less OR storage.
File changed: lib/Settings/openconnector_register.json (event_subscription.properties.action,
event_subscription.properties.retryPolicy added; register descriptor `version` bumped per existing
convention — see `openconnector-register-schema` spec REQ-A-001 for the versioning rule this change follows).
```

## Migration Steps
1. Add `action` and `retryPolicy` property definitions to the `event_subscription` schema in
   `lib/Settings/openconnector_register.json` (additive, no `required` entries added — both stay optional).
2. Bump the descriptor's schema `version` field per the existing per-schema semver convention (already used
   elsewhere in this file, e.g. `event_subscription.version: "1.0.0"` → `"1.1.0"` for an additive,
   backward-compatible change).
3. No data backfill: existing `event_subscription` rows are read as-is; `action`/`retryPolicy` resolve to
   their code-level defaults (Decision 3/4) when absent, so no UPDATE statement runs against existing data.
4. On next app boot, `Application.php::register()` registers the 4 new `IEventDispatcher::addServiceListener`
   calls (Decision 1) — this is a code-path activation, not a data migration, and takes effect immediately
   on deploy with no separate step.

## Data Impact
Zero existing records are modified. Zero data loss. The change is purely additive to the schema and runs
safely on live data — no downtime, no lock, no batch job. New `event` records begin appearing only after
deploy, sourced from newly-fired NC events; there is no retroactive backfill of historical NC activity (not
possible — NC core does not retain a queryable history of past file/calendar/table/form events for this app
to replay).

## Rollback Procedure
Revert `lib/Settings/openconnector_register.json` to drop the two new properties (or simply stop reading
them — since they are optional and additively handled, leaving stale `action`/`retryPolicy` values on
already-created subscriptions after a code rollback is harmless: the reverted `EventService` code simply
ignores fields it doesn't know about). Remove the 4 `addServiceListener` calls in `Application.php` to stop
producing NC-native `event` records. No reverse migration script is needed given step 3 above (no data was
transformed).

## Validation
- `openspec validate nextcloud-event-hub --type change --strict` passes (schema-level check that the
  descriptor delta is well-formed).
- After deploy: create one subscription with `action.kind: 'synchronization'` and confirm it validates and
  round-trips (GET returns the same `action` block) via the existing `subscribe()`/`subscriptions()`
  endpoints.
- Confirm a pre-existing subscription (no `action`/`retryPolicy` set) still delivers via webhook with the
  unchanged 60s/×4/6h/5-retry schedule (regression check called out in `tasks.md`).
