# Tasks: gateway-mcp-proxy

Kind: code. Size M. Row `integriq:acc-mcp-gateway`.

## Implementation tasks

### Task 1: The mcp target type and header pass-through
- **spec_ref**: `openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001`
- **files**: `lib/Service/EndpointService.php`, `lib/Service/Endpoint/McpProxyHandler.php`, `lib/Settings/integriq_register.json` (endpoint `targetType` description)
- **acceptance_criteria**:
  - GIVEN an mcp endpoint WHEN a client sends initialize THEN the upstream's Mcp-Session-Id comes back and the next call carries it upstream
- [ ] Implement
- [ ] Test (PHPUnit against a local MCP fixture server for initialize, tools/list and tools/call)

### Task 2: Relay an event stream
- **spec_ref**: `openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001`
- **files**: `lib/Service/Endpoint/McpProxyHandler.php`, `lib/Http/StreamedProxyResponse.php`
- **acceptance_criteria**:
  - GIVEN an upstream that answers text/event-stream WHEN a client calls THEN events arrive as they are sent, and the stream closes at the maximum duration
- [ ] Implement
- [ ] Test (PHPUnit with a fixture that emits three events; a manual run with the MCP inspector)

### Task 3: Log the method and the tool
- **spec_ref**: `openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-the-call-log-names-the-mcp-tool-req-mcpx-002`
- **files**: `lib/Settings/integriq_register.json` (call_log `mcpMethod`, `mcpTool`), `lib/Service/Endpoint/McpProxyHandler.php`, the call log page filters
- **acceptance_criteria**:
  - GIVEN three tools/call requests for two tools WHEN the administrator filters the call log by one tool THEN only its calls show
- [ ] Implement
- [ ] Test (PHPUnit; Playwright for the filter)

### Task 4: Per-tool limits
- **spec_ref**: `openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-a-tool-can-have-its-own-rate-limit-req-mcpx-003`
- **files**: `lib/Service/Endpoint/McpProxyHandler.php`, the endpoint editor, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a limit of 10 a minute on search WHEN a consumer calls search an eleventh time within the minute THEN it gets 429 with a JSON-RPC error naming the tool
- [ ] Implement
- [ ] Test (PHPUnit on the limiter key and the error body)

### Task 5: Seed and documentation
- **spec_ref**: `openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001`
- **files**: `lib/Settings/integriq_seed_data.json`, `docs/`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the endpoints page opens THEN the disabled example MCP endpoint is listed
- [ ] Implement
- [ ] Test (docs walked once)

## Verification
- [ ] `openspec validate gateway-mcp-proxy --type change --strict` passes
- [ ] PHPUnit and Newman run, exit codes read
- [ ] An api endpoint's behaviour is unchanged, asserted by the existing endpoint tests
