## Context

OpenConnector's `SynchronizationService::deleteInvalidObjects` is responsible for the cleanup pass at the end of an extern-to-intern sync: any synchronization contract whose target object did not appear in this run's source list should have its target object deleted from OpenRegister, and the contract's `target_id` should be cleared.

The cleanup loads all candidate contracts via `SynchronizationContractMapper::findAllBySynchronizationAndSchema(syncId, schemaId)`. The mapper currently does:

```sql
SELECT c.* FROM openconnector_synchronization_contracts c
INNER JOIN openregister_objects o ON c.target_id = o.uuid
WHERE c.synchronization_id = :syncId AND o.schema = :schemaId
```

Two purposes were intended for the JOIN:
1. **Existence check** (`c.target_id = o.uuid`) — exclude orphan contracts whose target object no longer exists.
2. **Scope guard** (`o.schema = :schemaId`) — exclude contracts whose target object lives in a different schema (e.g. after the synchronization's `target_id` was changed mid-life).

The breakage: OpenRegister has migrated to per-register/schema "magic tables" (`oc_or_{registerId}_{schemaId}`). The legacy `openregister_objects` table is no longer populated for synced objects, so the INNER JOIN returns zero rows on every cleanup pass. `array_diff($allContractTargetIds, $synchronizedTargetIds)` is therefore always empty and no contracts are ever deleted. The symptom is silent: orphan objects accumulate in OpenRegister forever and `result.objects.deleted` in the sync log is always `0`.

A naive fix — drop the JOIN and filter only by `synchronization_id` — restores the deletion path, but loses the scope guard. `ObjectService::deleteObject($uuid)` resolves UUIDs unscoped (`register: null, schema: null` at `ObjectService.php:1469-1474`) and derives the schema from the found object. Any contract whose `target_id` points to an object outside the sync's current scope would be silently cross-deleted. This is real risk: schema retargeting, legacy data, and partial-write states can all produce out-of-scope `target_id` values.

## Goals / Non-Goals

**Goals:**
- Restore the deletion behavior: when an object disappears from the source, the corresponding OpenRegister object is deleted on the next sync.
- Preserve the scope guard: contracts whose `target_id` points outside the sync's current `register/schema` are not touched.
- Decouple the cleanup path from OpenRegister's storage layout. Use the public `ObjectService` API only — no direct queries against `openregister_objects` or magic tables from openconnector.
- Stop swallowing `DoesNotExistException` silently in the cleanup loop.

**Non-Goals:**
- Audit and fix every other JOIN against `openregister_objects` across openconnector and other apps — flagged in the proposal as follow-up.
- Add a scoped `deleteObject($uuid, $register, $schema)` API to OpenRegister — flagged as follow-up; would let the safety guard live at the API boundary instead of in every caller.
- Change synchronization mutation semantics (single-event delete path, restrictDeletion behavior, etc.).
- Migrate or backfill any existing data.

## Decisions

### Decision 1: Per-contract scoped lookup via `ObjectService::find`

**Choice**: For each cleanup candidate, call `ObjectService::find($targetId, register: $registerId, schema: $schemaId)`. Treat `null` as "out of scope or does not exist — skip." Treat a returned entity as "in scope — proceed with delete."

**Why**: This uses the public OpenRegister API and is fully decoupled from storage layout. It naturally subsumes both intents of the original JOIN (existence + scope) in one call. Aligns with ADR-001 ("Apps SHOULD use the `ObjectService` for CRUD operations rather than direct mapper access"). Future OpenRegister storage refactors do not break us.

**Alternatives considered**:
- *Bulk-query the magic table directly* (`SELECT uuid FROM oc_or_{registerId}_{schemaId} WHERE uuid IN (...)`). One round-trip instead of N. Rejected because it re-introduces the storage-layout coupling the bug just exposed; if magic-table naming changes again, openconnector silently breaks again.
- *Leave the JOIN and just point it at the magic table.* Same coupling problem, plus the table name is parameterized by register/schema, which complicates the QueryBuilder.
- *Drop the JOIN, accept the cross-scope risk.* Rejected — simplest patch but loses the safety property the colleague flagged.
- *Add `deleteObject($uuid, $register, $schema)` to OpenRegister and rely on it to refuse cross-scope.* Best long-term answer but requires a coordinated change across two apps; tracked as follow-up.

### Decision 2: Rename `findAllBySynchronizationAndSchema` → `findAllBySynchronization`

**Choice**: After dropping the JOIN, the method no longer filters by schema — every contract under one `synchronization_id` already targets the same schema (the schema is on the `Synchronization`'s `target_id`, not on the contract). Drop the unused `$schemaId` parameter and rename the method to reflect its actual contract.

**Why**: Method names must match what the method does. Keeping `AndSchema` in the name with no schema filter is a footgun for the next reader.

**Alternatives considered**:
- *Keep the old signature, ignore `$schemaId`.* Smaller diff but leaves a misleading parameter that PHPStan/Psalm will flag. Net negative.

### Decision 3: Replace `// @todo log` with a real warning log

**Choice**: At `SynchronizationService.php:1189`, replace the empty `catch (DoesNotExistException $exception) { // @todo log }` with a `LoggerInterface::warning(...)` call that includes the synchronization id, target id, and exception message.

**Why**: This catch block has been dormant because the cleanup never ran. Once cleanup actually runs again, partial failures (contract-not-found-on-second-lookup, race conditions during concurrent syncs) need to surface, not vanish. A warning log is the minimum.

**Alternatives considered**:
- *Re-throw.* Too aggressive — one stale contract should not abort the entire cleanup pass.
- *Leave the silent swallow.* Rejected for the reason above.

### Decision 4: Cleanup pass cost — N scoped lookups, no batching

**Choice**: Iterate candidates and call `ObjectService::find` once per candidate. Do not introduce a bulk-find helper.

**Why**: N is "contracts whose target_id was not seen in this run." For typical synchronizations N is small (zero on a clean run, low hundreds in pathological cases). The find call routes through OpenRegister's handler stack, which already does its own caching. A bulk helper is premature optimization for a path that is bounded by source size in practice.

**Trigger to revisit**: If a future user reports cleanup-pass slowness on syncs with very large stale-contract sets (>1000 candidates), introduce a bulk pre-filter at that point.

## Risks / Trade-offs

- **[Risk] An openregister `find` that is not idempotent could mutate state during cleanup** → Mitigation: `find` is read-only by contract; verify by inspection during implementation and add a regression test.
- **[Risk] Permission system on `ObjectService::find` rejects the lookup when run from a sync context (no user)** → Mitigation: cleanup must run with the same RBAC bypass that the existing save path uses. Verify the call path in `updateTargetOpenRegister` does not pass a user; if needed, pass `_rbac: false` to `find`. Check during implementation.
- **[Risk] Introducing a per-candidate API call in a hot path causes a regression on syncs with large diffs** → Mitigation: cleanup-pass timing is already recorded in `result.timing.stages.cleanup_invalid`; monitor a few real syncs after deploy. Acceptable threshold: cleanup pass < 5% of total sync time for normal cases.
- **[Trade-off] The fix is openconnector-only** → The deeper design fix (scoped `deleteObject` in OpenRegister) is deferred. Future apps can repeat the same mistake. Mitigated by the proposal's follow-up audit item.
- **[Risk] Other openconnector queries against `openregister_objects` may be silently broken too** → Mitigation: a focused grep is part of the implementation tasks (as a verification step, not part of the fix scope itself). Findings get filed as separate changes.

## Migration Plan

No data migration. Pure code change.

**Deploy**:
1. Ship the openconnector update.
2. On the next scheduled (or manually-triggered) extern-to-intern sync, accumulated orphan objects from the past will start being deleted. This is the desired behavior, not a regression — but operators should be aware that the first post-deploy sync may show a higher-than-usual `result.objects.deleted` count.

**Rollback**: Revert the openconnector commit. The previous (silently broken) cleanup behavior returns. Already-deleted orphans stay deleted; no data is restored. This is acceptable because the orphans were objects that no longer exist in any source — the deletion is in line with intent.

## Open Questions

- Does `ObjectService::find` require an authenticated user context to succeed when called from a sync (which has no user session)? If yes, the call needs `_rbac: false`. **Resolve during implementation** by inspecting the existing successful path through `updateTargetOpenRegister::saveObject`, which faces the same constraint.
