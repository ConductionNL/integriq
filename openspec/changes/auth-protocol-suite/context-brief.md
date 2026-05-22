---
status: draft
spec: auth-protocol-suite
app: openconnector
owner: openconnector-core
depends_on:
  - openconnector-base
---

# Authentication Protocol Suite

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Beheer > Authenticatie / Beheer

**Rationale:** Cross-cutting auth foundation (OAuth/OIDC/SAML/mTLS/JWT)  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The Authentication Protocol Suite gives openconnector a single, uniform authentication layer that every adapter, source, and synchronization can plug into without reinventing the wheel for each external system. Today, integration code routinely re-implements OAuth2 token flows, certificate handling, or SAML assertions per adapter, leading to inconsistent error handling, scattered secrets, brittle refresh logic, and inconsistent observability. Token errors get caught generically and logged as "auth failed" with no actionable detail; certificate expiries are discovered only when downstream calls start failing in production; refresh-token rotation is implemented inconsistently, sometimes leaking long-lived secrets and sometimes failing silently when an issuer changes its rotation policy. This spec consolidates all supported protocols — OAuth2 (client_credentials, authorization_code with PKCE, refresh_token), JWT (signed and encrypted), mTLS, SAML2, and OIDC — behind a single AuthProfile abstraction that adapter authors declare once and configure per environment.

The suite handles cryptographic key management, automated certificate rotation, distributed token caching (Redis-backed), refresh-token rotation as required by RFC 6749 §10.4, and per-adapter selection of an auth profile. It must work equally well for high-frequency machine-to-machine sync (typical for Dutch government Haal Centraal APIs over mTLS, where a single sync job can fire thousands of requests per minute against the BRP) and for low-frequency user-delegated flows (a citizen authorising a municipality to read their DigiD-linked records, where the same refresh token may be reused across weeks of dormancy). Adapter authors should be able to declare "this source uses OAuth2 client_credentials with scope X" in configuration and never touch token plumbing; the runtime resolves the AuthProfile, manages the lifecycle, and attaches credentials to every outbound request automatically.

Reliability and observability are first-class: every token issuance, refresh, failure, and rotation is logged to the openconnector CallLog with a stable correlation ID; every cached token has an explicit TTL clamped to issuer-provided expiry minus a safety margin; every key rotation is announced via a domain event so downstream sync jobs can briefly pause if needed. Failures carry typed error codes (`TokenExpired`, `RefreshDenied`, `CertExpired`, `JWKSMismatch`, `AssertionReplay`) so operators can distinguish recoverable issuer hiccups from permanent configuration faults. Compliance teams get an auditable trail of every credential issued or rotated, satisfying BIO and NEN 7510 requirements for key custody. The end goal is that integration engineers stop thinking about authentication as a per-adapter concern and start thinking of it as a centrally-administered profile assigned per source.

## Data Model

- **AuthProfile** (schema in openregister): id, slug, type (oauth2_cc | oauth2_ac | jwt | mtls | saml2 | oidc), issuer_url, client_id, client_secret_ref (KeyVault pointer), scopes[], audience, token_endpoint, authorization_endpoint, jwks_uri, key_id, signing_algorithm, cert_pem_ref, key_pem_ref, ca_bundle_ref, refresh_rotation_enabled (bool), token_cache_ttl_seconds, created, updated, version.
- **TokenCacheEntry** (Redis): key = `oc:token:{profile_id}:{subject?}`, value = {access_token, refresh_token?, expires_at, scope, token_type, issued_at, rotation_counter}, TTL = min(issuer_expires_in - 60s, profile.token_cache_ttl_seconds).
- **KeyMaterial** (KeyVault-backed; secrets never live in DB plain): cert chains, private keys, JWKS, client secrets, all referenced by opaque `*_ref` IDs.
- **AuthEvent** (CallLog entry): profile_id, event_type (issue|refresh|rotate|fail|revoke), outcome, duration_ms, error_code, correlation_id.

## Requirements

### REQ-001: OAuth2 client_credentials flow with cached tokens

- **GIVEN** an adapter is configured with AuthProfile of type `oauth2_cc` and valid client_id/client_secret
- **WHEN** the adapter makes its first outbound request
- **THEN** openconnector requests a token from the token_endpoint, caches it in Redis with TTL = expires_in − 60s, attaches `Authorization: Bearer <token>` to the request, and logs an `issue` AuthEvent

### REQ-002: Reuse of cached tokens within validity window

- **GIVEN** a valid (non-expired) token exists in the Redis cache for an AuthProfile
- **WHEN** any adapter using that profile makes an outbound request
- **THEN** openconnector reuses the cached token without contacting the token endpoint and does not emit an `issue` AuthEvent

### REQ-003: Refresh-token rotation per RFC 6749 §10.4

- **GIVEN** an AuthProfile of type `oauth2_ac` with `refresh_rotation_enabled = true` and a cached entry whose access_token has expired but refresh_token is still valid
- **WHEN** an outbound request triggers a refresh
- **THEN** openconnector POSTs to the token endpoint with `grant_type=refresh_token`, accepts the new access_token AND new refresh_token, replaces both in the cache atomically, increments rotation_counter, and invalidates the old refresh_token reference; if the issuer returns the SAME refresh_token, openconnector logs a `rotation-skipped` warning but proceeds

### REQ-004: PKCE for authorization_code flows

- **GIVEN** an AuthProfile of type `oauth2_ac` initiating an authorization_code flow
- **WHEN** the user is redirected to the authorization_endpoint
- **THEN** openconnector generates a cryptographically random code_verifier (43-128 chars, RFC 7636 §4.1), computes code_challenge = BASE64URL(SHA256(code_verifier)), sends `code_challenge` + `code_challenge_method=S256` in the auth request, persists the code_verifier server-side keyed by `state`, and submits it during code exchange; flows missing either parameter are rejected at config-validation time

### REQ-005: JWT bearer assertion (signed)

- **GIVEN** an AuthProfile of type `jwt` with key_id, signing_algorithm (RS256|ES256|EdDSA), and a referenced private key
- **WHEN** an adapter prepares an outbound request
- **THEN** openconnector constructs a JWT with iss, sub, aud, exp (now+5min), iat, jti (UUIDv4), signs it with the configured key, attaches `Authorization: Bearer <jwt>`, and rejects HS256 or `none` algorithms at config-validation time

### REQ-006: mTLS with automated certificate rotation

- **GIVEN** an AuthProfile of type `mtls` with cert_pem_ref, key_pem_ref, ca_bundle_ref, and certificate notAfter within 30 days
- **WHEN** the daily cert-rotation cron runs
- **THEN** openconnector triggers the configured rotation backend (ACME, internal CA, or manual ticket), updates the KeyVault references, emits a `rotate` AuthEvent, and continues serving requests with zero downtime; certs expiring within 7 days raise a P1 alert; expired certs cause adapter requests to fail with a typed `CertExpired` error rather than a generic TLS handshake failure

### REQ-007: SAML2 assertion handling

- **GIVEN** an AuthProfile of type `saml2` configured as Service Provider with IdP metadata
- **WHEN** an end user is redirected from openconnector to the IdP and returns with a SAMLResponse
- **THEN** openconnector validates the response signature against the IdP's signing cert, validates the Conditions (NotBefore, NotOnOrAfter, AudienceRestriction = SP entityID), extracts attributes per the configured attribute mapping, creates a session, and logs an `issue` AuthEvent with the assertion's ID; replayed assertion IDs within the NotOnOrAfter window are rejected

### REQ-008: OIDC userinfo + discovery

- **GIVEN** an AuthProfile of type `oidc` with only issuer_url configured
- **WHEN** the profile is activated
- **THEN** openconnector fetches `<issuer>/.well-known/openid-configuration`, caches it for 24h, derives token_endpoint/authorization_endpoint/userinfo_endpoint/jwks_uri from the document, validates the issuer matches, and refuses to activate the profile if discovery returns invalid JSON or missing required fields

### REQ-009: Per-adapter auth-profile binding with override

- **GIVEN** an adapter has a default AuthProfile bound at the source level and a specific endpoint requires a different scope set
- **WHEN** that endpoint is called
- **THEN** openconnector accepts a per-call AuthProfile override (passed via the adapter call context), uses the override for that request only, and does not pollute the cached token under the source-level key

### REQ-010: Secret hygiene — no plaintext at rest

- **GIVEN** any AuthProfile field referencing key material (client_secret_ref, cert_pem_ref, key_pem_ref)
- **WHEN** an admin views the AuthProfile in the UI or exports it via API
- **THEN** openconnector returns the opaque reference ID and a masked indicator (e.g. `***last4`) but never the plaintext value; only the runtime resolver running inside the openconnector container resolves refs to plaintext, and that resolution is logged as an `access` AuthEvent

### REQ-011: JWKS caching and rotation handling

- **GIVEN** an AuthProfile of type `oidc` or `jwt` configured with a remote `jwks_uri`
- **WHEN** an inbound or outbound JWT must be verified
- **THEN** openconnector caches the JWKS in Redis with a 1-hour TTL keyed by `(jwks_uri, etag)`, refreshes proactively when a presented `kid` is not found in the cache (one refresh per minute maximum to prevent stampedes), and rejects tokens whose `kid` is absent after refresh with a typed `JWKSMismatch` error; HTTP cache-control headers from the JWKS endpoint override the default TTL when shorter

### REQ-012: SAML metadata refresh

- **GIVEN** an AuthProfile of type `saml2` configured with an IdP metadata URL
- **WHEN** the daily metadata-refresh cron runs
- **THEN** openconnector fetches the metadata, validates its signature against the configured trust anchor (the IdP's metadata-signing cert), parses the singleSignOnService endpoints and signing certificates, atomically swaps the cached metadata, and emits a `saml.metadata.refreshed` event; refresh failures keep the previous metadata in service and raise a P2 alert after 24h of staleness

### REQ-013: Token revocation on profile change

- **GIVEN** an AuthProfile is updated (e.g. client_secret rotated, scopes narrowed, audience changed)
- **WHEN** the update is persisted
- **THEN** openconnector invalidates all cached tokens under the affected profile key, calls the issuer's `revocation_endpoint` (RFC 7009) for each still-valid refresh token if available, emits a `profile.changed` event so in-flight sync jobs can re-acquire credentials cleanly, and writes an audit entry with the diff of changed fields

### REQ-014: Concurrent-issuance dedup (token stampede prevention)

- **GIVEN** a previously-cached token has just expired and N parallel requests need a fresh token simultaneously
- **WHEN** all N requests trigger token acquisition
- **THEN** openconnector serialises issuance via a Redis lock (`oc:token:lock:{profile_id}`, TTL 10s), the first acquirer fetches and caches, the remaining N-1 await the cache result (max 5s) and reuse it; the issuer endpoint sees exactly one acquisition call instead of N

## Standards

- **RFC 6749** — OAuth 2.0 Authorization Framework
- **RFC 7636** — PKCE for OAuth Public Clients
- **RFC 7519** — JSON Web Token (JWT)
- **RFC 7515 / 7516 / 7517 / 7518** — JWS / JWE / JWK / JWA
- **RFC 8252** — OAuth 2.0 for Native Apps
- **OpenID Connect Core 1.0** + **Discovery 1.0**
- **SAML 2.0 Core, Bindings, Profiles** (OASIS)
- **RFC 8446** — TLS 1.3 (mTLS over TLS 1.3 minimum)
- **NIST SP 800-63B** — Digital Identity Guidelines (token lifetimes)
- **NEN 7510** — Dutch healthcare information security (cert rotation cadence)
- **BIO** — Baseline Informatiebeveiliging Overheid (key custody)

## Cross-app Integration

- **openconnector adapters** — every Source/Adapter declares an `auth_profile` reference instead of inline credentials; the existing inline-credentials code path is deprecated in favour of profile references
- **openregister** — AuthProfile schema lives in the `openconnector` register; KeyVault references resolve via the openregister secrets handler with a uniform `secrets/{ref-id}` URI scheme
- **openzaak / openklant / valtimo sidecars** — consume openconnector adapters configured with mTLS profiles for Haal Centraal (BRP, BAG, KvK, HR, BGT) and StUF endpoints requiring PKI-overheid certificates
- **docudesk** — uses OIDC profiles for tenant-bound document signing services (Evidos, Validsign, SBR-services) and JWT-bearer profiles for the eIDAS qualified-signature backends
- **opentalk** — uses OAuth2 ac+PKCE for user-delegated Teams/Slack/Zoom integrations where the end user authorises personal-mailbox or personal-channel access
- **pipelinq** — orchestrates workflows that depend on AuthProfile rotation events to pause/resume long-running jobs, particularly around night-time cert rotations
- **hydra** — the AuthProfile schema is registered in `hydra/openspec/schemas/` so all apps reference the same canonical shape and any extension (new auth type, new field) flows fleet-wide automatically
- **microsoft-graph-workspace-adapter** — depends on this suite for both application and delegated OAuth2 flows against M365 and Workspace tenants

## Target Users

- **Integration engineers** at Dutch municipalities wiring openconnector to Haal Centraal BRP, BAG, or KvK APIs over mTLS, where PKI-overheid certificates must rotate annually and any expiry causes downstream service outages
- **SaaS administrators** configuring OAuth2 connections to Microsoft Graph, Google Workspace, Slack, Zoom, or HubSpot, each with subtly different refresh and scope semantics that the suite normalises
- **Security officers** requiring auditable token issuance and rotation across all integrations, with a single pane of glass over every credential the platform holds
- **Adapter authors** (internal + community) who want a one-line auth declaration in their adapter manifest instead of bespoke token plumbing
- **Government CISO teams** verifying BIO compliance on key custody, rotation cadence, and incident-response readiness during audits and pentests
- **Platform operators** running multi-tenant openconnector deployments where each tenant brings its own IdP and the auth layer must keep tenant credentials cleanly isolated
- **DevOps teams** automating cert rotation via ACME or internal CAs and needing first-class hooks (`rotate` events, KeyVault writes) rather than out-of-band scripts
