# Design: gateway-graphql-and-streaming-protocols

Kind: code. Size M. A GraphQL handler in the endpoint runtime, a connection authorization controller, the endpoint schema, and documentation.

## Context at development 92f282bc

- `EndpointService` dispatches by `targetType` (`lib/Service/EndpointService.php:678`, `:744`); `handleSourceRequest()` (`:2174`) buffers the upstream answer into a `JSONResponse`.
- Authentication runs in `processAuthenticationRule()` (`:2948`), consumer rate limits and quotas after it.
- No code handles GraphQL, WebSocket, gRPC or MQTT (matrix rows `gw-graphql` and `gw-protocols`).

## D1. GraphQL is HTTP, so integriq proxies it and reads the operation

A `graphql` endpoint forwards POST (and GET with `query` in the query string) to its source. Before forwarding, integriq parses the document with a PHP GraphQL parser (`webonyx/graphql-php`, MIT, parse only, no execution) to find the operation type and name. The endpoint may set:

- `allowedOperations`: a list of operation names; others get a GraphQL error with HTTP 403.
- `maxDepth`: queries nested deeper are refused before they reach the source.
- `operationLimits`: per-operation rate limits, counted per consumer, with the limiter the consumer rate limit uses.
- `introspection`: allowed or refused (default refused for consumers, allowed for administrators).

The call log gains `graphqlOperationType` and `graphqlOperationName`. Subscriptions over server-sent events use the bounded stream relay from `gateway-mcp-proxy`.

Rejected: a regex over the query text. GraphQL allows aliases, fragments and comments that a regex misreads.

## D2. Long-lived protocols stay outside PHP

A Nextcloud request runs in a PHP worker that is released when the response ends. A WebSocket or MQTT connection lives for hours and a gRPC stream needs HTTP/2 trailers end to end, which PHP-FPM does not give. Holding them in PHP would pin one worker per connection. So the web server or broker carries the traffic, and integriq answers the one question it is good at: may this caller connect, and how often.

`POST /api/gateway/authorize` (public route, called by the server, not by the client) receives the protocol, the target route, the client's credential (header, query token, MQTT username and password, or the forwarded certificate), and the client address. Integriq resolves the consumer as for an HTTP endpoint, checks the scopes of a `stream` endpoint that describes the route, applies a connection rate limit, logs a call log entry with `protocol` and `connectionId`, and answers 200 with the consumer uuid in a header, or 401 or 429. The route is protected by a shared secret the server sends, held in the credential broker (ADR-064).

A `stream` endpoint type describes such a route for the admin screens: protocol (`websocket`, `grpc`, `mqtt`), the public path or topic filter, the upstream, and the consumers or scopes allowed. Integriq does not proxy it.

## D3. Configurations as tested documentation

`docs/gateway/streaming/` holds an nginx configuration for WebSocket and gRPC with `auth_request` to the authorize route, and a Mosquitto configuration using an HTTP authentication plugin against the same route. Each is walked once against a real server in the task's test.

## Declarative versus imperative

No lifecycle or notification behaviour. The call log gains fields; the checks are request-time code.

## Seed data

One disabled `graphql` endpoint `graphql/example` with `maxDepth` 8 and an allowlist of two operations, and one `stream` endpoint `ws/meldingen` of protocol `websocket`.

## Risks

- The authorize route becomes a hot path under many reconnects. Mitigation: a connection rate limit per consumer, and the server may cache a 200 for a few seconds per credential.
- Operators expect integriq to carry the stream. Mitigation: the endpoint editor for `stream` says in one sentence that the web server carries the traffic and links the configuration.
