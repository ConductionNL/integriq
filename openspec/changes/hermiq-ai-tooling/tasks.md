# Tasks: hermiq-ai-tooling

## Implementation Tasks

### Task 1: `OpenConnectorAgentTools` with six `#[McpTool]` methods + scannable-services registration
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-105--exactly-six-curated-tools-must-exist-each-an-action-over-existing-configuration-or-a-payload-free-read-with-honest-scope-and-reach`
- **files**: `lib/Mcp/OpenConnectorAgentTools.php` (new), `lib/Mcp/OpenConnectorScannableServices.php` (new), `lib/Mcp/McpArgumentValidator.php` (new, decidesk pattern), `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN the container resolves `IMcpScannableServices::openconnector` THEN it returns `[OpenConnectorAgentTools::class]` and no `IMcpToolProvider::openconnector` alias exists
  - GIVEN the tool catalogue WHEN enumerated THEN it contains exactly 22 openconnector tools: 16 derived reads + `runSynchronization`, `testSynchronization`, `testSource`, `replayDeadLetters`, `discardDeadLetters`, `listDeadLetters`, all 2-segment ids
  - GIVEN each curated tool WHEN its metadata is read THEN scope/reach/hints match REQ-MCP-105 (run/test/replay = reach `external`; discard = `delete` + `destructiveHint: true`), and the two gated-write descriptions and the destructive one name their approval gate
  - GIVEN `runSynchronization` WHEN called with a `forceDeletion` argument THEN it rejects with invalid-arguments and stages nothing
  - GIVEN the new PHP WHEN `composer check:strict` runs THEN it is clean
- [ ] Implement
- [ ] Test

### Task 2: ADR-023 layering + delegation to the existing service paths — BLOCKS Task 3
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-106--every-curated-tool-must-run-the-existing-adr-023-action-check-and-delegate-to-the-existing-controller-backed-service-path`
- **files**: `lib/actions.seed.json`, `lib/Mcp/OpenConnectorAgentTools.php`, `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN `lib/actions.seed.json` WHEN read THEN it gains `sync-dead-letter.replay` and `sync-dead-letter.discard`, each `["admin"]`, and no existing row changes
  - GIVEN a granting user lacking the action WHEN any tool is invoked THEN `ActionAuthService::requireAction()` denies with its forbidden error before staging or execution
  - GIVEN a fixture where the UI path refuses a test run WHEN `testSynchronization` is invoked THEN the same domain error is returned and no remote call is made (gate-parity test)
  - GIVEN any tool WHEN its implementation is reviewed THEN execution goes only through the existing `SynchronizationService`/`CallService`/dead-letter replay-discard paths; no object write, no property argument, no direct ObjectService call
- [ ] Implement
- [ ] Test

### Task 3: Two-phase batch approval for run/replay/discard, server-enforced
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch`
- **files**: `lib/Mcp/OpenConnectorAgentTools.php`, `lib/Service/` (staged-proposal handling), `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN phase 1 of run/replay/discard WHEN it completes THEN a staged proposal with the full id list exists and nothing executed
  - GIVEN phase 2 WHEN invoked without a token, with an expired token, with a token minted for the acting agent, or with a token bound to another batch THEN it is refused and nothing executes
  - GIVEN phase 2 WHEN invoked with a valid human-approver token bound to the batch THEN each id executes through Task 2's path
  - GIVEN `testSynchronization`, `testSource`, `listDeadLetters` WHEN invoked THEN they are single-phase
- [ ] Implement
- [ ] Test

### Task 4: Agent-principal attribution including refusals
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-108--every-invocation-including-refusals-must-be-attributed-to-the-agent-principal-in-the-audit-trail`
- **files**: `lib/Mcp/OpenConnectorAgentTools.php`, `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN any invocation (denied / staged / executed / token-refused) WHEN the audit trail is read THEN it carries agent identity, granting user, tool id, outcome, and (where applicable) proposal reference + approval token id
  - GIVEN the same replay performed through the DeadLetters UI WHEN audited THEN it carries no agent fields (control)
- [ ] Implement
- [ ] Test

### Task 5: Payload-free `listDeadLetters` projection
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-109--the-dead-letter-read-must-be-payload-free-and-no-tool-may-return-or-accept-payload-content`
- **files**: `lib/Mcp/OpenConnectorAgentTools.php`, `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN seeded sync and event dead letters WHEN the tool runs THEN each row's key set equals exactly the REQ-MCP-109 projection (assert equality, not presence) and contains no `payload`/`lastResponse`
  - GIVEN `error` longer than the fixed limit WHEN returned THEN it is truncated
  - GIVEN the catalogue WHEN enumerated THEN no `openconnector.sync_item_dead_letter.*` or `openconnector.event_message.*` tool exists; `lib/Settings/openconnector_register.json` carries no dialect on either schema
- [ ] Implement
- [ ] Test

### Task 6: Hermiq classification check + chat-scenario e2e + docs
- **spec_ref**: `openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch`
- **files**: `tests/e2e/spec-coverage/hermiq-ai-tooling.spec.ts` (new), `docs/`, `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN Hermiq enumerates the openconnector tools WHEN the six are classified THEN run/replay/discard are default-denied writes and run/test/replay show reach `external` (verified once against a live Hermiq, recorded in the change)
  - GIVEN the e2e suite WHEN it runs THEN the nightly-triage flow passes: dead letters listed payload-free → batch staged → approved in Hermiq → replayed (visible in the DeadLetters UI); a rejected batch replays nothing
  - GIVEN `docs/` WHEN read THEN it records the tool table (scope × reach × gate × action id), the payload firewall, the refused/deferred action list with reasons, and the three chat scenarios
  - GIVEN `CHANGELOG.md` WHEN read THEN it records the governed action surface and the two new action-matrix rows
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate hermiq-ai-tooling --type change --strict` passes
- [ ] Manual testing against acceptance criteria (matrix denial, rejected batch, approved batch, token replay, `forceDeletion` rejection)
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for metadata, argument validation, matrix layering, gate parity, two-phase state machine, token binding, attribution, and projection key-set (`tests/Unit/Mcp/`, Tasks 1–5); zero new failures vs a self-measured baseline
- [ ] Browser tests (Playwright MCP): `tests/e2e/spec-coverage/hermiq-ai-tooling.spec.ts` (Task 6)
- [ ] All tests pass (`composer test`)
- Newman/Postman: N/A — no OpenConnector HTTP endpoint is added; the MCP surface is served by OpenRegister's `/api/mcp`, and the delegated controller paths keep their existing collection coverage.

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` (Task 6)
- [ ] Screenshot — N/A for OpenConnector UI: the approval flow lives in Hermiq; replay results use the existing DeadLetters UI.

## i18n (company-wide hydra ADR-007)

- [ ] N/A — tool descriptions and staged-proposal fields are agent-facing/backend prose, not UI copy; approval-flow copy is Hermiq's. The two new action ids appear in the existing Action authorization admin UI, which renders ids verbatim like the existing rows.
