# Tasks: access-oauth-and-token-validation

Kind: code. Size L. Rows `integriq:acc-jwks`, `acc-oidc`, `acc-protected-resource-metadata`, `acc-oauth-server`, `acc-scopes`.

## Implementation tasks

### Task 1: Extract the JWKS resolver
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-consumer-token-is-checked-against-its-issuers-jwks-req-tokv-001`
- **files**: `lib/Service/Jwks/JwksResolver.php`, `lib/Service/Lti/LtiJwksResolverService.php`
- **acceptance_criteria**:
  - GIVEN an LTI registration WHEN a launch is verified THEN the result and the cache key namespace are unchanged
- [ ] Implement
- [ ] Test (existing LTI resolver tests pass unchanged; new unit tests for cache namespace and rate-limited refetch)

### Task 2: JWKS and OIDC key sources on a consumer
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-consumer-can-accept-tokens-from-an-outside-openid-connect-provider-req-tokv-002`
- **files**: `lib/Service/AuthorizationService.php`, `lib/Settings/integriq_register.json` (consumer `authorizationConfiguration` description), `src/modals/v2/consumerDraft.js`, the consumer editor
- **acceptance_criteria**:
  - GIVEN a consumer with keySource jwks WHEN a token signed by a key in that set arrives THEN the call passes
  - GIVEN a token with alg HS256 WHEN the consumer uses jwks THEN it is refused
- [ ] Implement
- [ ] Test (PHPUnit with a local JWKS fixture, an unknown kid, an alg mismatch and a discovery document)

### Task 3: Client credentials token endpoint with a brokered key
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003`
- **files**: `lib/Controller/OAuthTokenController.php`, `lib/Service/OAuth/TokenIssuer.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a consumer with a client secret WHEN it posts grant_type client_credentials THEN it receives a signed JWT with its scopes
  - GIVEN the issuer key WHEN any object is read over the OpenRegister API THEN no private key material appears
- [ ] Implement
- [ ] Test (PHPUnit for issue and refuse; Newman for the token endpoint; an assertion that the key is a credentialRef)

### Task 4: Protected-resource metadata and the 401 header
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004`
- **files**: `lib/Controller/WellKnownController.php`, `lib/Service/EndpointService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a token-protected endpoint WHEN a client calls it without a token THEN the 401 names the metadata URL
- [ ] Implement
- [ ] Test (Newman: metadata document shape, 401 header, nothing for an open endpoint)

### Task 5: Scopes on consumers and endpoints
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005`
- **files**: `lib/Settings/integriq_register.json` (consumer `scopes`), `lib/Service/EndpointService.php`, the endpoint rule editor, the consumer editor, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an endpoint requiring zaken:write on POST WHEN a consumer with only zaken:read posts THEN it gets 403 naming zaken:write
- [ ] Implement
- [ ] Test (PHPUnit for the check; Playwright for editing scopes on a consumer)

### Task 6: Seed data and documentation
- **spec_ref**: `openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-consumer-can-accept-tokens-from-an-outside-openid-connect-provider-req-tokv-002`
- **files**: `lib/Settings/integriq_seed_data.json`, `docs/`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the consumers page opens THEN the two example consumers are listed and disabled
- [ ] Implement
- [ ] Test (docs page walked once against the seeded consumers)

## Verification
- [ ] `openspec validate access-oauth-and-token-validation --type change --strict` passes
- [ ] PHPUnit and Newman run, exit codes read
- [ ] No private key or client secret appears in any OR object read, asserted in a test
