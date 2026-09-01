# flow-orchestration Specification

## Purpose

Integriq's flows run on OpenRegister's flow engine. Integriq contributes the
node types that engine lacks; it does not run an engine of its own.

## Requirements

### Requirement: Integriq MUST NOT declare its own flow schema

A schema slug is global on a shared OpenRegister, and OpenRegister declares
`flow` as a core schema. An app that declares the same slug makes both
definitions resolvable from one bare `$ref`, and the wrong one can answer.

Integriq's flows are OpenRegister `flow` objects. Integriq contributes node
types through `RegisterFlowNodesEvent`.

#### Scenario: The register declares no flow schema
- GIVEN Integriq's shipped register descriptors
- WHEN gate-106 checks them against the fleet slug baseline
- THEN no descriptor declares the slug `flow`

#### Scenario: The live row is removed, not merely undeclared
- GIVEN an instance that imported Integriq's former `flow` schema
- WHEN `occ openregister:schemas:prune-retired --app integriq --slug flow --apply` runs
- THEN the schema row is deleted and every referencing register is unlinked
- AND a second run reports the slug as not found

### Requirement: Integriq MUST contribute a node for every step type it retires

Retiring the step vocabulary without a node for each type leaves a migrated
flow that cannot run. The failure is silent in the way flow failures usually
are: the run reports a step it cannot dispatch, and the work simply does not
happen.

#### Scenario: Every former step type has a node
- GIVEN the former step vocabulary call, mapping, synchronization, event, approval, branch
- WHEN the flow node registry is enumerated with Integriq enabled
- THEN each type resolves to a registered node

#### Scenario: An approval step pauses and resumes
- GIVEN a migrated flow whose approval node awaits a signal
- WHEN the request is approved
- THEN the run resumes on the approve edge
- AND when it is rejected the run takes the reject edge

### Requirement: The migration MUST preserve branch targets

A `branch` step references its targets by step `order`, which is a stable
identifier rather than an array position. A migration that renumbers loses
every branch.

#### Scenario: Branch targets survive translation
- GIVEN a flow whose branch step names nextStepOrder 40 and defaultNextStepOrder 50
- WHEN the flow is translated to nodes and edges
- THEN the branch node's edges point at the nodes whose ids are 40 and 50

#### Scenario: A flow with duplicate step orders is refused
- GIVEN a flow with two steps carrying order 20
- WHEN the translator runs
- THEN it refuses the flow, as FlowRunnerService::run() refuses it today
- AND it does not emit a graph with one of the two nodes missing
