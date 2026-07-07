---
kind: code
capabilities:
  - synchronization-engine
---

# Proposal: contract-duplication-handling

## Summary

Stop synchronization contracts from being silently mis-resolved or duplicated
for the same `(synchronizationId, originId)` pair, and clean up the duplicates
that already exist. A synchronization contract links a source object
(`originId`) within a synchronization (`synchronizationId`) to its synced
target; there must be at most ONE contract per `(synchronizationId, originId)`.
Contracts are stored as OpenRegister objects (register `openconnector`, schema
`synchronization_contract`), not a local DB table, so uniqueness is enforced in
the OpenConnector persist path rather than by a SQL index. This change tackles
three independent facets: (1) log instead of silently guessing when duplicate
contracts are found on lookup, (2) prevent new duplicates at write time, and
(3) migrate away the duplicates already present in stored data.

## Motivation

The current contract lookups return the first match whenever duplicates exist.
When two contracts share the same `(synchronizationId, originId)`, an arbitrary
one is chosen — so the wrong target can be updated, or an object can appear
"missing" — and there is zero trace in the Nextcloud log. Operators cannot see
that a mis-resolution happened, let alone which contracts collided.

Root causes (verified in code):

- `lib/Service/SynchronizationContractService.php` — `findBySyncAndOrigin`
  (~line 141) and `findByOriginId` (~line 173) both `return $matches[0]` with
  no logging and no deterministic ordering. This service has no logger injected.
- `lib/Service/SynchronizationService.php` — `findContractBySyncAndOrigin`
  (~line 425) and `findContractByOriginId` (~line 450) do the same.
- The create-new branch in `processSynchronizationObject` (~line 5267, reached
  when the lookup returned null) can insert a second contract for a pair that a
  concurrent run already created, and the persist path
  (`SynchronizationContractService::persist` ~line 222 /
  `createFromArray` ~line 263) does no existence check before inserting.
- Historically, the pre-retrofit main branch could throw
  `MultipleObjectsReturnedException` (→ 500) on these lookups; the current dev
  branch silently picks `[0]`. Either way, existing stored data already
  contains duplicate contracts that must be reconciled.

## Affected Projects

- [x] Project: `openconnector` — make the four contract lookups log-and-pick
  deterministically on duplicates, add an existence check to the persist path
  so an existing pair is updated rather than duplicated, and add a repair step
  that de-duplicates existing stored contracts.

## Scope

### In Scope

- Log-don't-guess on lookup: when a lookup finds more than one match for a
  `(synchronizationId, originId)` pair, log at warning level via the logger with
  the synchronizationId, originId, and ALL matching contract uuids, then pick
  DETERMINISTICALLY — the most-recently-updated contract (sort by
  updated/created descending) — instead of arbitrary `[0]`. Applies to all four
  lookup sites named above.
- Inject `Psr\Log\LoggerInterface` into `SynchronizationContractService` (it
  currently has none).
- Prevent new duplicates at write time: before inserting, look up any existing
  contract by `(synchronizationId, originId)`; if one exists, UPDATE it instead
  of creating a second.
- Migrate existing duplicates: a repair step that scans stored
  `synchronization_contract` objects, groups by `(synchronizationId, originId)`,
  keeps the most-recently-updated, and removes the rest. Idempotent and
  tolerant of both the pre-retrofit (`MultipleObjectsReturnedException`) and
  current (`[0]`) eras.
- Unit tests for all three facets.

### Out of Scope

- Any change to OpenRegister — contracts are OR objects but uniqueness is
  enforced in OpenConnector; OR is only read/written through its object service.
- Adding a SQL unique index — contracts are not a local DB table.
- Internal batching / paginated processing, and any assumption of a consistent
  source ordering — neither is introduced.
- The schema-nonconformance per-object error-isolation change
  (`sync-object-error-isolation`), which is a separate, independent symptom.

## Approach

Facet 1 is a small shared behavior at each lookup: after fetching matches, if
`count($matches) > 1`, log a warning with the pair and all uuids, then sort the
matches by updated/created descending and return the newest. Facet 2 adds a
pre-insert existence probe by `(synchronizationId, originId)` in the persist
path so `synchronizeContract`'s create branch upserts onto the existing
contract. Facet 3 is a Nextcloud repair step (`IRepairStep`, registered in
`appinfo/info.xml`, matching the existing `InitializeRegister` pattern) that
groups stored contracts by pair, keeps the newest per group, and deletes the
rest through the OR object service — idempotent, safe to re-run.

## New Dependencies

None.

## Impact

- `lib/Service/SynchronizationContractService.php`: constructor gains
  `LoggerInterface`; `findBySyncAndOrigin`, `findByOriginId`, and the persist
  path change behavior. Return shapes are unchanged.
- `lib/Service/SynchronizationService.php`: `findContractBySyncAndOrigin`,
  `findContractByOriginId`, and the create-new branch in
  `processSynchronizationObject`.
- `lib/Repair/` gains a de-duplication repair step; `appinfo/info.xml` gains a
  `<step>` entry.
- Behavioral change: duplicate lookups now emit a warning log and resolve
  deterministically instead of arbitrarily; stored duplicates are removed on
  repair. No public API or return-shape change.

## Cross-Project Dependencies

None. The OR object service is already on the call path and is only used, not
modified.

## Risks

### Risk 1: Repair step deletes the wrong contract of a duplicate pair

**Severity:** Medium — **Mitigation:** the keep policy is deterministic
(most-recently-updated, falling back to created) and mirrors the runtime lookup
tie-break, so the repair keeps exactly the contract the live lookups would now
resolve to. The step logs every group it collapses and the uuids it removes,
and is idempotent so it can be re-run and audited.

### Risk 2: Duplicates re-appear under concurrent runs after cleanup

**Severity:** Medium — **Mitigation:** facet 2's pre-insert existence check
closes the create-vs-create window in the common path; residual races remain
observable because facet 1 now logs every duplicate resolution, giving
operators a signal to re-run the repair.

### Risk 3: Pre-retrofit stored data throws on lookup during repair

**Severity:** Low — **Mitigation:** the repair step reads via the bulk
`findAllObjects` list path (which returns all matches) rather than the
single-match lookups, so it never trips the pre-retrofit
`MultipleObjectsReturnedException`; it tolerates both eras.

## Rollback Strategy

Revert the lookup/persist edits and remove the repair-step class and its
`info.xml` entry. The change is additive at the code level and the repair step
only deletes redundant duplicate rows (the kept contract is unchanged), so there
is no schema migration to reverse; already-removed duplicates stay removed,
which is the intended end state.

## Open Questions

None.
