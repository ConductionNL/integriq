# Tasks — openconnector: Authentication Protocol Suite

Multi-phase implementation of a unified authentication abstraction layer. Each phase is a logical grouping of related work. ADR-032 cap respected (≤20 phases).

## Phase 1 — AuthProfile schema and data model

Define the canonical AuthProfile schema in openregister with all protocol-specific fields. Create KeyVault reference pattern for secrets.

- [ ] Create AuthProfile schema in `lib/Settings/openconnector_register.json` with fields: id, slug, type (enum: oauth2_cc|oauth2_ac|jwt|mtls|saml2|oidc), issuer_url, client_id, client_secret_ref, scopes[], audience, token_endpoint, authorization_endpoint, jwks_uri, key_id, signing_algorithm, cert_pem_ref, key_pem_ref, ca_bundle_ref, refresh_rotation_enabled, token_cache_ttl_seconds, created, updated, version
- [ ] Define OpenRegister relation pattern for AuthProfile references from Source entities (source.auth_profile → AuthProfile.id)
- [ ] Create migration to add `auth_profile` field to existing Source and Endpoint schemas
- [ ] Add 5 seed AuthProfile objects to register template (oauth2_cc, oauth2_ac+PKCE, mTLS, OIDC, JWT) per design.md examples
- [ ] Verify KeyVault `secrets/{ref-id}` URI scheme works for all secret types (client_secret, private_key, cert_chain, ca_bundle)
- [ ] [ ] Phase complete

## Phase 2 — Token cache infrastructure (Redis-backed)

Implement Redis token caching with TTL management, cache key derivation, and distributed consistency.

- [ ] Create `AuthTokenCacheService` with methods:
  - `getToken(profileId, subject?): TokenCacheEntry|null`
  - `setToken(profileId, subject?, token, expiresIn): void`
  - `invalidateToken(profileId, subject?): void`
  - `invalidateAllForProfile(profileId): void`
- [ ] Implement cache key generation: `oc:token:{profile_id}:{subject?}` with subject hashing for user-delegated flows
- [ ] Implement TTL calculation: `min(issuer_expires_in - 60s, profile.token_cache_ttl_seconds)`
- [ ] Implement concurrent-issuance dedup via Redis lock `oc:token:lock:{profile_id}` with TTL=10s, waiter timeout=5s
- [ ] Add unit tests for cache hit/miss, expiry, dedup, lock timeout scenarios
- [ ] [ ] Phase complete

## Phase 3 — OAuth2 client_credentials flow

Implement the simplest OAuth2 flow: client_credentials token acquisition and caching.

- [ ] Create `OAuth2ClientCredentialsResolver` with method `resolve(profile): AccessToken`
- [ ] Implement `POST {token_endpoint}` with `grant_type=client_credentials&scope=...&client_id=...&client_secret=...`
- [ ] Resolve `client_secret_ref` from KeyVault (with `access` CallLog entry)
- [ ] Log `issue` CallLog entry on success with profile_id, duration_ms, correlation_id
- [ ] Handle issuer errors: timeout, invalid_client, invalid_scope → typed `error_code` in CallLog
- [ ] Add integration test with mock OAuth2 issuer
- [ ] [ ] Phase complete

## Phase 4 — OAuth2 authorization_code with PKCE

Implement user-delegated flow with PKCE binding.

- [ ] Create `OAuth2AuthorizationCodeResolver` with methods:
  - `generateAuthorizationUrl(profile, state): String` — generates auth URL with PKCE parameters
  - `exchangeCode(profile, code, state): AccessToken` — completes the flow
- [ ] Generate `code_verifier` (43-128 chars, random) per RFC 7636 §4.1
- [ ] Compute `code_challenge = BASE64URL(SHA256(code_verifier))`
- [ ] Persist code_verifier in `oc:pkce:{state}` with TTL=10 minutes
- [ ] Validate PKCE presence at profile config-validation time; reject if missing
- [ ] Implement code exchange: `POST {token_endpoint}` with `grant_type=authorization_code&code=...&code_verifier=...&client_id=...&client_secret=...&redirect_uri=...`
- [ ] Clean up code_verifier from cache after use
- [ ] Log `issue` CallLog entry
- [ ] Add integration test with mock OAuth2 issuer and callback handler
- [ ] [ ] Phase complete

## Phase 5 — OAuth2 refresh-token rotation (RFC 6749 §10.4)

Implement refresh-token reacquisition with optional rotation.

- [ ] Create `OAuth2RefreshTokenResolver` with method `refresh(profile): AccessToken`
- [ ] Implement `POST {token_endpoint}` with `grant_type=refresh_token&refresh_token=...&client_id=...&client_secret=...`
- [ ] If `profile.refresh_rotation_enabled=true`:
  - Atomically replace both `access_token` and `refresh_token` in cache
  - Increment `rotation_counter`
  - Invalidate old refresh_token (log `revoke` CallLog entry)
  - Log `refresh` CallLog entry with rotation_counter
- [ ] If false: replace only access_token
- [ ] Handle issuer returning same refresh_token: log `refresh` with `rotation_skipped=true`
- [ ] Handle refresh failures: `invalid_grant`, `invalid_client` → typed `error_code`, revoke cache entry
- [ ] Add integration tests with/without rotation
- [ ] [ ] Phase complete

## Phase 6 — JWT bearer assertion (signed)

Implement JWT construction, signing, and request attachment.

- [ ] Create `JWTAssertionResolver` with method `resolve(profile): JWT`
- [ ] Implement JWT construction per RFC 7519:
  - Header: `{ alg, kid, typ: "JWT" }`
  - Payload: `{ iss, sub, aud, exp: now+5min, iat, jti: UUIDv4 }`
- [ ] Implement signing with RS256, ES256, EdDSA (reject HS256, `none` at config-validation time)
- [ ] Resolve private key from `key_pem_ref` (KeyVault)
- [ ] Log `issue` CallLog entry with jwt_id=jti, signing_algorithm, iss, aud
- [ ] Handle signing errors: invalid key format, unsupported algorithm
- [ ] Add unit tests for JWT construction and signing per algorithm
- [ ] Add integration test with a service accepting JWT bearer tokens
- [ ] [ ] Phase complete

## Phase 7 — mTLS with automated certificate rotation

Implement certificate parsing, rotation triggering, and proactive monitoring.

- [ ] Create `CertificateRotationService` with methods:
  - `getCertificateExpiry(profile): DateTime`
  - `isExpiringSoon(profile, days): bool`
  - `triggerRotation(profile): RotationResult`
- [ ] Implement certificate parsing from PEM: extract `notBefore`, `notAfter`, `subject`, `subjectAltName`
- [ ] Create `CertRotationCron` job that runs daily:
  - Fetches all AuthProfiles of type `mtls`
  - For each, checks if `notAfter ≤ 30 days`: call `triggerRotation()`
  - If `notAfter ≤ 7 days`: raise P1 alert
  - On rotation success: update `cert_pem_ref` in KeyVault, emit `rotate` AuthEvent
  - Log CallLog entry of type `rotate` with old_cert_subject, new_cert_subject, duration_ms
- [ ] Implement rotation backends (plugins):
  - ACME (via external service or library)
  - Internal CA (webhook call to admin endpoint)
  - Manual ticket (generate support ticket, wait for admin response)
- [ ] Implement TLS handshake error handling: convert TLS errors to typed `CertExpired` error when cert is expired
- [ ] Add integration tests with mock ACME responder and manual rotation flow
- [ ] [ ] Phase complete

## Phase 8 — SAML2 assertion validation

Implement SAML2 response validation, attribute extraction, and replay detection.

- [ ] Create `SAML2AssertionResolver` with method `resolve(samlResponse): Session`
- [ ] Implement SAML response parsing and signature validation:
  - Validate signature using IdP's signing certificate
  - Validate Conditions: NotBefore, NotOnOrAfter, AudienceRestriction (must match SP entityID)
  - Extract attributes per configured attribute mapping
- [ ] Implement assertion replay detection:
  - Cache processed assertion IDs: `oc:saml:assertion:{assertion_id}` with TTL = NotOnOrAfter − now
  - On resubmission within window: reject with `AssertionReplay` error
- [ ] Log `issue` CallLog entry on success with assertion_id, mapped_attributes
- [ ] Handle validation errors: invalid signature, condition violations, replay → typed error_code
- [ ] Add integration tests with mock SAML2 IdP (SimpleSAML or similar)
- [ ] [ ] Phase complete

## Phase 9 — OIDC discovery and configuration

Implement OpenID Connect Discovery with endpoint derivation and caching.

- [ ] Create `OIDCDiscoveryService` with method `discoverConfiguration(issuer_url): OIDCConfig`
- [ ] Implement discovery fetch: `GET {issuer_url}/.well-known/openid-configuration`
- [ ] Cache discovery response in `oc:oidc_discovery:{issuer_url_hash}` with TTL=24h
- [ ] Parse required fields: `token_endpoint`, `authorization_endpoint`, `userinfo_endpoint`, `jwks_uri`, `issuer`
- [ ] Validate issuer matches: reject if issuer in response ≠ configured issuer (MITM detection)
- [ ] Respect HTTP `Cache-Control: max-age` header to override default 24h TTL
- [ ] Handle discovery errors: network timeout, invalid JSON, missing required fields → `DiscoveryFailed` error_code
- [ ] Integrate with OIDC profile activation: call discovery on first use
- [ ] Add integration tests with mock OIDC issuer (e.g., keycloak)
- [ ] [ ] Phase complete

## Phase 10 — JWKS caching with proactive refresh

Implement JWKS caching, ETags, and kid-miss-triggered refresh.

- [ ] Create `JWKSCacheService` with methods:
  - `getJWKS(jwks_uri): JWKSet`
  - `refreshJWKS(jwks_uri): JWKSet`
  - `getKey(jwks_uri, kid): JWK | null`
- [ ] Implement cache key: `oc:jwks:{url_hash}:{etag}` with TTL=1h (or per Cache-Control header)
- [ ] Implement ETag comparison: if response ETag matches cached, reuse; else update
- [ ] Implement kid-miss refresh: on unknown `kid` in JWT, refresh JWKS once per minute (rate-limit)
- [ ] Implement rate-limit via lock: `oc:jwks:lock:{url_hash}` (TTL=1 min) prevents thundering herd
- [ ] Handle JWKS errors: network timeout, invalid JSON, malformed keys → typed error_code
- [ ] Add integration tests with mock JWKS endpoint and kid rotation scenario
- [ ] [ ] Phase complete

## Phase 11 — SAML metadata refresh with signature validation

Implement daily SAML metadata refresh with cryptographic validation and caching.

- [ ] Create `SAMLMetadataRefreshService` with method `refreshMetadata(profile): SAMLMetadata`
- [ ] Create `SAMLMetadataRefreshCron` job that runs daily:
  - Fetches all AuthProfiles of type `saml2` with metadata_url
  - For each, fetches metadata from URL
  - Validates signature using IdP's metadata-signing certificate (from previous metadata or static trust anchor)
  - If signature invalid: keep previous metadata, raise P2 alert, emit `saml.metadata.staleness` event
  - If valid: parse endpoints and signing certs, atomically swap cache, emit `saml.metadata.refreshed` event
- [ ] Implement metadata caching: `oc:saml_metadata:{profile_id}` with TTL=24h (or until next cron refresh)
- [ ] Implement staleness tracking: if refresh fails > 24h, raise P2 alert
- [ ] Log CallLog entries of type `refresh` with old_metadata_hash, new_metadata_hash, validation_status
- [ ] Handle metadata errors: network timeout, invalid XML, signature mismatch, missing trust anchor → typed error_code
- [ ] Add integration tests with mock SAML IdP (SimpleSAML) and metadata rotation
- [ ] [ ] Phase complete

## Phase 12 — Per-adapter profile binding and override

Implement Source-level and per-request AuthProfile resolution.

- [ ] Add `auth_profile` field to Source schema (foreign key to AuthProfile)
- [ ] Add optional `auth_profile_override` to adapter request context
- [ ] Create `AuthProfileResolutionService` with method `resolve(source, endpoint?, context?): AuthProfile`
  - Checks context for override; if present, use it
  - Otherwise, use source's default profile
  - Return resolved profile object
- [ ] Implement token caching per resolved profile (overrides don't pollute source-level cache)
- [ ] Update all credential-resolution paths to use `AuthProfileResolutionService`
- [ ] Add integration tests: default profile, override, isolated cache, fallback to default
- [ ] [ ] Phase complete

## Phase 13 — Secret hygiene and KeyVault integration

Ensure secrets never leak in logs, responses, or the database.

- [ ] Create `SecretMaskingFilter` that intercepts all API responses:
  - Finds fields matching `*_ref` pattern
  - Replaces plaintext with opaque reference (e.g., `oc:oauth2:...`)
  - Appends masked indicator (e.g., `***last4`)
- [ ] Create `SecretAccessLogger` that logs every KeyVault resolution:
  - CallLog entry of type `access` with profile_id, ref_id, user/service identity, timestamp, correlation_id
  - No plaintext in the log
- [ ] Update AuthProfile validation: reject plaintext secrets in `client_secret`, `key_pem`, `cert_pem` fields
- [ ] Update UI: never show plaintext secrets, no copy-to-clipboard, no reveal button
- [ ] Add integration test: attempt to extract secret via API, verify masking
- [ ] Audit: search logs for any plaintext secret patterns (e.g., `BEGIN RSA PRIVATE KEY`)
- [ ] [ ] Phase complete

## Phase 14 — CallLog integration and typed errors

Integrate all auth events into CallLog with consistent error_code taxonomy.

- [ ] Create `AuthCallLogger` that wraps all resolver invocations:
  - Log before: profile_id, resolver_type, correlation_id
  - Log after: outcome (success|failure), duration_ms, error_code (if failure)
- [ ] Define error_code enum: `TokenExpired`, `RefreshDenied`, `CertExpired`, `JWKSMismatch`, `AssertionReplay`, `DiscoveryFailed`, `ValidationFailed`, `NoValidCredential`, `InvalidSigningAlgorithm`, `PKCERequired`
- [ ] Update all resolvers to use `AuthCallLogger` and set appropriate error_code on failure
- [ ] Create adapter-facing error translation: internal error_code → human-readable adapter response
- [ ] Add CallLog reporting: filter by profile_id, error_code, date range; useful for compliance audits
- [ ] Add integration test: verify CallLog entries for each protocol and error scenario
- [ ] [ ] Phase complete

## Phase 15 — Configuration validation and startup checks

Implement profile validation at config time and startup time.

- [ ] Create `AuthProfileValidator` with method `validate(profile): ValidationResult`
- [ ] For OAuth2 profiles: validate token_endpoint URL, PKCE for ac flows
- [ ] For JWT profiles: validate signing_algorithm (reject HS256, `none`), require key_pem_ref
- [ ] For mTLS profiles: validate cert_pem_ref, key_pem_ref are resolvable
- [ ] For SAML2 profiles: validate metadata_url or IdP config is present
- [ ] For OIDC profiles: attempt discovery if only issuer_url provided
- [ ] Create startup check `AuthProfileHealthCheck` that runs on app boot:
  - For each profile: attempt to resolve secrets, fetch discovery, validate certs expiry
  - Log warnings for profiles that fail checks
  - Prevent adapter startup if critical profiles are broken
- [ ] Add integration tests: valid profiles pass, invalid profiles rejected, health check catches issues
- [ ] [ ] Phase complete

## Phase 16 — Token revocation and profile change handling

Implement profile update invalidation and token cleanup.

- [ ] Create `AuthProfileChangeService` with method `onProfileChange(profile, oldProfile): void`
- [ ] On update: invalidate all cached tokens for the profile (`oc:token:{profile_id}:*`)
- [ ] For each invalidated refresh_token: call `POST {revocation_endpoint}` (RFC 7009)
- [ ] Log CallLog entries:
  - `profile.changed` event with before/after diff
  - `revoke` entries for each revoked refresh_token
- [ ] Emit domain event `profile.changed` so in-flight sync jobs can re-acquire credentials
- [ ] Add integration test: update profile, verify cache invalidation and revocation calls
- [ ] [ ] Phase complete

## Phase 17 — Daily background jobs (cron)

Implement certificate rotation, metadata refresh, and staleness alerting.

- [ ] Register cron jobs in openconnector's job scheduler:
  - `CertRotationCron` (daily, default 2 AM): check cert expiry, trigger rotations, raise alerts
  - `SAMLMetadataRefreshCron` (daily, default 3 AM): fetch and validate SAML metadata
  - `JWKSFreshnessCron` (optional, daily 4 AM): proactively refresh JWKS for known profiles (for high-traffic issuers)
- [ ] Implement job logging: duration, success/failure, alerts raised
- [ ] Implement retry logic: if a job fails, retry up to 3 times with exponential backoff
- [ ] Add integration tests: mock time, trigger cron, verify actions (cert rotation, metadata refresh, alerts)
- [ ] [ ] Phase complete

## Phase 18 — Adapter integration and migration

Update existing adapters to use AuthProfile instead of inline credentials.

- [ ] Migrate built-in adapters to AuthProfile:
  - Haal Centraal (BRP, BAG, KvK) → oauth2_cc + mTLS profiles
  - StUF → mTLS profile
  - DSO/Omgevingsloket → oauth2_cc or mTLS
  - Microsoft Graph → oauth2_ac + OIDC
- [ ] For each adapter: create example AuthProfile objects in register template
- [ ] Remove inline credential handling from adapter code
- [ ] Update adapter tests to use AuthProfile instead of mocking credentials
- [ ] Verify backward compatibility: if a legacy source has inline credentials, auto-create an AuthProfile and link it
- [ ] [ ] Phase complete

## Phase 19 — UI and admin experience

Build forms and dashboards for AuthProfile management.

- [ ] Create AuthProfile index page with list, create, edit, delete
- [ ] Use `CnFormDialog` + schema to auto-generate create/edit forms from AuthProfile schema
- [ ] For secret fields (`*_ref`), use masked input with masked indicator
- [ ] Add "Test Connection" button that attempts token acquisition (without actually using the token)
- [ ] Add profile usage dashboard: show which sources/adapters use each profile, token hit rates, error rates
- [ ] Add certificate expiry dashboard: heatmap of cert expiry windows, highlight < 7 days
- [ ] Add CallLog viewer filtered by profile_id for audit trail
- [ ] Add admin config for cert rotation backends and alert thresholds
- [ ] [ ] Phase complete

## Phase 20 — Observability, metrics, and documentation

Create dashboards, observability hooks, and user documentation.

- [ ] Add Prometheus metrics:
  - `oc_auth_token_cache_hits` (counter)
  - `oc_auth_token_cache_misses` (counter)
  - `oc_auth_token_issuance_duration_ms` (histogram)
  - `oc_auth_refresh_token_rotation_counter` (gauge per profile)
  - `oc_auth_cert_expiry_days` (gauge per profile)
  - `oc_auth_error_rate_by_error_code` (counter)
- [ ] Create Grafana dashboard: token cache hit rate, issuance latency, error rate, cert expiry warnings
- [ ] Create OpenAPI schema extensions for AuthProfile (document in Hydra OpenAPI registry)
- [ ] Write user documentation:
  - "Getting Started with AuthProfile" guide
  - Per-protocol examples (OAuth2 cc, OAuth2 ac+PKCE, mTLS, SAML2, OIDC, JWT)
  - Troubleshooting guide (typed error codes, CallLog inspection, cert rotation)
  - Compliance guide (audit trail, secret hygiene, rotation policies)
- [ ] Add architecture decision record (ADR) for AuthProfile design rationale
- [ ] [ ] Phase complete
