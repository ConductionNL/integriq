# Design: streaming-run-output

## Context
`SynchronizationsController::run` (~line 348) and `::test` (~line 269) both call `$this->synchronizationService->synchronize(...)` synchronously and return a `JSONResponse`; the request blocks for the entire run, so long runs hit the reverse-proxy 504 gateway timeout. The four routes are all POST: `synchronizations#run` (`/api/synchronizations/{id}/run`), `synchronizations#test` (`/api/synchronizations/{id}/test`), `jobs#run` (`/api/jobs/run/{id}`), and `jobs#test` (`/api/jobs/test/{id}`). No StreamResponse/SSE exists anywhere yet, and there is no sync-run `occ` command (only `lib/Command/MigrateToOpenRegister.php` exists).

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

## Risks / Trade-offs
- [Dispatcher double-renders or proxy buffers, so nothing streams live] → Emit-and-flush in the controller, return a non-re-rendering response, set `X-Accel-Buffering: no`, explicitly flush PHP buffers; verify end-to-end against the real proxy.
- [A hard fatal kills the process before any handler] → shutdown-function fatal capture; `occ` command as terminal fallback.
- [Streaming path drifts from the JSON path over time] → both branches call the same `synchronize()`/job-run and the final event carries the identical payload; regression tests pin the default response.
- [Long-lived streamed connections tie up a PHP worker] → this is a manual, opt-in debugging action, not a production path; acceptable and bounded by operator use.

## Migration Plan
No database, schema, or OpenRegister-schema changes. Deployment is additive: the streaming branch is inert unless the opt-in flag is sent, and the frontend console and `occ` command are additive. Rollback is a straight revert with no data impact.

## Frontend conventions
- The live-output console is a standalone modal under `src/modals/` (modal-isolation, ADR-004-class convention enforced by hydra gates) — no inline `<NcModal>`/`<NcDialog>` in a parent component.
- Any server-provided data uses the `IInitialState` + `loadState()` pattern, never DOM data-attributes.
- All new user-facing strings are localized in Dutch and English (hydra ADR-007). NL Design System / `@nextcloud/vue` components are used for the modal shell and controls; `NcSelect` (if any) carries an `inputLabel`.

## Open Questions
- Exact wire format of the streamed events (raw SSE `event:`/`data:` frames vs newline-delimited JSON). Provisional: SSE-style frames, since headers already declare `text/event-stream`; the frontend reader tolerates either. To be settled during implementation.
- Precise opt-in key name (`stream` vs `follow`) — provisional `stream` plus `?stream=1` and the Accept header; final name chosen in implementation for consistency with existing params.