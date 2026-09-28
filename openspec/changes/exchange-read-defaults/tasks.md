# Tasks: exchange-read-defaults

## Implementation Tasks

### Task 1: new seed value
- **spec_ref**: `openspec/specs/action-authorization/spec.md#requirement-req-001-exchangeread-defaults-to-admin-coordinators-and-compliance-officers`
- **files**: `lib/actions.seed.json`, `docs/administrators/exchange-jobs.md`
- [x] Implement

### Task 2: upgrade repair step
- **spec_ref**: `openspec/specs/action-authorization/spec.md#requirement-req-002-an-upgrade-broadens-only-the-untouched-default`
- **files**: `lib/Repair/BroadenExchangeReadDefault.php`, `appinfo/info.xml`
- [x] Implement

### Task 3: tests for every branch
- **files**: `tests/Unit/Repair/BroadenExchangeReadDefaultTest.php`
- [x] Test

## Verification
- Diff-scoped checks while building, one `composer check:strict` before push.
