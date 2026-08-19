# Proposal: hermiq-ai-tooling

## Summary

Extend OpenConnector's MCP surface from "read-only operational triage" to **governed action tools** — the strictest of the fleet's three hermiq-ai-tooling changes, because OpenConnector's actions are the most powerful. The canonical `openconnector-mcp-tool-surface` spec (created by the archived `openconnector-mcp-adoption` change) derives 16 read tools from 8 operational schemas and forbids every write (REQ-MCP-103) — while itself stating the re-enabling condition: *"human-in-the-loop approval on the write path **and** agent-principal attribution in the audit trail."* This change meets that condition for five real, controller-backed **actions** (never object CRUD): `openconnector.runSynchronization`, `openconnector.testSynchronization`, `openconnector.testSource`, `openconnector.replayDeadLetters`, `openconnector.discardDeadLetters`, plus one curated payload-free read (`openconnector.listDeadLetters` — the dead-letter schemas are not in the derived read set precisely because their `payload` is hostile-upstream data). Every action tool is default-deny in Hermiq (scope × reach per `ToolReachResolver`; all effectful actions here are reach `external` by nature — they touch remote systems), approval-gated server-side, layered on the existing ADR-023 `ActionAuthService` matrix (the seeded action ids `synchronization.run`, `synchronization.test`, `source.test` are the enforcement layer, verified in `lib/actions.seed.json`), and attributed to the agent principal in the audit trail. Object writes (create/update/delete a source, endpoint, mapping, job, rule…) remain forbidden — including "toggle job", which is an `isEnabled` object write and is explicitly refused. Canonical chat scenario: *"why did last night's sync fail? replay the dead-letters"* — triage over the existing derived reads, replay behind one approved batch.

## Motivation

- **PO framing (fleet-wide):** every app provides MCP tooling for its actions so any action can in principle be automated; users grant rights per agent, granularly; chat is a command surface even before autonomy. For OpenConnector the actions that matter are operational: run/test a sync, test a source, replay or discard dead letters — nightly-failure triage, currently a manual admin routine.
- **REQ-MCP-103 forbade writes and named the exit.** Its rationale is prompt-injection-to-infrastructure-takeover via object writes (repoint a source, plant an endpoint). None of the five actions here writes control-plane configuration: they *execute* configuration that an admin already wrote, through the same controller/service paths (`SynchronizationsController::run()/test()`, `SourcesController::test()`, `SyncDeadLetterController`/`EventsController` `replay()/discard()`, all verified) that already delegate authorization to `ActionAuthService::requireAction()`.
- **Dead-letter triage needs a read the derived surface rightly refuses.** `sync_item_dead_letter` and `event_message` are excluded from the 8 exposed schemas; their `payload` property carries raw upstream data — the exact injection vector REQ-MCP-103 warns about. A curated `listDeadLetters` that returns metadata (`error`, `phase`, `attempts`, `status`, `synchronization`, timestamps — all verified properties) and **never** `payload` serves triage without opening the vector, mirroring REQ-MCP-104's write-time-redaction logic at the tool layer.
- **The fleet pattern exists.** decidesk `lib/Mcp/` (gate + argument validator + scope resolver) for gated tools; Hermiq (`ToolReachResolver` `self/user/instance/external`, default-deny grants, `ApprovalService`, audit) as governor; scholiq/opencatalogi hermiq-ai-tooling as sibling changes.

## Affected Projects

- [x] Project: `openconnector` — new `lib/Mcp/OpenConnectorAgentTools.php` (`#[McpTool]` action methods), `lib/Mcp/OpenConnectorScannableServices.php`, `lib/Mcp/McpArgumentValidator.php`, action-matrix rows for the two dead-letter actions, tests. openregister (scanner) and hermiq (governance) are consumed unchanged.

## Scope

### In Scope

- Six curated tools on `OpenConnectorAgentTools`, discovered via `IMcpScannableServices::openconnector`:
  - `openconnector.runSynchronization` — scope `update`, reach `external`, **approval-gated**; delegates to the `synchronization.run` path (including the sync-safety guardrails: fetch-completeness gate, deletion-ratio guard; `forceDeletion` is NOT exposed as a tool argument)
  - `openconnector.testSynchronization` — scope `read`, reach `external` (dry-run, no-write by the test-run guarantee, but it calls the remote source), confirm-classified, not approval-gated
  - `openconnector.testSource` — scope `read`, reach `external`; delegates to the `source.test` path
  - `openconnector.replayDeadLetters` — scope `update`, reach `external`, **approval-gated**, batch-shaped; delegates to the audited replay paths (REQ-DLR-003/REQ-DLR-009)
  - `openconnector.discardDeadLetters` — scope `delete`, reach `instance`, `destructiveHint: true`, **approval-gated**, batch-shaped; delegates to the audited discard paths (a discard is a terminal state, REQ-DLR-004)
  - `openconnector.listDeadLetters` — scope `read`, reach `instance`; payload-free projection over both dead-letter stores
- Server-side two-phase approval (stage → human token → execute) for the three gated tools, decidesk-gate style, enforced in the tool path regardless of MCP client
- ADR-023 layering: every tool calls `ActionAuthService::requireAction()` with the existing action ids (`synchronization.run`, `synchronization.test`, `source.test`) or new seeded-admin-only ids for the dead-letter batches (`sync-dead-letter.replay`, `sync-dead-letter.discard` — added to `lib/actions.seed.json`)
- Agent-principal attribution on every invocation (including refused ones) in the audit trail
- 2–3 documented chat scenarios as verification fixtures

### Out of Scope

- Any object write on any schema: create/update/delete of `source`, `endpoint`, `mapping`, `job`, `rule`, `consumer`, `synchronization`, … remain forbidden by REQ-MCP-103, which this change narrows but does not lift
- **"Toggle job" — explicitly refused**: enabling/disabling a job is an `isEnabled` object write (there is no controller action for it; verified — `JobsController` has `run()`/`test()`/`logs()` only), i.e. persistent control-plane mutation, not action execution
- `resetCursor`, circuit-breaker trip/reset, contract activate/deactivate/execute, event-subscription management, environment promotion, configuration import — powerful actions deliberately deferred; each needs its own risk argument before joining the surface
- Exposing `payload` (or any credential-bearing schema) through any tool — REQ-MCP-104 untouched
- Dialect (`x-openregister-mcp`) write verbs — REQ-MCP-101/102 untouched
- Hermiq-side changes

## Approach

1. MODIFY REQ-MCP-101 and REQ-MCP-103 in the canonical spec (curated `#[McpTool]` action tools become the sanctioned exception, under the conditions REQ-MCP-103 itself named) and ADD REQ-MCP-105..109 for the action surface, gates, attribution, and the payload-free read.
2. Implement the tool class as thin gated wrappers: argument validation → `ActionAuthService::requireAction()` → (for gated tools) staged proposal + approval token verification → delegation to the existing service path → attribution.
3. Verify Hermiq classifies the tools from their declared hints (external-reach writes default-denied).

## New Dependencies

None. `AttributeToolScanner`/`IMcpScannableServices` (openregister) and hint-honouring classification (hermiq #57) are present at `origin/development`; decidesk is a reference, not a dependency.

## Impact

- Tool count grows from 16 derived reads to 22 (16 + 6 curated).
- `lib/actions.seed.json` gains 2 rows (`sync-dead-letter.replay`, `sync-dead-letter.discard`), seeded `["admin"]` per ADR-023's closed-default rule.
- New PHP under `lib/Mcp/` — permitted by this change's modification of REQ-MCP-101.
- No schema, register, endpoint, or frontend change.

## Cross-Project Dependencies

- **openregister** ≥ the attribute-scanner commit (present at `origin/development`).
- **hermiq** ≥ hermiq #57 (hint honouring on 2-segment curated ids, merged).
- Sibling context, no ordering constraint: scholiq/opencatalogi `hermiq-ai-tooling` changes establish the same pattern in consumer apps.

## Risks

### Risk 1: A prompt-injected agent replays a poisoned dead letter into a target system

**Severity:** High — replay re-enters upstream data into the delivery/sync machine, and the agent may have been steered by that very data. **Mitigation:** the agent never sees `payload` (`listDeadLetters` is payload-free, and no derived dead-letter tool exists), so it cannot be steered by dead-letter contents; replay is approval-gated — a human sees the batch (with payload access in the existing DeadLetters UI) before execution; the replay path itself is the existing audited machinery with its own retry/terminal-state rules.

### Risk 2: `runSynchronization` as a denial-of-service or data-loss lever

**Severity:** Medium — repeated agent-triggered runs could hammer a supplier API or, historically, trigger the mass-deletion class of bugs. **Mitigation:** approval gate per staged run request; the sync-safety guardrails (fetch-completeness gate, deletion-ratio guard) run unchanged and `forceDeletion` is not exposed as a tool argument, so the one override that can bypass the deletion guard stays human-and-UI-only; Hermiq budgets/rate limits apply on top.

### Risk 3: The action surface creeps toward the control plane

**Severity:** Medium — "just add resetCursor / toggle job / promote environment" pressure is predictable. **Mitigation:** REQ-MCP-105 closes the tool list; the refused and deferred actions are named in the spec with their reasons, so widening requires arguing against recorded rationale, not just adding a method.

### Risk 4: Two authorization layers disagree

**Severity:** Low — Hermiq grant says yes, ADR-023 matrix says no (or vice versa). **Mitigation:** intended behaviour — the layers are independent and BOTH must pass (defence in depth); the tool returns the matrix's forbidden error unchanged so the mismatch is visible and auditable.

## Rollback Strategy

Revert the commit. The scannable-services registration and tool class disappear; the derived read surface is untouched; the two new action-matrix rows become inert. Staged proposals are unreachable without the tool class. No data migration.

## Open Questions

- Should `testSynchronization`/`testSource` be approval-gated too on instances where even a diagnostic call to a production supplier API is sensitive? Current posture: grant-gated + confirm-classified, not approval-gated; per-instance tightening is possible via the guardrail policy's per-tool `confirm`/`deny`.
- Batch cap for replay/discard approvals (the DeadLetters UI does bulk today; what is reviewable in one approval — 50? 200?).
- Should `listDeadLetters` include `error` truncated (the error string can echo upstream response fragments)? Current posture: include, truncated to a fixed length, because triage is impossible without it; flagged for the security reviewer.
