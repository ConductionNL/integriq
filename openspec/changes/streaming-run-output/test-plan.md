# Test Plan: streaming-run-output

## Test Cases

### TC-1: Default non-streaming run/test is unchanged
- **spec_ref**: `openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-opt-in-streaming-selection-preserves-default-behaviour`
- **type**: regression
- **persona**: Priya (developer / integrator)
- **preconditions**: A synchronization and a job exist; caller is authorized
- **steps**: POST to `synchronizations#run`, `synchronizations#test`, `jobs#run`, `jobs#test` with no streaming flag or streaming Accept header
- **expected result**: Each returns the same `JSONResponse` payload and headers as before this change; no SSE headers emitted
- **test command**: /test-regression

### TC-2: Streaming run emits live progress and a final result
- **spec_ref**: `openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-shared-streaming-harness-emits-progress-and-a-final-result`
- **type**: api
- **preconditions**: A long-running synchronization exists; caller is authorized
- **steps**: POST to `synchronizations#run` with `?stream=1` (or `Accept: text/event-stream`) and read the response body incrementally
- **expected result**: SSE headers set (`text/event-stream`, `no-cache`, `X-Accel-Buffering: no`); progress events arrive before any gateway timeout; a final event carries the same payload as the JSON path; no 504
- **test command**: /test-api

### TC-3: Exceptions and fatal errors are streamed
- **spec_ref**: `openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-streaming-surfaces-exceptions-and-fatal-errors`
- **type**: api
- **preconditions**: A synchronization configured to throw, and one that exhausts memory / times out
- **steps**: Run each in streaming mode and observe the stream to completion
- **expected result**: A `\Throwable` is streamed as an error event with message + trace; a PHP fatal is streamed as a fatal event via the shutdown handler before the socket closes; a populated `errors` array is included when present
- **test command**: /test-api

### TC-4: Streaming works on job run/test via the shared harness
- **spec_ref**: `openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-streaming-covers-synchronization-and-job-run-test-endpoints`
- **type**: api
- **preconditions**: A job exists; caller is authorized
- **steps**: POST to `jobs#run` and `jobs#test` with the streaming flag
- **expected result**: Both stream progress and a final result through the same shared harness used by the synchronization endpoints
- **test command**: /test-api

### TC-5: Auth posture is preserved in streaming mode
- **spec_ref**: `openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-streaming-preserves-the-existing-authentication-posture`
- **type**: security
- **persona**: Noor (municipal CISO / functional admin)
- **preconditions**: An unauthorized caller
- **steps**: POST to a run/test endpoint with the streaming flag as an unauthorized user
- **expected result**: The request is rejected by the same access-control check as the non-streaming request; no streamed output is produced
- **test command**: /test-security

### TC-6: Live-output console renders the stream
- **spec_ref**: `openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-frontend-live-output-console-consumes-the-stream`
- **type**: functional
- **persona**: Priya (developer / integrator)
- **preconditions**: Operator on the synchronization detail page; a long-running synchronization
- **steps**: Trigger "Run now" / "Test (dry run)" in streaming mode and watch the console
- **expected result**: Progress lines render incrementally, error/fatal events are highlighted, the final result summary shows, and the `requesttoken` header is sent on the POST
- **test command**: /test-functional

### TC-7: Live-output console accessibility
- **spec_ref**: `openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-frontend-live-output-console-consumes-the-stream`
- **type**: accessibility
- **preconditions**: The live-output console is open
- **steps**: Navigate the modal with a keyboard and screen reader while output streams
- **expected result**: WCAG 2.1 AA met; streamed output announced via a labelled live region; controls are reachable and labelled
- **test command**: /test-accessibility

### TC-8: occ command runs a synchronization
- **spec_ref**: `openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-occ-command-runs-a-synchronization-from-the-terminal`
- **type**: functional
- **persona**: Priya (developer / integrator)
- **preconditions**: A synchronization exists; shell access to `occ`
- **steps**: Run `occ openconnector:synchronization:run <id>`, then with `--test` and `--force`
- **expected result**: The synchronization runs; progress, result, and errors print to the terminal with no request timeout; `--test` dry-runs and `--force` forces execution
- **test command**: /test-functional

## Coverage Summary
- REQ-001 (opt-in selection / default unchanged): covered by TC-1, TC-2
- REQ-002 (shared harness, progress + final result): covered by TC-2
- REQ-003 (exceptions + fatals): covered by TC-3
- REQ-004 (job run/test streaming): covered by TC-4
- REQ-005 (auth posture): covered by TC-5
- REQ-006 (frontend console): covered by TC-6, TC-7
- REQ-007 (occ command): covered by TC-8

## Out of Scope
- Performance/load testing of many concurrent long-lived streamed connections — streaming is a manual, opt-in debugging action, not a production path.