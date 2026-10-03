# endpoint-runtime Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- gateway-mcp-proxy

## Purpose

An outside MCP tool server sits behind integriq's gateway with the same keys, limits and logs as an API. Row `integriq:acc-mcp-gateway`.

## ADDED Requirements

### Requirement: An outside MCP server is published as a gateway endpoint (REQ-MCPX-001)

Integriq MUST offer an endpoint `targetType` of `mcp` that proxies MCP streamable HTTP to a source. It MUST apply the endpoint's consumer authentication, rate limit and quota before forwarding. It MUST keep the `Mcp-Session-Id` and `MCP-Protocol-Version` headers in both directions, MUST answer with the upstream's status and content type, and MUST relay a `text/event-stream` answer event by event up to a configured maximum duration.

#### Scenario: an assistant reaches a tool server only through the gateway
- GIVEN an endpoint `mcp/documents` of type `mcp` and a consumer with an API key
- WHEN the assistant sends `initialize` and then `tools/call` with the key
- THEN both reach the tool server with the same session id, and a call without a key gets 401
- @e2e exclude protocol proxying has no browser surface; covered by PHPUnit against a fixture MCP server

### Requirement: The call log names the MCP tool (REQ-MCPX-002)

For every call through an `mcp` endpoint integriq MUST record the JSON-RPC method and, for `tools/call`, the tool name in the call log, and the call log page MUST filter on them. Tool arguments MUST NOT be stored unless the endpoint enables it.

#### Scenario: an administrator finds every call to one tool
- GIVEN calls to the tools `search` and `fetch` through one endpoint
- WHEN the administrator filters the call log by tool `search`
- THEN only the `search` calls are listed, each without its arguments
- e2e: `tests/e2e/mcp-call-log.spec.ts`

### Requirement: A tool can have its own rate limit (REQ-MCPX-003)

Integriq MUST let an administrator set a rate limit per tool on an `mcp` endpoint, counted per consumer. A call over the limit MUST get HTTP 429 with a JSON-RPC error that names the tool.

#### Scenario: an expensive tool is limited on its own
- GIVEN a limit of 10 calls a minute on tool `search`
- WHEN a consumer calls `search` an eleventh time within a minute
- THEN it gets 429 with a JSON-RPC error naming `search`, and a call to `fetch` still passes
- @e2e exclude covered by PHPUnit
