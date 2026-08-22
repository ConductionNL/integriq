# openconnector-mcp-tool-surface Specification Delta — hermiq-ai-tooling

This delta extends the canonical spec (created by the archived `openconnector-mcp-adoption`,
REQ-MCP-101..104) with a governed **action** surface. It MODIFIES REQ-MCP-101 (curated
`#[McpTool]` action methods become the sanctioned exception to "no MCP code in `lib/`") and
REQ-MCP-103 (object writes stay forbidden; action execution is admitted only under the two
conditions REQ-MCP-103 itself named — HITL approval on the write path and agent-principal
attribution). REQ-MCP-102 and REQ-MCP-104 are unchanged. This delta ADDS REQ-MCP-105..109.

## MODIFIED Requirements

### Requirement: REQ-MCP-101 — Integriq's MCP tool surface MUST be declared on the schema via the `x-openregister-mcp` dialect and MUST NOT be hand-written in PHP, except for curated action tools

Integriq MUST NOT ship an MCP tool provider or an `IMcpToolProvider` implementation
(ADR-063); every **read over a schema** MUST remain derived by OpenRegister from the per-schema
`configuration["x-openregister-mcp"]` block, producing `integriq.{schema}.{verb}` ids, and a
schema without the dialect MUST produce no derived tools. Integriq MAY ship curated
`#[McpTool]`-annotated methods on `lib/Mcp/IntegriqAgentTools.php`, discovered through
`lib/Mcp/IntegriqScannableServices.php` registered under
`OCA\OpenRegister\Mcp\IMcpScannableServices::integriq`, and MUST do so only for the action
tools and the payload-free read enumerated in REQ-MCP-105. Every curated tool id MUST be
2-segment (`integriq.{toolName}`) so it can never shadow a derived 3-segment id.

#### Scenario: the read surface is still schema-declared, not coded
- **WHEN** the tool list is enumerated
- **THEN** every `integriq.{schema}.{verb}` tool comes from the register dialect
- **AND** `lib/Mcp/` contains no `IMcpToolProvider` implementation and no derived-read reimplementation
- @e2e exclude backend catalogue/DI shape — covered by PHPUnit

#### Scenario: curated action tools are discovered, not registered as a provider
- **GIVEN** the app is installed and enabled
- **WHEN** the container is asked for `IMcpToolProvider::integriq`
- **THEN** no service exists under that alias
- **AND** `IMcpScannableServices::integriq` resolves to `IntegriqScannableServices` returning `[IntegriqAgentTools::class]`
- @e2e exclude backend DI registration — covered by PHPUnit bootstrap assertions

### Requirement: REQ-MCP-103 — Integriq MUST NOT expose any MCP object-write verb on any schema, because its objects are the integration control plane; action execution is admitted only under approval and attribution

No `create`, `update`, or `delete` verb MUST be declared on any integriq schema, and no
curated tool MUST create, update, or delete a `source`, `endpoint`, `mapping`, `rule`,
`consumer`, `event_subscription`, `synchronization`, `synchronization_contract`, `job`, or any
log object — including toggling `job.isEnabled`, which is a persistent control-plane mutation
with no controller action and is explicitly refused. The takeover-chain rationale stands: writing
configuration redirects, opens, alters, or destroys data flows, and ingested upstream data reaches
the agent's context. What this requirement now admits is **execution of existing configuration**
through curated action tools (REQ-MCP-105), and only because both re-enabling conditions are met
mechanically: a server-verified human approval on every effectful path (REQ-MCP-107) and
agent-principal attribution in the audit trail (REQ-MCP-108).

#### Scenario: no integriq object-write tool is derivable or curated
- **WHEN** the MCP tool list is enumerated
- **THEN** no `integriq.*.create`, `integriq.*.update`, or `integriq.*.delete` tool exists
- **AND** no curated tool accepts an argument that names a schema property to write
- @e2e exclude backend catalogue assertion — covered by PHPUnit

#### Scenario: an agent still cannot redirect, create, toggle, or destroy a data flow
- **GIVEN** an agent instructed (or prompt-injected via ingested upstream data) to repoint a source, add an endpoint, disable a job, or delete a mapping
- **WHEN** it searches the tool registry
- **THEN** no tool exists for any of those acts, and none of the action tools can be bent to them (they accept object ids to *execute*, never property values to *store*)
- @e2e exclude negative-capability assertion — covered by PHPUnit over the tool argument schemas

## ADDED Requirements

### Requirement: REQ-MCP-105 — Exactly six curated tools MUST exist, each an action over existing configuration or a payload-free read, with honest scope and reach

`IntegriqAgentTools` MUST expose exactly: `integriq.runSynchronization` (scope
`update`, reach `external`, approval-gated), `integriq.testSynchronization` (scope `read`,
reach `external`, confirm-classified), `integriq.testSource` (scope `read`, reach
`external`, confirm-classified), `integriq.replayDeadLetters` (scope `update`, reach
`external`, approval-gated, batch of ids), `integriq.discardDeadLetters` (scope `delete`,
reach `instance`, `destructiveHint: true`, approval-gated, batch of ids), and
`integriq.listDeadLetters` (scope `read`, reach `instance`). Reach MUST use Hermiq's
`ToolReachResolver` vocabulary; every tool that calls a remote system MUST declare `external`.
`runSynchronization` MUST NOT expose `forceDeletion` (the deletion-ratio-guard override stays a
human, UI-only act) and MUST run with the sync-safety guardrails unchanged. The following actions
are named as refused or deferred and MUST NOT be added without a change to this requirement:
job enable/disable (refused — object write), `resetCursor`, circuit-breaker trip/reset, contract
activate/deactivate/execute, event-subscription management, environment promotion, configuration
import/export (deferred — each needs its own risk argument).

#### Scenario: the curated surface is exactly six tools with the declared metadata
- **WHEN** the tool list is enumerated
- **THEN** exactly six 2-segment `integriq.*` tools exist beside the 16 derived reads
- **AND** their scope, reach, and hints match this requirement (run/test/replay = `external`)
- @e2e exclude backend catalogue metadata — covered by PHPUnit

#### Scenario: `forceDeletion` is unreachable through MCP
- **GIVEN** an agent calls `integriq.runSynchronization` with a `forceDeletion` argument
- **WHEN** arguments are validated
- **THEN** the call is rejected as invalid arguments and nothing is staged
- @e2e exclude backend argument validation — covered by PHPUnit

### Requirement: REQ-MCP-106 — Every curated tool MUST run the existing ADR-023 action check and delegate to the existing controller-backed service path

Before any staging or execution, each tool MUST call `ActionAuthService::requireAction()` with
its action id — `synchronization.run`, `synchronization.test`, `source.test` (existing seeds) or
`sync-dead-letter.replay` / `sync-dead-letter.discard` (new, seeded `["admin"]` in
`lib/actions.seed.json`) — as the granting user of the agent's session, and MUST return the
matrix's forbidden error unchanged when denied. Execution MUST delegate to the same service path
the controllers use (`SynchronizationsController::run()/test()`, `SourcesController::test()`, the
audited dead-letter replay/discard paths of REQ-DLR-003/004/009/010), so guardrails, retry and
terminal-state rules run unchanged and a request the UI path would refuse fails identically
through MCP. Hermiq's grant/approval layer and the ADR-023 matrix are independent and MUST both
pass; neither may be bypassed by the other.

#### Scenario: the matrix denies before anything is staged
- **GIVEN** a granting user whose groups lack `synchronization.run`
- **WHEN** an agent invokes `integriq.runSynchronization`
- **THEN** `requireAction()` denies with the forbidden error and no proposal is staged
- @e2e exclude backend authorization — covered by PHPUnit

#### Scenario: gate parity with the UI path
- **GIVEN** a synchronization whose test run the UI path refuses for a fixture user
- **WHEN** the same user's agent invokes `integriq.testSynchronization`
- **THEN** the tool returns the same domain error and no remote call is made
- @e2e exclude backend gate parity — covered by PHPUnit against the same fixture

### Requirement: REQ-MCP-107 — Run, replay, and discard MUST be two-phase with a server-verified human approval bound to the batch

`runSynchronization`, `replayDeadLetters`, and `discardDeadLetters` MUST be two-phase: phase 1
validates ids, passes REQ-MCP-106, and stages a proposal (tool, target ids, requesting agent,
granting user) without executing anything; phase 2 executes only when presented with an approval
token the server verifies was minted for a human approver distinct from the acting agent,
unexpired, and bound to that exact staged batch. The gate MUST be enforced in Integriq's tool
path so a non-Hermiq MCP client without a token can never execute. The batch is the approval
unit; the approver MUST see the full id list, and reviews dead-letter payloads in the existing
DeadLetters UI, never through the agent. `testSynchronization`, `testSource`, and
`listDeadLetters` MUST be single-phase.

#### Scenario: the canonical nightly-triage chat flow
- **GIVEN** last night's synchronization dead-lettered 14 items
- **WHEN** an agent triages via the derived log reads and `listDeadLetters`, stages `replayDeadLetters` for the 14 ids, and an admin reviews and approves the batch in Hermiq
- **THEN** phase 2 replays each id through the audited replay path
- **AND** a batch the admin rejects replays nothing
- @e2e tests/e2e/spec-coverage/hermiq-ai-tooling.spec.ts

#### Scenario: a token cannot be replayed onto another batch
- **GIVEN** an approval token minted for batch A
- **WHEN** phase 2 of batch B is invoked with it
- **THEN** execution is refused and batch B remains staged
- @e2e exclude backend token binding — covered by PHPUnit

#### Scenario: an unapproved run never executes
- **GIVEN** a staged `runSynchronization` proposal with no token ever presented
- **WHEN** the staging period ends
- **THEN** no synchronization run occurred and the proposal is auditable as proposed-but-not-approved
- @e2e exclude backend two-phase state machine — covered by PHPUnit

### Requirement: REQ-MCP-108 — Every invocation, including refusals, MUST be attributed to the agent principal in the audit trail

Every curated tool invocation MUST record the acting agent identity (from the MCP session
context), the granting user, the tool id, the outcome (denied by matrix / staged / approved /
executed / refused-token), the proposal reference, and the approval token id where a gate
applies — alongside the existing audit fields the underlying service path already writes. A
replayed dead letter or an executed run MUST be answerable from the trail as "agent A proposed,
approver B approved, on behalf of user C", never as a purely human act.

#### Scenario: an approved replay is traceable end to end
- **GIVEN** a dead letter replayed via an approved batch
- **WHEN** its audit trail is read
- **THEN** it names the agent, the granting user, the tool id, the batch reference, and the approval token id
- @e2e exclude backend audit assertion — covered by PHPUnit

#### Scenario: a matrix refusal is auditable
- **GIVEN** an invocation denied by `requireAction()`
- **WHEN** the audit trail is read
- **THEN** the denied attempt is present with agent and granting user
- @e2e exclude backend audit assertion — covered by PHPUnit

### Requirement: REQ-MCP-109 — The dead-letter read MUST be payload-free, and no tool may return or accept payload content

`integriq.listDeadLetters` MUST be the only dead-letter-reading tool and MUST return per
row exactly: `id`, `store` (`sync`/`event`), the `synchronization` or `subscription` reference,
`phase`, `error` (truncated to a fixed length), `attempts`/`retryCount`, `status`, `created`,
`replayedAt`, `discardedAt`. It MUST NOT return `payload` or `lastResponse`, and no curated tool
MUST accept payload content as an argument (replay/discard take ids only). Neither
`sync_item_dead_letter` nor `event_message` may gain the `x-openregister-mcp` dialect. Rationale
(binding, extending REQ-MCP-104's logic): `payload` is raw data from remote systems the instance
does not control; what reaches the agent's context can steer the agent, so it must never reach it.

#### Scenario: triage works on metadata alone
- **GIVEN** pending dead letters with upstream error responses
- **WHEN** an agent calls `integriq.listDeadLetters` filtered by synchronization and status
- **THEN** each row carries the projection above and no `payload` or `lastResponse` key
- @e2e exclude backend projection key-set assertion — covered by PHPUnit

#### Scenario: no derived dead-letter tool exists
- **WHEN** the tool list is enumerated
- **THEN** no `integriq.sync_item_dead_letter.*` or `integriq.event_message.*` tool exists
- @e2e exclude backend catalogue assertion — covered by PHPUnit
