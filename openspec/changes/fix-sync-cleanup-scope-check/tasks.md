## 1. Mapper rewrite

- [x] 1.1 In `lib/Db/SynchronizationContractMapper.php`, rename `findAllBySynchronizationAndSchema` to `findAllBySynchronization`. Drop the `$schemaId` parameter from the signature and update the docblock.
- [x] 1.2 Replace the method body with a single-table query: select all columns from `openconnector_synchronization_contracts` filtered only by `synchronization_id`. Remove the alias `'c'`, the `INNER JOIN openregister_objects`, and the `o.schema = :schemaId` predicate.
- [x] 1.3 Verify the method still returns an array of `SynchronizationContract` entities (or `[]` on exception) so the call site does not need changes beyond the rename.

## 2. Cleanup pass — scope-checked deletion

- [x] 2.1 In `lib/Service/SynchronizationService.php::deleteInvalidObjects`, update the call site (currently around line 1155) from `findAllBySynchronizationAndSchema(syncId, schemaId)` to `findAllBySynchronization(syncId)`.
- [x] 2.2 Inside the `case 'register/schema':` branch, after computing `$targetIdsToDelete`, before the `foreach ($targetIdsToDelete ...)` loop, resolve the `ObjectService` instance the same way the rest of `SynchronizationService` does (via the container).
- [x] 2.3 In the `foreach`, before calling `$this->updateTarget(...)` for each candidate, call `$objectService->find($targetIdToDelete, register: $registerId, schema: $schemaId)`. If the result is `null`, skip this candidate (continue to the next). Resolution: passed `_rbac: false, _multitenancy: false` to match the prior SQL JOIN's lack of authorization filtering.
- [x] 2.4 Replace the swallowed `catch (DoesNotExistException $exception) { // @todo log }` block with a `LoggerInterface::warning(...)` call that includes synchronization id, target id, and exception message. (`LoggerInterface` was already injected as `$this->logger`.)

## 3. Tests

- [x] 3.1 Add a unit test for `SynchronizationContractMapper::findAllBySynchronization` that asserts: contracts are returned filtered by `synchronization_id` only, with no dependency on `openregister_objects` rows. (`tests/Unit/Db/SynchronizationContractMapperTest.php`)
- [x] 3.2 Add an integration-style test for `SynchronizationService::deleteInvalidObjects` covering the in-scope deletion path: contract exists, target object exists in the synchronization's `register/schema`, target is missing from the synchronized list → `deleteObject` is called and contract `target_id` is nulled. (`testInScopeOrphanIsDeleted`)
- [x] 3.3 Add a test for the cross-scope skip path: contract exists, target object exists in a *different* `register/schema` → `deleteObject` is NOT called, contract is unchanged. (`testCrossScopeContractIsSkipped`)
- [x] 3.4 Add a test for the missing-target path: contract exists, no object with that UUID exists anywhere → `deleteObject` is NOT called, contract is unchanged. (`testMissingTargetIsSkipped`)
- [x] 3.5 Add a test that asserts the warning log is emitted (and the loop continues) when `findOnTarget` throws `DoesNotExistException` mid-cleanup. (`testWarningLoggedWhenContractLookupThrows`) — plus `testInScopeAndOutOfScopeMixedBatch` for combined-batch coverage.

## 4. Verification & follow-up audit

- [ ] 4.1 Run `composer check:strict` from `openconnector/`. **Status:** the openconnector repo has no `vendor/` installed in this environment (composer install not run), so the strict toolchain (PHPCS / PHPMD / Psalm / PHPStan / PHPUnit) cannot run here. Fallback: `php -l` clean for all four touched files (`SynchronizationContractMapper.php`, `SynchronizationService.php`, both new test files). Run `composer install && composer check:strict` locally before merging — CI will gate on PR.
- [ ] 4.2 Manually run an extern-to-intern sync against a register/schema with a known orphan and confirm: (a) the orphan's OpenRegister object is deleted, (b) the contract's `target_id` is nulled, (c) `result.objects.deleted` increments, (d) `result.timing.stages.cleanup_invalid` is present in the sync log, (e) other syncs against unrelated register/schemas are unaffected. **Status:** requires a live Nextcloud + OpenRegister environment with seed data — cannot be executed from the apply environment. Reserved for the user during their manual verification pass.
- [x] 4.3 `grep -rn "openregister_objects" lib/` in the openconnector repo. Result: zero real references; the only hit is a doc comment in `SynchronizationService.php:1184` explaining what the JOIN was replaced with. Expected outcome (zero hits) achieved.

## 5. Documentation

- [x] 5.1 Checked `docs/developers/` for cleanup-pass documentation: no matching content. `docs/synchronization-timing.md` mentions the cleanup stage at a timing level only (no implementation details), so no update needed there either. Task complete with no doc edits.
