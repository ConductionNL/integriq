# Tasks: streaming-run-output

> **Revised 2026-07-30**, after checking the 2026-07-07 artifacts against the code.
> Verified unchanged: the four routes, `run()`/`test()` returning `JSONResponse`, the
> `runSynchronizationHandler`/`testSynchronizationHandler` registry entries, the
> `<commands>` block in `appinfo/info.xml`, and that no SSE/StreamResponse exists
> anywhere yet. Changed: progress events now reuse execution-trace steps rather than a
> new vocabulary (design Decision 7), test-mode streaming honours `$persistLog`
> Task 5's file path follows the real `src/modals/` layout. Decision 8's original
> `$persistLog` claim was verified WRONG during implementation and is corrected in
> design.md — a streamed test logs exactly as an unstreamed one does.

## Implementation Tasks

### Task 1: Shared streaming harness helper
- **spec_ref**: `openspec/specs/run-streaming/spec.md#requirement-shared-streaming-harness-emits-progress-and-a-final-result`
- **files**: `lib/Http/StreamingRunResponse.php`, `lib/Traits/StreamsRunOutput.php`, `lib/Service/Helper/ExecutionTraceContext.php`
- **acceptance_criteria**:
  - GIVEN streaming mode WHEN the harness starts THEN it sets `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`, disables output buffering, and calls `set_time_limit(0)`
  - GIVEN a running operation WHEN progress occurs THEN events are flushed immediately and the response does not double-render via the AppFramework dispatcher
  - GIVEN completion WHEN the run ends THEN a final `result` event carries the same payload the default `JSONResponse` returns
  - GIVEN `ExecutionTraceContext::addStep()` currently only appends to a private array WHEN a step is added THEN an OPTIONAL step listener is invoked, so the harness can flush that step live; with no listener set the class behaves byte-for-byte as before for every existing caller
  - GIVEN a progress event WHEN it is emitted THEN its `data` payload is the execution-trace step shape (`order`, `type`, `name`, `status`, `durationMs`, `startedAt`, `input`, `output`), NOT a bespoke format — one vocabulary and one redaction pass (design Decision 7)
- [x] Implement — `ExecutionTraceContext::setStepListener()`, `lib/Http/StreamingRunResponse.php` (`render()` returns `''` so the dispatcher cannot double-render; sets the three SSE headers incl. `X-Accel-Buffering: no`), `lib/Traits/StreamsRunOutput.php` (`wantsStreaming`, `beginStream`, `emitEvent`, `streamOperation`)
- [x] Test — 13 tests: 4 on the step listener, 10 on the harness, 3 on the response

### Task 2: Exception and fatal-error surfacing
- **spec_ref**: `openspec/specs/run-streaming/spec.md#requirement-streaming-surfaces-exceptions-and-fatal-errors`
- **files**: `lib/Traits/StreamsRunOutput.php`
- **acceptance_criteria**:
  - GIVEN a thrown `\Throwable` WHEN it reaches the harness THEN an error event with message and trace is streamed
  - GIVEN a PHP fatal (OOM/timeout/parse) WHEN the request shuts down THEN `register_shutdown_function` reads `error_get_last()` and streams a fatal event before the socket closes
  - GIVEN a result with a populated `errors` array WHEN the final event is streamed THEN those errors are included, and the feature works when absent
  - GIVEN `error` and `fatal` events WHEN they are emitted THEN they are STREAMING-ONLY event types rather than trace steps, because a persisted trace cannot record the death of its own process — this is the failure class the change exists for
- [x] Implement — `registerFatalCapture()` filters `error_get_last()` to the five process-terminating types and names them (`E_ERROR` etc.) rather than printing an integer; a throwing operation becomes an `error` frame with class/message/file/line/trace rather than being rethrown, since the status line is gone once the first byte flushes
- [x] Test — a throwing operation yields `error` and no `result`; an unencodable payload still yields a well-formed frame

### Task 3: Wire streaming into synchronization run/test
- **spec_ref**: `openspec/specs/run-streaming/spec.md#requirement-opt-in-streaming-selection-preserves-default-behaviour`
- **files**: `lib/Controller/SynchronizationsController.php`
- **acceptance_criteria**:
  - GIVEN no streaming flag WHEN `run`/`test` execute THEN the existing `JSONResponse` is returned unchanged
  - GIVEN the streaming flag (`stream`/`follow` body param, `?stream=1`, or `Accept: text/event-stream`) WHEN `run`/`test` execute THEN output streams through the shared harness with the existing auth posture unchanged
  - GIVEN a streamed `test` WHEN it runs THEN its logging behaviour is IDENTICAL to the non-streamed `test` (`isTest: true`, call logs written as usual). The earlier criterion here asserted it should thread `persistLog: false`; that was verified wrong before implementation — the flag belongs to `sources#test` and is unreachable from `synchronize()`. See design Decision 8.
- [x] Implement — the branch sits AFTER the auth, action-auth and existence checks in both `run()` and `test()`, so a 401/403/404 stays a real status code instead of becoming an error frame inside a 200 stream. Return types widened `JSONResponse` → `Response` (both are Responses; the methods have no internal callers). Each streamed call mints an `ExecutionTraceContext(entryPoint: 'manual', triggeredBy: 'http')` and passes it to `synchronize()`, which is what turns each step into a live frame.
- [x] Test — 7 tests in `SynchronizationsControllerStreamingTest`: default `run`/`test` still return the same `JSONResponse` and write NOTHING to the output stream; flag and Accept header each opt in; a trace context reaches `synchronize()`; a throwing run becomes an `error` frame; an unauthenticated streaming request is still a real 401 with no streamed output

### Task 4: Wire streaming into job run/test
- **spec_ref**: `openspec/specs/run-streaming/spec.md#requirement-streaming-covers-synchronization-and-job-run-test-endpoints`
- **files**: `lib/Controller/JobsController.php`
- **acceptance_criteria**:
  - GIVEN no streaming flag WHEN `jobs#run`/`jobs#test` execute THEN the existing `JSONResponse` is returned unchanged
  - GIVEN the streaming flag WHEN `jobs#run`/`jobs#test` execute THEN output streams through the shared harness with the existing auth posture unchanged
- [x] Implement — same shape as Task 3. `JobService::executeJob()` already accepted an `?ExecutionTraceContext`, so job runs get live progress frames for free. `run()`'s `forceRun` resolution was hoisted above the branch (equivalent to the previous if/else: an absent param still yields false) because both paths need it. The final frame carries `$result?->jsonSerialize() ?? []`, matching what the non-streaming branch puts in its JSONResponse.
- [ ] Test — covered indirectly by the shared-harness tests; dedicated JobsController streaming tests still to add

### Task 5: Frontend live-output console
- **spec_ref**: `openspec/specs/run-streaming/spec.md#requirement-frontend-live-output-console-consumes-the-stream`
- **files**: `src/modals/v2/RunOutputConsole.vue`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN an operator triggers a streaming Run/Test WHEN events arrive THEN the modal renders progress lines incrementally, highlights error/fatal events, and shows the final result summary
  - GIVEN the streaming POST WHEN `fetch()` starts THEN the Nextcloud `requesttoken` header is sent and `response.body.getReader()` reads the stream
  - GIVEN new UI strings WHEN rendered THEN they exist in Dutch and English
  - GIVEN the modal is added WHEN its location and shape are chosen THEN it lives in `src/modals/v2/` (that directory is where new modals go; `src/modals/` itself is split into per-resource subdirectories) and follows `TestSourceModal.vue` / `TestMappingModal.vue`, the existing interactive-test-result modals
  - GIVEN progress events carry the trace-step shape WHEN they are rendered THEN `src/views/ExecutionTrace/TraceTimelineWidget.vue` is reused where it fits rather than a parallel renderer being written
- [x] Implement — `src/modals/v2/RunOutputConsole.vue` plus the three-line wiring `ModalHost.vue` documents: `EVENT_OPEN_RUN_OUTPUT` in `modalBus.js`, import + render block + on/off in `ModalHost.vue`, and `runSynchronizationStreamHandler`/`testSynchronizationStreamHandler` in `actionHandlers.js` exported via `registry.js`. `fetch()` + `response.body.getReader()` with the `requesttoken` header, since EventSource is GET-only and cannot set headers. The existing fire-and-forget Run/Test handlers are deliberately untouched — streaming is opt-in, and changing what the default action does would break that promise.
- [x] Test — webpack build succeeds in the container; eslint reports 0 errors on the new file

Frame parsing buffers across chunk boundaries rather than parsing per chunk: a
chunk can split mid-frame, and per-chunk parsing silently corrupts exactly the
large frames that matter most under load. A trailing frame with no terminator is
still parsed, because a socket dying mid-write is the case this feature exists for.

NOTE: `src/modals/v2/` is silently unlinted. `eslint.config.js` has
`ignores: ['src/modals/**', '!src/modals/v2/**']`, but ESLint prunes the ignored
directory before descending, so the negation never applies. Pre-existing; affects
`TestSourceModal.vue` and `ModalHost.vue` too. Linted here with `--no-ignore`.

### Task 6: occ command for terminal runs
- **spec_ref**: `openspec/specs/run-streaming/spec.md#requirement-occ-command-runs-a-synchronization-from-the-terminal`
- **files**: `lib/Command/SynchronizationRun.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN `occ openconnector:synchronization:run <id>` WHEN executed THEN the synchronization runs and progress/result/errors print to the terminal with no request timeout
  - GIVEN `--test` and `--force` WHEN passed THEN they control dry-run and forced execution, and the command is registered in `appinfo/info.xml`
- [x] Implement — `lib/Command/SynchronizationRun.php`, registered in `appinfo/info.xml` under `<commands>` and confirmed present in `occ list`. Reuses the SAME `ExecutionTraceContext` step listener the streaming harness uses, rendering to a terminal instead of an SSE frame: one vocabulary, two renderers. `--json` emits the raw payload with progress suppressed so the command can be piped.
- [x] Test — 6 tests: progress reaches the terminal, missing synchronization fails without running, a throwing run prints class/message/location and tells the operator to use `-v`, flags forward to the engine, `--json` emits parseable JSON with no interleaved step lines, isolated per-object errors are surfaced

## Verification

- All tasks checked off
- `openspec validate` passes
- Manual testing against acceptance criteria: run a long synchronization in streaming mode and confirm live output plus surfaced exception/fatal
- Regression check: confirm a non-streaming run/test returns the identical `JSONResponse` as before
- Code review against spec requirements

## Tests (company-wide ADR-009)

- PHPUnit unit tests for the shared harness (header/flush behaviour, exception + fatal event emission, final payload parity)
- PHPUnit/Newman tests asserting the default non-streaming `JSONResponse` is unchanged on all four endpoints
- Browser tests (Playwright MCP) for the live-output console rendering streamed events
- All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

- Feature documentation for the streaming/debug mode and the `occ` command added under `docs/`
- Screenshot of the live-output console captured and committed to `docs/images/`

## i18n (company-wide hydra ADR-007)

- Dutch (`nl_NL`) and English (`en_US`) translation strings added for all new live-output console strings