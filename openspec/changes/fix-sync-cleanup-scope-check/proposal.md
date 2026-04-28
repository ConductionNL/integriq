## Why

Synchronizations no longer delete OpenRegister objects when they disappear from the source. The cleanup query in `SynchronizationContractMapper::findAllBySynchronizationAndSchema` joins against the legacy `openregister_objects` table, which OpenRegister has stopped writing to since the move to per-register/schema "magic tables" (`oc_or_{registerId}_{schemaId}`). The JOIN therefore returns zero rows on every sync, the cleanup diff is always empty, and orphan objects accumulate indefinitely.

A naive fix that simply drops the JOIN is unsafe: `ObjectService::deleteObject($uuid)` resolves UUIDs across all magic tables, so any contract whose `target_id` points outside the synchronization's current `register/schema` scope (e.g. after the sync's target was changed mid-life, or from legacy data) would result in cross-schema object deletion. The JOIN's `o.schema = :schemaId` filter was load-bearing as a scope guard.

## What Changes

- Replace the broken `INNER JOIN openregister_objects` in `SynchronizationContractMapper::findAllBySynchronizationAndSchema` with a `synchronization_id`-only filter. Rename the method to `findAllBySynchronization` to match its new contract.
- In `SynchronizationService::deleteInvalidObjects`, before calling `updateTarget('delete')` for each candidate target, verify the object exists in the synchronization's current `register/schema` via `ObjectService::find($targetId, register: $registerId, schema: $schemaId)`. Skip candidates that are out of scope or do not exist; only proceed for in-scope hits.
- Replace the silent `// @todo log` swallow at `SynchronizationService.php:1189` with a proper log entry — once cleanup actually runs again, partial failures should surface, not vanish.
- Add a test that asserts an object missing from the source list is deleted from OpenRegister on the next sync, and that an object whose UUID belongs to a different `register/schema` is NOT deleted (cross-scope safety).

## Capabilities

### New Capabilities

- `synchronization-cleanup`: Behavior of the synchronization cleanup pass — which contracts are considered for deletion, what scope guards apply, and how out-of-scope or missing target objects are handled.

### Modified Capabilities

(none — no other openconnector specs exist yet)

## Impact

- **Code**:
  - `openconnector/lib/Db/SynchronizationContractMapper.php` — rename and rewrite `findAllBySynchronizationAndSchema` → `findAllBySynchronization`; drop the JOIN against `openregister_objects`.
  - `openconnector/lib/Service/SynchronizationService.php` — update the call site at `deleteInvalidObjects` (~line 1155); add per-candidate `ObjectService::find` scope check before `updateTarget('delete')`; replace the `// @todo log` swallow with a real log line.
- **Behavior**:
  - Sync runs that previously silently failed to delete missing objects will now correctly delete them.
  - Contracts whose `target_id` falls outside the sync's current `register/schema` scope are left alone (defensive).
  - Cleanup pass cost shifts from one JOINed query to one unjoined query plus N scoped lookups, where N is "contracts per synchronization." N is typically small (hundreds at most); fan-out is not a concern at current scales.
- **Cross-app**: Aligns with ADR-001 ("Apps SHOULD use the `ObjectService` for CRUD operations rather than direct mapper access"). The fix removes the only remaining direct cross-app SQL coupling in the openconnector cleanup path.
- **Follow-up (out of scope here)**: Other openconnector queries — and queries in other Conduction apps — may also JOIN against `openregister_objects` and be silently broken. A separate audit-level change should sweep for this pattern. Long-term, OpenRegister should expose a scoped `deleteObject($uuid, $register, $schema)` so the safety lives at the API boundary instead of being re-implemented in every caller.
