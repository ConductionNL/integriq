# Tasks: observability-opentelemetry-export

Kind: code. Matrix row `integriq:obs-otel`.

### Task 1: Microsecond step times
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
- **files**: `lib/Service/Helper/ExecutionTraceContext.php`, `lib/Settings/register.d/execution-trace-observability.json`
- **acceptance_criteria**:
  - GIVEN two steps within one second WHEN the trace is persisted THEN their `startedAtUs` values order them correctly
  - GIVEN existing traces without `startedAtUs` WHEN they are read THEN the traces page still renders them
- [x] Implement. `ExecutionTraceContext::addStep()` writes `startedAtUs`; the context keeps `getStartedAtUs()`; `persist()` writes `startedAtUs`/`finishedAtUs`; the `execution_trace` schema 1.1.0 declares them (steps are free objects, so old traces without the key still render).
- [x] Test. `SpanMapperTest::testStepsWithinOneSecondOrderByMicroseconds`, `::testDroppedStepsAreARootAttributeAndOldTracesStillMap` (a trace without micro times maps from the whole seconds). The existing trace page Playwright test is unchanged and runs nightly.

### Task 2: Span mapper and OTLP exporter
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-spans-carry-no-message-content-req-otel-003
- **files**: `lib/Observability/Otel/TraceExporterInterface.php`, `lib/Observability/Otel/SpanMapper.php`, `lib/Observability/Otel/OtlpTraceExporter.php`
- **acceptance_criteria**:
  - GIVEN a trace with three steps WHEN mapped THEN one root and three child spans result with the trace id in hex
  - GIVEN a step input holding a BSN WHEN mapped THEN the payload contains no BSN
  - GIVEN a mapped batch WHEN exported THEN one POST to `/v1/traces` with `Content-Type: application/json` is made
- [x] Implement. `lib/Observability/Otel/TraceExporterInterface.php`, `SpanMapper.php`, `OtlpTraceExporter.php` (bound in `Application`).
- [x] Test. `SpanMapperTest` (root plus a child per step, hex trace id, failed 503 span, no BSN, name or header in the payload); `OtelExportTest::testAMappedTraceIsPostedOnceToV1Traces` (one POST, JSON, credential header) and `::testARefusingCollectorIsAFailedSend`.

### Task 3: Queue on persist and the background job
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
- **files**: `lib/Service/ExecutionTraceService.php`, `lib/BackgroundJob/OtelExportJob.php`
- **acceptance_criteria**:
  - GIVEN export enabled WHEN `persist()` runs THEN no HTTP call is made and the trace id is queued
  - GIVEN a refused batch WHEN the job runs four times THEN it stops retrying and logs once
- [x] Implement. `TraceExportQueue` (called from `ExecutionTraceService::persist()`, queues only) and `lib/BackgroundJob/OtelExportJob.php` (one trace per job, requeued with `attempt + 1`, dropped after three retries with one warning).
- [x] Test. `OtelExportTest::testPersistQueuesAndNeverSends` (no client created, running not queued, payload valid against the real schema), `::testARefusedBatchStopsAfterFourTriesAndLogsOnce`, `::testTheJobSendsNothingWhenExportIsOff`.

### Task 4: traceparent in and out
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
- **files**: `lib/Service/EndpointService.php`, `lib/Service/CallService.php`, `lib/Observability/Otel/TraceParent.php`
- **acceptance_criteria**:
  - GIVEN a valid inbound `traceparent` WHEN an endpoint is called THEN the trace id comes from the header
  - GIVEN an invalid header WHEN an endpoint is called THEN a fresh trace id is minted
  - GIVEN an active trace WHEN CallService dispatches THEN the request carries `traceparent`
- [x] Implement. `TraceParent`; `EndpointService::mintEndpointTrace()`; `CallService::withTraceParent()` on `call()` and `callAsync()`, the call step records the span id it sent.
- [x] Test. `TraceContextPropagationTest` (inbound valid and invalid, outbound header and span id, own header left alone), `SpanMapperTest::testTraceParentAcceptsOnlyTheW3cShape`.
- [ ] Newman for the inbound header (not run: needs a live instance; recipe in STATE "Still owed").

### Task 5: Admin settings
- **spec_ref**: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
- **files**: `lib/Settings/IntegriqAdmin.php`, the admin settings section, `lib/Service/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN the admin page WHEN an administrator enables export with an endpoint THEN the settings persist and a sampled trace is queued
  - GIVEN a failed trace and a sampling ratio of 0 WHEN persisted THEN it is still queued
- [x] Implement. `OtelSettings` (`otel_*` app settings), `OtelSettingsController` (`/api/admin/otel`, admin only), `src/views/admin/OtelSettings.vue` in the admin page, en and nl strings.
- [x] Test. PHPUnit `OtelExportTest::testAFailedTraceIsAlwaysSampled`, `::testAnAdministratorSwitchesExportOn`.
- [ ] Playwright `tests/e2e/opentelemetry-export.spec.ts` (written; not run: Playwright runs in the nightly job, not locally).

## Verification

- `openspec validate observability-opentelemetry-export --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- A manual export to a local OpenTelemetry collector container, with the trace found by its id
