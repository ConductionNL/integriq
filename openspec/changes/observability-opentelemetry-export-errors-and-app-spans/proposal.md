---
kind: code
depends_on: [observability-opentelemetry-export]
---

# Proposal: observability-opentelemetry-export-errors-and-app-spans

## Summary

Forward errors from the apps an administrator selects to a Sentry compatible or OTLP logs service, and let other apps hand integriq their spans through a typed command.

- Rows: closes Woo row 13.29 "Errors and traces go to a monitoring service the organisation chooses, configured without code" (not statutory), together with the first part.
- Wave: 1.
- Depends on: `integriq/observability-opentelemetry-export` (https://github.com/ConductionNL/integriq/issues/2537), the first part of this chain. Build after its tasks 1 to 5 have merged.
- Decision: no Ruben decision governs this row; D5 does not touch it.

Build rules: openspec/woo-build-rules.md

## Why

This is the second part of `observability-opentelemetry-export`. It was written
on 2026-10-05 as an amendment to that change and split off on 2026-10-06 so
each part stays within 20 tasks. Requirements REQ-OTEL-001 to REQ-OTEL-005 and
tasks 1 to 5 live in the first part. "Tasks 1 to 5" below means that first
part.

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

App absent: when integriq is not installed, nothing listens to
`SpanExportRequestedEvent` and the class does not load. A sibling app that
dispatches it SHALL guard with `class_exists()` and treat a missing class, or a
`getResult()` that is still empty after dispatch, as "not exported". It never
blocks or fails its own request on that. The error sink simply does not exist
without integriq, and errors stay with Nextcloud's own logging.

Fail closed: an error event carries the app, the level, the exception class,
the file and line, and the message after integriq's redactor has run. Request
bodies, step input and step output never leave. A sink that is down never
blocks or slows the request that logged the error.

Wave 1. No dependency on another planned change. Implements no Ruben decision
directly; D5 does not touch it.
