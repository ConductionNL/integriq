# Migration: contract-duplication-handling

This change de-duplicates existing stored synchronization contracts. Because
contracts are OpenRegister objects (register `openconnector`, schema
`synchronization_contract`) rather than local DB rows, there is no table/column
schema to alter — the reconciliation runs as an idempotent Nextcloud **repair
step**, not a `changeSchema()` migration. This document describes that repair.

## Current State

- Stored `synchronization_contract` objects may contain more than one contract
  for the same `(synchronizationId, originId)` pair.
- There is no SQL unique constraint enforcing one-per-pair (contracts are OR
  objects, not a local table).
- Two historical eras produced the duplicates:
  - **Pre-retrofit era:** single-match lookups could throw
    `OCP\AppFramework\Db\MultipleObjectsReturnedException` (surfacing as a 500)
    when a pair had duplicates.
  - **Current era:** lookups silently return the first match (`$matches[0]`),
    masking the duplicates entirely.

## Target State

- At most ONE contract per `(synchronizationId, originId)` pair remains in
  storage — the most-recently-updated contract of each former duplicate group.
- Removed duplicate uuids and the kept uuid are recorded in the log for each
  collapsed group.
- Re-running the repair on the reconciled dataset removes nothing (idempotent).

## Migration Class

This is a repair step, not a schema migration.

```
Type: Nextcloud repair step (OCP\Migration\IRepairStep)
File: lib/Repair/DeduplicateSynchronizationContracts.php
Namespace: OCA\OpenConnector\Repair
Registered: appinfo/info.xml <repair-steps> (post-migration, alongside
            InitializeRegister / InitializeActions)
Key operations:
- getName(): human-readable step name
- run(IOutput $output):
  - read ALL synchronization_contract objects via the bulk list path
    (SynchronizationContractService::findAllObjects / OR findAll) — NOT the
    single-match lookups, so it never trips MultipleObjectsReturnedException
  - group by (synchronizationId, originId)
  - for each group with >1 member: sort by updated (fallback created) desc,
    keep the newest, delete the rest via the OR object service (deleteObject)
  - log kept + removed uuids per collapsed group
Dependencies (constructor): OrObjectService (or SynchronizationContractService),
  LoggerInterface — resolved lazily via the container where OR peer-app
  autoloading matters, mirroring InitializeRegister.
```

## Migration Steps

1. Fetch all `synchronization_contract` objects via the bulk list path (register
   `openconnector`, schema `synchronization_contract`). This path returns every
   match and does not throw on duplicates, so it works in both the pre-retrofit
   and current eras.
2. Group the fetched contracts in memory by the `(synchronizationId, originId)`
   pair.
3. For each group containing more than one contract, sort the group by `updated`
   descending (falling back to `created` when `updated` is absent) and select
   the first as the keeper.
4. Delete every non-keeper contract in the group through the OR object service
   (`deleteObject(uuid)`).
5. Log the pair, the kept uuid, and each removed uuid for the collapsed group.
6. Groups with a single member are left untouched.

## Data Impact

- Affects only pairs that currently have more than one contract; single-contract
  pairs are untouched.
- The kept contract is not modified — only redundant duplicate contracts are
  deleted, so no field data is transformed or lost on the surviving record.
- Runs on live data: the step is read-then-delete over the bulk list and does
  not require downtime. Deletions are limited to redundant duplicates, so a
  concurrent sync at worst re-creates a contract that the next repair run will
  reconcile again.
- Deletion is not automatically reversible; the kept contract is the
  most-recently-updated one, matching what the retrofitted live lookups resolve
  to, so the removed rows are the stale copies.

## Rollback Procedure

There is no schema change to reverse. Rollback means reverting the code edits
and removing the repair-step class plus its `appinfo/info.xml` `<step>` entry.
Already-removed duplicate contracts stay removed — that is the intended end
state (one contract per pair). No reverse migration re-creates deleted
duplicates, and none is desired.

## Validation

- After the step runs, grouping all `synchronization_contract` objects by
  `(synchronizationId, originId)` yields no group with more than one member.
- The log contains one collapsed-group entry per former duplicate group, each
  listing the kept uuid and the removed uuids.
- Re-running the repair step reports zero removals (idempotency check).
- A unit test seeds a set of duplicate contracts (including at least one
  multi-member group and one single-member pair) and asserts the step keeps the
  most-recently-updated contract per group, removes the rest, and is a no-op on
  the second run.