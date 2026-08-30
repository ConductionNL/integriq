# rule-pipeline Specification (delta)

## ADDED Requirements

### Requirement: `flow` rule action type triggers a flow run (REQ-RULE-009)

The system MUST provide a `flow` rule type in
`EndpointService::processRules()`'s type dispatch (the existing 22-way
`match` on `$ruleData['type']`, alongside `save_object`, `approval`,
etc.), valid for either `timing: before` or `timing: after`. When a
`flow` rule's conditions pass (per the existing `checkRuleConditions()`
contract, REQ-RULE-001), the system MUST resolve the rule's `configRef`
to a `flow` OR object and call `FlowRunnerService::run($flow, data:
$data)` (see `flow-orchestration` REQ-001/REQ-007). The flow runs
synchronously within the same request; its result MUST NOT alter the
pipeline's existing before/after ordering or short-circuit contract for
other rules (matching REQ-RULE-008's precedent for the `approval` type —
this requirement only adds one new dispatch entry, it does not change
REQ-RULE-001's ordering/condition/short-circuit contract).

If the referenced flow's run ends with `flow_run.status: failed`,
`stopped`, or `dead_letter`, the rule pipeline MUST treat this the same
way it treats any other rule-level failure today (surfaced as an error
through the pipeline's existing error contract) — a flow rule does not
introduce a new pipeline-level failure mode beyond what `error`/`approval`
rule types already establish.

@e2e exclude backend rule pipeline dispatch — covered by PHPUnit, not browser UI

#### Scenario: a `flow` rule triggers a flow run mid-pipeline

- **GIVEN** an endpoint with rules at order 10 (`authentication`), order
  20 (`flow`, `configRef` pointing at an enabled flow), and order 30
  (`save_object`)
- **WHEN** the pipeline evaluates the `before`-phase rules and the
  order-20 rule's conditions pass
- **THEN** `FlowRunnerService::run()` is called for the referenced flow
- **AND** the order-10 and order-30 rules still run in their existing
  order, unaffected by the flow rule's dispatch

#### Scenario: a flow rule's conditions gate whether the flow runs

- **GIVEN** a `flow` rule with a condition that evaluates false for the
  current request
- **WHEN** the pipeline reaches that rule
- **THEN** `FlowRunnerService::run()` is NOT called
- **AND** the pipeline proceeds to the next rule as normal

#### Notes

- This requirement only adds a new entry to the existing `match` dispatch
  in `processRules()` (alongside `save_object`, `authentication`,
  `approval`, etc.) — the exact same integration pattern REQ-RULE-008
  already used for the `approval` type. It does not change
  REQ-RULE-001's ordering/condition/short-circuit contract, which the
  `flow` type reuses as-is.
- Unlike the `approval` type (REQ-RULE-008, `timing: before` only), a
  `flow` rule is valid at either timing — a flow can be a pre-write
  side-effect (`before`) or a post-write follow-up action (`after`),
  matching how `synchronization`/`mapping` rule types are already valid
  at either timing.
- A `flow` rule referencing a flow that itself contains an `approval`
  step will suspend that flow run (per `flow-orchestration` REQ-005) —
  from the endpoint rule pipeline's perspective this is treated
  identically to any other rule dispatch that completes without altering
  the pipeline's own response; the pipeline does NOT wait on or surface
  the flow's suspension state synchronously. This is a deliberate v1
  simplification: chaining a suspending flow off an endpoint rule is
  supported for triggering, but the endpoint response is not itself
  gated on that flow's eventual approval outcome (only a direct
  `approval` rule type, per REQ-RULE-008, gates the endpoint response
  itself).
