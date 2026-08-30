# Design — retrofit-2026-05-25-rule-pipeline

> **Retrofit change.** Tasks describe retroactive annotation of existing code,
> not new implementation work. No code behaviour changes — only `@spec`
> annotations are added to the affected methods, and the spec delta is merged
> into `openspec/specs/rule-pipeline/spec.md` on archive.

## Context

The `rule-pipeline` cluster (Bucket 2b, 31 methods, no capability owner) is the
endpoint rule engine. It spans `EndpointService.php` (rule-evaluation methods)
and `RuleService.php` (custom software-catalogus + external-extension rules).
ADR-002 establishes that this engine is integriq-local with no OpenRegister
equivalent — it will not be generalised into OR — so it warrants its own
capability spec rather than an OR abstraction reference.

The cluster shares `EndpointService.php` with the `endpoint-runtime` cluster.
Rule-evaluation methods are annotated here; endpoint dispatch / caching methods
are annotated by the sibling `endpoint-runtime` retrofit.

## Decisions

- **`--cluster`, not `--extend`.** No existing capability owns this behaviour
  (the only main spec is `prometheus-metrics`). A new `rule-pipeline` capability
  is the correct home.
- **5 REQs fold 31 methods.** Grouped by observable behaviour: pipeline driver
  (001), data-mutation rules (002), auth/error rules (003), file/sync/download
  rules (004), custom catalogue rules (005). One REQ per coherent behaviour
  cluster rather than one per method.
- **Observed, not aspirational.** Stubs (`processJavaScriptRule`), silent
  failures (`processWriteFileRule` swallowed write errors), and demo-shaped
  constants (software-catalogus UUID pools) are documented in Notes, not fixed.

## Risks / follow-ups

These are surfaced in the spec Notes for human triage — they are NOT addressed
by this annotation-only change:
- Silent per-file write failure in `processWriteFileRule`.
- Authentication rule does not record the authenticated principal.
- `extendExternalUrl` write side-effect (auto-creating Source rows).
- Hard-coded test data in the software-catalogus rule.
