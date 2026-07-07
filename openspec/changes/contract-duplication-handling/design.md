# Design: contract-duplication-handling

## Context

Synchronization contracts are stored as OpenRegister objects (register
`openconnector`, schema `synchronization_contract`) — not a local DB table, so
there is no SQL unique constraint on `(synchronizationId, originId)`. Uniqueness
is a runtime invariant that OpenConnector must enforce itself. Today it does
not: four lookup sites return the first match and stored data already contains
duplicates.

The affected sites (verified in code):

- `lib/Service/SynchronizationContractService.php`: `findBySyncAndOrigin`
  (~141) and `findByOriginId` (~173) — both `return $matches[0]->jsonSerialize()`
  with no logger (the class has no `LoggerInterface`), no ordering.
- `lib/Service/SynchronizationService.php`: `findContractBySyncAndOrigin`
  (~425) and `findContractByOriginId` (~450) — same `$matches[0]` pattern
  (this service already injects `$this->logger`).
- `SynchronizationService::processSynchronizationObject` (~5267) resolves the
  contract via `findContractBySyncAndOrigin`; when that returns null the
  create-new branch runs, and `SynchronizationContractService::persist` (~222) /
  `createFromArray` (~263) insert with no existence check.

## Goals / Non-Goals

Goals: (1) every duplicate resolution is logged and deterministic; (2) the
write path cannot add a second contract for an existing pair in the common
case; (3) existing stored duplicates are reconciled to one-per-pair.

Non-Goals: modifying OpenRegister, adding a SQL index, introducing batching or
assuming source ordering, and the separate `sync-object-error-isolation` change.

## Decisions

### Decision 1: Log-and-pick-newest as the shared duplicate-resolution rule

At each of the four lookups, after fetching matches: if `count($matches) > 1`,
log at warning level with `synchronizationId`, `originId`, and the full list of
matching contract uuids, then sort matches by `updated` (falling back to
`created`) descending and return the newest. Rationale: the most-recently-updated
contract is the one that reflects the latest sync state; picking it
deterministically means repeated lookups agree, and the warning gives operators
the uuids to investigate. Alternative considered: throw
`MultipleObjectsReturnedException`. Rejected — that reintroduces the pre-retrofit
500 and aborts otherwise-healthy runs; logging + deterministic pick degrades
gracefully.

### Decision 2: Inject LoggerInterface into SynchronizationContractService

`SynchronizationContractService` currently has only `OrObjectService` in its
constructor. It gains `private readonly LoggerInterface $logger` so its two
lookups can log duplicates. `SynchronizationService` already has `$this->logger`
and needs no constructor change.

### Decision 3: Pre-insert existence check in the persist path

`synchronizeContract`'s create branch must not blindly insert. Before inserting
a contract that carries a `(synchronizationId, originId)` pair, the persist path
looks up any existing contract for that pair (reusing `findBySyncAndOrigin`); if
one exists, it UPDATEs that contract (upsert onto its uuid) instead of creating
a second. This keeps a single row per pair even when the caller's earlier lookup
returned null (e.g. a concurrent run created it in between). Alternative
considered: rely solely on the repair step. Rejected — that lets duplicates
accumulate between repair runs.

### Decision 4: De-duplication as an idempotent IRepairStep

Facet 3 is a Nextcloud repair step (`OCA\OpenConnector\Repair\*` implementing
`IRepairStep`), registered in `appinfo/info.xml` `<repair-steps>` alongside the
existing `InitializeRegister` / `InitializeActions`. It reads ALL contracts via
the bulk list path (`findAllObjects` / OR `findAll`), groups them in memory by
`(synchronizationId, originId)`, keeps the most-recently-updated per group, and
deletes the rest through the OR object service (`deleteObject(uuid)`). It is
idempotent (a second run finds no groups with >1 member) and reads via the list
path so it never trips a pre-retrofit `MultipleObjectsReturnedException`.
Alternative considered: a `lib/Migration/Version*` schema migration. Rejected —
contracts are OR objects, not DB rows; there is no schema to alter, and a repair
step runs with peer-app autoloaders registered (matching the existing OR-object
bootstrap pattern in this app).

## Nextcloud Integration

- Services: `lib/Service/SynchronizationContractService.php` (constructor +
  `findBySyncAndOrigin` + `findByOriginId` + persist path);
  `lib/Service/SynchronizationService.php` (`findContractBySyncAndOrigin`,
  `findContractByOriginId`, create-new branch in `processSynchronizationObject`).
- Repair: new `lib/Repair/` class implementing `OCP\Migration\IRepairStep`,
  wired in `appinfo/info.xml` `<repair-steps>`.
- Logging: `Psr\Log\LoggerInterface` — newly injected into
  `SynchronizationContractService`, already present on `SynchronizationService`.
- OR access: `OrObjectService` `findAll` / `saveObject` / `deleteObject` — used,
  not modified.
- Controllers / Mappers / Entities: none.

## Security Considerations

No security impact. The change logs diagnostic fields (synchronizationId,
originId, contract uuids) — identifiers, not credentials or payloads — and the
repair step only removes redundant duplicate rows while leaving the kept
contract untouched. No authentication, authorization, or trust boundary is
altered.

## File Structure
```
lib/
  Service/
    SynchronizationContractService.php   # +LoggerInterface; log-and-pick-newest;
                                         # persist existence check
    SynchronizationService.php           # log-and-pick-newest on both lookups;
                                         # create-branch upsert guard
  Repair/
    <DeduplicateSynchronizationContracts>.php   # idempotent de-dup repair step
appinfo/
  info.xml                               # register the repair step
tests/
  Unit/
    Service/
      SynchronizationContractServiceTest.php   # duplicate-log + persist-upsert
      SynchronizationServiceTest.php           # duplicate-log on lookups
    Repair/
      <DeduplicateSynchronizationContractsTest>.php   # seeded-duplicate de-dup
```

## Risks / Trade-offs

- [Repair deletes the wrong duplicate] → keep policy (most-recently-updated,
  fallback created) mirrors the runtime tie-break, so the kept contract equals
  what live lookups now resolve to; every collapsed group is logged.
- [Duplicates re-appear under concurrency] → facet 2 closes the common-path
  window; facet 1 logs any residual duplicate so the repair can be re-run.
- [Pre-retrofit data throws on lookup] → the repair reads via the bulk list
  path, not the single-match lookups, so it tolerates both eras.

## Migration Plan

Facet 3's reconciliation of existing stored contracts is described in detail in
`migration.md` (repair-step outline, grouping, keep-newest policy, idempotency,
pre-retrofit tolerance). Deploy is a code update plus the repair step running on
`occ upgrade`; rollback reverts the code edits and removes the repair step — no
schema migration is involved and already-removed duplicates stay removed.

## Open Questions

None.