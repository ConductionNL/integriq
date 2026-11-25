# Tasks: gateway-graphql-and-streaming-protocols

Kind: code. Size M. Rows `integriq:gw-graphql`, `integriq:gw-protocols`.

## Implementation tasks

### Task 1: The graphql endpoint type
- **spec_ref**: `openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001`
- **files**: `lib/Service/Endpoint/GraphqlProxyHandler.php`, `lib/Service/EndpointService.php`, `lib/Settings/integriq_register.json` (endpoint GraphQL fields, call_log operation fields), `composer.json`
- **acceptance_criteria**:
  - GIVEN an allowlist of two operations WHEN a consumer sends a third THEN it gets 403 with a GraphQL error and the source is not called
  - GIVEN maxDepth 8 WHEN a query nests 12 deep THEN it is refused before the source
- [x] Implement
  - `GraphqlProxyHandler` (allowlist, `maxDepth`, introspection for administrators unless `introspection` is on, GET carries queries only, batches refused) over `GraphqlDocumentReader`, a lexer and parser of the executable grammar that reads the operation, its depth with fragments expanded and its root fields. `EndpointService` proxies a `graphql` endpoint like an `api` one after the check, and writes `graphqlOperationType` and `graphqlOperationName` onto the stored call log. Fields in `lib/Settings/register.d/gateway-graphql-and-streaming-protocols.json`.
  - Not `webonyx/graphql-php`: adding a dependency needs a `composer.lock` update, which this build may not commit. The reader parses (no pattern over the text, as D1 asks) and refuses what it cannot read; swapping in webonyx later changes only `GraphqlDocumentReader`.
- [x] Test (PHPUnit with fixture queries including aliases and fragments)
  - `tests/Unit/Service/Endpoint/GraphqlDocumentReaderTest.php`, `GraphqlProxyHandlerTest.php`.

### Task 2: Operation logging and per-operation limits
- **spec_ref**: `openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001`
- **files**: `lib/Service/Endpoint/GraphqlProxyHandler.php`, the call log page filters, the endpoint editor, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN calls of two operations WHEN the administrator filters the call log by operation name THEN only that operation's calls show
- [x] Implement
  - `operationLimits` on the endpoint (an operation name to `{requestsPerWindow, windowSeconds}`), counted per endpoint, operation and consumer (or client address) by `GraphqlOperationLimiter` with the limiter consumer rate limits use; past it a GraphQL error with 429 before the source is called. The Endpoint logs page has a GraphQL operation filter (options read from the logged operation names) and an Operation column; the endpoint editor shows the GraphQL fields for a graphql endpoint and the stream fields for a stream endpoint. Strings in `l10n/en.json`, `l10n/nl.json` and the browser catalogues. Seed: `graphql/example` (no source chosen, so it answers nothing) and `ws/meldingen`.
- [x] Test (PHPUnit; Playwright for the filter)
  - 2026-11-25 live (sandbox Nextcloud 34.0.6 + MariaDB, OpenRegister main 43ba16d9): Playwright `tests/e2e/graphql-call-log.spec.ts` 2/2 (the operation filter offers the seeded operations and narrows the list; the URL filter lists only that operation's calls with durations). The spec matched the option by accessible name, which NcSelect splits over two spans; it now matches by text. PHPUnit `GraphqlProxyHandlerTest`, `GraphqlDocumentReaderTest`, `GraphqlOperationLimiterTest` green.
  - PHPUnit done: `tests/Unit/Service/Endpoint/GraphqlOperationLimiterTest.php`. Playwright written, `tests/e2e/graphql-call-log.spec.ts` (filter through the address and through the control); left unticked until it has run against a live instance.

### Task 3: The connection authorization route and the stream endpoint type
- **spec_ref**: `openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002`
- **files**: `lib/Controller/GatewayAuthorizeController.php`, `appinfo/routes.php`, `lib/Settings/integriq_register.json` (endpoint `stream` fields)
- **acceptance_criteria**:
  - GIVEN a stream endpoint for websocket WHEN the server asks with a valid key THEN it gets 200 with the consumer uuid, and with a wrong server secret it gets 401
- [x] Implement
  - `GET|POST /api/gateway/authorize` (`GatewayAuthorizeController`, public, called by the server): GET for nginx `auth_request`, POST for a broker plugin. `GatewayConnectionAuthorizer` checks the server secret first (`GatewayServerSecret`: app config holds only a credential broker id, `gateway_authorize_credential`, and its organisation; nothing configured refuses every call), resolves the client's key or `Bearer` JWT to a consumer, finds the `stream` endpoint whose `streamProtocol` and `streamRoute` cover the path or MQTT topic (`StreamRouteMatcher`), checks `allowedConsumers` and `requiredScopes`, applies `connectionRateLimit`, writes one call log row with `protocol` and `connectionId`, and answers 200 with `X-Integriq-Consumer`, 401 or 429. Endpoint `stream` fields and call log fields in `lib/Settings/register.d/gateway-graphql-and-streaming-protocols.json`; the route in `openapi.json`.
- [x] Test (PHPUnit; Newman for the route)
  - 2026-11-25 live, same instance: Newman folder "18 — Gateway connection authorization" 3 requests, 9/9 (no secret, a wrong secret and a broker POST with a wrong secret are 401 JSON refusals naming no consumer); PHPUnit `tests/Unit/Service/Gateway` + `GatewayAuthorizeControllerTest` 36/36.
  - PHPUnit done: `StreamRouteMatcherTest`, `GatewayServerSecretTest`, `GatewayConnectionAuthorizerTest`, `GatewayAuthorizeControllerTest`. Newman written: folder 18 of `tests/postman/integriq.postman_collection.json` (the refusals that need no configured secret); left unticked until it has run.

### Task 4: Server and broker configurations, tested
- **spec_ref**: `openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-websocket-grpc-and-mqtt-traffic-reaches-the-upstream-through-a-documented-server-req-gqlp-003`
- **files**: `docs/gateway/streaming/nginx-websocket-grpc.conf`, `docs/gateway/streaming/mosquitto.conf`, `docs/gateway/streaming.md`
- **acceptance_criteria**:
  - GIVEN the nginx configuration WHEN a WebSocket client connects with and without a key THEN only the keyed client connects and the call log shows one connection
- [ ] Implement
  - Not built in this pass: the configurations are only worth shipping once walked against a real nginx and Mosquitto, which this build had no access to. The route they call is built (task 3).
- [ ] Test (walked once against nginx and Mosquitto in the dev compose, result recorded in the PR)

## Verification
- [x] `openspec validate gateway-graphql-and-streaming-protocols --type change --strict` passes (openspec 2.2.0, 25 Nov 2026)
- [x] PHPUnit and Newman run, exit codes read
  - 2026-11-25: PHPUnit exit 0 (Gateway 36/36, GraphQL handler/reader/limiter green), Newman exit 0 (9/9), Playwright 2/2. Task 4 (nginx and Mosquitto configurations) is not built, so its walk is not part of this run.
- [ ] `webonyx/graphql-php` passes `composer audit` and the licence gate
