# endpoint-runtime Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- gateway-graphql-and-streaming-protocols

## Purpose

GraphQL traffic is governed per operation, and WebSocket, gRPC and MQTT connections are allowed or refused by integriq while the web server or broker carries them. Rows `integriq:gw-graphql` and `integriq:gw-protocols`.

## ADDED Requirements

### Requirement: A GraphQL API is proxied with operation-aware policies (REQ-GQLP-001)

Integriq MUST offer an endpoint `targetType` of `graphql` that forwards GraphQL over HTTP to a source. It MUST parse the request to find the operation type and name, MUST refuse an operation outside a configured allowlist or deeper than a configured depth before calling the source, MUST apply per-operation rate limits per consumer, and MUST record the operation in the call log.

#### Scenario: an unlisted operation never reaches the source
- GIVEN a `graphql` endpoint that allows only `zaakDetails` and `zakenLijst`
- WHEN a consumer sends a mutation named `verwijderZaak`
- THEN the answer is 403 with a GraphQL error, and the source receives nothing
- @e2e exclude protocol handling; covered by PHPUnit

#### Scenario: an administrator finds the slow operation
- GIVEN calls of two operations through one endpoint
- WHEN the administrator filters the call log by operation `zakenLijst`
- THEN only those calls are listed with their durations
- e2e: `tests/e2e/graphql-call-log.spec.ts`

### Requirement: A web server or broker asks integriq who may connect (REQ-GQLP-002)

Integriq MUST serve a connection authorization route that a web server or MQTT broker calls before it opens a WebSocket, gRPC or MQTT connection. The route MUST accept only calls carrying the configured server secret, MUST resolve the client's credential to a consumer, MUST check the scopes of the matching stream endpoint and a connection rate limit, MUST log the connection, and MUST answer 200 with the consumer id or refuse with 401 or 429.

#### Scenario: a WebSocket client without a key is refused
- GIVEN nginx configured with the documented `auth_request` to integriq and a stream endpoint `ws/meldingen`
- WHEN one client connects with a valid key and another without
- THEN only the first connection opens, and the call log shows one allowed and one refused connection
- @e2e exclude needs a real web server; walked once against nginx in the dev compose
