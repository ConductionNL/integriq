# openconnector: Authentication Protocol Suite

## Why

openconnector integrates with dozens of external systems — government APIs (Haal Centraal BRP, BAG, KvK, StUF, DSO), SaaS platforms (Microsoft Graph, Google Workspace, Slack, Zoom, HubSpot), and custom IdPs. Every adapter reimplements authentication from scratch:

- **Scattered token plumbing**: OAuth2 client_credentials, authorization_code with PKCE, refresh-token flows, JWT bearer assertions, mTLS certificate handling, SAML2 assertions, OIDC discovery — each adapter bakes the protocol inline
- **Inconsistent error handling**: token expiries, refresh failures, certificate rotations are caught generically and logged as "auth failed" with no actionable detail; operators can't distinguish recoverable issuer hiccups from permanent configuration faults
- **Brittle secrets**: client secrets, private keys, certificates live in plaintext or are scattered across KeyVault without a consistent reference scheme
- **No observability**: token issuance, refresh, rotation, and failures are invisible; compliance teams have no auditable trail of who issued or rotated credentials
- **Per-adapter refresh semantics**: refresh-token rotation per RFC 6749 §10.4 is implemented inconsistently, sometimes leaking long-lived secrets, sometimes failing silently when an issuer changes its rotation policy
- **Certificate surprises**: mTLS certificate expiries are discovered only when downstream calls fail in production; no proactive rotation, no alerting

The suite consolidates all supported protocols — OAuth2 (client_credentials, authorization_code with PKCE, refresh_token), JWT (signed and encrypted), mTLS, SAML2, and OIDC — behind a single AuthProfile abstraction. Adapter authors declare "this source uses OAuth2 client_credentials with scope X" in configuration once, and the runtime resolves the AuthProfile, manages the lifecycle, and attaches credentials to every outbound request automatically.

## What Changes

### Core AuthProfile abstraction

1. **New entity: AuthProfile** (in openregister) with type (oauth2_cc | oauth2_ac | jwt | mtls | saml2 | oidc) and protocol-specific fields (client_id, token_endpoint, cert_pem_ref, jwks_uri, etc.). All key material referenced via opaque KeyVault pointers (client_secret_ref, cert_pem_ref, key_pem_ref, ca_bundle_ref).
2. **Token caching in Redis** with automatic expiry: `oc:token:{profile_id}:{subject?}` keyed by profile + optional subject, TTL = min(issuer_expires_in − 60s, profile.token_cache_ttl_seconds).
3. **Cryptographic key management**: client secrets, private keys, certificates stored in KeyVault, never plaintext in DB. `*_ref` pointers resolve only inside the openconnector container runtime; ref resolution is logged as an `access` AuthEvent.

### Protocol implementations

4. **OAuth2 client_credentials**: acquire token from token_endpoint, cache, reuse until expiry, log `issue` event.
5. **OAuth2 authorization_code with PKCE** (RFC 7636): generate code_verifier, compute code_challenge, persist server-side, submit during code exchange; flows missing PKCE are rejected at config-validation time.
6. **OAuth2 refresh-token rotation** (RFC 6749 §10.4): when access_token expires but refresh_token is valid, POST to token_endpoint with `grant_type=refresh_token`, atomically replace both tokens, increment rotation counter, invalidate old refresh_token.
7. **JWT bearer assertion (signed)** (RFC 7519): construct JWT with iss, sub, aud, exp, iat, jti, sign with configured key (RS256/ES256/EdDSA only; reject HS256 and `none` at config-validation time).
8. **mTLS with automated certificate rotation**: daily cron checks certificate notAfter; if within 30 days, trigger rotation backend (ACME, internal CA, manual ticket), update KeyVault refs, emit `rotate` AuthEvent; certs expiring within 7 days raise P1 alert; expired certs cause typed `CertExpired` error instead of generic TLS failure.
9. **SAML2 assertion handling**: validate signature against IdP's signing cert, validate Conditions, extract attributes per mapping, create session, log `issue` event; replayed assertion IDs within NotOnOrAfter window rejected.
10. **OIDC with discovery** (OpenID Connect Core + Discovery 1.0): fetch `<issuer>/.well-known/openid-configuration`, cache 24h, derive endpoints, validate issuer matches, reject if discovery fails.

### Observability & compliance

11. **CallLog integration**: every token issuance, refresh, failure, rotation is logged with event_type (issue|refresh|rotate|fail|revoke), outcome, duration_ms, error_code, correlation_id.
12. **Typed error codes**: `TokenExpired`, `RefreshDenied`, `CertExpired`, `JWKSMismatch`, `AssertionReplay` so operators distinguish recoverable from permanent faults.
13. **JWKS caching & rotation** (RFC 7517, 7518): cache `(jwks_uri, etag)` in Redis with 1h TTL; refresh proactively when a presented `kid` is not found; reject tokens with missing `kid` after refresh with `JWKSMismatch`.
14. **SAML metadata refresh** (daily cron): fetch metadata, validate signature, parse singleSignOnService endpoints and signing certs, atomically swap cache, emit event; staleness > 24h raises P2 alert.

### Adapter integration

15. **Per-adapter AuthProfile binding**: source declares default AuthProfile; adapter can override per-endpoint (passed via adapter call context). Override token cached separately, not mixed with source-level key.
16. **Profile change invalidation**: when AuthProfile is updated (e.g. client_secret rotated, scopes narrowed), invalidate all cached tokens under that profile, call issuer's revocation_endpoint (RFC 7009) for each valid refresh_token, emit `profile.changed` event so in-flight sync jobs acquire fresh credentials.
17. **Concurrent-issuance dedup**: when N parallel requests trigger token acquisition simultaneously, serialize via Redis lock (`oc:token:lock:{profile_id}`, TTL 10s); first acquirer fetches and caches, remaining N−1 await (max 5s) and reuse; issuer sees exactly one call instead of N.

### Standards compliance

18. **No plaintext at rest**: secret hygiene enforced; UI/API returns opaque `*_ref` and masked indicator (e.g. `***last4`), never plaintext. Only runtime resolver inside container accesses plaintext.
19. **NEN 7510 / BIO compliance**: audit trail of every credential issued, rotated, or accessed; rotation cadence per Dutch healthcare/government information security standards.
20. **RFC 8446 (TLS 1.3 minimum)** for mTLS, **NIST SP 800-63B** token lifetime guidelines, **OASIS SAML 2.0** assertion handling.

## Impact

- **openconnector adapters**: every Source/Adapter declares `auth_profile` reference instead of inline credentials; adapter code no longer touches token plumbing, certificate rotation, or secrets management
- **openregister**: AuthProfile schema lives in openregister `openconnector` register; KeyVault reference resolution via `secrets/{ref-id}` URI scheme
- **openzaak, openklant, valtimo, opentalk, docudesk, pipelinq**: all consume authprofile specs for mTLS (Haal Centraal), OIDC (document signing, multi-tenant IdP), JWT (eIDAS), OAuth2 (user delegation)
- **Hydra integration**: AuthProfile schema registered in `hydra/openspec/schemas/` so all apps reference the canonical shape; any new auth type automatically flows fleet-wide
- **Compliance**: auditable trail of every credential lifecycle event; operators can verify BIO/NEN 7510 compliance in a single pane of glass

## Dependencies

- **openregister**: AuthProfile schema + KeyVault secrets handler + archival annotation for token cache eviction
- **OpenRegister services**: `RegisterResolverService` (resolve schema slugs), `SecretsHandler` (resolve `*_ref` pointers)
- **Hydra**: AuthProfile schema canonical definition, optional integration-registry if auth discovery is needed per-app
- **Redis**: distributed token cache (already present in openconnector stack)
- **openconnector CallLog**: already exists; auth events are CallLog entries

## Standards Reference

- **RFC 6749** — OAuth 2.0 Authorization Framework
- **RFC 7636** — PKCE (Proof Key for Public Clients)
- **RFC 7519** — JSON Web Token (JWT)
- **RFC 7515 / 7516 / 7517 / 7518** — JWS / JWE / JWK / JWA
- **RFC 8252** — OAuth 2.0 for Native Apps
- **RFC 8446** — TLS 1.3
- **RFC 7009** — OAuth 2.0 Token Revocation
- **OpenID Connect Core 1.0, OpenID Connect Discovery 1.0**
- **SAML 2.0 Core, Bindings, Profiles** (OASIS)
- **NIST SP 800-63B** — Digital Identity Guidelines (token lifetimes)
- **NEN 7510** — Dutch healthcare information security (cert rotation)
- **BIO** — Baseline Informatiebeveiliging Overheid (key custody)

## Related Specs

- `openregister: openconnector-register-storage` — AuthProfile schema lives here
- `openregister: register-resolver-service` — slug-based schema/field lookup for protocol validation
- `openregister: archival-annotation` — token cache eviction policy
- `openconnector: CallLog` (existing) — audit trail backend
- `hydra: integration-registry` (optional) — per-app auth discovery
