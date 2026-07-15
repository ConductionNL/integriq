# rule-pipeline Specification (delta)

**OpenSpec changes**:
- `hitl-approval-rule-action` _(in progress)_ — adds the `approval` rule
  action type (human-in-the-loop suspension/resume). See the new
  `approval-workflow` capability spec for the full suspend/resume/
  authorization/notification state machine; this delta only adds the
  dispatch entry point and pipeline-level contract to `rule-pipeline`.

## ADDED Requirements

### Requirement: `approval` rule action type suspends the pipeline (REQ-RULE-008)

The system MUST provide an `approval` rule type in
`EndpointService::processRules()`'s type dispatch, valid only for
`timing: before`. When a `before`-phase `approval` rule's conditions pass,
processing MUST NOT continue to later rules in the same run; instead the
system MUST delegate to `ApprovalService::suspend()` (see
`approval-workflow` REQ-001) and return the resulting `JSONResponse(202)`
through the pipeline's existing short-circuit contract (the same contract
`error` and other terminal rule types already use — no new Response type).
An `approval` rule configured with `timing: after` MUST be treated as
invalid configuration and MUST NOT be dispatched.

@e2e exclude backend rule pipeline execution — covered by PHPUnit, not browser UI

#### Scenario: approval rule short-circuits the before-phase pipeline

- **GIVEN** an endpoint with rules at order 10 (`authentication`), 20
  (`approval`), and 30 (`save_object`)
- **WHEN** the `before`-phase pipeline runs and the order-20 rule's
  conditions pass
- **THEN** the order-10 rule runs normally, the order-20 rule suspends the
  pipeline via `ApprovalService::suspend()`, the pipeline returns HTTP 202,
  and the order-30 rule does NOT run in this request

#### Scenario: an approval rule configured for the after phase never dispatches

- **GIVEN** an `approval` rule configured with `timing: after`
- **WHEN** the pipeline evaluates rules for either phase
- **THEN** the rule is never matched to the `approval` dispatch case (timing
  mismatch is invalid configuration, not a runtime skip)

#### Notes

- This requirement only adds a new entry to the existing `match` dispatch
  in `processRules()` (alongside `save_object`, `authentication`, `error`,
  etc.) and does not change REQ-RULE-001's ordering/condition/short-circuit
  contract, which the `approval` type reuses as-is.
- Resume (the counterpart to this suspension) is specified in
  `approval-workflow` REQ-003, not here — resuming calls back into
  `processRules()` for the remaining rules in the same phase, so no
  separate "resume" rule type exists.
