---
kind: code
depends_on: []
---

# Proposal: observability-opentelemetry-export

## Summary

Integriq keeps a trace of every execution, with its steps and their timing,
but the trace only lives in integriq's own register. An operations team that
runs Jaeger, Tempo, Grafana or any OpenTelemetry collector cannot see an
integriq run next to the systems it called. This change exports each execution
trace as OpenTelemetry spans over OTLP/HTTP to a collector an administrator
configures, reads an incoming `traceparent` so a caller's trace continues into
integriq, and sends `traceparent` on outbound calls so the partner's spans
join it.

## Why

Matrix row `integriq:obs-otel`, "Send traces to an OpenTelemetry collector."
The matrix rates integriq `no` with `built.state` `none`: "No OpenTelemetry
exporter, collector client, or trace-context propagation exists anywhere in
the app."

There is no demand row. Five competitors rate `yes`:

- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/mule-runtime/latest/otel-support.md, "OpenTelemetry
  enables Mule runtime engine to provide observability" with an OTLP span
  exporter, and https://docs.mulesoft.com/monitoring/telemetry-exporter.md,
  "Export Mule app trace data and audit logs to third-party observability
  platforms like Azure Monitor, Splunk HEC, and OpenTelemetry-compliant tools".
- n8n (`n8n`), source read at n8n@2.40.7:
  "packages/cli/src/modules/otel/otel.constants.ts:7 N8N_OTEL_ENABLED with
  exporter protocol, endpoint and headers". No evidence URL is recorded.
- Tyk (`tyk`), source read at v5.15.0: "config/config.go:1307 opentelemetry
  section with exporter, endpoint and sampling". No evidence URL is recorded.
- Apache APISIX (`apisix`), source read at 3.18.0:
  "apisix/plugins/opentelemetry.lua:100 collector address, sends OTLP over
  HTTP". No evidence URL is recorded.
- WSO2 API Manager (`wso2`), source read at v4.7.0:
  "carbon-apimgt/components/apimgt/org.wso2.carbon.apimgt.tracing/src/main/java/org/wso2/carbon/apimgt/tracing/telemetry/OTLPTelemetry.java:41
  exports over OTLP". No evidence URL is recorded.

No ADR assigns trace export to another app: a search of integriq's and the
organisation's ADRs for OpenTelemetry, OTLP and tracing finds none. This change
covers one row: `integriq:obs-otel`.

## What integriq already has

- A trace per execution, minted at four entry points:
  `lib/Service/EndpointService.php:446`, `lib/Service/JobService.php:451`,
  `lib/Service/EventService.php:964` and
  `lib/Service/SynchronizationService.php:3180`.
- An ordered step list with type, name, status and duration:
  `lib/Service/Helper/ExecutionTraceContext.php:206` (`addStep()`). A step's
  `startedAt` is kept to the whole second (`:235`), and steps past 500 are
  counted, not kept (`:134`).
- One `execution_trace` object per execution, written by
  `lib/Service/ExecutionTraceService.php:100` (`persist()`).
- Snapshots redacted before they are buffered (`execution-trace` REQ-003).
- Prometheus metrics through the AppHost observability engine (the
  `observability` block of `src/manifest.json`, ADR-040). No traces leave the
  instance.
- Outbound calls go through `lib/Service/CallService.php:1186`
  (`dispatchRequest()`), which sends at `:1231` without a trace header.

## What this change builds

1. An OTLP/HTTP exporter that turns a persisted trace into one root span and a
   child span per step, and posts it to `{endpoint}/v1/traces` in the OTLP JSON
   encoding.
2. Export off the request path: `persist()` queues the trace id, and a
   background job sends batches, so a slow collector never slows an endpoint.
3. Admin settings: enabled, collector endpoint, headers as a credential
   reference, service name and a sampling ratio.
4. Microsecond step start times, so child spans order and nest correctly.
5. Trace context: an inbound W3C `traceparent` on an endpoint becomes the
   trace's parent, and outbound calls carry a `traceparent` for the step that
   made them.
6. Spans carry names, timing, status and an allowlisted set of attributes,
   never a step's input or output.

## Out of scope

- Metrics and logs over OTLP. Metrics stay on the Prometheus endpoint of the
  AppHost engine.
- The OpenTelemetry PHP SDK as a dependency.
- Export from other apps. If a second app wants it, the exporter moves to the
  AppHost observability engine (ADR-040), and this change keeps it behind one
  interface so that move is a relocation.

## Amendment 2026-10-05: errors, and traces from other apps (Woo row 13.29)

Woo capability row 13.29, "Errors and traces go to a monitoring service the
organisation chooses, configured without code". Our column reads `partial`:
errors go where Nextcloud core logging points (file, syslog, errorlog,
systemd), openregister serves Prometheus metrics, and "zero hits for Sentry,
OpenTelemetry, OTLP or traceparent in the four apps, so no traces and no
error-tracking service". The gap register names the missing half: "Error
tracking to an operator chosen service (Sentry compatible or OTLP logs),
configured in admin, and trace export beyond integriq so openregister and
opencatalogi requests are traced too." Build plan: amend this change, wave 1,
size S.

What this amendment adds, on top of tasks 1 to 5 (none of which is built):

1. An error sink. Integriq listens to Nextcloud's
   `OCP\Log\BeforeMessageLoggedEvent` and forwards every message at or above
   a chosen level, from the apps an administrator selects, to a Sentry
   compatible endpoint or to `{endpoint}/v1/logs` as OTLP JSON. It is
   configured on the integriq admin page. No app needs code for its errors to
   arrive.
2. A span command for other apps. `OCA\Integriq\Event\SpanExportRequestedEvent`
   lets openregister, opencatalogi or any app hand integriq finished spans,
   which go through the same queue, sampler and exporter as integriq's own.
3. A reading of the out-of-scope line above: export from other apps is now in
   scope, through that command. The exporter stays in integriq. ADR-040 still
   allows moving it into the AppHost engine later, behind the same interface.

What it does not do: it does not instrument openregister or opencatalogi. Each
of them dispatches the command from its own request path in a change of its
own. Until they do, 13.29 is met for errors from every app and for traces from
integriq and from any app that dispatches the command.

Fail closed: an error event carries the app, the level, the exception class,
the file and line, and the message after integriq's redactor has run. Request
bodies, step input and step output never leave. A sink that is down never
blocks or slows the request that logged the error.

Wave 1. No dependency on another planned change. Implements no Ruben decision
directly; D5 does not touch it.
