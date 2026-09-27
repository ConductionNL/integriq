---
kind: code
depends_on: []
---

# Proposal: gateway-mcp-proxy

## Summary

An organisation that runs a tool server for AI assistants (an MCP server) has to expose it directly, with its own keys and no shared log. This change lets integriq publish an outside MCP server as a gateway endpoint, so the same consumer keys, rate limits, quotas and call log that guard a REST API guard the tool server too, and the log names which tool was called.

## Why

Row `integriq:acc-mcp-gateway` (rated no, built none) of integriq's capability matrix, access area, decided `build` in the OpenSpec pass of 2026-09-27: four competitors rate yes.

- Changelog https://tyk.io/docs/developer-support/release-notes/gateway: Tyk 5.13.0 (2026-05-19) added an MCP gateway and 5.15.0 MCP proxies.
- Tyk v5.15.0 `gateway/server.go:951-955` "/tyk/mcps creates MCP proxies" and `gateway/mw_mcp_access_control.go:37` "checks per key MCP access rights after the normal auth, rate" limits.
- APISIX 3.18.0 `apisix/plugins/mcp-bridge.lua:36` "puts a stdio MCP server behind a route over SSE, so the route's key-auth, limit-count and logger plugins apply to it".
- MuleSoft https://docs.mulesoft.com/gateway/latest/policies-included-mcp-support.md "Adds MCP support to an Omni Gateway MCP server instance".
- WSO2 v4.7.0 `publisher-api.yaml:2808` `/mcp-servers` with MCP governance and analytics.

The matrix note: "Row plt-ai-tools covers integriq's own objects as tools; proxying other MCP servers is absent."

## How this sits with ADR-063

ADR-063 makes OpenRegister the one registry and server for the fleet's own MCP tools, derived from schemas, with Hermiq as the consumer. This change does not add tools to that catalogue. It puts an outside tool server behind integriq's gateway, the same way integriq already puts outside REST APIs behind it (ADR-067: external HTTP egress is integriq's plane). Whether Hermiq may call such a server is an agent whitelist decision in Hermiq.

## What integriq already has

- `EndpointService::handleSourceRequest()` (`lib/Service/EndpointService.php:2174`) proxies an endpoint of `targetType` `api` to a source, but always answers with a buffered `JSONResponse` built from the call log.
- Consumer authentication, rate limits and quotas apply to every endpoint (`processAuthenticationRule()`, `:2948`; consumer `rateLimit` and `quota`).
- The call log (`call_log`) records every outbound call with its request and response.

## What this change builds

1. `targetType` `mcp` on an endpoint, pointing at a source that is an MCP server over streamable HTTP.
2. A pass-through that keeps the `Mcp-Session-Id` and `MCP-Protocol-Version` headers and answers with the upstream's content type, including a `text/event-stream` answer.
3. JSON-RPC aware logging: each call log records the MCP method and, for `tools/call`, the tool name, so an administrator filters the log by tool.
4. Per-tool rate limits on the endpoint, on top of the consumer's limits.

## Out of scope

- Filtering the tool list a consumer sees (`integriq:acc-mcp-tool-filter`, deferred: one competitor).
- A stdio MCP server. Only streamable HTTP servers are proxied; a stdio server needs a bridge outside integriq.
- Registering outside tools in OpenRegister's MCP catalogue (ADR-063).
