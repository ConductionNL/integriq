# Synchronization Engine Specification

**Status**: in-progress
**Scope**: openconnector
**OpenSpec changes**:
- contract-duplication-handling

## Purpose

Enforce the ADR-005 invariant that at most ONE synchronization contract exists
per `(synchronizationId, originId)` pair. Contracts are stored as OpenRegister
objects (register `openconnector`, schema `synchronization_contract`), so
uniqueness is enforced in the OpenConnector persist path rather than by a SQL
index. This spec adds three behaviors: log-and-pick-deterministically when a
lookup finds duplicates, upsert-instead-of-insert on the write path, and an
idempotent repair that reconciles already-stored duplicates.

## ADDED Requirements

### Requirement: Deterministic logged resolution of duplicate contracts on lookup

The system MUST resolve duplicate contract lookups deterministically and MUST
log them rather than returning an arbitrary match. When a contract lookup keyed
on the synchronizationId and originId pair (or on originId alone) finds more than
one matching contract, it MUST NOT silently return an arbitrary match. It MUST:

- Log at warning level via the logger, including the `synchronizationId`, the
  `originId`, and the uuids of ALL matching contracts.
- Resolve deterministically by returning the most-recently-updated contract —
  sorting the matches by their `updated` timestamp (falling back to `created`
  when `updated` is absent) in descending order — rather than the first match.

This behavior applies to all four lookup sites:
`SynchronizationContractService::findBySyncAndOrigin`,
`SynchronizationContractService::findByOriginId`,
`SynchronizationService::findContractBySyncAndOrigin`, and
`SynchronizationService::findContractByOriginId`. Because
`SynchronizationContractService` has no logger today, a `LoggerInterface` MUST
be injected into it. When exactly one contract matches, behavior is unchanged;
when none match, `null` is returned as before.

#### Scenario: duplicate contracts are logged with all uuids and newest is chosen

- GIVEN two or more stored contracts share the same `(synchronizationId,
  originId)` pair
- WHEN a lookup resolves that pair
- THEN a warning-level log entry is written containing the synchronizationId,
  the originId, and the uuids of all matching contracts
- AND the lookup returns the most-recently-updated contract rather than an
  arbitrary first match

#### Scenario: single match is returned without a warning

- GIVEN exactly one stored contract matches the lookup key
- WHEN the lookup resolves
- THEN that contract is returned
- AND no duplicate warning is logged

#### Scenario: newest is chosen by created when updated is absent

- GIVEN duplicate contracts for a pair where one or more lack an `updated`
  timestamp
- WHEN the lookup resolves the duplicates
- THEN ordering falls back to the `created` timestamp
- AND the most-recently-created contract is returned

### Requirement: Persist path prevents duplicate contracts for a pair

The persist path MUST NOT create a second contract for a pair that already has
one. Before creating a new synchronization contract that carries a
synchronizationId and originId pair, the system MUST look up any existing
contract for that pair. If one exists, the system MUST UPDATE that existing
contract (upsert onto its uuid) instead of creating a second contract. This
guards the create-new branch reached in `processSynchronizationObject` when the
caller's earlier lookup returned null (for example when a concurrent run created
the contract in between).

#### Scenario: persisting an existing pair updates rather than inserts

- GIVEN a stored contract already exists for a `(synchronizationId, originId)`
  pair
- WHEN the persist path is asked to create a contract for that same pair
- THEN the existing contract is updated in place
- AND no second contract is created for the pair

#### Scenario: persisting a new pair inserts a single contract

- GIVEN no stored contract exists for a `(synchronizationId, originId)` pair
- WHEN the persist path creates a contract for that pair
- THEN exactly one contract is created

### Requirement: Repair step reconciles existing duplicate contracts

The system MUST provide an idempotent Nextcloud repair step that reconciles
already-stored duplicate contracts. The step MUST:

- Read all stored `synchronization_contract` objects via the bulk list path
  (not the single-match lookups), so it tolerates both the pre-retrofit era
  (single-match lookups could throw `MultipleObjectsReturnedException`) and the
  current era (lookups pick the first match).
- Group the contracts by `(synchronizationId, originId)`.
- For each group with more than one member, keep the most-recently-updated
  contract (falling back to `created`) and remove the rest through the
  OpenRegister object service.
- Log each collapsed group with the kept uuid and the removed uuids.
- Be idempotent: a subsequent run finds no group with more than one member and
  removes nothing.

#### Scenario: seeded duplicates are collapsed keeping the newest

- GIVEN several stored contracts include duplicate groups sharing a
  `(synchronizationId, originId)` pair
- WHEN the repair step runs
- THEN each duplicate group is reduced to a single contract — the
  most-recently-updated one
- AND the removed contract uuids and the kept uuid are logged per group

#### Scenario: repair is idempotent on a clean dataset

- GIVEN a dataset with at most one contract per `(synchronizationId, originId)`
  pair
- WHEN the repair step runs
- THEN no contract is removed
- AND the step completes successfully

#### Scenario: repair tolerates the pre-retrofit era

- GIVEN a dataset that predates the lookup retrofit and contains duplicate
  contracts that would make a single-match lookup throw
- WHEN the repair step runs
- THEN it reads the contracts via the bulk list path without throwing
- AND it collapses the duplicates keeping the most-recently-updated contract

## Non-Functional Requirements

- **Performance:** No internal batching is introduced and no consistent source
  ordering is assumed; the repair groups in memory over the bulk list result.
- **Accessibility:** N/A — backend-only change with no user-facing UI surface.
- **Internationalization:** N/A — no new user-facing strings; log messages are
  diagnostic, not localized UI copy.

## Acceptance Criteria

- [ ] A lookup that finds duplicate contracts logs a warning containing the
  synchronizationId, originId, and all matching uuids, and returns the
  most-recently-updated contract
- [ ] `SynchronizationContractService` has a `LoggerInterface` injected
- [ ] The persist path updates an existing `(synchronizationId, originId)`
  contract instead of inserting a second
- [ ] The repair step collapses seeded duplicate groups to the newest contract,
  logs kept/removed uuids, is idempotent, and reads via the bulk list path so it
  tolerates the pre-retrofit era

## Notes

- Fix is pure OpenConnector; OpenRegister is not modified. Contracts are OR
  objects accessed via the object service (`findAll` / `saveObject` /
  `deleteObject`).
- Related ADR: ADR-005 (Source / Synchronization / SynchronizationContract data
  triad — one contract per Synchronization per object). ADR-003 (CallLog as
  primary observability surface) informs the warning-log expectation.
- The `sync-object-error-isolation` change is an independent symptom and is out
  of scope here.
