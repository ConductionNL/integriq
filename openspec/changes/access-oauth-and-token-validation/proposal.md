---
kind: code
depends_on: []
---

# Proposal: access-oauth-and-token-validation

## Summary

A consumer that signs its calls with keys from its own identity provider cannot reach an integriq endpoint today unless an administrator pastes a static public key into the consumer. This change lets integriq check a token against the issuer's published keys (JWKS), accept tokens from an outside OpenID Connect provider, hand out its own OAuth 2.0 tokens to consumers, tell a client where to get a token (protected-resource metadata), and limit a consumer to the endpoints and actions its scopes allow.

## Why

Five rows of integriq's capability matrix (`openspec/parity/capabilities.json`), all in the access area, decided `build` in the OpenSpec pass of 2026-09-27 (`openspec/parity/gap-decisions.json`).

| row | rating | what is missing |
|---|---|---|
| `integriq:acc-jwks` | partial, built | a consumer's JWT checked against its issuer's JWKS address |
| `integriq:acc-oidc` | no | consumers signing in through an outside OpenID Connect provider |
| `integriq:acc-protected-resource-metadata` | no | RFC 9728 metadata so a client finds its token endpoint by itself |
| `integriq:acc-oauth-server` | no | integriq issuing OAuth 2.0 tokens to consumers |
| `integriq:acc-scopes` | partial, built | scopes on a consumer, limiting it to endpoints and actions |

Demand and competitor cells, quoted from the matrix:

- `acc-jwks`: featureRequest https://github.com/apache/apisix/issues/12791, open since 2025-12-05 for `jwt-auth`. Five competitors rate yes. Tyk v5.15.0 `apidef/oas/security.go:160` "jwksURIs lists the issuer JWKS addresses". MuleSoft https://docs.mulesoft.com/gateway/latest/policies-included-jwt-validation.md "parameter jwksUrl: JWKS server URLs that contain the public keys for the signature validation". APISIX 3.18.0 `apisix/plugins/openid-connect.lua:376`, WSO2 v4.7.0 key manager JWKS URL, Frank!Framework v10.2.0 `ApiListener.java:581 setJwksURL`.
- `acc-oidc`: five competitors rate yes. MuleSoft https://docs.mulesoft.com/access-management/configure-client-management-openid-task.md "Configure an external OpenID Connect (OIDC) identity provider". Tyk v5.15.0 `apidef/oas/authentication.go:780`, APISIX 3.18.0 `openid-connect.lua:143` with discovery and introspection, WSO2 v4.7.0 `/key-managers`, Frank!Framework `OAuth2Authenticator.java:84`.
- `acc-protected-resource-metadata`: changelog https://tyk.io/docs/developer-support/release-notes/gateway (Tyk 5.13.0, RFC 9728). Four competitors rate yes. n8n 2.40.7 was driven on the lab: a webhook "answered 401 with WWW-Authenticate resource_metadata". MuleSoft https://docs.mulesoft.com/gateway/latest/policies-included-oauth-protected-resource-metadata.md. Tyk `gateway/mw_protected_resource.go:50`, WSO2 `McpMediator.java:300-331`.
- `acc-oauth-server`: three competitors rate yes. MuleSoft https://docs.mulesoft.com/oauth2-provider-module/latest/index.md "The OAuth2 Provider module enables a Mule runtime engine (Mule) app to be configured as an Authentication Manager". Tyk `gateway/server.go:1017-1019` serves `/oauth/token`, WSO2 resident key manager.
- `acc-scopes`: five competitors rate yes. Tyk `user/session.go:116-126` per-key access rights with path and methods, APISIX `consumer-restriction.lua:38`, MuleSoft https://docs.mulesoft.com/gateway/latest/policies-included-oauth-token-introspection.md "scopes and scopeValidationCriteria", WSO2 `/scopes`, Frank!Framework `ApiListener.java:459`.

## What integriq already has

- `AuthorizationService::authorizeJwt()` (`lib/Service/AuthorizationService.php:368`) finds the consumer by the token's `iss` (`findIssuer()`, `:132`) and builds a key set from one static `authorizationConfiguration.publicKey` (`getJWK()`, `:186`). It already pins the algorithm against the header (algorithm confusion guard).
- `LtiJwksResolverService` (`lib/Service/Lti/LtiJwksResolverService.php:128`) resolves a `kid` from a remote `jwks_uri` with a distributed cache and a rate-limited refetch, for LTI registrations only.
- `authorizeOAuth()` (`:561`) accepts a Nextcloud OAuth2 bearer token for a Nextcloud user. It does not issue tokens to machine consumers.
- The consumer schema (`lib/Settings/integriq_register.json`, `consumer`) carries `authorizationType`, `authorizationConfiguration`, `domains`, `ips`, `rateLimit` and `quota`. It has no scopes.
- An endpoint's authentication rule allowlists keys per endpoint (`EndpointService::processAuthenticationRule()`, `lib/Service/EndpointService.php:2948`).

## What this change builds

1. JWKS validation for a gateway consumer, reusing the LTI resolver's cache and refetch logic behind a shared class.
2. An OIDC issuer on a consumer: discovery from `.well-known/openid-configuration`, the issuer's JWKS, audience and required claims.
3. A client credentials token endpoint that issues short-lived signed JWTs to consumers, with the signing key held in OpenRegister's credential broker (ADR-064), and a JWKS for it.
4. RFC 9728 protected-resource metadata per protected endpoint, and a `WWW-Authenticate` header that points to it on a 401.
5. Scopes on a consumer, enforced per endpoint and method, and carried in the tokens integriq issues.

## Out of scope

- An authorization code flow with a login page for people. Integriq's consumers are systems; people sign in through Nextcloud.
- Token introspection for opaque tokens from outside providers. JWT access tokens only in this change.
- Several login methods on one endpoint and inbound mTLS. Both are in `access-consumer-credentials`.
