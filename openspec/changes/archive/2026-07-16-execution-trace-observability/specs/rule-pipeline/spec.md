# rule-pipeline Specification (delta)

**OpenSpec changes**:
- `execution-trace-observability` _(in progress)_ — adds trace-step
  emission to the rule pipeline when an `ExecutionTraceContext` is active,
  and a dry-run mode that suppresses write-shaped rule dispatch during a
  traced replay preview. See the new `execution-trace` capability spec for
  the full timeline/persistence/replay contract; these deltas only add the
  pipeline-level hooks.

## ADDED Requirements

### Requirement: Trace-step emission during rule pipeline execution (REQ-RULE-010)

The system MUST append one ordered `Step` to the active
`ExecutionTraceContext`'s buffer (per `execution-trace` REQ-001) for every
rule `processRules()` evaluates, when a non-null `ExecutionTraceContext` is
supplied. This MUST include rules skipped by REQ-RULE-001's condition/timing
checks (`status: 'skipped'`), rules that mutate the data envelope
(`status: 'success'`, redacted input/output per `execution-trace` REQ-003),
and rules whose processing throws (`status: 'error'`, the same
endpoint/rule name/type/message the HTTP 500 body carries). When no
`ExecutionTraceContext` is supplied, `processRules()` MUST behave
identically to its current, untraced behaviour — no step buffering, no
additional OpenRegister writes, no change to REQ-RULE-001's
ordering/condition/short-circuit contract.

@e2e exclude backend rule pipeline execution — covered by PHPUnit, not browser UI

#### Scenario: a traced pipeline records a step per evaluated rule

- **GIVEN** an endpoint with three rules of `order` 10, 20, 30 and an active
  `ExecutionTraceContext`
- **WHEN** the pipeline runs and the order-20 rule's conditions fail
- **THEN** three steps are appended in order 10, 20, 30
- **AND** the order-20 step carries `status: 'skipped'`

#### Scenario: an untraced pipeline is unaffected

- **GIVEN** an endpoint call with no `ExecutionTraceContext` supplied
  (`traceId` not minted — e.g. a code path that predates this change)
- **WHEN** `processRules()` runs
- **THEN** behaviour is byte-for-byte identical to REQ-RULE-001's existing
  scenarios — no step is buffered, no `execution_trace` write occurs

#### Notes

- This requirement only adds an optional-parameter hook to the existing
  `processRules()`/`dispatchAfterBeforeRules()` signatures (default `null`);
  it does not change REQ-RULE-001's ordering, condition evaluation, or
  short-circuit contract.

### Requirement: Dry-run mode suppresses write-shaped rule dispatch (REQ-RULE-011)

`processRules()` MUST accept an optional `dryRun` parameter (default
`false`, preserving existing behaviour exactly). When `dryRun === true`,
rule types with an external or persisted side-effect — `save_object`,
`override`, `locking`, `write_file`, `fileparts_create`, `filepart_upload`,
`composite_fanout` (per `rule-pipeline` REQ-RULE-006) — MUST NOT perform
their write; the pipeline MUST instead record a step with `status:
'skipped_dry_run'` and continue evaluating downstream rules against the
pre-rule data envelope. Rule types with no external side-effect —
`mapping`, `extend_input`, `authentication`, `error` — MUST execute
normally under `dryRun: true`. A `synchronization` rule under `dryRun: true`
MUST forward `isTest: true` to `SynchronizationService::synchronize()`
(reusing `synchronization-engine` REQ-011's existing no-write guarantee)
rather than being unconditionally skipped, since the target synchronization
already knows how to no-op safely.

@e2e exclude backend rule pipeline execution — covered by PHPUnit, not browser UI

#### Scenario: dryRun suppresses a save_object rule's write

- **GIVEN** a `save_object` rule and `dryRun: true`
- **WHEN** the pipeline reaches it
- **THEN** no OpenRegister object is persisted
- **AND** the recorded step carries `status: 'skipped_dry_run'`

#### Scenario: dryRun does not suppress a mapping rule

- **GIVEN** a `mapping` rule and `dryRun: true`
- **WHEN** the pipeline reaches it
- **THEN** the mapping is applied for real and the step carries a normal
  `status: 'success'`

#### Scenario: dryRun forwards isTest to a synchronization rule

- **GIVEN** a `synchronization` rule and `dryRun: true`
- **WHEN** the pipeline reaches it
- **THEN** `SynchronizationService::synchronize()` is invoked with
  `isTest: true`, and no target write occurs

#### Notes

- `dryRun` defaults to `false`; every pre-existing REQ-RULE-* requirement in
  this capability is exercised with the default and is unaffected by this
  requirement's existence.
- This requirement exists to support `execution-trace` REQ-005/REQ-006's
  endpoint-entryPoint replay preview; it has no caller outside that replay
  path in this change's scope.
- **Integration follow-up (not in this change's scope):** this change was
  authored against a base that predates the `flow` rule action type
  (REQ-RULE-009, added independently). `flow` triggers a flow run — a
  write-shaped side effect — but is NOT in this requirement's suppression
  set (`EndpointService::DRY_RUN_SUPPRESSED_RULE_TYPES`), because it does
  not exist in this change's base `processRules()` type dispatch. Whoever
  integrates the two MUST decide whether `flow` belongs in the suppression
  set (likely yes, or a forwarded dry-run flag mirroring the
  `synchronization` partial exception above); until then a dry-run replay of
  an endpoint carrying a `flow` rule WOULD trigger a real flow run.
