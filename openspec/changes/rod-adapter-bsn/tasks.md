# Tasks: rod-adapter-bsn

## Implementation Tasks

### Task 1: persoonsgebonden nummer choice element
- **spec_ref**: `openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-001-the-persoonsgebonden-nummer-goes-in-duos-choice-element`
- **files**: `lib/Service/Rod/RodEnvelopeTranslator.php`, `lib/Settings/register.d/learniq-exchange-jobs.json`
- [x] Implement
- [x] Test

### Task 2: AanleverenAdviesVO_Request and the schooladvies mapping row
- **spec_ref**: `openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-002-the-school-advice-is-sent-as-aanleverenadviesvo_request`
- **files**: `lib/Service/Rod/RodEnvelopeTranslator.php`, `lib/Service/Exchange/ExchangeJobService.php`, `lib/Settings/register.d/learniq-exchange-jobs.json`
- [x] Implement
- [x] Test

### Task 3: redaction on every log, exception and stored error
- **spec_ref**: `openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-003-the-persoonsgebonden-nummer-never-reaches-a-log-or-a-stored-error`
- **files**: `lib/Service/Rod/RodPersonalNumberRedactor.php`, `lib/Service/RodService.php`, `lib/Service/Rod/RodEdukoppelingClient.php`, `lib/Service/Exchange/ExchangeJobRunner.php`
- [x] Implement
- [x] Test

## Verification
- Diff-scoped checks while building, one `composer check:strict` before push.
