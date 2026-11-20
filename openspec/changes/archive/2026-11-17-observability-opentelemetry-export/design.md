# Design: observability-opentelemetry-export

Kind: code. The execution trace is already the record. The exporter translates
it into OTLP spans after it is persisted and sends it from a background job.
Trace context travels in and out as W3C `traceparent`.

## Where it fits

- Exporter: a new `lib/Observability/Otel/OtlpTraceExporter.php` behind an
  interface `TraceExporterInterface`, and `lib/Observability/Otel/SpanMapper.php`
  that turns an `execution_trace` object into an OTLP `resourceSpans` payload.
  The folder `lib/Observability/` already holds `IntegriqMetricsProvider.php`.
- Queue: `lib/Service/ExecutionTraceService.php:100` (`persist()`) adds the
  trace id to `IJobList` for a new `lib/BackgroundJob/OtelExportJob.php` when
  export is enabled and the trace is sampled. The job reads up to 100 queued
  traces, posts one batch through `IClientService`, and keeps a failed batch
  for three retries. ADR-069 background job conventions apply.
- Step precision: `lib/Service/Helper/ExecutionTraceContext.php:206`
  (`addStep()`) adds `startedAtUs` (microseconds since the epoch) next to the
  existing `startedAt` at `:235`, and the trace itself keeps a microsecond
  start. The `execution_trace` schema in
  `lib/Settings/register.d/execution-trace-observability.json:66` describes the
  new step key.
- Inbound context: `lib/Service/EndpointService.php:446`, where the endpoint
  trace is minted, reads a valid `traceparent` header and passes its trace id
  to the `ExecutionTraceContext` constructor (`traceId` parameter at
  `lib/Service/Helper/ExecutionTraceContext.php:159`) and records the parent
  span id on the trace as `parentSpanId`.
- Outbound context: `lib/Service/CallService.php:1186` (`dispatchRequest()`)
  sets `traceparent` on the request when a trace is active, with the span id
  of the call step.
- Settings: stored in `IAppConfig` under `otel_*` keys and shown on the admin
  page `lib/Settings/IntegriqAdmin.php`, the AppHost settings plane of ADR-076.
  Collector headers are a `credentialRef` (ADR-064), resolved at send time.

## D1. Translate the trace, do not instrument twice

`ExecutionTraceContext` already records every step with its type, name, status
and duration, at the four entry points. The exporter maps what is recorded:
root span `integriq.<entryPoint>` and one child per step named
`<type> <name>`. The alternative was to instrument the code again with an
OpenTelemetry tracer around each call. Rejected: it would be a second timeline
that can disagree with the one on the traces page, and every new step would
need two edits.

## D2. OTLP/HTTP JSON, no SDK

OTLP over HTTP with the JSON encoding is a documented protocol and a single
POST. The OpenTelemetry PHP SDK brings an exporter, a protobuf runtime and
several packages under the ADR-093 cooldown, for a job this exporter does with
`IClientService`. Rejected for now; `TraceExporterInterface` lets an SDK-based
exporter replace it without touching the mapper.

## D3. Export after persist, from a background job

A collector that is slow or down must not slow an endpoint response or a
synchronization. `persist()` only queues. The alternative was to send inline
with a short timeout. Rejected: a timeout on every request while a collector is
down is a latency incident caused by observability.

## D4. Spans carry no payloads

A step's `input` and `output` hold redacted records, but redacted is not empty:
names, addresses and case numbers remain. Spans carry only the step's type,
name, status, duration, the HTTP method, status code and the URL without its
query string, and the trace's entry point and ids. The alternative was to
attach the snapshots as span events. Rejected: it would copy personal data to a
system outside integriq's retention rules.

## D5. Trace ids are the same on both sides

An execution's `traceId` is a UUIDv4, 128 bits, which is the size of a W3C
trace id. The exporter writes it as 32 hex characters without dashes, so a
trace found in the collector has the same id as the one on integriq's traces
page. An inbound `traceparent` supplies that id instead of a fresh one.

## Declarative versus imperative

Export is imperative: it is an outbound HTTP call made by a background job.
The settings are declared through the AppHost settings plane. Nothing is added
to the manifest's `observability` block, which the AppHost engine reads for
metrics and health only.

## Risks

- Sampling at 100% on a busy instance produces a large queue. The default
  sampling ratio is 10%, and a failed or replayed trace is always sampled.
- A collector URL is administrator input to an outbound call; it is validated
  as `https` or a host an administrator marks as internal, and the call goes
  through the same client as a source call.
- A caller can send a crafted `traceparent`. Only the W3C format is accepted,
  and an invalid header is ignored and a fresh trace id is minted.
