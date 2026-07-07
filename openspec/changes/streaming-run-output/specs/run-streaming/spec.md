# run-streaming Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- streaming-run-output

## Purpose
Provides an opt-in live-streaming mode for the synchronization and job run/test endpoints so long-running operations can be observed in real time rather than blocking on a single response that dies at a reverse-proxy timeout. Its primary purpose is debugging: surfacing uncaught exceptions and PHP fatal errors (OOM, script timeout, parse) that the persisted run-log never captures, by streaming them live before the socket closes. Streaming is always opt-in; the default response behaviour is unchanged.

## ADDED Requirements

### Requirement: Opt-in streaming selection preserves default behaviour
The system MUST default the synchronization and job run/test endpoints to their existing synchronous `JSONResponse` behaviour, and MUST switch to streaming output only when the caller explicitly opts in via a request flag (a `stream`/`follow` body parameter or a `?stream=1` query parameter) and/or an `Accept: text/event-stream` request header.

#### Scenario: Default request is unchanged
- GIVEN a caller POSTs to a run or test endpoint without any streaming flag or streaming Accept header
- WHEN the endpoint executes
- THEN the endpoint runs the operation synchronously and returns the same `JSONResponse` payload as before this change
- AND no streaming headers are emitted

#### Scenario: Streaming flag opts in
- GIVEN a caller POSTs to a run or test endpoint with the streaming flag set (body param or `?stream=1`) or with `Accept: text/event-stream`
- WHEN the endpoint executes
- THEN the endpoint responds in streaming mode instead of returning a buffered `JSONResponse`

### Requirement: Shared streaming harness emits progress and a final result
The system MUST provide a single shared streaming helper reused by both the synchronization and job run/test endpoints that, in streaming mode, sets SSE headers (`Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`), disables PHP output buffering, calls `set_time_limit(0)`, flushes progress events immediately as the operation runs, and emits a final event carrying the same result payload the default `JSONResponse` would have returned.

#### Scenario: Progress is flushed live
- GIVEN a streaming run of a long-running synchronization
- WHEN the operation processes stages or objects
- THEN progress events are written and flushed to the client immediately so bytes reach the proxy before any gateway timeout
- AND the connection does not 504

#### Scenario: Final event mirrors the JSON payload
- GIVEN a streaming run that completes normally
- WHEN the operation finishes
- THEN a final event is emitted containing the identical result payload the non-streaming `JSONResponse` would have returned

#### Scenario: Response does not double-render
- GIVEN the AppFramework dispatcher renders a response after the controller returns
- WHEN the endpoint is in streaming mode
- THEN the controller emits and flushes within the streaming branch and returns a response that does not re-render the body, so streamed output is not buffered or duplicated

### Requirement: Streaming surfaces exceptions and fatal errors
The system MUST wrap the streamed operation in a `try/catch (\Throwable)` and stream the exception message and stack trace as an error event, and MUST register a shutdown handler that inspects `error_get_last()` and streams any FATAL error (OOM, script timeout, parse) as a fatal event before the socket closes.

#### Scenario: Uncaught exception is streamed
- GIVEN a streaming run whose operation throws a `\Throwable`
- WHEN the exception propagates to the streaming harness
- THEN an error event containing the exception message and trace is streamed to the client

#### Scenario: Fatal error is streamed before socket close
- GIVEN a streaming run that hits a PHP fatal error such as memory exhaustion or a script timeout
- WHEN the request shuts down
- THEN the shutdown handler reads `error_get_last()` and streams a fatal event describing the error before the connection closes

#### Scenario: Isolated per-object errors are included when present
- GIVEN a run result that contains a populated `errors` array (as produced by sync-object-error-isolation)
- WHEN the final event is streamed
- THEN those errors are included in the streamed output
- AND the feature functions normally when the `errors` array is absent

### Requirement: Streaming covers synchronization and job run/test endpoints
The system MUST support the opt-in streaming mode on the synchronization run and test endpoints and on the job run and test endpoints, using the same shared harness for all four.

#### Scenario: Synchronization run and test stream
- GIVEN a caller opts into streaming on `synchronizations#run` or `synchronizations#test`
- WHEN the endpoint executes
- THEN the synchronization runs with live streamed output through the shared harness

#### Scenario: Job run and test stream
- GIVEN a caller opts into streaming on `jobs#run` or `jobs#test`
- WHEN the endpoint executes
- THEN the job runs with live streamed output through the shared harness

### Requirement: Streaming preserves the existing authentication posture
The system MUST keep the same authentication and authorization posture on the run/test endpoints as before this change and MUST NOT loosen access control when streaming is requested.

#### Scenario: Unauthorized caller is rejected in streaming mode
- GIVEN a caller who is not authorized for the run/test endpoint
- WHEN they request the endpoint with the streaming flag
- THEN the request is rejected by the same access-control check that applies to the non-streaming request
- AND no streamed output is produced

### Requirement: Frontend live-output console consumes the stream
The system MUST provide a frontend live-output console, implemented as a modal in its own file under `src/modals/`, that is wired to the existing "Run now" and "Test (dry run)" actions and consumes the streaming POST response via `fetch()` and a `ReadableStream` reader (`response.body.getReader()`), sending the Nextcloud `requesttoken` CSRF header, rendering progress lines incrementally and highlighting error and fatal events, and ending with the final result summary.

#### Scenario: Operator watches a run live
- GIVEN an operator triggers a streaming "Run now" or "Test (dry run)" on the synchronization detail page
- WHEN the endpoint streams events
- THEN the live-output console renders progress lines as they arrive, highlights any error or fatal event, and shows the final result summary when the run completes

#### Scenario: CSRF token is sent on the streaming POST
- GIVEN the frontend opens the streaming POST request
- WHEN it initiates the `fetch()`
- THEN it includes the Nextcloud `requesttoken` header so the POST is accepted

#### Scenario: User-facing strings are localized
- GIVEN the live-output console renders labels and status text
- WHEN it is displayed
- THEN all new user-facing strings are available in Dutch (`nl_NL`) and English (`en_US`) per hydra ADR-007

### Requirement: occ command runs a synchronization from the terminal
The system MUST provide an `occ` command (for example `openconnector:synchronization:run <id> [--test] [--force]`), registered in `appinfo/info.xml` under `<commands>`, that runs `synchronize()` and prints its output and errors to the terminal without a request timeout so raw fatals can be reproduced and debugged.

#### Scenario: Command runs a synchronization
- GIVEN an operator runs `occ openconnector:synchronization:run <id>`
- WHEN the command executes
- THEN the synchronization runs and its progress, result, and any errors are printed to the terminal
- AND the `--test` and `--force` options control dry-run and forced execution respectively

## Non-Functional Requirements

- **Performance:** Streaming MUST flush the first bytes well before the reverse-proxy gateway timeout so a streaming run never returns a 504; the default non-streaming path MUST retain its existing performance characteristics.
- **Accessibility:** The live-output console MUST meet WCAG 2.1 AA, including a labelled, screen-reader-announced live region for streamed output.
- **Internationalization:** Dutch and English MUST be supported for all new user-facing strings (hydra ADR-007).

## Acceptance Criteria

- [ ] A non-streaming run/test request returns the identical `JSONResponse` as before this change.
- [ ] A streaming run/test request emits live progress, streams exceptions and fatals, and ends with a final result event carrying the same payload.
- [ ] Streaming works on all four endpoints (synchronization run/test, job run/test) via one shared harness.
- [ ] The live-output console renders streamed output, sends the `requesttoken` header, and is wired to the Run/Test actions.
- [ ] The `occ` command runs a synchronization and prints output/errors to the terminal.

## Notes

- Streaming folds into the existing endpoints deliberately to reproduce the real failing path (same controller, auth, DI request scope, memory limits) rather than adding separate debug endpoints.
- Soft reference: reuses `result['errors'][]` from sync-object-error-isolation (#108) when present but does not depend on it being merged.
- The AppFramework dispatcher buffering/double-render caveat is the implementation crux; see design.md.