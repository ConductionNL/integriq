# Design: access-oauth-and-token-validation

Kind: code. Size L. Five rows, one service: `AuthorizationService`, plus one new controller for the token endpoint and the metadata documents.

## Context at development 92f282bc

- `AuthorizationService::authorizeJwt()` (`lib/Service/AuthorizationService.php:368`) resolves the issuer by name, reads `authorizationConfiguration.publicKey` and `.algorithm`, and verifies with `getJWK()` (`:186`). It refuses a token whose header `alg` differs from the configured one.
- `LtiJwksResolverService::resolveKey()` (`lib/Service/Lti/LtiJwksResolverService.php:128`) caches a JWKS per registration in `integriq.lti.jwks`, rate-limits refetches on an unknown `kid`, and fetches through the outbound call machinery (`fetchJwks()`, `:198`).
- `LtiKeyService` (`lib/Service/Lti/LtiKeyService.php:159`) generates RSA key pairs and stores the PEM private key on the registration object as `privateKeySecret`, marked "plaintext pending encryption". That pattern is not repeated here, see D3.
- `EndpointService::processAuthenticationRule()` (`lib/Service/EndpointService.php:2948`) switches on the rule's `authentication.type`: `apikey`, `jwt` and `jwt-zgw`, `basic`, `oauth`, `nc-session`.
- The consumer editor offers `none`, `basic`, `bearer`, `apiKey`, `oauth2`, `jwt` (`src/modals/v2/consumerDraft.js:72`).

## D1. One JWKS resolver, two callers

The LTI resolver already solves the hard parts: cache per registration, not per URL, so two registrations sharing a `jwks_uri` cannot poison each other, and a rate-limited refetch when a `kid` is unknown. Extract it into `lib/Service/Jwks/JwksResolver.php` with the cache namespace as a parameter, and keep `LtiJwksResolverService` as a thin caller so LTI behaviour does not change. A second resolver would drift.

Rejected: calling the LTI service from the gateway path. Its cache keys and log lines say LTI, and its `registrationType` argument has no meaning for a consumer.

## D2. The consumer says how its tokens are checked

`authorizationConfiguration` gains a `keySource` of `static` (today's behaviour, the default), `jwks` (with `jwksUri`) or `oidc` (with `issuerUrl`, `audience` and optional `requiredClaims`). For `oidc` the discovery document gives the JWKS address and the issuer string the token must carry. `findIssuer()` keeps matching on the consumer name for `static`; for `jwks` and `oidc` it matches the `iss` claim against the configured issuer, so a consumer can be named for people.

The algorithm guard stays: the key's `alg` from the JWKS must match the header, and `none` and HMAC algorithms are refused for `jwks` and `oidc`, because a published key set is public by definition.

## D3. Integriq's own tokens, key in the broker

A consumer with `authorizationType` `client_credentials` gets a client id (its uuid) and a client secret. `POST /api/oauth/token` (grant type `client_credentials`, RFC 6749 section 4.4) checks the secret and returns a JWT access token signed with integriq's issuer key: `iss` the instance URL, `sub` the consumer uuid, `aud` the requested resource, `scope` the granted scopes, lifetime five minutes by default.

The private key is minted as an `organisation`-scope credential in OpenRegister's credential broker and referenced by `credentialRef` (ADR-064 decisions 1, 4 and 5). It never sits on an OR object, unlike `LtiKeyService`'s `privateKeySecret`. The public half is served at `/.well-known/integriq/jwks.json`, and `authorizeJwt()` treats integriq's own issuer as a `jwks` source, so one validation path serves both.

The client secret is stored hashed, shown once on creation (the reveal-once pattern belongs to `access-consumer-credentials`), and never returned.

Rejected: reusing Nextcloud's OAuth2 app. It issues tokens for Nextcloud users through an authorization code flow; a consumer is not a user.

## D4. Protected-resource metadata

For every endpoint whose authentication rule requires a token, `GET /.well-known/oauth-protected-resource/<endpoint path>` returns RFC 9728 metadata: `resource`, `authorization_servers` (integriq's own issuer, or the consumer-facing OIDC issuers the endpoint accepts), `scopes_supported` and `bearer_methods_supported`. A 401 from that endpoint carries `WWW-Authenticate: Bearer resource_metadata="<url>"`. The route is public and returns nothing for an endpoint that is not token-protected.

## D5. Scopes

A consumer gains `scopes`, an array of strings. An endpoint rule's authentication config gains `requiredScopes`, per method. Scope strings are free text with a recommended shape `<endpoint slug>:<read|write>`. The check runs after authentication in `processAuthenticationRule()`: a token's `scope` claim, or the consumer's stored scopes for API key and Basic callers, must include every required scope, else 403 with the missing scope named. An endpoint without `requiredScopes` behaves as today.

## Declarative versus imperative

No lifecycle, aggregation, notification or relation behaviour is added. The two schema edits are plain properties. The checks are request-time logic and stay in `AuthorizationService`, which is the ADR-031 exception for authorization.

## Seed data

- One seeded consumer `example-oidc-consumer` with `keySource: oidc`, `issuerUrl: https://login.example.nl/realms/gemeente`, `audience: integriq`, disabled by default.
- One seeded consumer `example-client-credentials` with scopes `["zaken:read"]` and no secret set.

## Risks

- A JWKS address that is slow or down blocks every call. Mitigation: cached keys serve until expiry, refetch is rate-limited, and a fetch failure returns 401 with a reason rather than hanging.
- Scopes added to an endpoint lock out consumers that have none. Mitigation: `requiredScopes` is opt-in per endpoint, and the endpoint page shows which consumers lack a required scope before saving.
