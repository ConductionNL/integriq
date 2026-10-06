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
