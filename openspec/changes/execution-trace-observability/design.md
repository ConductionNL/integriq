# Design: execution-trace-observability

## Architecture Overview

Today, one logical execution (an inbound endpoint call, a cron job run, a CloudEvent delivery, or a manual synchronization run) produces zero or more independent OpenRegister log rows (`call_log`, `synchronization_log`, `event_message`) with no shared key. An operator debugging a failure joins these by timestamp + source/synchronization id, by hand.

This change introduces a lightweight, in-process **`ExecutionTraceContext`** value object minted once at each of the four entry points and threaded — as an additional optional parameter, alongside the existing `FlowToken` — through the rule pipeline (`EndpointService::processRules()`), synchronization item processing (`SynchronizationService::processSynchronizationObject()`), and outbound dispatch (`CallService::call()`). Each stage appends one ordered step (type, timing, duration, status, redacted input/output) to the context's in-memory buffer. `ExecutionTraceService` persists the assembled buffer as one `execution_trace` OpenRegister object (register.d fragment `execution-trace-observability.json`) when the execution completes — success, short-circuit, or exception — so the write happens exactly once per execution on the common path, with one documented exception for approval-suspend/resume (see Decision 1).

No new external dependency, no new NC database table, no OpenTelemetry export (out of scope — noted as follow-up in proposal.md).

```
Entry point (endpoint/job/event/sync)
  → mint ExecutionTraceContext{traceId, entryPoint, entryPointId, startedAt}
  → FlowToken + ExecutionTraceContext threaded together (not merged)
  → rule pipeline / mapping / synchronization / CallService append Step[] to context
  → ExecutionTraceService::persist(context) writes ONE execution_trace object
  → CallService::call() additionally stamps call_log.sessionId = traceId (REQ-011)
```

## Goals / Non-Goals

**Goals**
- Join rule → mapping → synchronization → outbound-call activity for one execution under one id, without touching existing log schemas' write paths (except the one already-dead `call_log.sessionId` field).
- Redact every persisted snapshot through the existing `SensitiveFieldRegistry`, with zero new redaction logic.
- Dry-run replay by default; explicit force for a real write, built on machinery that already exists (`SynchronizationService`'s `isTest` concept per `synchronization-engine` REQ-011, `JobService`'s test mode per `job-management` REQ-JOB-002) rather than inventing a third dry-run mechanism.

**Non-Goals**
- Cross-app / distributed tracing (OpenTelemetry, W3C `traceparent`) — proposal.md Out of Scope.
- Changing `FlowToken`'s public shape or its two existing zero-arg-then-rehydrate call sites (`ApprovalService::rehydrateFlowToken`, `SynchronizationService::replaySynchronizationItem`).
- Changing `CallService`'s existing redaction implementation (`http-call-engine` REQ-006) — the trace layer consumes its output, never re-implements it.
- Cleaning up the pre-existing, already-dead `call_log.synchronization`/`userId`/`actionId` fields beyond the one (`sessionId`) this change repurposes — flagged in proposal.md Open Questions as a separate follow-up.

## Decisions

### Decision 1: `traceId` propagation — companion context object, not a `FlowToken` constructor param

**Chosen:** a new, separate `ExecutionTraceContext` (plain data object, `lib/Service/Helper/ExecutionTraceContext.php`) carrying `traceId` (UUIDv4, minted via the same `Symfony\Component\Uid\Uuid` already imported in `EndpointService.php`), `entryPoint` (`endpoint|job|event|sync`), `entryPointId`, `startedAt`, an in-memory `Step[]` buffer, and optional `replayOf`/`dryRun` fields. It is passed as an additional method parameter alongside `FlowToken` wherever `FlowToken` already flows (`processRules()`, `dispatchAfterBeforeRules()`, `processSyncRule()`, `processSynchronizationObject()`, `CallService::call()`), always with a `?ExecutionTraceContext $trace = null` default so every touched signature stays backward compatible.

**Rejected alternative — add a 9th `FlowToken` constructor param (`?string $traceId`):** `FlowToken`'s constructor (`lib/Service/Helper/FlowToken.php:101-107`) is invoked two ways today: fresh construction from an `IRequest` (`EndpointService::doHandleRequest()` line 298), and **zero-arg construction followed by setter rehydration** (`ApprovalService::rehydrateFlowToken()` and `SynchronizationService::replaySynchronizationItem()`, both `new FlowToken()`). A required or defaulted id param on the constructor would either break those call sites' "identity" semantics (an id minted at zero-arg construction time, then silently discarded/overwritten by the rehydration flow) or add a param that's meaningless for 2 of the 3 construction paths. `FlowToken` is a pure data container for the 8 request/response/sync slots (confirmed: `__serialize()` at lines 524-537 emits exactly those 8 keys) — identity/correlation is a different concern and does not belong on it.

**Rejected alternative — thread `traceId` as a bare `?string`:** loses the in-memory step buffer, forcing every call site to pass the buffer back up manually. A context object is negligibly more code and keeps step-accumulation logic in one place (`ExecutionTraceContext::addStep()`).

### Decision 2: `execution_trace` schema — register.d fragment, mutable (not append-only)

**Chosen:** new fragment `lib/Settings/register.d/execution-trace-observability.json`, following the `hitl-approval-rule-action.json` template exactly (ADR-037 merge-at-repair-step mechanism, `InitializeRegister.php:140-172`). Schema `execution_trace`:

| Field | Type | Notes |
|---|---|---|
| `traceId` | string (uuid) | Client-minted (Decision 1), used as the OR object's own `id` at save time — no separate correlation column needed. |
| `entryPoint` | enum `endpoint\|job\|event\|sync` | |
| `entryPointId` | string | uuid of the endpoint/job/subscription/synchronization that started the execution. |
| `status` | enum `running\|success\|failed\|short_circuited` | default `running`; set on persist. |
| `startedAt` / `finishedAt` / `durationMs` | string / string / integer | |
| `steps` | array of `{order, type, name, timing, status, durationMs, startedAt, input, output}` | `input`/`output` are `SensitiveFieldRegistry::redactArray()`-passed snapshots (Decision 3); `type` ∈ `rule\|mapping\|synchronization\|call`. |
| `error` | nullable object `{message, ruleType, ruleName}` | mirrors the existing rule-pipeline HTTP 500 body shape (`rule-pipeline` REQ-RULE-001), so a trace's terminal error is the same shape operators already see in the response. |
| `replayOf` | nullable string (uuid) | set only on a trace created by replay. |
| `isReplay` / `dryRun` | boolean, default `false` | |
| `triggeredBy` | enum `http\|cron\|manual` | |

`x-openregister-archival.retention`: default `P30D`; rule `status == 'success' AND dryRun == false` → `P7D` (matches the debugging-window rationale, shorter than `call_log`'s error retention since a successful trace has lower forensic value once verified); rule `dryRun == true` → `P1D` (previews are throwaway). `appendOnly: false`, `immutable: false` — **deliberate deviation** from `call_log`/`synchronization_log`'s append-only-and-immutable pattern, because the approval-workflow suspend/resume flow (`EndpointService::resumeFromApproval()`, `ApprovalsController.php:305-311`) spans two separate HTTP requests against the *same* logical execution: the trace opened during the `before`-phase pipeline (suspended by an `approval` rule, `rule-pipeline` REQ-RULE-008) must be re-opened and appended-to when `resumeFromApproval()` continues the `after`-phase rules, rather than producing a second, disconnected trace for the same execution. This is the one case where `ExecutionTraceService::persist()` performs an update rather than a create; every other entry point does exactly one create.

**Rejected alternative — model on `call_log`'s append-only/immutable pattern, treat resume as a second trace linked by a new `continuesFrom` field:** rejected because it fragments a single logical execution's timeline across two rows for the one entry point (approval-gated endpoints) that most needs a unified view — the whole point of this change is one execution, one timeline.

### Decision 3: Snapshot redaction — call `SensitiveFieldRegistry::redactArray()` directly, never `CallService`'s local reimplementation

**Chosen:** every step's `input`/`output` snapshot is redacted via `SensitiveFieldRegistry::redactArray()` (`lib/Service/Security/SensitiveFieldRegistry.php:133`) at the point the step is appended to the buffer — for rule/mapping/sync steps, on the relevant `FlowToken` slot array (`getRequestAmended()`, `getSyncOutputAmended()`, etc.); for the outbound-call step specifically, the trace layer does **not** re-derive redaction — `CallService::buildResponseData()` (already fully redacted per `http-call-engine` REQ-006) hands its already-redacted `request`/`response` array to the active `ExecutionTraceContext` (when one is present) as part of `buildAndPersistCallLog()`, so the call step's snapshot is byte-for-byte the same data written to `call_log`, never a second independent redaction pass. This closes the asymmetry the research surfaced: `CallService` today calls its own private `isSecretKeyName()`/`redactSecretsFromConfig()` (lines 1150-1196) instead of `SensitiveFieldRegistry::redactArray()` — this change does not fix that pre-existing duplication (out of scope; it works correctly today, just redundantly with the registry), but it must not add a *third* redaction implementation on top. The call step reuses CallService's existing output rather than choosing either of the two existing paths.

**Rejected alternative — have the trace layer re-redact the call step from the unredacted live config:** would require `CallService` to expose pre-redaction internals to a new consumer, widening the surface that can leak a secret, for zero benefit over reusing output that is already correct and already tested (REQ-006's scenarios).

### Decision 4: Replay — new trace-owned orchestration, dry-run by reusing existing test-mode machinery, force by reusing existing dead-letter/job dispatch

**Chosen:** `ExecutionTraceService::replay(string $traceId, string $actorUid, bool $force = false): ObjectEntity` is new code, added to the new capability — it does **not** modify the existing dead-letter-replay endpoints or their contracts (`dead-letter-replay` REQ-DLR-003/REQ-DLR-009 keep their exact current behavior and default arguments). It branches on the stored trace's `entryPoint`:

- **`sync`**: calls `SynchronizationService::replaySynchronizationItem()`, extended with a new optional `bool $isTest = false` parameter (default preserves `dead-letter-replay` REQ-DLR-009's existing hardcoded-real-write behavior for its own direct callers). `ExecutionTraceService` always passes `isTest: !$force` explicitly. This reuses `synchronization-engine` REQ-011 ("Test runs make no writes") — the exact mechanism that already exists for manual test-runs — rather than inventing a second dry-run concept for synchronizations.
- **`job`**: dispatches through `JobService::executeJob()`'s existing test-mode parameter (`job-management` REQ-JOB-002) — `force=false` → test mode on, `force=true` → off.
- **`event`**: `force=true` delegates to the existing `EventService::attemptDelivery()`/`replayMessage()` dispatch (`dead-letter-replay` REQ-DLR-003, unchanged); `force=false` (dry-run) does **not** call `attemptDelivery()` at all — it resolves and returns the request that *would* be dispatched (rendered URL/method/headers via the same config-rendering `CallService` would use) without invoking the network call, so a webhook dry-run never risks a duplicate delivery to an external system that has no test-mode concept of its own.
- **`endpoint`**: re-invokes the rule pipeline against the trace's stored request snapshot with a new `bool $dryRun` parameter threaded into `EndpointService::processRules()` (rule-pipeline REQ-RULE-010, new). Rule types with an external side-effect (`save_object`, `override`, `locking`, `write_file`, `fileparts_create`/`filepart_upload`, `composite_fanout`) do not perform their write when `$dryRun === true`; the pipeline instead records a `status: 'skipped_dry_run'` step and continues evaluating downstream rules against the pre-rule data envelope (the best-effort available state — the pipeline cannot know a real write's resulting shape without performing it). Rules with no external side effect (`mapping`, `extend_input`, `authentication`, `error`, `synchronization` when the target sync itself is dispatched with `isTest: true` per the `sync` branch above) execute for real so the trace reflects genuine evaluation up to the write boundary. `synchronization` rules are a deliberate partial exception: they forward the same `isTest` flag rather than being blanket-skipped, since `SynchronizationService` already knows how to no-op writes safely.

Every replay call (dry-run or forced) creates a **new** `execution_trace` row with `replayOf` set to the original id and `isReplay: true` — it never mutates the original trace (the one deliberate mutation case is the approval-resume path in Decision 2, which is not a replay).

**Credentials note:** replay never reads outbound-call credentials from the stored (redacted) trace snapshot. Source-level auth is always re-resolved live by `CallService` from the Source object exactly as in the original execution — the trace only supplies the *business* input (endpoint request body / job trigger params / event CloudEvent payload / sync item payload), matching the existing dead-letter-replay pattern where `sync_item_dead_letter.payload` is the raw source object, never a credential.

**Rejected alternative — give `EndpointService` a full transactional dry-run (execute everything, roll back at the end, mirroring `CompositeFanoutRule`'s rollback-on-failure):** rejected as materially more invasive (every write-rule type would need a compensating delete, and some effects — e.g. `synchronization`'s own downstream calls, `write_file`'s file writes — are not cleanly transactional) for a debugging-preview feature where "the write didn't happen, here's what would have run" is sufficient value; full transactional dry-run is a candidate follow-up, not blocking this change.

### Decision 5: `call_log.sessionId` is repurposed for `traceId`, not left dead

**Chosen:** `CallService::buildAndPersistCallLog()` sets `call_log.sessionId = $trace->traceId` when an `ExecutionTraceContext` is active for the call (new `http-call-engine` REQ-011); when no trace context is present (the common case for calls outside a traced execution, e.g. ad-hoc `SourcesController::test()`), `sessionId` stays unset exactly as it does today. This is a **zero-schema-change** win — `call_log.sessionId` already exists in `openconnector_register.json` (line ~1844+) with the description "Session token for correlating multi-call traces" but has never been populated by any code path (research confirmed zero write sites). Populating it lets any existing `call_log` consumer (the `logs-and-statistics` REQ-003 per-source call log listing, the `SourcesController::logs()` filter surface) join on `sessionId = traceId` without a migration.

**Rejected alternative — add a new `traceId` column to `call_log` alongside the unused `sessionId`:** would leave two dead-then-half-dead correlation fields on the same schema. Reusing the field that already exists for exactly this purpose is strictly better; a separate follow-up can decide whether to rename `sessionId` → `traceId` for clarity (out of scope here — a rename is a `RENAMED Requirement` for a future change, not blocking).

## Risks / Trade-offs

- [Mutable `execution_trace` schema, unlike sibling log schemas] → Mitigation: the only writer that updates rather than creates is `resumeFromApproval()`'s continuation path; all other writes are single creates. `ExecutionTraceService::persist()` enforces this by requiring an explicit `resume: true` flag to take the update branch — a bug elsewhere cannot silently start mutating finalized traces.
- [Dry-run `endpoint` replay's "best-effort pre-rule envelope" for downstream rules after a skipped write] → Mitigation: each skipped step is explicitly labeled `skipped_dry_run` in the persisted trace so an operator never mistakes a dry-run's downstream steps for what would truly happen after a real write; documented in the UI (REQ-007 scenarios).
- [In-memory step buffer on a long-running pipeline (e.g. `processSyncRule`'s blocking `preDelay`/`postDelay` sleeps, `rule-pipeline` REQ-RULE-004 Notes) holds snapshots in PHP memory for the request's full duration] → Mitigation: same size posture as existing `call_log`/`synchronization_log` bodies already accepted (proposal.md Risk 3); no new truncation policy introduced.
- [`http-call-engine` REQ-006 redaction and this change's snapshot redaction must never diverge] → Mitigation: Decision 3 makes the call step reuse `CallService`'s already-redacted output verbatim rather than re-deriving it, so there is structurally one source of truth for that step; the integration test (proposal.md scope item 6) asserts this equivalence.

## Migration Plan

No Nextcloud DB migration — persistence is entirely via the OpenRegister register.d fragment mechanism (ADR-037), which is additive and version-gated (`InitializeRegister.php` re-imports automatically when the fragment's content hash changes, per the existing mechanism — no new migration class needed). See `migration.md` for the fragment-merge verification steps and rollback (delete the fragment file; no data to migrate back).

## Open Questions

Carried from proposal.md: whether to also populate/rename the other currently-dead `call_log` correlation fields (`synchronization`, `userId`, `actionId`) as part of a later cleanup change. Not blocking this change — `sessionId` is the only field this change touches (Decision 5).
