# Tasks: observability-opentelemetry-export-errors-and-app-spans

Kind: code. Woo row 13.29. Wave 1. The second part of
`observability-opentelemetry-export`; task numbers continue from it.

Build these after tasks 1 to 5 of `observability-opentelemetry-export` have merged on `development`. Each test named
here fails on today's code, because none of these classes exists.

### Task 6: Error sink listener and queue
- **spec_ref**: openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
- **files**: `lib/Observability/Errors/ErrorSinkListener.php`, `lib/BackgroundJob/ErrorSinkExportJob.php`, `lib/AppInfo/Application.php` (register the listener on `OCP\Log\BeforeMessageLoggedEvent`)
- **acceptance_criteria**:
  - GIVEN the sink enabled and `openregister` listed WHEN a real `BeforeMessageLoggedEvent('openregister', 3, [...])` is dispatched THEN one event is queued and no HTTP call is made
  - GIVEN `dossiq` unlisted, or a level below the minimum, WHEN the event fires THEN nothing is queued
  - GIVEN the listener itself throws WHEN the event fires THEN it swallows the error without calling the logger
- [ ] Implement
- [ ] Test: PHPUnit `tests/Unit/Observability/Errors/ErrorSinkListenerTest.php` (`testAnOpenRegisterErrorIsQueuedForTheSink`, `testUnlistedAppsAndLowLevelsAreNotQueued`, `testTheListenerMakesNoHttpCall`). Construct the real `OCP\Log\BeforeMessageLoggedEvent`; its constructor is `(string $app, int $level, array $message)` since Nextcloud 28. Do not hand-roll a fake event.
- [ ] Wiring: a test that boots `Application::register()` with a registration context double and asserts the listener is registered for `BeforeMessageLoggedEvent::class`. A listener with a green suite and no registration is the defect this task exists to prevent.

### Task 7: Error sink exporters
- **spec_ref**: openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
- **files**: `lib/Observability/Errors/ErrorSinkExporterInterface.php`, `lib/Observability/Errors/SentryEnvelopeExporter.php`, `lib/Observability/Errors/OtlpLogExporter.php`
- **acceptance_criteria**:
  - GIVEN a DSN `https://key@sentry.example.org/42` WHEN an event is exported THEN one POST goes to `https://sentry.example.org/api/42/envelope/` with the Sentry auth header built from the key
  - GIVEN kind `otlp-logs` WHEN an event is exported THEN one POST goes to `{endpoint}/v1/logs` with an OTLP JSON `resourceLogs` body
  - GIVEN a message containing a BSN WHEN it is queued THEN `BodyRedactor` has already removed it
  - GIVEN a refused post WHEN the job runs four times THEN it stops retrying and drops the event without logging an error
- [ ] Implement
- [ ] Test: PHPUnit `tests/Unit/Observability/Errors/ErrorSinkExporterTest.php` (`testASentryEnvelopeIsPostedToTheDsnEndpoint`, `testAnOtlpLogRecordIsPostedToV1Logs`, `testTheRedactorRunsBeforeTheEventIsQueued`) with a mocked `IClientService` and stored payload fixtures

### Task 8: Span command for other apps
- **spec_ref**: openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
- **files**: `lib/Event/SpanExportRequestedEvent.php`, `lib/Listener/SpanExportRequestedListener.php`, `lib/AppInfo/Application.php`, `lib/Observability/Otel/SpanMapper.php` (accept foreign spans), `docs/features/opentelemetry.md` (the contract for sibling apps)
- **acceptance_criteria**:
  - GIVEN export enabled WHEN a valid span is dispatched THEN `getResult()` is `['queued' => 1, 'rejected' => 0, 'reasons' => []]` and the job exports it with `service.name` set to the dispatching app id
  - GIVEN a 30-character `traceId` WHEN dispatched THEN `rejected: 1` with a reason naming `traceId`
  - GIVEN an attribute `http.request.body` WHEN dispatched THEN that attribute is dropped and the span is queued
  - GIVEN export disabled WHEN dispatched THEN `queued: 0` with the reason `export-disabled`
- [ ] Implement
- [ ] Test: PHPUnit `tests/Unit/Listener/SpanExportRequestedListenerTest.php` dispatching the real event class through a real `IEventDispatcher` wired by `Application::register()`, so the test proves the caller reaches the listener
- [ ] Initialise the result to `['queued' => 0, 'rejected' => 0, 'reasons' => ['no-listener']]` in the event constructor, so a dispatch nobody handles answers "not exported" instead of an empty array. Test: `SpanExportRequestedEventTest::testAnUnhandledEventAnswersNotExported`.
- [ ] Cross-app contract: the docs page states the constructor, every span key and every result key, and the `class_exists()` guard a sibling app needs when integriq is absent. Opening the openregister and opencatalogi dispatches is not part of this task; record in the PR body that each app needs its own change to send spans.

### Task 9: Admin settings for the error sink
- **spec_ref**: openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
- **files**: `lib/Service/SettingsService.php`, the admin settings section beside the collector settings of task 5, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the admin page WHEN an administrator enables the sink with kind, DSN and apps and saves THEN the values persist under `errorsink_*` keys in `IAppConfig`
  - GIVEN the sink enabled with no endpoint WHEN saved THEN the save is refused
  - GIVEN an `http` endpoint not marked internal WHEN saved THEN the save is refused
- [ ] Implement
- [ ] Test: PHPUnit `SettingsServiceTest::testAnEnabledErrorSinkNeedsAnEndpoint`; Playwright `tests/e2e/opentelemetry-export.spec.ts` extended with the error sink section

### Verification for tasks 6 to 9

The building agent follows `openspec/woo-build-rules.md`. In short:

- Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`. Set `TMPDIR` to a sibling directory outside the clone.
- Judge PHPUnit by its `Tests:` line or run it with `--no-coverage`: a green suite exits 1 when no coverage driver is installed.
- Run `run-hydra-gates.sh --base origin/development` and count the gates that ran. Without `--base` they read NOT APPLICABLE, which is not a pass.
- Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`.
- CI runs the gates on the full tree, and the coverage guard needs a test for every added statement.
- One PR with `--base development`. Merge `development` in, never rebase. No `Co-Authored-By` trailer on any commit.
- Done means merged on `development` with CI green. Row 13.29 counts as `production` only once a store release carries it.
