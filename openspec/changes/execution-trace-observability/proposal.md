# Proposal: execution-trace-observability

## Summary
Integriq today records call/job/sync activity as independent per-entity logs (`call_log`, `synchronization_log`, `event_message`) with no shared identifier tying one request's rule → mapping → synchronization → outbound-call path together, and no way to re-run a failed execution. This change mints an execution id at every entry point (endpoint call, job run, event delivery, manual sync), threads it through the existing pipeline, persists an ordered per-execution timeline as a new `execution_trace` OpenRegister object (redacted via the existing `SensitiveFieldRegistry`), adds a Traces UI (manifest v2 list+detail) and a Prometheus counter, and adds dry-run/force replay of a traced failure.

## Motivation
n8n shipped a per-step execution trace + replay debugging engine (June 2026); this is now a baseline expectation for integration-platform observability and a named competitive gap (Specter insight #1267, Codeberg issue #154). Operators debugging a failed sync today must correlate rows across three separate log schemas by timestamp and source/synchronization id, with no persisted step-by-step view and no one-click re-run. This change closes that gap without introducing distributed tracing (OpenTelemetry export is explicitly out of scope) or a new persistence layer — it is built entirely on existing Integriq/OpenRegister primitives (register.d fragments, FlowToken, SensitiveFieldRegistry, AppHost `tableCount` metrics, dead-letter replay dispatch).

## Affected Projects
- [ ] Project: `integriq` — mints/propagates an execution id through `EndpointService`/`FlowToken`, `RuleService`, `SynchronizationService`, and `CallService`; adds an `execution_trace` register.d fragment schema; adds `ExecutionTraceService` (Controller→Service→Mapper, ADR-008) for trace assembly, persistence, and replay; adds a Traces manifest v2 page; adds an AppHost `tableCount` Prometheus counter.

## Scope

### In Scope
1. An execution id (`traceId`, distinct from the pre-existing, unrelated `correlationId` used by the case-handoff intake engine) minted once per entry point — endpoint call (`EndpointService::handleRequest`), job run, event delivery (`EventService::attemptDelivery`), manual synchronization run — and propagated through rule pipeline → mapping → synchronization → outbound `CallService` calls, so every log/call produced within one logical execution can be joined by `traceId`.
2. A per-execution timeline of ordered steps (type, order, duration, status, input/output snapshot) persisted as one `execution_trace` OpenRegister object per execution. Snapshots reuse `FlowToken`'s existing 8-slot shape where the pipeline already captures request/response/sync-input/sync-output state, and MUST be redacted via the existing `SensitiveFieldRegistry` before persistence — no new redaction logic.
3. Trace persistence as a `register.d` fragment schema (`execution_trace`) with retention modeled on `call_log`'s `x-openregister-archival` pattern.
4. Failed-execution replay: re-run a traced entry point with the same input. Dry-run by default (produces a preview trace, no writes); an explicit `force` flag performs the real write. Reuses the two existing replay dispatch points (`EventService::attemptDelivery`'s action.kind dispatch, `SynchronizationService::replaySynchronizationItem`) rather than inventing a third redispatch mechanism — both are extended with a dry-run parameter that does not exist today.
5. A Traces UI (manifest v2 `"type": "logs"` list page over `execution_trace`, following the `call_log`/`SourceLogs` precedent, plus a detail timeline view; any `NcSelect` filter carries `inputLabel`) and a `traces_total` Prometheus counter added as an AppHost `tableCount` descriptor in `src/manifest.json`, alongside the existing 9 descriptors.
6. Unit tests for trace-id propagation and redaction-in-snapshot; one integration test proving a single endpoint call produces a trace spanning rule → mapping → call.

### Out of Scope
Distributed tracing across apps (OpenTelemetry export, W3C traceparent propagation to OpenRegister/other Conduction apps) — noted as a follow-up; this change is Integriq-internal correlation only.

## Approach
Mint the `traceId` at each of the four entry points and carry it as a lightweight `ExecutionTraceContext` value object passed alongside `FlowToken` (not added as a 9th `FlowToken` constructor parameter — `FlowToken` has two existing zero-arg-then-rehydrate call sites that a required id param would break; see design.md Decision 1). Each pipeline stage (rule, mapping step, synchronization item, outbound call) appends one ordered step to an in-memory trace buffer; `ExecutionTraceService` persists the assembled buffer as one `execution_trace` object at the end of the execution (success, short-circuit, or exception). Redaction is applied per-step at snapshot-build time by calling `SensitiveFieldRegistry::redactArray()` directly (not through `CallService`'s local reimplementation — see design.md Decision 3, which flags that asymmetry as pre-existing debt this change does not need to fix but must not copy). Replay re-invokes the existing dead-letter dispatch points with a new dry-run parameter, producing a new `execution_trace` linked to the original via a `replayOf` field rather than mutating the original trace.

## New Dependencies
None.

## Impact
- `lib/Service/EndpointService.php` — mint/propagate `traceId` at `handleRequest()`/`doHandleRequest()`, emit rule-step trace entries from `processRules()`.
- `lib/Service/Helper/FlowToken.php` — unchanged (no new constructor param; see design.md).
- `lib/Service/RuleService.php` — emit trace entries for custom rule dispatch.
- `lib/Service/SynchronizationService.php` — propagate `traceId` into `processSynchronizationObject()`/`replaySynchronizationItem()`; add dry-run parameter.
- `lib/Service/CallService.php` — accept/forward `traceId` into `buildAndPersistCallLog()`; no change to existing redaction logic (REQ-006 in `http-call-engine` is unaffected).
- `lib/Service/EventService.php` — propagate `traceId` into `attemptDelivery()`; add dry-run parameter to the replay path.
- `lib/Service/ExecutionTraceService.php` (new) — assembly, persistence, retrieval, replay orchestration.
- `lib/Controller/ExecutionTracesController.php` (new) — list/detail/replay HTTP surface.
- `lib/Settings/register.d/execution-trace-observability.json` (new) — `execution_trace` schema fragment.
- `src/manifest.json` — new `Traces`/`TraceDetail` pages, new `traces_total` observability descriptor.
- `src/views/ExecutionTrace/*.vue` (new) — list + detail Vue components.

## Cross-Project Dependencies
None — self-contained within `integriq`. No OpenRegister core change is required; the fragment mechanism (ADR-037) and `SensitiveFieldRegistry`/AppHost engine are consumed as-is.

## Risks

### Risk 1: Snapshot volume/PII exposure if redaction is skipped on a new code path
**Severity:** High — **Mitigation:** every snapshot-producing step MUST call `SensitiveFieldRegistry::redactArray()` before the step is appended to the trace buffer (never after persistence); the integration test in scope item 6 asserts no plaintext secret survives in a persisted `execution_trace`, mirroring `http-call-engine` REQ-006's existing test pattern.

### Risk 2: Replay-without-dry-run causing duplicate writes
**Severity:** Medium — **Mitigation:** dry-run is the explicit default at both the controller and service layer (force requires an explicit boolean, never inferred), and dry-run replays never call the underlying write path (`processSynchronizationObject`'s persistence branch, `deliverMessage`) — see design.md Decision 4.

### Risk 3: Trace-buffer memory growth on pipelines with many steps or large payloads
**Severity:** Low — **Mitigation:** snapshots follow the same size posture as existing `call_log`/`synchronization_log` bodies (no new truncation policy introduced or required beyond what those schemas already accept); flagged as a follow-up if pipelines with very large mapped result sets prove to be a problem in practice.

## Rollback Strategy
The change is additive: a new register.d fragment (removable by deleting the file — no destructive migration, per ADR-037's version-gated re-import), a new service/controller pair, and threading of an optional `traceId`/`ExecutionTraceContext` parameter through existing methods with safe defaults (`null` disables trace-step emission, preserving current behavior byte-for-byte). Reverting is: remove the fragment file, remove the new controller route registrations and manifest pages, and drop the (default-`null`) trace parameters from the touched method signatures. No existing schema, log shape, or call path is modified.

## Open Questions
- Should `execution_trace` supersede the currently-unused `sessionId`/`synchronization` correlation fields already present but unpopulated on `call_log` (see design.md), or leave them as separate, still-dead surface for a later cleanup change? Deferred to design.md Decision 5; recommend filing a follow-up issue rather than blocking this change on a `call_log` schema edit.
