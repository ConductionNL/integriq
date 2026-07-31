# Design: streaming-run-output

> **Revised 2026-07-30, before implementation.** These artifacts were written on
> 2026-07-07 and reviewed against the code nine days of development later. Most of
> the original analysis survived verification — the endpoints, routes, handler
> names and the "no SSE anywhere yet" premise all still hold. Two things did not:
> `execution-trace-observability` (#216, 2026-07-16) landed an entire per-step run
> timeline with a UI, which overlaps this change's progress events and is now
> reused rather than reinvented (Decision 7).
>
> **Corrected again 2026-07-31**, during implementation: Decision 8 originally
> asserted that streamed `test` had to thread `$persistLog`. Verification showed
> that flag belongs to `sources#test`, a different endpoint, and is unreachable from
> `synchronize()` — see Decision 8 for the corrected position.

## Context
`SynchronizationsController::run` (~line 348) and `::test` (~line 269) both call `$this->synchronizationService->synchronize(...)` synchronously and return a `JSONResponse`; the request blocks for the entire run, so long runs hit the reverse-proxy 504 gateway timeout. The four routes are all POST: `synchronizations#run` (`/api/synchronizations/{id}/run`), `synchronizations#test` (`/api/synchronizations/{id}/test`), `jobs#run` (`/api/jobs/run/{id}`), and `jobs#test` (`/api/jobs/test/{id}`). No StreamResponse/SSE exists anywhere yet, and there is no sync-run `occ` command (`lib/Command/` holds `MigrateToOpenRegister`, `MigrateInlineSecrets` and `AuthenticationConfig`, none of which run a synchronization).

The real problem is not just the timeout — it is that some runs die in a way the persisted run-log never records (uncaught exceptions, PHP fatals, OOM, script timeout). When the log is the thing failing you, only live observation helps. This change adds an opt-in streaming mode to those exact endpoints, plus the UI to trigger and watch it, plus an `occ` complement for terminal reproduction.

## Goals / Non-Goals

**Goals**
- Let operators watch a run live and see the failing output the instant before the socket dies.
- Surface exceptions and fatals that the run-log path can swallow.
- Keep the default `JSONResponse` behaviour byte-for-byte unchanged (backward compatible with cron, scheduler, and API consumers).
- One shared streaming harness reused by all four endpoints.

**Non-Goals**
- Changing how cron/scheduler invoke `synchronize()`.
- Separate debug-only endpoints (folding into the existing endpoints is deliberate).
- Loosening auth. Internal batching. Any change to source ordering.

## Decisions

### Decision 1: Fold streaming into the existing endpoints as an opt-in branch
Add a streaming branch guarded by a request flag rather than new endpoints. **Why:** the whole point is to reproduce the real failing path — same controller, same auth, same DI request scope, same memory limits. A separate debug endpoint would run under different conditions and might not reproduce the fatal. **Alternative considered:** dedicated `/debug/...` endpoints — rejected because they diverge from the production path and double the surface to maintain.

### Decision 2: Selection via request flag and/or Accept header, default stays JSONResponse
Streaming is selected by a `stream`/`follow` body parameter or `?stream=1`, and/or `Accept: text/event-stream`. Absent that, the endpoint returns the existing `JSONResponse`. **Why:** fully backward compatible; cron/jobs/API consumers are untouched. **Alternative considered:** always stream — rejected, breaks every existing consumer.

### Decision 3: Shared streaming helper/trait
A single helper (trait or injected service) holds the harness: set SSE headers, disable output buffering, `set_time_limit(0)`, emit/flush progress, `try/catch (\Throwable)` for exceptions, `register_shutdown_function` + `error_get_last()` for fatals, and a final result event. Both `SynchronizationsController` and `JobsController` (run + test) call it. **Why:** the tricky output-buffering/flush logic lives in exactly one place. **Alternative considered:** duplicating per controller — rejected, drift risk.

### Decision 4: Work around AppFramework dispatcher buffering (the crux)
The AppFramework dispatcher buffers and renders a `Response` after the controller returns, which fights real-time streaming. The streaming branch must emit-and-flush inside the controller and return a response that does not re-render the body — a callback/StreamResponse-style `Http\Response`, or emit-then-terminate. Headers `Content-Type: text/event-stream`, `Cache-Control: no-cache`, and crucially `X-Accel-Buffering: no` (to stop nginx/proxy buffering) are set, and PHP output buffering is flushed (`ob_end_flush()`/`flush()`). **Why:** without this the proxy and PHP both buffer and the operator sees nothing until the end — defeating the purpose. **Alternative considered:** returning a normal `JSONResponse` after collecting output — that is exactly the failing behaviour we are replacing.

### Decision 5: Fatal capture via shutdown function
A hard fatal (OOM/timeout/parse) kills the process before any `catch` runs. `register_shutdown_function` reads `error_get_last()` and streams a fatal event before the socket closes. **Why:** these silent deaths are usually the culprit and are precisely what the run-log misses. The `occ` command is the last-resort fallback when even the streamed socket dies mid-fatal (raw terminal, no timeout, xdebug-able).

### Decision 6: Frontend uses fetch + ReadableStream reader, not EventSource
The endpoints are POST and require the Nextcloud `requesttoken` CSRF header. `EventSource` is GET-only and cannot set headers, so the console consumes the stream with `fetch()` + `response.body.getReader()`, decoding chunks and rendering events line-by-line. The console is a modal in its own file under `src/modals/` (modal-isolation convention), wired to the existing `runSynchronizationHandler` / `testSynchronizationHandler` in `src/registry.js`. **Why:** POST + CSRF rules out EventSource. **Alternative considered:** switching the endpoints to GET for EventSource — rejected, would break the existing POST contract and CSRF posture.

### Decision 7: Streamed progress events ARE execution-trace steps
`execution-trace-observability` (#216) already defines the vocabulary for "what
happened during a run": `ExecutionTraceContext::addStep(type, name, timing, status,
input, output, …)` produces an ordered, timed, redaction-aware step list, and
`TraceTimelineWidget.vue` / `TraceDetailPage.vue` already render it.
`synchronize()` accepts a `?ExecutionTraceContext $trace` for exactly this.

So the streaming harness does NOT invent a second event vocabulary. Progress
events emit the trace-step shape, which means one vocabulary, one redaction pass
(execution-trace REQ-003), and a frontend console that can reuse the existing
timeline rendering instead of a parallel implementation. **Why:** a second event
format alongside `TraceTimelineWidget` is duplicated observability of the kind
ADR-022 pushes back on, and it would drift.

`addStep()` currently only appends to a private array — the trace is persisted at
the end of the run — so nothing can observe a step as it happens. This change adds
an OPTIONAL step listener to `ExecutionTraceContext` (a callable invoked at the end
of `addStep()`); when unset, behaviour is byte-for-byte unchanged for every existing
caller. The harness registers a listener that flushes each step as an SSE frame.

**What streaming still adds over the trace**, and why this change is not redundant:
a persisted trace cannot record its own death. An OOM, a script timeout or a parse
error kills the process before the trace is written, which is precisely the failure
class the original analysis identified ("when the log is the thing failing you,
only live observation helps"). Exception and fatal events are therefore
streaming-only (Decision 5), not trace steps.

**Alternative considered:** an independent event format for streaming — rejected;
it duplicates a vocabulary and a UI that already exist.

### Decision 8: `$persistLog` does NOT apply here — corrected
An earlier revision of this design claimed that streamed `test` should thread
`persistLog: false` "where the non-streaming `test` already does". **That was
wrong, and verified wrong before implementation.** `persistLog` is passed as false
in exactly one place: `SourcesController::test()`, the interactive *source
connection* test. `synchronizations#test` is a different endpoint that runs through
`SynchronizationService::synchronize()`, which does not mention `persistLog` at all
— nor does the service have any plumbing to reach `CallService::call()`'s flag. The
two endpoints were conflated on the basis of both being called "test".

So a streamed `test` behaves exactly as the non-streamed `test` does with respect
to logging: `isTest: true`, and call logs are written as usual.

Recorded rather than deleted so the same wrong inference is not made twice.
Suppressing call-log writes during a *synchronization* test would be a genuine
feature — plumbing a flag from `synchronize()` down through `callSourceObject()` to
`CallService::call()` — and is deliberately out of scope here; this change adds a
way to WATCH a run, not a way to change what it persists.

## Risks / Trade-offs
- [Dispatcher double-renders or proxy buffers, so nothing streams live] → Emit-and-flush in the controller, return a non-re-rendering response, set `X-Accel-Buffering: no`, explicitly flush PHP buffers; verify end-to-end against the real proxy.
- [A hard fatal kills the process before any handler] → shutdown-function fatal capture; `occ` command as terminal fallback.
- [Streaming path drifts from the JSON path over time] → both branches call the same `synchronize()`/job-run and the final event carries the identical payload; regression tests pin the default response.
- [Long-lived streamed connections tie up a PHP worker] → this is a manual, opt-in debugging action, not a production path; acceptable and bounded by operator use.

## Migration Plan
No database, schema, or OpenRegister-schema changes. Deployment is additive: the streaming branch is inert unless the opt-in flag is sent, and the frontend console and `occ` command are additive. Rollback is a straight revert with no data impact.

## Frontend conventions
- The live-output console is a standalone modal file (modal-isolation, ADR-004-class
  convention enforced by hydra gates) — no inline `<NcModal>`/`<NcDialog>` in a parent
  component. It goes in **`src/modals/v2/`**, not bare in `src/modals/`: that directory
  is organised into per-resource subdirectories and `v2/` is where new modals are being
  added. `src/modals/v2/TestSourceModal.vue` and `TestMappingModal.vue` are the closest
  precedents — both render the result of an interactive test — and should be followed for
  shell, prop and store conventions rather than reinvented.
- Rendering reuses `src/views/ExecutionTrace/TraceTimelineWidget.vue` where it fits,
  since progress events carry the trace-step shape (Decision 7).
- Any server-provided data uses the `IInitialState` + `loadState()` pattern, never DOM data-attributes.
- All new user-facing strings are localized in Dutch and English (hydra ADR-007). NL Design System / `@nextcloud/vue` components are used for the modal shell and controls; `NcSelect` (if any) carries an `inputLabel`.

## Open Questions
- ~~Exact wire format of the streamed events~~ — **settled by Decision 7**: SSE
  `event:`/`data:` frames whose `data` payload is the execution-trace step shape for
  progress, plus streaming-only `error` and `fatal` event types and a final `result`
  event carrying the same payload the default `JSONResponse` returns.
- Precise opt-in key name (`stream` vs `follow`) — provisional `stream` plus `?stream=1` and the Accept header; final name chosen in implementation for consistency with existing params.