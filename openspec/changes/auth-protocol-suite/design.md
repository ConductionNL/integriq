# Design — openconnector: Authentication Protocol Suite

## Context

openconnector synchronizes data from dozens of external systems: government APIs (Haal Centraal, StUF, DSO), SaaS platforms (Microsoft, Google, Slack), and custom IdPs. Each integration requires authentication, but today every adapter bakes its protocol inline. The suite consolidates OAuth2, JWT, mTLS, SAML2, and OIDC into a single AuthProfile entity that adapters reference once and never touch token plumbing again.

This spec depends on openregister's schema system, KeyVault secrets handler, archival annotations, and openconnector's CallLog observability layer (all existing).

## Goals

- Eliminate per-adapter authentication reimplementation by providing a unified AuthProfile abstraction
- Provide automatic token caching with distributed (Redis) consistency for high-frequency sync (e.g. BRP queries at 1000 req/min)
- Enable proactive certificate rotation for mTLS without downtime and alert operators before expiry
- Create an auditable compliance trail of every credential issue, refresh, rotation, and access
- Enforce secret hygiene: no plaintext at rest, no exposure in logs or APIs, KeyVault-backed references only
- Support both machine-to-machine (OAuth2 cc, mTLS) and user-delegated (OAuth2 ac+PKCE, OIDC) flows without adding per-flow infrastructure

## Non-Goals

- Building a full identity/federation platform (the suite consumes external IdPs, doesn't replace them)
- Generic credential storage for non-auth purposes (limited to auth keys/secrets)
- Real-time certificate revocation checking (daily proactive rotation is sufficient per Dutch standards)
- Single sign-on provisioning (SCIM, just-in-time provisioning — scope for separate spec)

## Decisions

### Decision 1 — AuthProfile schema lives in openregister, referenced by adapters

openconnector adapters today hardcode secrets inline or pass them per-request. AuthProfile moves the declaration to openregister so adapters declare `"auth_profile": "oauth2-haal-centraal"` once and the runtime resolves credentials.

**Decision**: AuthProfile is an openregister schema (owned by `openconnector` app, shared by all consumers). Sources declare a default AuthProfile; adapters can override per-endpoint via context. The AuthProfile schema includes all fields from the context-brief data model: id, slug, type, issuer_url, client_id, client_secret_ref, scopes[], audience, token_endpoint, authorization_endpoint, jwks_uri, key_id, signing_algorithm, cert_pem_ref, key_pem_ref, ca_bundle_ref, refresh_rotation_enabled, token_cache_ttl_seconds, created, updated, version.

**Why**: centralized, auditable, versioned. Eliminates cognitive overhead of per-adapter secret management.

### Decision 2 — Token cache is Redis only, not database

Tokens are transient; database storage adds I/O overhead and couples cache to a single database node. Redis allows distributed caching across a clustered openconnector deployment and is already present in the stack.

**Decision**: token cache uses Redis keyed by `oc:token:{profile_id}:{subject?}`. TTL = min(issuer_expires_in − 60s, profile.token_cache_ttl_seconds). Cache key includes optional subject to support user-delegated flows where the same profile may cache different tokens for different users (e.g. OAuth2 authorization_code on behalf of each citizen).

**Why**: low latency, no DB contention, multi-instance consistency, standard Redis eviction.

### Decision 3 — Key material never lives plaintext in database

secrets (client_secret, private keys, certificates, CAs) MUST be encrypted or external. KeyVault is the standard Conduction secrets backend.

**Decision**: AuthProfile stores only `*_ref` pointers (e.g. `client_secret_ref: "oc:oauth2:haal-centraal:secret"`) to KeyVault entries. Plaintext resolution happens only inside the openconnector container at runtime. Any ref resolution is logged as an `access` AuthEvent so compliance teams can audit who fetched which secret.

**Why**: meets BIO/NEN 7510 encryption at rest; audit trail; ops cannot accidentally dump a config file containing secrets.

### Decision 4 — PKCE is mandatory for authorization_code flows

authorization_code flows over HTTPS can still leak the code via browser history, referrer headers, or access logs. PKCE (RFC 7636) mitigates this by binding the code exchange to the client's proof.

**Decision**: AuthProfile type `oauth2_ac` REQUIRES `code_challenge_method=S256` (SHA256). The suite rejects any authorization_code profile lacking PKCE at config-validation time.

**Why**: prevents code interception attacks; RFC 7636 §4.1 explicit requirement for public clients; government APIs (Haal Centraal) increasingly mandate PKCE.

### Decision 5 — Refresh-token rotation is opt-in per profile

Some issuers rotate refresh tokens on every exchange; others reissue the same token. Rotation adds complexity (atomic cache updates, invalidating old tokens); it must be safe to skip if the issuer doesn't rotate.

**Decision**: AuthProfile includes `refresh_rotation_enabled: bool` (default false). When true, refresh exchanges atomically replace both access_token and refresh_token in cache, increment a rotation_counter, and log a `refresh` AuthEvent with the counter. When false, only access_token is replaced.

**Why**: accommodates issuer diversity; rotation complexity is opt-in for environments that need it (e.g. government compliance); non-rotating issuers get simpler codepath.

### Decision 6 — Concurrent-issuance dedup prevents token-acquisition stampedes

If a cached token expires and 100 requests hit simultaneously, all 100 will race to fetch a new token from the issuer. A Redis lock serializes acquisition so the issuer sees exactly one request.

**Decision**: when a token expires, acquire a Redis lock `oc:token:lock:{profile_id}` (TTL 10s) before issuing. The first acquirer fetches a new token and caches it; the remaining 99 await the lock release (timeout 5s) and read from cache. If the first acquirer fails or exceeds 5s, the remaining 99 timeout and attempt their own acquisition (each trying to acquire the same lock, cascading safely).

**Why**: RFC 6749 §3.2 issuer protection; prevents issuer DOS from token-cache cache-miss thundering herds; observed in high-frequency BRP queries.

### Decision 7 — Error codes are typed, not generic

When a token refresh fails, is the issuer temporarily unavailable? Is the refresh token revoked? Is the client_secret wrong? Generic "auth failed" logs can't answer.

**Decision**: AuthEvent includes an `error_code` enum: `TokenExpired`, `RefreshDenied`, `CertExpired`, `JWKSMismatch`, `AssertionReplay`, `DiscoveryFailed`, `ValidationFailed`, `NoValidCredential`. Adapter code can inspect the error_code to decide retry vs. abandon.

**Why**: operations can distinguish permanent faults (wrong client_secret) from transient faults (issuer temporarily down) and choose retry policy; compliance audits can slice failures by root cause.

### Decision 8 — Certificate rotation is proactive, not reactive

Discovering a cert expired only when TLS fails is a page-at-2am scenario. Proactive daily rotation checks 30-day window and initiates rotation before expiry.

**Decision**: openconnector runs a daily cron job (`CertRotationCron`) that:
- Fetches all AuthProfiles of type `mtls`
- For each cert, checks notAfter
- If notAfter ≤ 30 days: triggers rotation backend (ACME, internal CA plugin, manual ticket webhook)
- If notAfter ≤ 7 days: raises P1 alert
- Updates KeyVault `cert_pem_ref` once rotation backend returns new cert
- Emits `rotate` AuthEvent with old_cert_subject, new_cert_subject, rotation_duration_ms

**Why**: eliminates surprise cert expiries; alerts give ops time to respond; zero-downtime rotation because the cache key is the AuthProfile ID, not the cert; new requests pick up the new cert immediately.

### Decision 9 — SAML metadata caching with signature validation

IdP metadata can change (signing cert rotation, endpoint updates). Caching without validation defeats the purpose; fetching on every request kills performance.

**Decision**: cache SAML IdP metadata in Redis with 24-hour TTL. Cache key is the metadata URL. Daily cron (`SAMLMetadataRefreshCron`) fetches and validates the signature against the IdP's signing cert (from the previous metadata or a static trust anchor configured in AuthProfile). If signature is invalid, keep the previous metadata and raise P2 alert. Parse endpoints and signing certs, emit `saml.metadata.refreshed` event on success.

**Why**: balance freshness and performance; signature validation prevents MITM modification; audit trail of metadata changes.

### Decision 10 — JWKS caching with kid-miss refresh

Some issuers rotate signing keys frequently. If a JWT arrives with a `kid` not in the cached JWKS, should we reject or refresh the cache?

**Decision**: cache JWKS in Redis by `(jwks_uri, etag)` with 1-hour TTL. When a JWT presents a `kid` not in the cache, refresh the JWKS once per minute (rate-limit to prevent thundering herds). If the `kid` is still missing after refresh, reject with `JWKSMismatch` error. HTTP cache-control headers from the JWKS endpoint override the 1-hour default.

**Why**: supports key rotation; prevents cache-invalidation stampedes; rate-limit allows the issuer time to propagate the new key.

## Seed Data

When openconnector installs with this spec, administrators need example AuthProfiles to understand the feature. The register template includes 3-5 seed AuthProfiles per type:

### OAuth2 Client Credentials (Haal Centraal BRP example)

```json
{
  "@self": {
    "register": "openconnector",
    "schema": "AuthProfile",
    "slug": "oauth2-haal-centraal-brp"
  },
  "id": "{{uuid}}",
  "type": "oauth2_cc",
  "issuer_url": "https://dev-inzage.haalcentraal.nl",
  "client_id": "example-gemeente-001",
  "client_secret_ref": "oc:oauth2:haal-centraal:secret",
  "scopes": ["brp:read"],
  "audience": "https://dev-inzage.haalcentraal.nl",
  "token_endpoint": "https://dev-inzage.haalcentraal.nl/oauth/token",
  "refresh_rotation_enabled": false,
  "token_cache_ttl_seconds": 3600,
  "created": "{{now}}",
  "updated": "{{now}}",
  "version": 1
}
```

### OAuth2 Authorization Code with PKCE (Microsoft Graph example)

```json
{
  "@self": {
    "register": "openconnector",
    "schema": "AuthProfile",
    "slug": "oauth2-msgraph-delegated"
  },
  "id": "{{uuid}}",
  "type": "oauth2_ac",
  "issuer_url": "https://login.microsoftonline.com/common",
  "client_id": "example-app-id-{{uuid}}",
  "client_secret_ref": "oc:oauth2:msgraph:secret",
  "scopes": ["User.Read", "Mail.Read"],
  "redirect_uri": "https://openconnector.gemeente.local/auth/callback",
  "token_endpoint": "https://login.microsoftonline.com/common/oauth2/v2.0/token",
  "authorization_endpoint": "https://login.microsoftonline.com/common/oauth2/v2.0/authorize",
  "refresh_rotation_enabled": true,
  "token_cache_ttl_seconds": 3600,
  "created": "{{now}}",
  "updated": "{{now}}",
  "version": 1
}
```

### mTLS (PKI-Overheid example)

```json
{
  "@self": {
    "register": "openconnector",
    "schema": "AuthProfile",
    "slug": "mtls-pki-overheid"
  },
  "id": "{{uuid}}",
  "type": "mtls",
  "cert_pem_ref": "oc:mtls:pki-overheid:cert",
  "key_pem_ref": "oc:mtls:pki-overheid:key",
  "ca_bundle_ref": "oc:mtls:pki-overheid:ca",
  "created": "{{now}}",
  "updated": "{{now}}",
  "version": 1
}
```

### OIDC (Custom IdP example)

```json
{
  "@self": {
    "register": "openconnector",
    "schema": "AuthProfile",
    "slug": "oidc-custom-idp"
  },
  "id": "{{uuid}}",
  "issuer_url": "https://idp.gemeente.local",
  "client_id": "openconnector-client",
  "client_secret_ref": "oc:oidc:custom-idp:secret",
  "scopes": ["openid", "profile"],
  "token_cache_ttl_seconds": 3600,
  "created": "{{now}}",
  "updated": "{{now}}",
  "version": 1
}
```

### JWT Bearer (eIDAS example)

```json
{
  "@self": {
    "register": "openconnector",
    "schema": "AuthProfile",
    "slug": "jwt-eidas-signing"
  },
  "id": "{{uuid}}",
  "type": "jwt",
  "key_id": "oc:signing:eidas:2026",
  "signing_algorithm": "ES256",
  "key_pem_ref": "oc:jwt:eidas:key",
  "audience": "https://signing-service.eidas.eu",
  "created": "{{now}}",
  "updated": "{{now}}",
  "version": 1
}
```

## Open Questions

- **Issuance timeout policy**: if the token endpoint is slow or unresponsive, how long should a request wait before timeout/retry? Recommend 5s per RFC 3986, but this should be configurable per AuthProfile.
- **Credential refresh on profile update**: when an admin changes a profile (e.g. narrowing scopes), should in-flight requests complete with old credentials or should they block until credential re-acquisition? Recommend: emit `profile.changed` event and let adapters decide (most will re-acquire cleanly, some may want to fail fast).
- **Multi-tenant token isolation**: in a multi-tenant openconnector deployment, should each tenant's tokens be isolated in Redis by tenant ID? Currently the cache key is profile-id only. Recommend: add optional `tenant_id` to the cache key if multi-tenancy is enabled.
- **Key rotation discovery**: should AuthProfile support a `jwks_uri` polling interval separate from the default 1-hour JWKS cache TTL? Some issuers publish rotation schedules in their metadata.
