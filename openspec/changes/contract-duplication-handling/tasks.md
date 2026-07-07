# Tasks: contract-duplication-handling

## Implementation Tasks

### Task 1: De-duplication repair step (migration facet)
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-repair-step-reconciles-existing-duplicate-contracts`
- **files**: `lib/Repair/DeduplicateSynchronizationContracts.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN stored contracts contain duplicate `(synchronizationId, originId)` groups WHEN the repair step runs THEN each group is reduced to the most-recently-updated contract (fallback `created`) and the rest are removed via the OR object service
  - The step reads via the bulk list path (not the single-match lookups) so it tolerates the pre-retrofit `MultipleObjectsReturnedException` era, logs kept + removed uuids per group, and is idempotent on re-run
  - The step is registered in `appinfo/info.xml` `<repair-steps>` alongside the existing steps
- [ ] Implement
- [ ] Test

### Task 2: Log-and-pick-newest in SynchronizationContractService lookups
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-deterministic-logged-resolution-of-duplicate-contracts-on-lookup`
- **files**: `lib/Service/SynchronizationContractService.php`
- **acceptance_criteria**:
  - GIVEN `findBySyncAndOrigin` / `findByOriginId` find more than one match WHEN they resolve THEN a warning is logged with synchronizationId + originId + all matching uuids and the most-recently-updated contract is returned instead of `$matches[0]`
  - `LoggerInterface` is injected into the constructor; single/no-match behavior is unchanged
- [ ] Implement
- [ ] Test

### Task 3: Log-and-pick-newest in SynchronizationService lookups
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-deterministic-logged-resolution-of-duplicate-contracts-on-lookup`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN `findContractBySyncAndOrigin` / `findContractByOriginId` find more than one match WHEN they resolve THEN a warning is logged via `$this->logger` with synchronizationId + originId + all matching uuids and the most-recently-updated contract is returned
  - Ordering falls back to `created` when `updated` is absent; single/no-match behavior is unchanged
- [ ] Implement
- [ ] Test

### Task 4: Prevent duplicate inserts in the persist path
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-persist-path-prevents-duplicate-contracts-for-a-pair`
- **files**: `lib/Service/SynchronizationContractService.php`, `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a contract already exists for a `(synchronizationId, originId)` pair WHEN the persist path (reached via `synchronizeContract`'s create branch in `processSynchronizationObject`) is asked to create a contract for that pair THEN the existing contract is updated in place rather than a second being inserted
  - GIVEN no contract exists for the pair WHEN the persist path creates one THEN exactly one contract is created
- [ ] Implement
- [ ] Test

### Task 5: Unit tests for all three facets
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-deterministic-logged-resolution-of-duplicate-contracts-on-lookup`
- **files**: `tests/Unit/Service/SynchronizationContractServiceTest.php`, `tests/Unit/Service/SynchronizationServiceTest.php`, `tests/Unit/Repair/DeduplicateSynchronizationContractsTest.php`
- **acceptance_criteria**:
  - GIVEN duplicate contracts for a pair WHEN a lookup resolves THEN a warning is logged containing all matching uuids and the newest contract is returned
  - GIVEN an existing pair WHEN persist is invoked THEN it updates rather than inserts a second contract
  - GIVEN a seeded set of duplicates WHEN the repair step runs THEN it keeps the newest per group, removes the rest, and is a no-op on the second run
- [ ] Implement
- [ ] Test

## Verification
- All tasks checked off
- `openspec validate` passes
- Manual testing against acceptance criteria
- Code review against spec requirements

## Tests (company-wide ADR-009)
- PHPUnit unit tests for the lookup, persist, and repair logic (`tests/Unit/`)
- Newman/Postman tests: N/A — no new or changed API endpoint
- Browser tests (Playwright MCP): N/A — no UI change
- All tests pass (`composer test`)

## Documentation (company-wide ADR-010)
- Feature documentation in `docs/`: N/A — internal contract-integrity behavior, no user-facing feature surface
- Screenshot: N/A — no UI change

## i18n (company-wide hydra ADR-007)
- N/A — no new user-facing strings (log entries are diagnostic, not localized UI copy)
