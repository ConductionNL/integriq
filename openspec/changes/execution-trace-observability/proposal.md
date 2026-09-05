---
kind: spec-only
depends_on: []
---

# Proposal: execution-trace-observability (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. Execution
trace observability was implemented and archived on 2026-07-16
(`archive/2026-07-16-execution-trace-observability`, 27/42 tasks checked
with per-task evidence), yet this live copy was resurrected at 0/41: the
openconnector→integriq rename applied to the prose, the evidence notes
stripped, every box reset. The machinery exists at HEAD:
`lib/Service/ExecutionTraceService.php`,
`lib/Service/Helper/ExecutionTraceContext.php`,
`lib/Controller/ExecutionTracesController.php` with its routes, the
`execution_trace` schema in
`lib/Settings/register.d/execution-trace-observability.json`, trace
propagation through `CallService`/`EndpointService`/`EventService`/
`JobService`/`SynchronizationService`, and the UI (manifest pages `Traces`
/ `TraceDetail`, `src/views/ExecutionTrace/TraceDetailPage.vue`,
`TraceTimelineWidget.vue`).

`lib/Settings/register.d/execution-trace-observability.json` references
this directory's `design.md` (Decision 2), so that file stays exactly where
it is as a reference target. The other artifacts (test plan, spec deltas)
are removed; they survive verbatim in the archived twin and in git history.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| `execution_trace` schema, traceId minting + propagation across the rule → mapping → synchronization → call chain, traces controller + routes, timeline UI, Prometheus descriptor, Newman folder authored | **Already shipped and archived**: `archive/2026-07-16-execution-trace-observability` (27/42 boxes checked), code at HEAD |
| Residual verification with substance beyond a live-instance pass: the job-entryPoint replay has **no no-write test mode** (`executeJob()`'s `$forceRun` only bypasses the schedule gate — see the disclosed deviation in `ExecutionTraceService::replayJob()`'s docblock), the suspend→resume trace round trip is unwired-tested, the Traces UI has never been rendered, the trace metric has never been scraped, and the docs page is missing | Open, and honestly unticked in the archived twin with per-box reasons. This is the largest genuine residual of the twelve retirements; it deserves its own verification-pack-style successor (shaped like `approvals-verification-pack`) when the observability track is next picked up — author it then, not here |

## Sequencing

Nothing remains to implement from this change directly. The residual
verification above is real but not in flight; author a successor change
when the observability track resumes.

## Archival

This directory is retired in place (not moved or renamed): `design.md` is
referenced from the register fragment, and a rename would break that
pointer and detonate every diff-scoped gate. Archive it via the normal flow
only after that comment is repointed.
