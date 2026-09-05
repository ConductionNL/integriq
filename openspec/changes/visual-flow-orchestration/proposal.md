---
kind: spec-only
depends_on: []
---

# Proposal: visual-flow-orchestration (superseded — re-scoped 2026-09-02)

This directory double-counted a change that had already shipped. The wave-3
spec commit (46dfa497, 2026-07-15 19:00) created it as a fresh 0/55 change;
the same change was implemented and archived two hours later the same evening
(`archive/2026-07-15-visual-flow-orchestration`, PR #209, 43/55 tasks checked
with per-task evidence, the rest deliberately left open for want of a live
instance). Since then the live copy has sat at 0/55, permanently inflating the
backlog with work that exists at HEAD: `FlowRunnerService`, `FlowAction`,
`FlowsController`, the `flow` rule action in `EndpointService::processRules()`,
and the `flow`/`flow_run`/`flow_run_log` schemas in
`lib/Settings/register.d/visual-flow-orchestration.json`.

More importantly, the One-engine direction has inverted this change's premise.
It built an app-local flow engine; the fleet decision is that OpenRegister runs
the only flow engine, and Integriq contributes node types to it. The manifest
already reflects this: the `Flows` index reads OpenRegister's native flow
table and `FlowDetail` renders OR's shared `flow` page type (CnFlowEditorPage);
the step-list editor this change specified (`FlowDetailPage.vue`) no longer
exists in the tree.

No `@spec` tag anywhere in `lib/`, `src/` or `tests/` points into this
directory (the shipped code's tags point at the archived copy), so the delta
specs, design and test plan are removed with this re-scope; they survive
verbatim in the archived twin and in git history.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| `flow`/`flow_run`/`flow_run_log` schemas, `FlowRunnerService`, `FlowAction`, `flow` rule action, `FlowsController`, triggers (tasks 1-15, 20) | **Already shipped and archived**: `archive/2026-07-15-visual-flow-orchestration` (PR #209), code at HEAD |
| Flows index + detail step-list editor UI (tasks 16-19) | Shipped, then **replaced**: the manifest's `Flows`/`FlowDetail` pages now render OpenRegister's shared `flow` page type over OR's native flow table; `FlowDetailPage.vue` and the step-list editor were deleted with that cutover. Their open Playwright/verification boxes are cut — the components under test no longer exist |
| The app-local flow engine itself | **Being retired** onto OR's engine: `openspec/changes/retire-integriq-flow-schema` (schema and runner retirement, steps-to-graph migration, `occ openregister:schemas:prune-retired`), gated on `openspec/changes/integriq-flow-nodes` (`openconnector.source-call`, `openconnector.synchronization-run`, `openconnector.approval-request` contributed via `RegisterFlowNodesEvent`) |
| Synchronization-as-a-flow | `openspec/changes/flow-native-synchronization` (18/19 done): the canonical sync becomes a drawn OR flow delegating to the existing services |
| Newman tests for `/api/flows`, feature docs, screenshots, `nl_NL` strings | **Cut.** Documenting a runner scheduled for deletion is negative work; `retire-integriq-flow-schema` section 5 carries the proof obligations for the OR-backed replacement |
| v2 follow-ups (drag-and-drop canvas, fan-out, loops) | **Cut as app-local work.** OR's flow editor is the canvas; fan-out and looping are engine primitives (`FlowConcurrency`, LoopNode) and land in OpenRegister, never in a leaf app |

## Sequencing

`integriq-flow-nodes` first (it is fully authored and independent), then
`retire-integriq-flow-schema` (its tasks.md opens with that gate), with
`flow-native-synchronization` continuing in parallel. Nothing remains to
implement from this change directly.

## Archival

This directory is retired in place (not moved or renamed) so no path breaks
and no diff-scoped gate detonates. Archive it via the normal flow once the
three successor changes have shipped or been deliberately dropped.
