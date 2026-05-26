# Tasks: openconnector-write-once-immutable-logging

## 1. Schema: executionId correlation field
- [x] 1.1 Add an `executionId` string property to `synchronization_log`,
  `synchronization_contract_log`, `job_log`, `call_log` in
  `lib/Settings/openconnector_register.json` (additive; keep `immutable:true` +
  `appendOnly:true`). Re-validate the JSON + register declaration.

## 2. Sync-log write-once
- [x] 2.1 In `synchronize()`: generate `$executionId`; build `$logData` with
  `executionId`; remove the early `saveObject` creates (internToExtern + externToIntern).
- [x] 2.2 Thread `string $executionId` into `synchronizeExternToIntern()` and
  `synchronizeInternToExtern()`; mutate in-memory log entity in place; no
  `saveObject` for sync-log inside those methods.
- [x] 2.3 In `synchronize()`: append the sync-log exactly once on the success path;
  in the exception path append one terminal log with the error message then rethrow.
- [x] 2.4 Verify: `POST /synchronizations/{id}/test` on a sync with no `sourceId`
  returns HTTP 400 (non-405) with "sourceId ... cannot be empty" message.

## 3. Contract-log write-once
- [x] 3.1 In `synchronizeContract()`: change the `?ObjectEntity $log` parameter to
  `?string $executionId`; replace `synchronizationLogId = $log->getUuid()` with
  `executionId = $executionId`.
- [x] 3.2 Replace the initial contract-log create + every update site
  (~1688/1698/1775/1820/1867/1927) with in-memory `$contractLogData[...]` mutations
  guarded by `$contractLogEnabled`.
- [x] 3.3 Add a private `appendContractLog(array $data): ?array` helper and call it
  once before each of the three returns; use its result for the `'log'` key.
- [ ] 3.4 Verify: a real source+sync run produces one `synchronization_log` and N
  `synchronization_contract_log` appends, all sharing one `executionId`.

## 4. Regression + gates
- [x] 4.1 Run the full Newman suite → 94/94 (the two `/test` 405s resolved).
- [x] 4.2 `composer check:strict` — available checks (routes/lint/no-legacy-types) pass; phpcs/phpmd/psalm/phpstan not vendor-installed in worktree (pre-existing environment gap).
- [ ] 4.3 `npm run check:specs` (manifest/register/json) still green.
