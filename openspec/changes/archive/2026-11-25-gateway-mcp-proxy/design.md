# Design: gateway-mcp-proxy

Kind: code. Size M. `EndpointService`, the endpoint schema, the call log, and the endpoint editor.

## Context at development 92f282bc

- The endpoint schema (`lib/Settings/integriq_register.json`, endpoint) describes `targetType` as `register/schema`, `api`, `job`, `synchronization`, with no enum.
- `EndpointService` dispatches on `targetType` (`lib/Service/EndpointService.php:678` for register/schema, `:744` for api). `handleSourceRequest()` (`:2174`) reads the raw body, renders the upstream path, calls `CallService::call()` and returns `new JSONResponse($callLogData['response'], $statusCode)`.
- Authentication, consumer rate limit and quota run before dispatch for every endpoint.

## D1. A new target type, not a rule

MCP traffic is a different wire shape: a JSON-RPC body, session headers, and a response that may be an event stream. Adding `mcp` next to `api` keeps the api path unchanged and lets the editor show MCP-specific fields. Rejected: a rule on an `api` endpoint that rewrites the response. Rules run on decoded arrays, and an event stream is not one.

## D2. Streamable HTTP pass-through

For `mcp` the handler forwards POST, GET and DELETE to the source with the body untouched and these headers kept: `Mcp-Session-Id`, `MCP-Protocol-Version`, `Accept`, `Last-Event-ID`. It answers with the upstream status, content type and `Mcp-Session-Id`. A `text/event-stream` answer is relayed with a streamed response that flushes each event as it arrives, with a configurable maximum duration (default 60 seconds) after which integriq closes the stream. Authorization headers of the consumer are never forwarded; the source's own credential is used, as for `api`.

## D3. Logging by tool

The handler decodes the JSON-RPC request once. The call log gains `mcpMethod` and `mcpTool` (for `tools/call`, `params.name`). Arguments are not stored by default, because tool arguments may carry personal data; an endpoint flag stores them after the redaction the call log already applies.

## D4. Per-tool limits

The endpoint gains `mcpToolLimits`, a map of tool name to `{ limit, window }`. The check runs after the consumer's own limits, keyed by consumer and tool, using the same limiter as the consumer rate limit. A refused call answers with a JSON-RPC error `-32000` "rate limit reached for tool <name>" and HTTP 429, so an MCP client reads it as a tool error.

## Declarative versus imperative

No lifecycle, aggregation or notification behaviour. Request-time proxying stays in `EndpointService`.

## Seed data

One disabled source `example-mcp-server` (`https://mcp.example.nl/mcp`) and one disabled endpoint `mcp/example` of `targetType` `mcp` with a limit of 10 calls a minute on a tool named `search`.

## Risks

- Long streams tie up PHP workers. Mitigation: the maximum duration in D2, and the endpoint editor warns that streaming endpoints count against the web server's worker pool.
- A tool server that ignores its session header. Mitigation: integriq passes headers and does not invent sessions.
