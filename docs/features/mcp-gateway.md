# MCP tool servers behind the gateway

Put an outside MCP tool server, the kind of server an AI assistant calls tools
on, behind integriq's gateway. The assistant then reaches the tool server only
through an integriq endpoint, under the same consumer keys, rate limits,
quotas and call log that guard a REST API, and the call log says which tool
was called.

Only MCP servers that speak **streamable HTTP** can be published this way. A
server that only runs over stdio needs a bridge outside integriq first.

## Publish a tool server

1. Add a **Source** for the tool server: its location (for example
   `https://tools.gemeente.example`) and the tool server's own credential, as for
   any API source. Integriq always uses the source's credential; the consumer's
   own key is never forwarded to the tool server.
2. Add an **Endpoint** with target type **mcp**, pick the source under **Tool
   server source** and give it a path, for example `mcp/documents`. Integriq
   relays to the same path on the source, so the source above serves
   `https://tools.gemeente.example/mcp/documents`.
3. Give the consumers that may use the tool server access to the endpoint, as
   for any endpoint. A call without a valid key gets 401 and never reaches the
   tool server.

A fresh install has a disabled example: the source **Example MCP tool server**
and the endpoint `mcp/example`. Point the source at a real tool server and
enable it to try it out.

## What the gateway passes on

- The JSON-RPC body, untouched.
- The headers `Accept`, `Content-Type`, `Mcp-Session-Id`, `MCP-Protocol-Version`
  and `Last-Event-ID`, so the tool server keeps its session with the assistant.
  The tool server's `Mcp-Session-Id` comes back to the assistant the same way.
- The tool server's own status code and content type.
- An event stream (`text/event-stream`) event by event, as the tool server
  sends it, until **Maximum stream duration** (60 seconds unless set), when
  integriq closes the stream.

Every open stream holds a web server worker until it ends, so keep the maximum
duration as short as the tools allow.

## A limit per tool

Under **Tool limits**, give an expensive tool its own limit: a number of calls
per number of seconds, for example 10 calls per 60 seconds for `search`. The
limit counts per consumer, on top of the consumer's own rate limit and quota. A
call over it gets HTTP 429 with a JSON-RPC error that names the tool
(`rate limit reached for tool search`, code -32000), so the assistant reads it as
a tool error; a call to another tool still passes.

## The call log

Each call through an mcp endpoint is an outbound call in **Sources › Logs**. Its
log names the JSON-RPC method (**MCP method**, such as Tool call or Tool list)
and, for a tool call, the tool (**MCP tool**).

- Filter on the method with the **MCP method** control.
- List the calls to one tool with the link `…/sources/logs?mcpTool=search`.

The log never stores the tool arguments, because they may carry personal data,
unless **Store tool arguments in the call log** is on for the endpoint. Then the
request body is kept after the call log's usual redaction.

## Not covered

- Filtering which tools a consumer sees in `tools/list`.
- MCP servers that only run over stdio.
- Adding an outside server's tools to OpenRegister's own MCP catalogue: that
  catalogue serves the fleet's own tools (ADR-063). Whether Hermiq may call an
  outside tool server is a decision in Hermiq's agent settings.
