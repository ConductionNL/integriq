# Tasks: gateway-mcp-proxy

Kind: code. Size M. Row `integriq:acc-mcp-gateway`.

## Implementation tasks

### Task 1: The mcp target type and header pass-through
- **spec_ref**: `openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001`
- **files**: `lib/Service/EndpointService.php`, `lib/Service/Endpoint/McpProxyHandler.php`, `lib/Settings/integriq_register.json` (endpoint `targetType` description)
- **acceptance_criteria**:
  - GIVEN an mcp endpoint WHEN a client sends initialize THEN the upstream's Mcp-Session-Id comes back and the next call carries it upstream
- [x] Implement
- [x] Test (PHPUnit against a local MCP fixture server for initialize, tools/list and tools/call)
  - Built: `McpProxyHandler` (forwarded headers `accept`, `content-type`, `mcp-session-id`, `mcp-protocol-version`, `last-event-id`; never the consumer's `Authorization` or cookies), dispatched from `EndpointService::handleMcpRequest()` after the consumer limits. The fixture is a CallService double that answers through the relay's own headers callback and sink (`McpProxyHandlerTest`, `EndpointServiceMcpTargetTest`).

### Task 2: Relay an event stream
- **spec_ref**: `openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001`
- **files**: `lib/Service/Endpoint/McpProxyHandler.php`, `lib/Http/StreamedProxyResponse.php`
- **acceptance_criteria**:
  - GIVEN an upstream that answers text/event-stream WHEN a client calls THEN events arrive as they are sent, and the stream closes at the maximum duration
- [x] Implement
- [x] Test (PHPUnit with a fixture that emits three events; a manual run with the MCP inspector)
  - Built: `CallService::relay()` streams into `McpRelayStream`, which flushes each chunk to the caller, and hands the upstream status and headers over before the first byte (`McpProxyResponse`, a callback response; placed in `lib/Service/Endpoint/` beside the handler rather than `lib/Http/`). The duration is the request timeout, `mcpMaxStreamSeconds` (default 60). PHPUnit covers three events and the relay (`CallServiceRelayTest`); the manual MCP inspector run needs a live tool server and is not done here.

### Task 3: Log the method and the tool
- **spec_ref**: `openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-the-call-log-names-the-mcp-tool-req-mcpx-002`
- **files**: `lib/Settings/integriq_register.json` (call_log `mcpMethod`, `mcpTool`), `lib/Service/Endpoint/McpProxyHandler.php`, the call log page filters
- **acceptance_criteria**:
  - GIVEN three tools/call requests for two tools WHEN the administrator filters the call log by one tool THEN only its calls show
- [x] Implement
- [x] Test (PHPUnit; Playwright for the filter)
  - Built: `McpProxyHandler::stamp()` writes `mcpMethod` and `mcpTool` on the call log (both facetable), and tool arguments stay out unless `mcpStoreArguments` is set. The source logs page shows an MCP tool column and an MCP method control. The logs page has no free-text control type, so a filter on one tool is the link `/sources/logs?mcpTool=<tool>`, which the page passes on as a filter; a text control for it belongs in nextcloud-vue's `logFilterControls`. PHPUnit in `McpProxyHandlerTest`. The Playwright run needs a live instance and is not done here.

### Task 4: Per-tool limits
- **spec_ref**: `openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-a-tool-can-have-its-own-rate-limit-req-mcpx-003`
- **files**: `lib/Service/Endpoint/McpProxyHandler.php`, the endpoint editor, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a limit of 10 a minute on search WHEN a consumer calls search an eleventh time within the minute THEN it gets 429 with a JSON-RPC error naming the tool
- [x] Implement
- [x] Test (PHPUnit on the limiter key and the error body)
  - Built: `McpProxyHandler::toolLimitRefusal()` counts `mcp-tool:<endpoint>:<consumer>:<tool>` with the consumer limiter, after the consumer's own limits; over the limit it answers 429 with a JSON-RPC error `-32000` that names the tool, never rewritten into a problem document. The endpoint editor gains `mcp` as a target type and `McpEndpointFields` (source, tool limits, stream duration with the worker-pool warning, the argument switch); en and nl strings.

### Task 5: Seed and documentation
- **spec_ref**: `openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001`
- **files**: `lib/Settings/integriq_seed_data.json`, `docs/`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the endpoints page opens THEN the disabled example MCP endpoint is listed
- [x] Implement
- [x] Test (docs walked once)
  - Built: the disabled source `example-mcp-server` and endpoint `mcp-example` (path `mcp/example`, `search` limited to 10 a minute) are seeded by the register fragment `register.d/gateway-mcp-proxy.json`, the way this repo seeds connector examples, rather than `integriq_seed_data.json`; `docs/features/mcp-gateway.md`, linked from the endpoints page and the feature index.

## Verification
- [ ] `openspec validate gateway-mcp-proxy --type change --strict` passes
- [ ] PHPUnit and Newman run, exit codes read
- [x] An api endpoint's behaviour is unchanged, asserted by the existing endpoint tests (the unit suite fails the same 7 cases as development, none of them endpoint tests; `EndpointServiceMcpTargetTest::testAnApiEndpointIsUnchanged`)
