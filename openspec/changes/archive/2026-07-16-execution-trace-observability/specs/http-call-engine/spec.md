# http-call-engine Specification (delta)

**OpenSpec changes**:
- `execution-trace-observability` _(in progress)_ — populates the
  pre-existing, previously-unused `call_log.sessionId` field with the
  active execution's `traceId` and emits the same already-redacted
  request/response data to the trace layer, without changing REQ-001's
  dispatch contract or REQ-006's redaction logic.

## ADDED Requirements

### Requirement: Trace-scoped call correlation via call_log.sessionId (REQ-011)

`CallService::buildAndPersistCallLog()` MUST set the persisted
`call_log.sessionId` field to the active `ExecutionTraceContext`'s
`traceId` (per `execution-trace` REQ-001) when a trace context is present
for the call, and MUST leave `sessionId` unset — exactly as it is today,
byte-for-byte — when no trace context is present. `CallService::call()`
MUST hand the already-redacted `request`/`response` array produced by
`buildResponseData()` (per REQ-006) to the active `ExecutionTraceContext`,
when present, as the `call` step's snapshot, WITHOUT running a second,
independent redaction pass over the same data — the trace layer MUST NOT
re-derive redaction from the pre-redaction config.

@e2e exclude backend dispatch plumbing — covered by PHPUnit

#### Scenario: sessionId is populated for a call inside a traced execution

- **GIVEN** a `CallService::call()` dispatch made from within a traced
  endpoint execution (an active `ExecutionTraceContext` with `traceId =
  'abc-123'`)
- **WHEN** the call completes and the `call_log` is persisted
- **THEN** `call_log.sessionId` equals `'abc-123'`

#### Scenario: sessionId stays unset for an untraced call

- **GIVEN** a `CallService::call()` dispatch with no active
  `ExecutionTraceContext` (e.g. `SourcesController::test()`)
- **WHEN** the call completes and the `call_log` is persisted
- **THEN** `call_log.sessionId` is absent, unchanged from pre-existing
  behaviour

#### Scenario: the trace's call step reuses the persisted call_log's redacted data

- **GIVEN** a traced call to a source configured with a `client_secret`
  form parameter
- **WHEN** the call completes
- **THEN** the `execution_trace`'s `call` step `output` is the same
  redacted `request`/`response` array persisted to `call_log` (per REQ-006)
  — no second redaction implementation runs, and no plaintext secret exists
  in either location

#### Notes

- This requirement changes only the previously-always-absent `sessionId`
  field's value when a trace context exists; it does not alter REQ-001's
  dispatch contract, REQ-002's certificate handling, REQ-006's redaction
  rules, REQ-007's retry policy, or REQ-008/REQ-009's circuit-breaker
  behaviour in any way.
- `sessionId` was already declared on the `call_log` schema
  ("Session token for correlating multi-call traces") but had zero write
  sites before this change — see `design.md` Decision 5 for why this reuses
  the existing field rather than adding a new column.
