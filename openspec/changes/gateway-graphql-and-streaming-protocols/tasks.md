# Tasks: gateway-graphql-and-streaming-protocols

Kind: code. Size M. Rows `integriq:gw-graphql`, `integriq:gw-protocols`.

## Implementation tasks

### Task 1: The graphql endpoint type
- **spec_ref**: `openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001`
- **files**: `lib/Service/Endpoint/GraphqlProxyHandler.php`, `lib/Service/EndpointService.php`, `lib/Settings/integriq_register.json` (endpoint GraphQL fields, call_log operation fields), `composer.json`
- **acceptance_criteria**:
  - GIVEN an allowlist of two operations WHEN a consumer sends a third THEN it gets 403 with a GraphQL error and the source is not called
  - GIVEN maxDepth 8 WHEN a query nests 12 deep THEN it is refused before the source
- [ ] Implement
- [ ] Test (PHPUnit with fixture queries including aliases and fragments)

### Task 2: Operation logging and per-operation limits
- **spec_ref**: `openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001`
- **files**: `lib/Service/Endpoint/GraphqlProxyHandler.php`, the call log page filters, the endpoint editor, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN calls of two operations WHEN the administrator filters the call log by operation name THEN only that operation's calls show
- [ ] Implement
- [ ] Test (PHPUnit; Playwright for the filter)

### Task 3: The connection authorization route and the stream endpoint type
- **spec_ref**: `openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002`
- **files**: `lib/Controller/GatewayAuthorizeController.php`, `appinfo/routes.php`, `lib/Settings/integriq_register.json` (endpoint `stream` fields)
- **acceptance_criteria**:
  - GIVEN a stream endpoint for websocket WHEN the server asks with a valid key THEN it gets 200 with the consumer uuid, and with a wrong server secret it gets 401
- [ ] Implement
- [ ] Test (PHPUnit; Newman for the route)

### Task 4: Server and broker configurations, tested
- **spec_ref**: `openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002`
- **files**: `docs/gateway/streaming/nginx-websocket-grpc.conf`, `docs/gateway/streaming/mosquitto.conf`, `docs/gateway/streaming.md`
- **acceptance_criteria**:
  - GIVEN the nginx configuration WHEN a WebSocket client connects with and without a key THEN only the keyed client connects and the call log shows one connection
- [ ] Implement
- [ ] Test (walked once against nginx and Mosquitto in the dev compose, result recorded in the PR)

## Verification
- [ ] `openspec validate gateway-graphql-and-streaming-protocols --type change --strict` passes
- [ ] PHPUnit and Newman run, exit codes read
- [ ] `webonyx/graphql-php` passes `composer audit` and the licence gate
