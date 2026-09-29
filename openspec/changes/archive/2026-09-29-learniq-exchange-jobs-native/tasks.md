# Tasks: learniq-exchange-jobs-native

## Implementation tasks

### Task 1: Register fragment (job and dead letter tags, 28 mapping seed rows)
- **spec_ref**: `openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job`, `#requirement-req-007-the-vocabulary-ships-as-integriq-seed-rows`
- **files**: `lib/Settings/register.d/learniq-exchange-jobs.json`, `tests/Unit/Settings/LearniqExchangeJobsFragmentTest.php`
- [x] Fragment written and tested (targets enum, 23 slugs, five vocabulary rows, versions, ratchet unchanged)

### Task 2: Target catalogue and error code catalogue
- **spec_ref**: `.../spec.md#requirement-req-007-the-vocabulary-ships-as-integriq-seed-rows`
- **files**: `lib/Service/Exchange/ExchangeTargetCatalogue.php`, `lib/Service/Exchange/ExchangeErrorCodeCatalogue.php`
- [x] Implemented and tested

### Task 3: Typed events
- **spec_ref**: `.../spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed`
- **files**: `lib/Event/ExchangeJobRequestedEvent.php`, `lib/Event/ExchangeMappingRequestedEvent.php`, `lib/Event/ExchangeGateRequestedEvent.php`, `lib/Event/ExchangeJobConcludedEvent.php`
- [x] Implemented (contract.md shapes exactly)

### Task 4: Gate client
- **spec_ref**: `.../spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed`
- **files**: `lib/Service/Exchange/ExchangeGateClient.php`
- [x] Implemented and tested (six outcomes, never HTTP)

### Task 5: Job service and the two request listeners
- **spec_ref**: `.../spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job`, `#requirement-req-002-a-migrated-job-keeps-its-history`
- **files**: `lib/Service/Exchange/ExchangeJobService.php`, `lib/EventListener/ExchangeJobRequestedListener.php`, `lib/EventListener/ExchangeMappingRequestedListener.php`
- [x] Implemented and tested (refusals, history, idempotent legacy id, mapping upsert)

### Task 6: Target dispatcher
- **spec_ref**: `.../spec.md#requirement-req-005-export-handlers-hand-records-to-the-existing-adapters`
- **files**: `lib/Service/Exchange/ExchangeTargetDispatcher.php`
- [x] Implemented and tested (eight handled targets, kenmerk, parameter precedence)

### Task 7: Rejection service and dead letter replay branch
- **spec_ref**: `.../spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop`
- **files**: `lib/Service/Exchange/ExchangeRejectionService.php`, `lib/Service/SyncItemDeadLetterService.php`
- [x] Implemented and tested

### Task 8: Runner and job action
- **spec_ref**: `.../spec.md#requirement-req-004-the-jobs-mapping-transforms-each-allowed-record`, `#requirement-req-009-a-terminal-job-raises-a-concluded-event`
- **files**: `lib/Service/Exchange/ExchangeJobRunner.php`, `lib/Action/ExchangeJobAction.php`, `lib/Service/JobService.php`
- [x] Implemented and tested (order: handler, mapping, gate, map, dispatch, conclude)

### Task 9: Read model, controller, routes and actions
- **spec_ref**: `.../spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model`
- **files**: `lib/Service/Exchange/ExchangeReadModel.php`, `lib/Controller/ExchangeController.php`, `appinfo/routes.php`, `lib/actions.seed.json`
- [x] Implemented and tested

### Task 10: Wiring and translations
- **files**: `lib/AppInfo/Application.php`, `l10n/nl.json`, `l10n/en.json`
- [x] Listeners registered, every new user-facing string in both catalogues

## Verification

- Diff-scoped while building: `php -l`, `phpcs`, `phpstan`, `phpunit --filter` per touched class.
- Once before push: `composer check:strict`, `npm run lint`, hydra gates, each exit code in the PR body.
