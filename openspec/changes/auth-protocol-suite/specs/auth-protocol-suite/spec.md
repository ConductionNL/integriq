# Capability — auth-protocol-suite

Authentication Protocol Suite provides a unified abstraction for OAuth2, JWT, mTLS, SAML2, and OIDC behind a single AuthProfile entity. Adapters declare an auth profile once and the runtime manages credentials, caching, refresh, rotation, and observability without per-adapter reimplementation.

## ADDED Requirements

### Requirement: OAuth2 client_credentials with cached tokens

Adapters configured with an OAuth2 client_credentials AuthProfile request tokens from the issuer, cache them in Redis with an explicit TTL, and reuse cached tokens until expiry.

#### Scenario: First request acquires and caches token

- **GIVEN** an AuthProfile of type `oauth2_cc` with valid `client_id`, `client_secret_ref`, `token_endpoint`, and `scopes`
- **WHEN** an adapter makes its first outbound request using that profile
- **THEN** openconnector POSTs to the `token_endpoint` with `grant_type=client_credentials&scope=...&client_id=...&client_secret=...` (secret resolved from KeyVault)
- **AND** the issuer returns `{ "access_token": "...", "expires_in": 3600, "token_type": "Bearer" }`
- **AND** openconnector caches the entry in Redis as `oc:token:{profile_id}` with value `{ access_token, expires_at, token_type, issued_at }` and TTL = min(3600 − 60, profile.token_cache_ttl_seconds)
- **AND** openconnector attaches `Authorization: Bearer {access_token}` to the adapter's outbound request
- **AND** a CallLog entry of type `issue` is created with profile_id, outcome=`success`, duration_ms, correlation_id.

#### Scenario: Cached token is reused

- **GIVEN** a valid (non-expired) token exists in Redis for an AuthProfile
- **WHEN** another adapter using the same profile makes an outbound request
- **THEN** openconnector reads the cached token and attaches it to the request without contacting the token_endpoint
- **AND** no `issue` CallLog entry is created (only a reference entry is logged with cache_hit=true).

#### Scenario: Expired token triggers refresh

- **GIVEN** a cached token whose expiry time has passed
- **WHEN** an adapter makes a request
- **THEN** openconnector deletes the expired cache entry and acquires a new token from the token_endpoint
- **AND** a CallLog entry of type `refresh` is created.

### Requirement: OAuth2 authorization_code with PKCE

The authorization_code flow for delegated user consent requires PKCE to bind code exchange to the client. openconnector MUST enforce PKCE presence at config-validation time.

#### Scenario: PKCE is generated and persisted

- **GIVEN** an AuthProfile of type `oauth2_ac` with `authorization_endpoint`, `token_endpoint`, and `client_id`
- **WHEN** the flow is initiated (e.g. user navigates to the authorization endpoint)
- **THEN** openconnector generates a cryptographically random `code_verifier` (43-128 chars per RFC 7636 §4.1)
- **AND** computes `code_challenge = BASE64URL(SHA256(code_verifier))`
- **AND** redirects the user to `{authorization_endpoint}?client_id=...&code_challenge=...&code_challenge_method=S256&state=...&redirect_uri=...`
- **AND** persists the `code_verifier` server-side keyed by `state` in Redis (keyed by `oc:pkce:{state}`, TTL 10 minutes)
- **AND** the `state` value is cryptographically random and securely stored.

#### Scenario: Code exchange submits PKCE proof

- **GIVEN** the user completes the IdP flow and is redirected back with `code` and `state`
- **WHEN** the callback handler exchanges the `code` for an access token
- **THEN** openconnector retrieves the `code_verifier` from `oc:pkce:{state}`
- **AND** POSTs to `token_endpoint` with `grant_type=authorization_code&code=...&code_verifier=...&client_id=...&client_secret=...&redirect_uri=...`
- **AND** the issuer validates `code_verifier` against the original `code_challenge` and returns the token
- **AND** the `code_verifier` is deleted from cache after use.

#### Scenario: PKCE is mandatory at config validation

- **GIVEN** an AuthProfile of type `oauth2_ac` is being created or updated
- **WHEN** the profile is saved
- **THEN** if `code_challenge_method` is absent or not `S256`, openconnector SHALL reject the profile with validation error `PKCE_REQUIRED`
- **AND** no flow can proceed without PKCE.

### Requirement: OAuth2 refresh-token rotation (RFC 6749 §10.4)

When a cached access token expires but a refresh token remains valid, acquire a new access token using the refresh token. If `refresh_rotation_enabled=true` on the profile, atomically replace both tokens.

#### Scenario: Refresh acquires new access token

- **GIVEN** an AuthProfile of type `oauth2_ac` with `refresh_rotation_enabled=true`, a cached entry with expired `access_token` but valid `refresh_token`
- **WHEN** an adapter requests a token
- **THEN** openconnector POSTs to the `token_endpoint` with `grant_type=refresh_token&refresh_token=...&client_id=...&client_secret=...`
- **AND** the issuer returns `{ "access_token": "...", "refresh_token": "...", "expires_in": 3600 }`
- **AND** openconnector atomically updates the cache entry: replaces `access_token`, `refresh_token`, `expires_at`, and increments `rotation_counter`
- **AND** the old `refresh_token` is invalidated (logged as `revoke` CallLog entry)
- **AND** a CallLog entry of type `refresh` is created with `rotation_counter` value.

#### Scenario: Issuer does not rotate refresh token

- **GIVEN** the issuer returns the same `refresh_token` in the response
- **WHEN** openconnector compares new vs. old refresh_token
- **THEN** openconnector logs a `refresh` CallLog with `rotation_skipped=true` and continues
- **AND** no error is raised.

#### Scenario: Rotation is disabled

- **GIVEN** an AuthProfile with `refresh_rotation_enabled=false`
- **WHEN** a token refresh occurs
- **THEN** openconnector replaces only the `access_token` in the cache
- **AND** the `refresh_token` is left unchanged.

### Requirement: JWT bearer assertion (signed)

Construct a JWT per RFC 7519, sign with a configured private key, and attach to requests. Reject insecure algorithms (HS256, `none`) at config-validation time.

#### Scenario: JWT is constructed with required claims

- **GIVEN** an AuthProfile of type `jwt` with `key_id`, `signing_algorithm` (RS256|ES256|EdDSA), key_pem_ref, and `audience`
- **WHEN** an adapter prepares an outbound request
- **THEN** openconnector constructs a JWT with the following claims:
  - `iss`: issuer URL from the AuthProfile
  - `sub`: subject (adapter name or user ID if delegated)
  - `aud`: `audience` from the AuthProfile
  - `exp`: now + 5 minutes
  - `iat`: current timestamp
  - `jti`: UUIDv4 (unique token ID for replay prevention)
- **AND** signs the JWT with the private key referenced by key_pem_ref
- **AND** attaches `Authorization: Bearer {jwt}` to the outbound request
- **AND** a CallLog entry of type `issue` is created with jwt_id=jti.

#### Scenario: Insecure algorithms are rejected at config validation

- **GIVEN** an AuthProfile of type `jwt` is being created
- **WHEN** `signing_algorithm` is set to `HS256` or `none`
- **THEN** openconnector rejects the profile with validation error `INVALID_SIGNING_ALGORITHM`
- **AND** only RS256, ES256, or EdDSA are accepted.

#### Scenario: Token expiry prevents replay

- **GIVEN** a JWT has been issued with `exp` = now + 5 minutes
- **WHEN** 6 minutes pass
- **THEN** the JWT is no longer valid (issuer will reject it with `TokenExpired`)
- **AND** openconnector will issue a fresh JWT on the next request.

### Requirement: mTLS with automated certificate rotation

Certificates are checked daily; if expiry is within 30 days, rotation is triggered. Expired certificates cause a typed error. Certs expiring within 7 days raise a P1 alert.

#### Scenario: Daily cert rotation checks for expiry

- **GIVEN** an AuthProfile of type `mtls` with `cert_pem_ref`, `key_pem_ref`, `ca_bundle_ref`
- **WHEN** the daily `CertRotationCron` runs
- **THEN** openconnector fetches the certificate from KeyVault and parses the `notAfter` field
- **AND** if `notAfter ≤ 30 days from now`, triggers the configured rotation backend (ACME, internal CA, manual ticket webhook)
- **AND** once rotation completes, updates the KeyVault reference for `cert_pem_ref` with the new certificate
- **AND** emits a `rotate` AuthEvent with old_cert_subject, new_cert_subject, rotation_duration_ms
- **AND** new adapter requests immediately use the new certificate (zero downtime).

#### Scenario: 7-day warning alert

- **GIVEN** a certificate's `notAfter ≤ 7 days from now`
- **WHEN** the daily cron runs
- **THEN** openconnector raises a P1 (page-now) alert with the affected profile slug and days remaining.

#### Scenario: Expired certificate causes typed error

- **GIVEN** a certificate's `notAfter < now`
- **WHEN** an adapter attempts to use the profile for an outbound mTLS connection
- **THEN** the TLS handshake fails with `CertExpired` error (not a generic TLS error)
- **AND** the CallLog entry includes `error_code=CertExpired`
- **AND** adapters can use this error code to log a message like "certificate is expired; please rotate immediately".

### Requirement: SAML2 assertion validation

Validate SAML2 assertions from IdP responses against the IdP's signing certificate, validate Conditions, and create a session.

#### Scenario: SAML response is validated

- **GIVEN** an AuthProfile of type `saml2` configured as a Service Provider with IdP metadata (issuer URL, signing certificates, singleSignOnService endpoints)
- **WHEN** an end user is redirected from openconnector to the IdP authorization endpoint and returns with a SAMLResponse
- **THEN** openconnector validates the response signature using the IdP's signing certificate
- **AND** validates the Conditions: NotBefore, NotOnOrAfter, AudienceRestriction (must equal the SP's entityID)
- **AND** extracts attributes per the configured attribute mapping (e.g. uid, mail, displayName)
- **AND** creates a session for the user
- **AND** logs a CallLog entry of type `issue` with assertion_id and mapped attributes.

#### Scenario: Replayed assertions are rejected

- **GIVEN** a SAML assertion with id=`_abc123` and NotOnOrAfter=now+5min
- **WHEN** the assertion is processed once (logged in the CallLog)
- **THEN** within the 5-minute window, if the same assertion_id is submitted again
- **AND** openconnector SHALL reject it with `AssertionReplay` error
- **AND** a CallLog entry is created with `error_code=AssertionReplay`.

#### Scenario: Invalid conditions are rejected

- **GIVEN** a SAML assertion with AudienceRestriction not matching the SP's entityID
- **WHEN** openconnector validates the assertion
- **THEN** it SHALL reject the assertion with `ValidationFailed` error.

### Requirement: OIDC discovery and configuration

Fetch the `.well-known/openid-configuration` from the issuer, cache it, and derive the endpoints and JWKS URI.

#### Scenario: OIDC discovery retrieves endpoints

- **GIVEN** an AuthProfile of type `oidc` with only `issuer_url` configured (e.g. `https://login.microsoftonline.com/common`)
- **WHEN** the profile is activated (first use or app startup)
- **THEN** openconnector fetches `{issuer_url}/.well-known/openid-configuration`
- **AND** parses the response to extract:
  - `token_endpoint`
  - `authorization_endpoint`
  - `userinfo_endpoint`
  - `jwks_uri`
  - `issuer` (must match the configured issuer_url)
- **AND** caches the discovery response in Redis with TTL=24 hours keyed by `oc:oidc_discovery:{issuer_url}`
- **AND** stores the derived endpoints in the AuthProfile object (or cache) so subsequent requests use the cached values.

#### Scenario: Invalid discovery is rejected

- **GIVEN** the discovery endpoint returns invalid JSON or is unreachable
- **WHEN** openconnector attempts to activate the profile
- **THEN** the profile activation fails with `DiscoveryFailed` error
- **AND** adapters cannot use the profile until discovery succeeds.

#### Scenario: Issuer mismatch is detected

- **GIVEN** the discovery response contains an `issuer` field that doesn't match the configured `issuer_url`
- **WHEN** openconnector validates the response
- **THEN** it rejects the discovery with `ValidationFailed` error (potential MITM).

### Requirement: Per-adapter auth-profile binding with override

Sources declare a default AuthProfile; adapters can override per-endpoint via the request context.

#### Scenario: Default profile is used

- **GIVEN** a Source is configured with `auth_profile=oauth2-haal-centraal`
- **WHEN** an adapter endpoint under that source makes an outbound request without specifying an override
- **THEN** openconnector uses the source's default profile for credential resolution.

#### Scenario: Per-request override

- **GIVEN** the same source, but a specific endpoint requires a different scope set (e.g. broader scopes for a webhook callback)
- **WHEN** the adapter call context includes `auth_profile_override=oauth2-haal-centraal-broad`
- **THEN** openconnector uses the override profile for that request only
- **AND** caches the token separately under `oc:token:oauth2-haal-centraal-broad` (not mixed with the default profile's cache)
- **AND** does not pollute the source-level cache.

#### Scenario: Override does not persist

- **GIVEN** a request with auth_profile_override completes
- **WHEN** the next request is made without an override
- **THEN** openconnector reverts to the source's default profile
- **AND** the override applies to that single request only.

### Requirement: Secret hygiene — no plaintext at rest

Secret values (client_secret, private keys, certificates) are never returned in responses or logs; only opaque references and masked indicators are shown.

#### Scenario: API response masks secret values

- **GIVEN** an admin or API client views an AuthProfile via GET `/auth-profiles/{id}`
- **WHEN** the response is generated
- **THEN** fields like `client_secret_ref` are returned as opaque strings (e.g. `"oc:oauth2:haal-centraal:secret"`)
- **AND** a masked indicator is shown (e.g. `"client_secret_masked": "***last4"`)
- **AND** the plaintext secret is NEVER included in the response.

#### Scenario: Plaintext resolution is logged as access

- **GIVEN** the openconnector runtime resolves a `client_secret_ref` from KeyVault to plaintext for use in a token request
- **WHEN** the resolution happens
- **THEN** openconnector logs a CallLog entry of type `access` with:
  - event_type: `access`
  - profile_id, ref_id (e.g. `oc:oauth2:haal-centraal:secret`)
  - correlation_id (ties to the originating request)
  - user/service identity (who triggered the resolution)
  - timestamp
- **AND** plaintext is kept in memory only and never persisted or logged.

#### Scenario: UI mask prevents secret exposure

- **GIVEN** an admin views an AuthProfile in the UI
- **WHEN** the profile detail page renders
- **THEN** secret fields show a masked value (e.g. `●●●●●●●● (ending in ...xyz)`)
- **AND** no copy-to-clipboard button, no reveal button, no way to extract plaintext from the UI.

### Requirement: JWKS caching with proactive refresh

Cache JWKS from a remote `jwks_uri` with ETags; refresh proactively when a presented `kid` is not found.

#### Scenario: JWKS is cached with ETag

- **GIVEN** an AuthProfile of type `oidc` or `jwt` with a remote `jwks_uri`
- **WHEN** openconnector first needs to validate a JWT
- **THEN** it fetches the JWKS from `jwks_uri` and caches it in Redis with key `oc:jwks:{url_hash}:{etag}` and TTL=1 hour
- **AND** stores the `etag` header from the response
- **AND** on subsequent requests, checks the cache first; if a cache hit, uses the cached JWKS without re-fetching.

#### Scenario: Cache miss on unknown kid triggers refresh

- **GIVEN** a JWT is presented with `kid=unknown_key`
- **WHEN** openconnector looks up the key in the cached JWKS and doesn't find it
- **THEN** it fetches the JWKS again from `jwks_uri` (compare etag; if changed, update cache; if not changed, use existing cache)
- **AND** looks up the `kid` again in the refreshed JWKS
- **AND** if still not found, rejects the JWT with `JWKSMismatch` error.

#### Scenario: Refresh is rate-limited

- **GIVEN** multiple requests within a 1-minute window each present an unknown `kid`
- **WHEN** the first request triggers a refresh
- **THEN** subsequent requests within the 1-minute window do NOT re-fetch the JWKS
- **AND** they wait for the first refresh to complete (or timeout after 5s) and use the refreshed cache.

#### Scenario: HTTP cache-control headers are respected

- **GIVEN** the JWKS endpoint returns `Cache-Control: max-age=3600`
- **WHEN** openconnector caches the JWKS
- **THEN** it uses 3600 seconds (1 hour) as the TTL instead of the default 1 hour
- **AND** if the header specifies a shorter duration, that shorter duration is used.

### Requirement: SAML metadata refresh with signature validation

Daily cron refreshes SAML IdP metadata, validates the signature, and swaps atomically.

#### Scenario: Metadata is fetched and validated

- **GIVEN** an AuthProfile of type `saml2` configured with an IdP metadata URL
- **WHEN** the daily `SAMLMetadataRefreshCron` runs
- **THEN** openconnector fetches the metadata from the URL
- **AND** validates the signature using the IdP's metadata-signing certificate (stored in KeyVault or static trust anchor)
- **AND** if the signature is invalid, keeps the previous metadata in cache and raises a P2 alert.

#### Scenario: Metadata is atomically swapped

- **GIVEN** the signature is valid
- **WHEN** the cron completes
- **THEN** openconnector atomically swaps the cached metadata (no in-flight requests are interrupted)
- **AND** parses the new metadata to extract singleSignOnService endpoints and signing certificates
- **AND** emits a `saml.metadata.refreshed` event with old_metadata_hash, new_metadata_hash, refresh_duration_ms.

#### Scenario: Staleness alert after 24 hours

- **GIVEN** metadata refresh has failed for more than 24 hours
- **WHEN** the cron runs again
- **THEN** openconnector raises a P2 alert: "SAML metadata for {profile_slug} is {N} hours stale; issuer may have rotated endpoints or signing certs"
- **AND** the previous metadata remains in use (graceful degradation).

### Requirement: Token revocation on profile change

When an AuthProfile is updated, invalidate cached tokens and revoke valid refresh tokens with the issuer.

#### Scenario: Profile update invalidates cache

- **GIVEN** an AuthProfile with cached tokens in Redis
- **WHEN** the profile is updated (e.g. client_secret rotated, scopes narrowed, audience changed)
- **THEN** openconnector:
  - Invalidates all cached tokens under the affected profile key (`oc:token:{profile_id}:*`)
  - For each invalidated refresh_token, calls the issuer's `revocation_endpoint` (RFC 7009) with the token
  - Emits a `profile.changed` event with the list of changed fields (before/after diff)
  - Writes an audit entry in CallLog with event_type=`profile_changed`
- **AND** in-flight sync jobs receive the `profile.changed` event and can re-acquire credentials.

#### Scenario: Revocation endpoint is called

- **GIVEN** a valid refresh_token in cache and a profile update
- **WHEN** openconnector calls the revocation endpoint
- **THEN** it POSTs to `revocation_endpoint` with `token=...&token_type_hint=refresh_token`
- **AND** logs a CallLog entry of type `revoke` with status (success or failure).

### Requirement: Concurrent-issuance dedup (token stampede prevention)

Use a Redis lock to serialize token acquisition when multiple requests expire the same token simultaneously.

#### Scenario: First acquirer fetches, others wait

- **GIVEN** a previously-cached token has just expired and 100 parallel requests need a fresh token
- **WHEN** all 100 requests check the cache and find it expired
- **THEN** each tries to acquire `oc:token:lock:{profile_id}` (Redis lock with TTL=10s)
- **AND** the first acquirer succeeds, fetches a new token from the issuer, and updates the cache
- **AND** the remaining 99 await the lock release (timeout=5s) and read the refreshed cache
- **AND** the issuer sees exactly 1 acquisition call instead of 100.

#### Scenario: Lock timeout prevents deadlock

- **GIVEN** the first acquirer crashes before releasing the lock
- **WHEN** 5 seconds pass
- **THEN** a waiter's timeout fires and it attempts to acquire the lock again
- **AND** the lock has auto-expired (TTL=10s), so the waiter can proceed
- **AND** the system recovers gracefully without manual intervention.

#### Scenario: Cache miss with no lock contention

- **GIVEN** a single request needs a token and the cache is empty
- **WHEN** the request tries to acquire the lock
- **THEN** it succeeds immediately (no other waiters), fetches the token, and proceeds
- **AND** no contention penalty.

## See Also

- `openregister: register-resolver-service` — resolve schema slugs for profile validation
- `openregister: secrets-handler` — KeyVault integration for `*_ref` resolution
- `openregister: archival-annotation` — token cache eviction policy declaration
- `openconnector: CallLog` (existing) — audit trail for all auth events
- `hydra: app-manifest` (optional) — declare auth-protocol-suite consumption in openspec/manifest.yaml
- Standards: RFC 6749, RFC 7636, RFC 7519, RFC 7009, OpenID Connect Core 1.0, SAML 2.0, NIST SP 800-63B, NEN 7510, BIO
