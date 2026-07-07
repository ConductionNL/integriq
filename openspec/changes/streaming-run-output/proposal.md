---
kind: code
---

# Proposal: streaming-run-output

## Summary
Add an opt-in live-streaming mode to the synchronization and job run/test endpoints so long-running operations can be observed in real time instead of blocking on a single JSON response that dies at the reverse-proxy 504 timeout. The key goal is debugging: when a run dies in a way the persisted run-log never captures (uncaught exceptions, PHP fatals, OOM, script-timeout), streaming lets an operator watch the process live and see the failing output the instant before the socket closes. The default behaviour of every endpoint stays a plain `JSONResponse`, so cron jobs, the scheduler, and API consumers are completely unchanged.

## Motivation
Synchronizations normally run detached via cron jobs and produce run-logs; this change does not alter how production syncs run. It adds a debugging escape hatch for the edge cases where a run fails in a way the log cannot record. When the log is the very thing failing you, polling the log is useless — you must observe the process live. Sibling changes `sync-object-error-isolation` (#108) and `contract-duplication-handling` (#109) shrink this set of silent failures but do not eliminate it. Today `SynchronizationsController::run` and `::test` (and the equivalent Jobs endpoints) call the service synchronously and block the whole request, so a long run 504s and the operator learns nothing about why. Streaming turns that dead-end into a live console.

## Affected Projects
- [ ] Project: `openconnector` — add a shared streaming harness used by the synchronization and job run/test endpoints, a live-output frontend console wired to the existing Run/Test actions, and an `occ` CLI command for terminal-side reproduction.

## Scope

### In Scope
- A shared streaming helper/trait (SSE-style output) reused by `SynchronizationsController` (`run`, `test`) and `JobsController` (`run`, `test`).
- Opt-in selection: a request flag (`stream`/`follow` body param or `?stream=1`) and/or `Accept: text/event-stream`. Default remains `JSONResponse`.
- Live streaming of progress events, of caught `\Throwable` exceptions (message + trace), and of FATAL errors via `register_shutdown_function` + `error_get_last()`, with a final event carrying the same result payload the `JSONResponse` would have returned.
- A frontend live-output console (a modal under `src/modals/`) that consumes the POST stream via `fetch()` + `response.body.getReader()`, sending the Nextcloud `requesttoken` CSRF header, wired to the existing "Run now" / "Test (dry run)" actions.
- An `occ` command (`openconnector:synchronization:run <id> [--test] [--force]`) that runs the sync and prints output/errors to the terminal, registered in `appinfo/info.xml`.

### Out of Scope
- Changing how cron jobs or the scheduler invoke `synchronize()` — production detached runs are untouched.
- Adding separate debug-only endpoints — streaming folds into the existing endpoints to reproduce the real failing path (same controller, auth, DI request scope, memory limits).
- Loosening authentication or authorization on any endpoint.
- Internal batching or any change to source ordering.

## Approach
Fold streaming into the existing endpoints as an opt-in branch. When streaming is requested the controller sets SSE headers (`Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`), disables output buffering, calls `set_time_limit(0)`, runs the operation while flushing progress events immediately, wraps it in `try/catch (\Throwable)` to stream exceptions, registers a shutdown handler to stream fatals, and emits a final result event. Because the AppFramework dispatcher buffers and renders a `Response` after the controller returns (which fights real-time output), the streaming branch must emit-and-flush within the controller and return a response that does not double-render. Getting `X-Accel-Buffering` plus PHP output-buffering handling right is the crux and is detailed in design.md.

## New Dependencies
None. Uses PHP output buffering primitives, Nextcloud AppFramework response types, and the browser Streams API already available in the frontend build.

## Impact
- `lib/Controller/SynchronizationsController.php` (`run` ~line 348, `test` ~line 269) and `lib/Controller/JobsController.php` (`run`, `test`) gain an opt-in streaming branch.
- New shared streaming helper/trait under `lib/`.
- New `occ` command under `lib/Command/` plus a `<commands>` entry in `appinfo/info.xml`.
- Frontend: a new live-output modal under `src/modals/` and changes to `runSynchronizationHandler` / `testSynchronizationHandler` in `src/registry.js`.
- Reuses `result['errors'][]` from `sync-object-error-isolation` as part of the streamed output when present (soft reference — not a hard dependency).

## Cross-Project Dependencies
None. Self-contained within OpenConnector. It soft-references sibling change `sync-object-error-isolation` (#108) but does not depend on it being merged.

## Risks

### Risk 1: AppFramework dispatcher double-rendering / buffering fights real-time streaming
**Severity:** High — **Mitigation:** Emit and flush within the controller and return a response type that does not re-render a body (callback/StreamResponse-style `Http\Response`, or emit-then-terminate). Verify no proxy buffering with `X-Accel-Buffering: no` and explicit `ob_end_flush()`/`flush()`.

### Risk 2: A hard fatal (OOM/timeout) kills the process before any handler runs
**Severity:** Medium — **Mitigation:** `register_shutdown_function` + `error_get_last()` streams the fatal as a final event before the socket closes; the `occ` command is the fallback for reproduction when even the streamed socket dies mid-fatal.

### Risk 3: Streaming path diverges from the default JSON path and drifts over time
**Severity:** Low — **Mitigation:** Both branches call the same `synchronize()`/job-run and the final streamed event carries the identical result payload; regression tests assert the default `JSONResponse` behaviour is unchanged.

## Rollback Strategy
Revert the change. Because streaming is an additive opt-in branch guarded by a request flag, removing it restores the exact prior synchronous `JSONResponse` behaviour with no data or schema impact. The frontend console and `occ` command are additive and can be removed independently.