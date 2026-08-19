# Design: hermiq-ai-tooling

## Context

Canonical spec `openspec/specs/openconnector-mcp-tool-surface/spec.md` (from the archived `openconnector-mcp-adoption`): 16 derived read tools over 8 operational schemas (`endpoint`, `mapping`, `synchronization`, `synchronization_contract`, `call_log`, `job`, `job_log`, `synchronization_log`); REQ-MCP-103 forbids every write verb with a takeover-chain rationale and names the re-enabling condition (HITL approval on the write path + agent-principal attribution); REQ-MCP-104 excludes credential-bearing schemas entirely and admits `call_log` only because secrets are stripped at write time.

Verified action landscape:

- `SynchronizationsController::run()`, `::test(force?)`, `::resetCursor()`; `JobsController::run()`, `::test()` (no toggle action — `isEnabled` is an object property); `SourcesController::test()`, `::tripCircuitBreaker()`, `::resetCircuitBreaker()`; `SyncDeadLetterController` and `EventsController` `replay()/discard()/bulkReplay()/bulkDiscard()`.
- ADR-023: `lib/actions.seed.json` seeds `synchronization.run`, `synchronization.test`, `source.test`, `job.run`, `job.test`, `approval.approve/reject`, … all `["admin"]`; `ActionAuthService::requireAction()` is the centralised enforcement (canonical `action-authorization` spec).
- `sync-safety-guardrails` gives `run` a fetch-completeness gate, a deletion-ratio guard, and a `forceDeletion` override parameter; test runs carry an absolute no-write guarantee.
- Dead-letter machinery is spec'd (`dead-letter-replay` REQ-DLR-001..013) with audited replay/discard and terminal states; the dead-letter schemas' `payload` holds raw upstream data and neither schema is in the derived read set.

Governance context: Hermiq `ToolReachResolver` (`self/user/instance/external`), default-deny write grants per agent, `ApprovalService`, per-tool guardrail classification (`auto/confirm/deny`), audit; decidesk `lib/Mcp/` is the gate-pattern reference.

## Goals / Non-Goals

**Goals**
- Nightly-failure triage and remediation as a governed chat flow: read logs (existing derived tools) → list dead letters (payload-free) → replay behind one approved batch.
- Action execution without control-plane mutation: the agent can run what admins configured, never change what is configured.
- REQ-MCP-103's own re-enabling conditions met precisely, and its object-write prohibition kept.

**Non-Goals**
- Object CRUD tools of any kind; job toggling; cursor resets; circuit-breaker manipulation; environment/config actions; payload exposure; dialect writes.

## Decisions

### Decision 1: Actions, not writes — the tool table

| Tool id | Scope | Reach | Gate | Delegates to (existing, unchanged) | ADR-023 action |
|---|---|---|---|---|---|
| `openconnector.runSynchronization` | update | external | **approval** | `synchronization.run` path incl. sync-safety guardrails; `forceDeletion` never exposed | `synchronization.run` |
| `openconnector.testSynchronization` | read | external | confirm | test path (absolute no-write guarantee) | `synchronization.test` |
| `openconnector.testSource` | read | external | confirm | `source.test` path (`CallService`) | `source.test` |
| `openconnector.replayDeadLetters` | update | external | **approval**, batch | audited replay (REQ-DLR-003/009) for sync + event dead letters | `sync-dead-letter.replay` (new seed) |
| `openconnector.discardDeadLetters` | delete | instance | **approval**, batch, `destructiveHint` | audited discard (REQ-DLR-004/010) | `sync-dead-letter.discard` (new seed) |
| `openconnector.listDeadLetters` | read | instance | none | dead-letter listing services, payload stripped | — (read; RBAC + matrix-free like other curated reads) |

`external` reach on run/test tools is not negotiable: they call remote systems the instance does not control. `discard` is `instance` (terminal bookkeeping, no remote call) but `delete`-scoped and destructive-hinted.

### Decision 2: Why "toggle job" is refused, in one sentence

Every allowed tool executes existing configuration once and leaves the control plane unchanged; toggling `job.isEnabled` **changes** the control plane persistently (scheduled execution on/off), has no controller action (it would be an object write, verified), and is exactly the REQ-MCP-103 class this change keeps forbidden.

### Decision 3: The payload firewall

The agent-facing dead-letter surface is metadata-only: `listDeadLetters` returns per row `id`, `store` (`sync`/`event`), `synchronization`/`subscription` ref, `phase`, `error` (truncated to a fixed length), `attempts`/`retryCount`, `status`, `created`, `replayedAt`/`discardedAt` — all verified properties — and never `payload`. Replay/discard tools accept **ids only**, never payload content. The human approver reviews payloads in the existing DeadLetters UI, which is where payload belongs. This mirrors REQ-MCP-104's logic (what the stored object carries determines what a tool may return) one layer up: what the agent's context window carries determines what can steer the agent.

### Decision 4: Two-phase approval, server-enforced, batch-shaped

Phase 1 validates ids, calls `requireAction()`, stages a proposal (tool, target ids, requesting agent, granting user); no execution. Phase 2 executes only with a server-verified approval token: minted for a human approver distinct from the acting agent, unexpired, bound to that batch. Enforced in the tool path so a non-Hermiq MCP client without a token can never execute. The batch is the approval unit (one reviewed dead-letter batch or one run request per approval).

### Decision 5: Both authorization layers always run

`requireAction()` (ADR-023 matrix, admin-seeded) runs inside every tool before staging; Hermiq's grant/approval runs outside. They are independent by design: a Hermiq misconfiguration cannot open what the matrix closes, and vice versa. Attribution records both outcomes, including refusals.

### Decision 6: Chat scenarios as verification fixtures

1. **Nightly triage** — "Why did last night's sync fail? Replay the dead-letters." → derived `synchronization_log.search` + `call_log.search` (existing) → `listDeadLetters(synchronization: X, status: pending)` → agent explains the 429/500 pattern → `replayDeadLetters` staged for the 14 ids → admin reviews payloads in the DeadLetters UI, approves → replay executes, audit shows agent+approver.
2. **Source smoke-test** — "The supplier says they fixed their API — check." → `testSource` (confirm) → result summarised from status/timing, no config touched.
3. **Poison cleanup** — "These 3 dead letters are malformed spam, drop them." → `discardDeadLetters` staged → approved → terminal discard state, audited.

## Risks / Trade-offs

Carried in proposal.md. Trade-off accepted: truncated `error` strings may echo upstream fragments (triage is impossible without them); flagged to the security reviewer.

## Migration Plan

Additive; no ordering constraint on sibling changes. Rollback = revert (matrix rows become inert, staged proposals unreachable).

## Open Questions

Carried in proposal.md (gating of test tools per instance; batch cap; error-string truncation length).
