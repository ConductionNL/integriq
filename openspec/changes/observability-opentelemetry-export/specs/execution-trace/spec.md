# execution-trace Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- observability-opentelemetry-export

## Purpose

Integriq sends its execution traces to an OpenTelemetry collector over
OTLP/HTTP, continues a caller's trace, and passes its own trace on to the
systems it calls. Matrix row `integriq:obs-otel`.

## ADDED Requirements

### Requirement: A persisted trace is exported as OpenTelemetry spans (REQ-OTEL-001)

When export is enabled and a trace is sampled, integriq MUST send the trace to
the configured collector at `{endpoint}/v1/traces` as OTLP JSON, with one root
span for the execution and one child span per retained step. The trace id MUST
equal the execution's `traceId` written as 32 hexadecimal characters. Each span
MUST carry its start time, end time and status. The root span MUST carry the
number of steps that were counted but not retained.

#### Scenario: a synchronization run appears in the collector
- GIVEN export enabled with a collector at `https://otel.example.org:4318` and sampling at 100%
- WHEN a synchronization run completes with a mapping step and two call steps
- THEN the collector receives one trace with a root span `integriq.sync` and three child spans, and its trace id equals the run's trace id without dashes
- @e2e exclude an outbound export to a collector; covered by PHPUnit on SpanMapper and OtlpTraceExporter against a recorded request

#### Scenario: a failed step is a failed span
- GIVEN a trace whose call step failed with status 503
- WHEN it is exported
- THEN that span has status error and carries `http.response.status_code` 503
- @e2e exclude a span attribute; covered by PHPUnit on SpanMapper

### Requirement: Export never delays the traced work (REQ-OTEL-002)

Integriq MUST NOT send spans on the request or run that produced the trace. It
MUST queue the trace and send it from a background job, and a collector that
is down MUST NOT change the response time or outcome of the traced work. A
failed batch MUST be retried at most three times and then dropped with one log
line.

#### Scenario: the collector is down
- GIVEN export enabled and a collector that refuses connections
- WHEN a consumer calls an endpoint
- THEN the endpoint answers as it would with export off, and the trace is queued for the background job
- @e2e exclude a timing guarantee; covered by PHPUnit asserting no HTTP call during persist()

### Requirement: Spans carry no message content (REQ-OTEL-003)

A span MUST NOT carry a step's `input` or `output`. Span attributes MUST be
limited to the step type and name, the status, the duration, the HTTP method,
the HTTP status code, the URL without its query string, the entry point and
the ids of the trace and the entry point object.

#### Scenario: a BSN never reaches the collector
- GIVEN a mapping step whose input contains a BSN
- WHEN the trace is exported
- THEN no attribute or event in the exported payload contains the BSN
- @e2e exclude a payload absence claim; covered by PHPUnit on SpanMapper

### Requirement: Trace context travels in and out as W3C traceparent (REQ-OTEL-004)

An endpoint request carrying a valid W3C `traceparent` MUST produce a trace
whose id is the header's trace id and whose root span's parent is the header's
span id. An invalid header MUST be ignored. Every outbound call made during a
trace MUST carry a `traceparent` naming the trace id and the span id of the
call step.

#### Scenario: a caller's trace continues into integriq
- GIVEN a consumer sending `traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01`
- WHEN it calls an integriq endpoint
- THEN the resulting execution trace has trace id `4bf92f35-77b3-4da6-a3ce-929d0e0e4736` and the exported root span has parent `00f067aa0ba902b7`
- @e2e exclude a header contract; covered by Newman in `tests/postman/`

#### Scenario: a partner sees integriq's trace
- GIVEN an endpoint that proxies to a source during a trace
- WHEN integriq calls the source
- THEN the request carries a `traceparent` with the trace's id and the call step's span id
- @e2e exclude an outbound header; covered by PHPUnit on CallService

### Requirement: Export is configured by an administrator (REQ-OTEL-005)

The admin page MUST let an administrator enable export and set the collector
endpoint, the service name, the sampling ratio and collector headers given as a
credential reference. A failed or replayed trace MUST always be sampled.

#### Scenario: an administrator switches export on
- GIVEN an administrator on the integriq admin page
- WHEN they enable export, enter a collector endpoint and a sampling ratio of 0.1, and save
- THEN the settings are stored and the next sampled trace is queued for export
- e2e: tests/e2e/opentelemetry-export.spec.ts

## ADDED Requirements (amendment 2026-10-05, Woo row 13.29)

### Requirement: Errors from the apps an administrator selects go to an error sink (REQ-OTEL-006)

When the error sink is enabled, integriq SHALL forward every log message whose
level is at or above the configured minimum (default `3`, error) and whose app
is in the configured list (default `integriq`, `openregister`, `opencatalogi`)
to the configured sink. The sink kind SHALL be `sentry` (a Sentry compatible
envelope posted to the endpoint derived from a DSN) or `otlp-logs` (OTLP JSON
posted to `{endpoint}/v1/logs`). Integriq SHALL read the messages from
Nextcloud's `OCP\Log\BeforeMessageLoggedEvent` (`getApp(): string`,
`getLevel(): int`, `getMessage(): array`), so no app needs code of its own.
Forwarding SHALL be queued and sent by a background job, as REQ-OTEL-002
requires for spans. A forwarded event SHALL carry only the app, the level,
the time, the exception class, the file, the line, the request id and the
message text after `BodyRedactor` has run. Integriq SHALL NOT log from inside
the listener, so a failing sink cannot raise an error about itself.

#### Scenario: an error in openregister reaches the organisation's Sentry
- GIVEN the error sink enabled with kind `sentry`, a DSN for `https://sentry.example.org`, and `openregister` in the app list
- WHEN openregister logs an exception at level error
- THEN one event is queued, and the background job posts it to the Sentry envelope endpoint with the exception class and the app `openregister`
- @e2e exclude an outbound post to an error service; covered by PHPUnit `ErrorSinkListenerTest::testAnOpenRegisterErrorIsQueuedForTheSink` and `ErrorSinkExporterTest::testASentryEnvelopeIsPostedToTheDsnEndpoint`

#### Scenario: a BSN in an error message never leaves
- GIVEN an error message that contains the BSN `999993653`
- WHEN it is forwarded
- THEN the posted payload does not contain `999993653`
- @e2e exclude a payload absence claim; covered by PHPUnit `ErrorSinkExporterTest::testTheRedactorRunsBeforeTheEventIsQueued`

#### Scenario: a message below the level or from an unlisted app stays home
- GIVEN the minimum level error and an app list without `dossiq`
- WHEN dossiq logs an error and openregister logs a warning
- THEN nothing is queued
- @e2e exclude a filter on a log event; covered by PHPUnit `ErrorSinkListenerTest::testUnlistedAppsAndLowLevelsAreNotQueued`

#### Scenario: a sink that is down changes nothing for the request
- GIVEN the error sink enabled and an endpoint that refuses connections
- WHEN openregister logs an error during a request
- THEN the request answers as it would with the sink off, no HTTP call is made during the request, and the job retries at most three times
- @e2e exclude a timing guarantee; covered by PHPUnit `ErrorSinkListenerTest::testTheListenerMakesNoHttpCall`

### Requirement: Another app hands integriq its spans through a typed command (REQ-OTEL-007)

Integriq SHALL offer `OCA\Integriq\Event\SpanExportRequestedEvent`, constructed
as `new SpanExportRequestedEvent(string $appId, array $spans)`. Each span SHALL
be an array with the keys `traceId` (32 lowercase hex), `spanId` (16 lowercase
hex), `parentSpanId` (16 hex or absent), `name` (string), `startTimeUnixNano`
(int), `endTimeUnixNano` (int), `status` (`ok` or `error`) and `attributes`
(a map of string keys to scalar values). The listener SHALL validate every
span, queue the valid ones for the same background job and sampler as
integriq's own traces, and answer through `getResult(): array` with the keys
`queued` (int), `rejected` (int) and `reasons` (list of strings). A span with
an attribute key outside the REQ-OTEL-003 allowlist SHALL have that attribute
dropped, not the span. When export is disabled the result SHALL be
`queued: 0` with the reason `export-disabled`. The event SHALL be handled
without a Nextcloud session.

#### Scenario: an opencatalogi request is traced in the same collector
- GIVEN export enabled, sampling at 100%, and a collector at `https://otel.example.org:4318`
- WHEN opencatalogi dispatches `SpanExportRequestedEvent` with one span `GET /api/publications` and trace id `4bf92f3577b34da6a3ce929d0e0e4736`
- THEN `getResult()` answers `queued: 1`, and the collector receives a span with that trace id and the service name `opencatalogi`
- @e2e exclude a backend command; covered by PHPUnit `SpanExportRequestedListenerTest::testAValidSpanIsQueuedAndAnsweredQueued` with the real event class

#### Scenario: a malformed span is refused with a reason
- GIVEN a span whose `traceId` is 30 characters
- WHEN it is dispatched
- THEN `getResult()` answers `queued: 0`, `rejected: 1`, and a reason naming `traceId`
- @e2e exclude a validation answer; covered by PHPUnit `SpanExportRequestedListenerTest::testAMalformedSpanIsRejectedWithItsField`

### Requirement: The error sink is configured on the admin page (REQ-OTEL-008)

The integriq admin page SHALL let an administrator enable the error sink and
set its kind, its endpoint or DSN, its headers as a credential reference
(ADR-064), the minimum level and the list of apps. The endpoint SHALL be
validated as `https`, or as a host the administrator marked internal, as
REQ-OTEL-005 does for the collector. Saving with the sink enabled and no
endpoint SHALL be refused.

#### Scenario: an administrator points errors at Sentry
- GIVEN an administrator on the integriq admin page
- WHEN they enable the error sink, choose `sentry`, paste a DSN, keep the default apps and save
- THEN the settings are stored, and the next openregister error is queued for that DSN
- e2e: tests/e2e/opentelemetry-export.spec.ts

#### Scenario: an enabled sink without an endpoint is refused
- GIVEN the error sink switched on with an empty endpoint
- WHEN the administrator saves
- THEN the save is refused with a message naming the endpoint field
- @e2e exclude a server-side validation; covered by PHPUnit `SettingsServiceTest::testAnEnabledErrorSinkNeedsAnEndpoint`
