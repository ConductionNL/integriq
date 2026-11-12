# Tasks: gateway-a2a-agent-card

Kind: code. Matrix row `integriq:acc-a2a-gateway`.

### Task 1: The a2a endpoint target with card rewriting
- **spec_ref**: openspec/changes/gateway-a2a-agent-card/specs/endpoint-runtime/spec.md#requirement-an-a2a-agents-card-points-at-the-gateway-req-a2a-001
- **files**: `lib/Service/EndpointService.php`, `lib/Service/A2aAgentCardRewriter.php`, `lib/Settings/integriq_register.json` (endpoint `targetType` enum), `tests/Unit/Service/A2aAgentCardRewriterTest.php`
- **acceptance_criteria**:
  - GIVEN an `a2a` endpoint WHEN the agent card is fetched through it THEN every agent address in the card is the endpoint address
  - GIVEN an authentication rule WHEN a call has no valid key THEN 401 and no upstream call
- [ ] Implement
- [ ] Test (PHPUnit)
