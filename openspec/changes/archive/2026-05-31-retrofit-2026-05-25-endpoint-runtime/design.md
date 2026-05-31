# Design — retrofit-2026-05-25-endpoint-runtime

> **Retrofit change.** Tasks describe retroactive annotation of existing code,
> not new implementation work. No code behaviour changes — only `@spec`
> annotations are added to the affected methods, and the spec delta is merged
> into `openspec/specs/endpoint-runtime/spec.md` on archive.

## Context

The `endpoint-runtime` cluster (Bucket 2b, 32 methods, no capability owner) is
the endpoint request entry point. It spans `EndpointsController.php` (generic
dispatch + simple fast path), `EndpointService.php` (full pipeline + target
dispatch + request/response normalisation), and `EndpointCacheService.php`
(endpoint resolution cache). Target dispatch follows ADR-008's polymorphic
`targetType` / `targetId` contract.

The cluster shares `EndpointService.php` with the `rule-pipeline` cluster.
Dispatch / caching / normalisation methods are annotated here; rule-evaluation
methods are annotated by the sibling `rule-pipeline` retrofit. `getRuleById` is
a generic lookup helper used by the dispatch path and is annotated here per the
coverage report's cluster assignment.

## Decisions

- **`--cluster`, not `--extend`.** No existing capability owns this behaviour
  (the only main spec is `prometheus-metrics`). A new `endpoint-runtime`
  capability is the correct home.
- **5 REQs fold 32 methods.** Grouped by observable behaviour: generic dispatch
  + CORS (001), simple fast path (002), full pipeline + target dispatch (003),
  endpoint cache (004), request/response normalisation (005). One REQ per
  coherent behaviour cluster rather than one per method.
- **Observed, not aspirational.** The exception-trace leak, the placeholder
  `logs()` stub, and the by-design regex duplication are documented in Notes,
  not changed.

## Risks / follow-ups

Surfaced in the spec Notes for human triage — NOT addressed by this
annotation-only change:
- `handleRequest` returns `$e->getTrace()` to clients (information disclosure,
  OWASP A05:2021).
- `getRuleById` silently drops unresolvable rule ids.
- `logs()` returns an empty result placeholder pending call-log wiring.
