## 1. Mapper rewrite

- [ ] 1.1 In `lib/Db/SynchronizationContractMapper.php`, rename `findAllBySynchronizationAndSchema` to `findAllBySynchronization`. Drop the `$schemaId` parameter from the signature and update the docblock.
- [ ] 1.2 Replace the method body with a single-table query: select all columns from `openconnector_synchronization_contracts` filtered only by `synchronization_id`. Remove the alias `'c'`, the `INNER JOIN openregister_objects`, and the `o.schema = :schemaId` predicate.
- [ ] 1.3 Verify the method still returns an array of `SynchronizationContract` entities (or `[]` on exception) so the call site does not need changes beyond the rename.

## 2. Cleanup pass — scope-checked deletion

- [ ] 2.1 In `lib/Service/SynchronizationService.php::deleteInvalidObjects`, update the call site (currently around line 1155) from `findAllBySynchronizationAndSchema(syncId, schemaId)` to `findAllBySynchronization(syncId)`.
- [ ] 2.2 Inside the `case 'register/schema':` branch, after computing `$targetIdsToDelete`, before the `foreach ($targetIdsToDelete ...)` loop, resolve the `ObjectService` instance the same way the rest of `SynchronizationService` does (via the container).
- [ ] 2.3 In the `foreach`, before calling `$this->updateTarget(...)` for each candidate, call `$objectService->find($targetIdToDelete, register: $registerId, schema: $schemaId)`. If the result is `null`, skip this candidate (continue to the next). Verify whether `_rbac: false` is needed by inspecting how `updateTargetOpenRegister`'s existing `saveObject`/`deleteObject` calls succeed without a user session, and match that behavior.
- [ ] 2.4 Replace the swallowed `catch (DoesNotExistException $exception) { // @todo log }` block with a `LoggerInterface::warning(...)` call that includes synchronization id, target id, and exception message. Inject `LoggerInterface` into `SynchronizationService` if not already available; otherwise use the existing logger reference.

## 3. Tests

- [ ] 3.1 Add a unit test for `SynchronizationContractMapper::findAllBySynchronization` that asserts: contracts are returned filtered by `synchronization_id` only, with no dependency on `openregister_objects` rows.
- [ ] 3.2 Add an integration-style test for `SynchronizationService::deleteInvalidObjects` covering the in-scope deletion path: contract exists, target object exists in the synchronization's `register/schema`, target is missing from the synchronized list → `deleteObject` is called and contract `target_id` is nulled.
- [ ] 3.3 Add a test for the cross-scope skip path: contract exists, target object exists in a *different* `register/schema` → `deleteObject` is NOT called, contract is unchanged. Use a real second register/schema or a mocked `ObjectService::find` returning `null` for the foreign-scope call.
- [ ] 3.4 Add a test for the missing-target path: contract exists, no object with that UUID exists anywhere → `deleteObject` is NOT called, contract is unchanged.
- [ ] 3.5 Add a test that asserts the warning log is emitted (and the loop continues) when `findOnTarget` throws `DoesNotExistException` mid-cleanup.

## 4. Verification & follow-up audit

- [ ] 4.1 Run `composer check:strict` from `openconnector/`. Address any new PHPCS / PHPMD / Psalm / PHPStan findings introduced by this change. Fix pre-existing findings in the touched methods per project policy.
- [ ] 4.2 Manually run an extern-to-intern sync against a register/schema with a known orphan and confirm: (a) the orphan's OpenRegister object is deleted, (b) the contract's `target_id` is nulled, (c) `result.objects.deleted` increments, (d) `result.timing.stages.cleanup_invalid` is present in the sync log, (e) other syncs against unrelated register/schemas are unaffected.
- [ ] 4.3 `grep -rn "openregister_objects" lib/` in the openconnector repo. Document any remaining hits in a follow-up note (separate change, not this one) so the audit work is tracked. The expectation is zero hits after this change.

## 5. Documentation

- [ ] 5.1 If the openconnector developer docs (`docs/developers/`) include a description of the synchronization cleanup pass, update it to reflect the scoped-find approach. If no such doc exists, leave this task unchecked.
