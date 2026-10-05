# Tasks: observability-opentelemetry-export

Kind: code. Matrix row `integriq:obs-otel`.

### Task 1: Microsecond step times
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
- **files**: `lib/Service/Helper/ExecutionTraceContext.php`, `lib/Settings/register.d/execution-trace-observability.json`
- **acceptance_criteria**:
  - GIVEN two steps within one second WHEN the trace is persisted THEN their `startedAtUs` values order them correctly
  - GIVEN existing traces without `startedAtUs` WHEN they are read THEN the traces page still renders them
- [ ] Implement
- [ ] Test (PHPUnit on the context; existing trace page Playwright test)

### Task 2: Span mapper and OTLP exporter
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-spans-carry-no-message-content-req-otel-003
- **files**: `lib/Observability/Otel/TraceExporterInterface.php`, `lib/Observability/Otel/SpanMapper.php`, `lib/Observability/Otel/OtlpTraceExporter.php`
- **acceptance_criteria**:
  - GIVEN a trace with three steps WHEN mapped THEN one root and three child spans result with the trace id in hex
  - GIVEN a step input holding a BSN WHEN mapped THEN the payload contains no BSN
  - GIVEN a mapped batch WHEN exported THEN one POST to `/v1/traces` with `Content-Type: application/json` is made
- [ ] Implement
- [ ] Test (PHPUnit with a mocked IClientService and a stored OTLP payload fixture)

### Task 3: Queue on persist and the background job
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
- **files**: `lib/Service/ExecutionTraceService.php`, `lib/BackgroundJob/OtelExportJob.php`
- **acceptance_criteria**:
  - GIVEN export enabled WHEN `persist()` runs THEN no HTTP call is made and the trace id is queued
  - GIVEN a refused batch WHEN the job runs four times THEN it stops retrying and logs once
- [ ] Implement
- [ ] Test (PHPUnit on persist() and the job)

### Task 4: traceparent in and out
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
- **files**: `lib/Service/EndpointService.php`, `lib/Service/CallService.php`, `lib/Observability/Otel/TraceParent.php`
- **acceptance_criteria**:
  - GIVEN a valid inbound `traceparent` WHEN an endpoint is called THEN the trace id comes from the header
  - GIVEN an invalid header WHEN an endpoint is called THEN a fresh trace id is minted
  - GIVEN an active trace WHEN CallService dispatches THEN the request carries `traceparent`
- [ ] Implement
- [ ] Test (PHPUnit on TraceParent, EndpointService and CallService; Newman for the inbound header)

### Task 5: Admin settings
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
- **files**: `lib/Settings/IntegriqAdmin.php`, the admin settings section, `lib/Service/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN the admin page WHEN an administrator enables export with an endpoint THEN the settings persist and a sampled trace is queued
  - GIVEN a failed trace and a sampling ratio of 0 WHEN persisted THEN it is still queued
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/opentelemetry-export.spec.ts`; PHPUnit on the sampler)

## Verification

- `openspec validate observability-opentelemetry-export --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- A manual export to a local OpenTelemetry collector container, with the trace found by its id

## Amendment 2026-10-05: tasks for Woo row 13.29

Build these after tasks 1 to 5, in the same PR or a follow-up. Each test named
here fails on today's code, because none of these classes exists.

### Task 6: Error sink listener and queue
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
- **files**: `lib/Observability/Errors/ErrorSinkListener.php`, `lib/BackgroundJob/ErrorSinkExportJob.php`, `lib/AppInfo/Application.php` (register the listener on `OCP\Log\BeforeMessageLoggedEvent`)
- **acceptance_criteria**:
  - GIVEN the sink enabled and `openregister` listed WHEN a real `BeforeMessageLoggedEvent('openregister', 3, [...])` is dispatched THEN one event is queued and no HTTP call is made
  - GIVEN `dossiq` unlisted, or a level below the minimum, WHEN the event fires THEN nothing is queued
  - GIVEN the listener itself throws WHEN the event fires THEN it swallows the error without calling the logger
- [ ] Implement
- [ ] Test: PHPUnit `tests/Unit/Observability/Errors/ErrorSinkListenerTest.php` (`testAnOpenRegisterErrorIsQueuedForTheSink`, `testUnlistedAppsAndLowLevelsAreNotQueued`, `testTheListenerMakesNoHttpCall`). Construct the real `OCP\Log\BeforeMessageLoggedEvent`; its constructor is `(string $app, int $level, array $message)` since Nextcloud 28. Do not hand-roll a fake event.
- [ ] Wiring: a test that boots `Application::register()` with a registration context double and asserts the listener is registered for `BeforeMessageLoggedEvent::class`. A listener with a green suite and no registration is the defect this task exists to prevent.

### Task 7: Error sink exporters
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
- **files**: `lib/Observability/Errors/ErrorSinkExporterInterface.php`, `lib/Observability/Errors/SentryEnvelopeExporter.php`, `lib/Observability/Errors/OtlpLogExporter.php`
- **acceptance_criteria**:
  - GIVEN a DSN `https://key@sentry.example.org/42` WHEN an event is exported THEN one POST goes to `https://sentry.example.org/api/42/envelope/` with the Sentry auth header built from the key
  - GIVEN kind `otlp-logs` WHEN an event is exported THEN one POST goes to `{endpoint}/v1/logs` with an OTLP JSON `resourceLogs` body
  - GIVEN a message containing a BSN WHEN it is queued THEN `BodyRedactor` has already removed it
  - GIVEN a refused post WHEN the job runs four times THEN it stops retrying and drops the event without logging an error
- [ ] Implement
- [ ] Test: PHPUnit `tests/Unit/Observability/Errors/ErrorSinkExporterTest.php` (`testASentryEnvelopeIsPostedToTheDsnEndpoint`, `testAnOtlpLogRecordIsPostedToV1Logs`, `testTheRedactorRunsBeforeTheEventIsQueued`) with a mocked `IClientService` and stored payload fixtures

### Task 8: Span command for other apps
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
- **files**: `lib/Event/SpanExportRequestedEvent.php`, `lib/Listener/SpanExportRequestedListener.php`, `lib/AppInfo/Application.php`, `lib/Observability/Otel/SpanMapper.php` (accept foreign spans), `docs/features/opentelemetry.md` (the contract for sibling apps)
- **acceptance_criteria**:
  - GIVEN export enabled WHEN a valid span is dispatched THEN `getResult()` is `['queued' => 1, 'rejected' => 0, 'reasons' => []]` and the job exports it with `service.name` set to the dispatching app id
  - GIVEN a 30-character `traceId` WHEN dispatched THEN `rejected: 1` with a reason naming `traceId`
  - GIVEN an attribute `http.request.body` WHEN dispatched THEN that attribute is dropped and the span is queued
  - GIVEN export disabled WHEN dispatched THEN `queued: 0` with the reason `export-disabled`
- [ ] Implement
- [ ] Test: PHPUnit `tests/Unit/Listener/SpanExportRequestedListenerTest.php` dispatching the real event class through a real `IEventDispatcher` wired by `Application::register()`, so the test proves the caller reaches the listener
- [ ] Cross-app contract: the docs page states the constructor, every span key and every result key. Opening the openregister and opencatalogi dispatches is not part of this task; record in the PR body that each app needs its own change to send spans.

### Task 9: Admin settings for the error sink
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
- **files**: `lib/Service/SettingsService.php`, the admin settings section beside the collector settings of task 5, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the admin page WHEN an administrator enables the sink with kind, DSN and apps and saves THEN the values persist under `errorsink_*` keys in `IAppConfig`
  - GIVEN the sink enabled with no endpoint WHEN saved THEN the save is refused
  - GIVEN an `http` endpoint not marked internal WHEN saved THEN the save is refused
- [ ] Implement
- [ ] Test: PHPUnit `SettingsServiceTest::testAnEnabledErrorSinkNeedsAnEndpoint`; Playwright `tests/e2e/opentelemetry-export.spec.ts` extended with the error sink section

### Verification for tasks 6 to 9

The building agent follows `~/memcap-work/woo-build/LANE-RULES-BUILD.md`. In short:

- Work in your own clone, branched with `git checkout --no-track -b <branch> origin/development`. Set `TMPDIR` to a sibling directory outside the clone.
- Judge PHPUnit by its `Tests:` line or run it with `--no-coverage`: a green suite exits 1 when no coverage driver is installed.
- Run `run-hydra-gates.sh --base origin/development` and count the gates that ran. Without `--base` they read NOT APPLICABLE, which is not a pass.
- Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`.
- CI runs the gates on the full tree, and the coverage guard needs a test for every added statement.
- One PR with `--base development`. Merge `development` in, never rebase. No `Co-Authored-By` trailer on any commit.
- Done means merged on `development` with CI green. Row 13.29 counts as `production` only once a store release carries it.
