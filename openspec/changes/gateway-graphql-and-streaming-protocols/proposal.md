---
kind: code
depends_on: [gateway-mcp-proxy]
---

# Proposal: gateway-graphql-and-streaming-protocols

## Summary

Integriq's gateway speaks plain HTTP request and response. A GraphQL API can be passed through as HTTP, but integriq cannot see which operation a caller runs, so it cannot limit or log per operation. WebSocket, gRPC and MQTT traffic cannot pass at all. This change adds a GraphQL endpoint type that understands operations, and a connection authorization route so the web server or an MQTT broker carries the long-lived traffic while integriq decides who may connect, counts it and logs it.

## Why

Two rows of integriq's capability matrix, gateway area (the core area), decided `build` in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `integriq:gw-graphql` | no | build: core area, four competitors yes |
| `integriq:gw-protocols` | no | build: core area, three competitors yes |

Competitor cells, quoted from the matrix:

- `gw-graphql`: MuleSoft https://docs.mulesoft.com/gateway/latest/index.md, Omni Gateway "supports the following protocols: HTTP, WebSocket, SOAP, gRPC, GraphQL". Tyk v5.15.0 `apidef/api_definitions.go:1297` GraphQL execution modes including `proxyOnly`. APISIX 3.18.0 `apisix/core/ctx.lua:97` "parses GraphQL bodies so routes can match graphql_operation and graphql_name". WSO2 v4.7.0 `publisher-api.yaml:5466` `/apis/import-graphql-schema`.
- `gw-protocols`: Tyk v5.15.0 `gateway/reverse_proxy.go:2032` "proxies WebSocket upgrades" and `:836` "h2c and HTTP/2 transport carry gRPC". APISIX 3.18.0 `apisix/schema_def.lua:503` upstream schemes grpc, tcp, kafka, `:638` `enable_websocket`, and `apisix/stream/plugins/mqtt-proxy.lua:32`. WSO2 v4.7.0 `publisher-api.yaml:14027` API types WS and WEBSUB.

The matrix evidence for `gw-protocols` also records a promise nobody kept: `event_subscription.url` once said "or AMQP/MQTT endpoint", and its own note at `lib/Settings/integriq_register.json:1032` says nothing reads it.

## What integriq already has

- Endpoint `targetType` `api` proxies HTTP to a source (`EndpointService::handleSourceRequest()`, `lib/Service/EndpointService.php:2174`) and answers with a buffered JSON response.
- Consumer authentication, rate limits, quotas and the call log apply to every endpoint.
- `gateway-mcp-proxy` adds relaying a streamed HTTP answer for a bounded time; this change reuses it for GraphQL subscriptions over server-sent events.

## What this change builds

1. `targetType` `graphql`: forwards GraphQL over HTTP, reads the operation type and name, logs them, and applies per-operation allowlists, depth limits and per-operation rate limits.
2. A connection authorization route for protocols PHP cannot carry: the web server (nginx `auth_request`, Apache `mod_auth_request` style) or an MQTT broker's HTTP authentication hook asks integriq whether a consumer may open a WebSocket, gRPC or MQTT connection. Integriq checks the key, the scopes and the limits, and logs the connection.
3. Documented web server and broker configurations for the three protocols, tested against real servers.

## Out of scope

- Serving a GraphQL schema over OpenRegister data. Whether registers get a GraphQL interface is OpenRegister's decision (ADR-022); this change proxies.
- Terminating WebSocket, gRPC or MQTT inside PHP. A Nextcloud PHP worker cannot hold these connections; the design explains why.
