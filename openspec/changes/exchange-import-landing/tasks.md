# Tasks: exchange-import-landing

## Implementation Tasks

### Task 1: the event
- **spec_ref**: `openspec/changes/exchange-import-landing/specs/exchange-jobs/spec.md#requirement-req-002-the-owning-apps-answer-ends-the-job`
- **files**: `lib/Event/ExchangeRecordsReceivedEvent.php`
- [x] Implement
- [x] Test

### Task 2: dispatch for the three import jobs and end the job by the answer
- **spec_ref**: `openspec/changes/exchange-import-landing/specs/exchange-jobs/spec.md#requirement-req-001-an-import-job-hands-its-records-to-the-owning-app`
- **files**: `lib/Service/Exchange/ExchangeTargetDispatcher.php`, `lib/Service/Exchange/ExchangeJobRunner.php`
- [x] Implement
- [x] Test

### Task 3: no-owner-answer
- **spec_ref**: `openspec/changes/exchange-import-landing/specs/exchange-jobs/spec.md#requirement-req-003-an-unanswered-import-ends-with-no-owner-answer`
- **files**: `lib/Service/Exchange/ExchangeTargetDispatcher.php`, `lib/Settings/register.d/learniq-exchange-jobs.json`
- [x] Implement
- [x] Test

## Verification
- Diff-scoped checks while building, one `composer check:strict` before push.
