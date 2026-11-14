# Tasks: access-oauth-and-token-validation

Kind: code. Size L. Rows `integriq:acc-jwks`, `acc-oidc`, `acc-protected-resource-metadata`, `acc-oauth-server`, `acc-scopes`.

## Implementation tasks

### Task 1: Extract the JWKS resolver
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-consumer-token-is-checked-against-its-issuers-jwks-req-tokv-001`
- **files**: `lib/Service/Jwks/JwksResolver.php`, `lib/Service/Lti/LtiJwksResolverService.php`
- **acceptance_criteria**:
  - GIVEN an LTI registration WHEN a launch is verified THEN the result and the cache key namespace are unchanged
- [x] Implement
- [x] Test (existing LTI resolver tests pass unchanged; new unit tests for cache namespace and rate-limited refetch)

### Task 2: JWKS and OIDC key sources on a consumer
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-consumer-can-accept-tokens-from-an-outside-openid-connect-provider-req-tokv-002`
- **files**: `lib/Service/AuthorizationService.php`, `lib/Settings/integriq_register.json` (consumer `authorizationConfiguration` description), `src/modals/v2/consumerDraft.js`, the consumer editor
- **acceptance_criteria**:
  - GIVEN a consumer with keySource jwks WHEN a token signed by a key in that set arrives THEN the call passes
  - GIVEN a token with alg HS256 WHEN the consumer uses jwks THEN it is refused
- [x] Implement. The backend is `ConsumerKeySourceResolver`, the issuer lookup in `IntegriqConsumerSource` and the schema description. The consumer editor's JWT example now carries `keySource`, and its help text names the static, jwks and oidc key sources and the fields they read. The key source is set in the editor's configuration field rather than in separate inputs, which matches how the editor handles every other credential.
- [x] Test (PHPUnit with a local JWKS fixture, an unknown kid, an alg mismatch and a discovery document)

### Task 3: Client credentials token endpoint with a brokered key
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003`
- **files**: `lib/Controller/OAuthTokenController.php`, `lib/Service/OAuth/TokenIssuer.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a consumer with a client secret WHEN it posts grant_type client_credentials THEN it receives a signed JWT with its scopes
  - GIVEN the issuer key WHEN any object is read over the OpenRegister API THEN no private key material appears
- **status**: built.
  - `IssuerKeyService` mints an RSA key as an organisation-scope credential in OpenRegister's broker (default organisation, provider `generic-apikey`). Only the credentialRef, the organisation and the public key, as JWK and PEM, are kept in the app config. The key is published at `/.well-known/jwks.json`, and an admin creates or replaces it with `POST /api/oauth/issuer-key`.
  - `TokenIssuer` serves the `client_credentials` grant at `POST /api/oauth/token`, with HTTP Basic or form fields. The answer is a five-minute RS256 JWT with `iss` the instance, `sub` the consumer, `aud` the resource and `scope` the granted scopes, sent no-store, with RFC 6749 errors.
  - An admin sets a consumer's secret with `POST /api/consumers/{id}/client-secret`. The secret is shown once and only its password hash is stored, as `clientSecretHash` (consumer 1.2.0).
  - `IntegriqConsumerSource` accepts integriq's own tokens, finding the consumer by `sub` and checking against the published key only.
- [x] Implement
- [ ] Test (PHPUnit for issue and refuse; Newman for the token endpoint; an assertion that the key is a credentialRef). The PHPUnit part is done in `tests/Unit/Service/OAuth/TokenIssuerTest.php`, including that the private key reaches only the broker. Newman is not done.

### Task 4: Protected-resource metadata and the 401 header
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004`
- **files**: `lib/Controller/WellKnownController.php`, `lib/Service/EndpointService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a token-protected endpoint WHEN a client calls it without a token THEN the 401 names the metadata URL
- **status**: built.
  - `ProtectedResourceMetadata` builds the RFC 9728 document of an endpoint behind a `jwt` or `jwt-zgw` authentication rule: `resource`, `authorization_servers` (integriq's issuer), `scopes_supported` (every `requiredScopes` value) and `bearer_methods_supported`. `WellKnownController` serves it publicly at `/.well-known/oauth-protected-resource/{path}` and answers 404 for an endpoint that is not token-protected.
  - A call without a token to such an endpoint now gets 401 instead of 403, with `WWW-Authenticate: Bearer resource_metadata="…"`. A refused token gets the same header. Other authentication types keep their answers.
  - The file list named `lib/Controller/WellKnownController.php`, `lib/Service/EndpointService.php` and `appinfo/routes.php`. The document is built in the new `lib/Service/OAuth/ProtectedResourceMetadata.php`.
- [x] Implement
- [ ] Test (Newman: metadata document shape, 401 header, nothing for an open endpoint). PHPUnit covers the same three points in `tests/Unit/Service/OAuth/ProtectedResourceMetadataTest.php`. Newman is not done.

### Task 5: Scopes on consumers and endpoints
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005`
- **files**: `lib/Settings/integriq_register.json` (consumer `scopes`), `lib/Service/EndpointService.php`, the endpoint rule editor, the consumer editor, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an endpoint requiring zaken:write on POST WHEN a consumer with only zaken:read posts THEN it gets 403 naming zaken:write
- [x] Implement. The backend is consumer `scopes` and the `requiredScopes` check in `EndpointService`. The consumer editor has a Scopes chip field (`consumerDraft.js` carries `scopes`, and an emptied list is sent as `[]`). The rule editor's authentication form has read and write scope lists (`requiredScopes.js`), which write a per-method map. The new strings are in en and nl.
- [ ] Test. PHPUnit for the check is done, and vitest covers the draft (`consumerDraft.spec.js`) and the rule editor mapping (`requiredScopes.spec.js`). Playwright for editing scopes on a consumer is left.

### Task 6: Seed data and documentation
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-consumer-can-accept-tokens-from-an-outside-openid-connect-provider-req-tokv-002`
- **files**: `lib/Settings/integriq_seed_data.json`, `docs/`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the consumers page opens THEN the two example consumers are listed and disabled
- **status**: built. `integriq_seed_data.json` gains `example-oidc-consumer` (key source `oidc`, issuer `https://login.example.nl/realms/gemeente`, audience `integriq`) and `example-client-credentials` (scope `zaken:read`, no secret). The consumer schema has no on/off switch, so "disabled" here means neither can authenticate until an administrator sets a real issuer or a secret. The new docs page `docs/features/consumer-tokens.md` covers key sources, client credentials, metadata and scopes.
- [x] Implement
- [ ] Test (docs page walked once against the seeded consumers): needs a live instance.

## Verification
- [ ] `openspec validate access-oauth-and-token-validation --type change --strict` passes
- [ ] PHPUnit and Newman run, exit codes read
- [ ] No private key or client secret appears in any OR object read, asserted in a test
