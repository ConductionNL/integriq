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
