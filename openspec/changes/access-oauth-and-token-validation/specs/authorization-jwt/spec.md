# authorization-jwt Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- access-oauth-and-token-validation

## Purpose

Consumers prove who they are with tokens from their own identity provider, or with tokens integriq issues, and a client can find out by itself where to get one. Rows `integriq:acc-jwks`, `integriq:acc-oidc`, `integriq:acc-oauth-server` and `integriq:acc-protected-resource-metadata`.

## ADDED Requirements

### Requirement: A consumer token is checked against its issuer's JWKS (REQ-TOKV-001)

Integriq MUST verify a consumer's JWT against the keys published at the consumer's configured JWKS address when the consumer's key source is `jwks`. It MUST cache the key set, MUST refetch at most once per configured interval when a token names an unknown `kid`, and MUST refuse a token whose header algorithm differs from the key's algorithm or is an HMAC algorithm.

#### Scenario: a consumer rotates its signing key without calling us
- GIVEN a consumer with key source `jwks` and a JWKS address that now publishes a new key
- WHEN the consumer calls a protected endpoint with a token signed by the new key
- THEN integriq fetches the key set once, finds the new `kid` and the call passes
- @e2e exclude token verification has no browser surface; covered by PHPUnit and Newman

#### Scenario: an HMAC token against a published key set is refused
- GIVEN a consumer with key source `jwks`
- WHEN a caller presents a token with header `alg` HS256
- THEN the endpoint answers 401 and the reason names the algorithm
- @e2e exclude covered by PHPUnit on AuthorizationService

### Requirement: A consumer can accept tokens from an outside OpenID Connect provider (REQ-TOKV-002)

Integriq MUST let an administrator set a consumer's key source to `oidc` with an issuer URL and an audience. It MUST read the provider's discovery document, MUST take the JWKS address and the issuer string from it, and MUST refuse a token whose `iss` or `aud` does not match or that lacks a configured required claim.

#### Scenario: an administrator connects a Keycloak realm
- GIVEN an administrator on the consumers page
- WHEN they set key source to OpenID Connect, enter the realm's issuer URL and audience `integriq`, and save
- THEN a token from that realm with audience `integriq` passes, and one with another audience gets 401
- e2e: `tests/e2e/consumer-oidc.spec.ts`

### Requirement: Integriq issues client credentials tokens to consumers (REQ-TOKV-003)

Integriq MUST serve an OAuth 2.0 token endpoint for the `client_credentials` grant. A consumer with a client secret MUST receive a signed JWT carrying its uuid as subject and its granted scopes. The signing key MUST be held in OpenRegister's credential broker and referenced by `credentialRef`. The public key MUST be published as a JWKS, and integriq MUST accept its own tokens on protected endpoints.

#### Scenario: a partner system gets a token and calls an endpoint
- GIVEN a consumer with a client secret and scope `zaken:read`
- WHEN the partner posts `grant_type=client_credentials` with its id and secret to `/api/oauth/token`
- THEN it receives a JWT that expires in five minutes, and a call with that token to an endpoint requiring `zaken:read` passes
- @e2e exclude machine-to-machine flow; covered by Newman

#### Scenario: the signing key never appears on an object
- GIVEN the token issuer has a signing key
- WHEN any integriq object is read over the OpenRegister object API
- THEN no private key material appears in the response
- @e2e exclude covered by PHPUnit

### Requirement: A protected endpoint publishes where to get a token (REQ-TOKV-004)

Integriq MUST serve RFC 9728 protected-resource metadata for every endpoint whose authentication requires a token, naming the authorization servers it accepts and the scopes it supports. A 401 from such an endpoint MUST carry a `WWW-Authenticate` header whose `resource_metadata` parameter points to that document.

#### Scenario: a client discovers the token endpoint from a 401
- GIVEN an endpoint that requires a token
- WHEN a client calls it without one
- THEN the answer is 401 with `WWW-Authenticate: Bearer resource_metadata="..."`, and that URL returns the issuer and the supported scopes
- @e2e exclude no browser surface; covered by Newman
