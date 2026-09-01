---
kind: code
depends_on: [integriq-flow-nodes]
---

# Proposal: retire-integriq-flow-schema

## Summary

Integriq runs its own flow engine. `visual-flow-orchestration` declares a
`flow` schema with an ordered `steps[]` pipeline, plus `flow_run` and
`flow_run_log`, and `FlowRunnerService` executes it. OpenRegister runs a flow
engine too, with a `nodes`/`edges` graph, triggers, an execution mode and 22
node types.

Two engines is the defect. This change retires Integriq's and moves its flows
onto OpenRegister's, so the fleet has one flow model, one runner and one
editor.

## Motivation

### The slug collision is the symptom, not the disease

Measured on the dev instance 2026-08-31: `flow` is declared by both
`openregister` and `integriq`. A schema slug is global on a shared
OpenRegister, so `SchemaMapper::find()` can answer either one, and a bare
`"$ref": "flow"` resolves to whichever row it fetches first. Renaming
Integriq's slug would clear that collision in an afternoon.

It would also leave the fleet with two flow engines, which is the thing worth
fixing. The two models barely overlap:

| | openregister `flow` | integriq `flow` |
|---|---|---|
| shape | `nodes` + `edges` graph | ordered `steps[]` |
| start | `trigger` catalog, `cron`, `executionMode` | none |
| step vocabulary | 22 node types | `call`, `mapping`, `synchronization`, `event`, `approval`, `branch` |
| properties | 14 | 7 |

They share `name` and `description`.

### The gap that kept them separate is already being closed

OpenRegister's engine has no node that makes an outbound call and none that
runs a synchronization, which is Integriq's entire purpose. That is exactly
what the existing `integriq-flow-nodes` change addresses: it contributes
`openconnector.source-call` and `openconnector.synchronization-run` through
`RegisterFlowNodesEvent`, the seam OpenRegister already ships for apps to add
node types without patching the engine.

Once those nodes exist, Integriq's step vocabulary maps onto OpenRegister's
with one gap remaining:

| integriq step | openregister node |
|---|---|
| `call` | `openconnector.source-call` (integriq-flow-nodes) |
| `synchronization` | `openconnector.synchronization-run` (integriq-flow-nodes) |
| `mapping` | `MapNode` |
| `branch` | `RouterNode` / `SwitchNode` |
| `event` | `TriggerObjectNode` on the read side; the emit side needs confirming |
| `approval` | **no node exists** |

So this change also contributes an `openconnector.approval-request` node, and
confirms or contributes the emit half of `event`.

## What changes

1. **An approval node.** Integriq contributes `openconnector.approval-request`
   alongside the two nodes `integriq-flow-nodes` adds, so the `approval` step
   has a home. Its pause/resume semantics ride on `AwaitSignalNode`, which
   OpenRegister already ships.
2. **A migration** from `steps[]` to `nodes`/`edges`. Step `order` is a stable
   identifier that `branch` targets reference by value, so it maps to node
   ids directly and the graph is a chain with branch edges. Existing `flow`
   objects are rewritten in place by a repair step, and `flow_run` /
   `flow_run_log` history is retained read-only rather than migrated: run
   history is evidence, and rewriting evidence to a new shape is worse than
   keeping it where it is.
3. **`FlowRunnerService` is retired** in favour of OpenRegister's
   `FlowRunService`. `FlowsController`'s docblock already records that flows
   are read through `/api/objects/integriq/flow/*`, so the read path moves to
   the openregister register.
4. **The three schemas are retired** from
   `register.d/visual-flow-orchestration.json`, and the live rows are removed
   with `occ openregister:schemas:prune-retired --app integriq --slug flow`
   (and `flow_run`, `flow_run_log`). The import unions schema ids, so a
   descriptor deletion alone leaves the rows behind.
5. **`RuleToFlowGenerator`** emits OpenRegister flow graphs rather than
   Integriq step lists.

## What this is not

It is not a rename. `integration-flow` was considered and rejected: it clears
the slug collision and leaves both engines standing, which is the more
expensive outcome because every later flow feature then has to be built twice.

## Risks

- **The editor.** Integriq ships a step-list editor; OpenRegister ships a
  visual flow builder. Users lose one and gain the other. That is a UX change
  and needs the PO's sign-off before Task 1, not after.
- **`event` emit is unconfirmed.** The mapping table above marks it as needing
  confirmation. If OpenRegister has no emit node, this change grows by one
  more contributed node.
- **Run history.** Keeping `flow_run` read-only means the two schemas stay in
  the register after the migration, retired but not deleted. That is a
  deliberate choice and it means the slug collision on `flow` clears while
  `flow_run` / `flow_run_log` remain Integriq-owned. Neither collides today.
