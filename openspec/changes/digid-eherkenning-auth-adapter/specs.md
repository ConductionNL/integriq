# Specs — DigiD + eHerkenning Authentication Broker

**Status**: proposed
**Scope**: openconnector
**Tier**: authentication-broker
**Depends on**: openregister (RegisterObject, encryption-service ADR-016, audit-trail-immutable ADR-022), digikoppeling-adapter (DigikoppelingCertificate schema), prometheus-metrics (openconnector), Logius (DigiD Koppelvlakspecificatie, eHerkenning Afsprakenstelsel, Routeringsdienst Koppelvlakspec), OASIS SAML 2.0, eIDAS verordening (EU 910/2014), Forum Standaardisatie

## REQ-001: DigiD SAML2 Web Browser SSO Profile

The adapter SHALL implement the DigiD SAML2 Web Browser SSO Profile via the Routeringsdienst, handling AuthnRequest construction, assertion validation, and LoA enforcement at both request and response.

#### Scenario: DigiD authentication flow initiation

**GIVEN** a configured DigiD `AuthBrokerConfig` with valid PKIoverheid signing/encryption certificates, a `entityId` registered at the Routeringsdienst, and a consuming app that redirects a citizen to `/auth/digid/start?consumingAppId={appId}&callbackUrl={url}&loa=substantieel`

**WHEN** the adapter handles the start request

**THEN** it:
- Creates an `AuthSession` with `state=initiated`, `loaRequested=substantieel`, `means=digid`, `consumingAppId={appId}`, `consumingAppCallbackUrl={url}`, `ipAddress={client-ip}`, `userAgent={user-agent}`
- Constructs a signed SAML AuthnRequest with `AuthnContextClassRef` set to the Logius URI for "substantieel" LoA
- Sets `Comparison=minimum` on the AuthnContextClassRef to allow higher LoA to be returned
- Stores the SAML `RequestId` on the session for later InResponseTo matching
- Redirects the browser to the Routeringsdienst's SSO URL with the AuthnRequest in the `SAMLRequest` parameter (HTTP Redirect binding per OASIS spec)
- Writes an `authnrequest-sent` audit event with `details: { requestId: "...", loa: "substantieel" }`

#### Scenario: DigiD assertion validation and session completion

**GIVEN** the citizen authenticates at DigiD and is redirected back to the AssertionConsumer endpoint (`/auth/digid/callback`) with a signed SAML response

**WHEN** the adapter handles the POST with the SAMLResponse and RelayState

**THEN** it:
- Verifies the assertion signature against the IdP signing certificate from the cached metadata (REQ-007)
- Verifies the assertion's `InResponseTo` attribute matches the stored SAML `RequestId` (replay protection)
- Verifies the assertion has not been replayed (assertion ID not seen in the last 8 hours) per REQ-008
- Verifies the assertion's `NotBefore` and `NotOnOrAfter` conditions (with ±60 second clock-skew tolerance)
- Extracts the LoA from the assertion and verifies it is at least the requested LoA (or rejects with `assertion-rejected-loa` per REQ-004)
- Extracts the BSN attribute from the assertion (typically `urn:nl:bsn:id` or Logius equivalent)
- Detects the DigiD Machtigen `Representee` attribute if present (see REQ-005)
- Checks for fraud markers on the extracted BSN (see REQ-010); if a marker exists, rejects with a generic "service temporarily unavailable" error without revealing the marker
- Creates an `AuthIdentity` record with:
  - `sessionRef=<current-session>`
  - `means=digid`
  - `loaActual=<assertion-loa>`
  - `subjectId=<bsn-or-pseudobsn>` (per `AuthAttributeMapping.bsnPseudoBsnMode`)
  - `bsnEncrypted=<encrypted-real-bsn>` (only if pseudo-BSN mode is NOT active; encrypted via ADR-016)
  - `authenticatedAt=<now>`
  - `expiresAt=<now + sessionLifetimeDays>`
- Updates the session to `state=authenticated`
- Writes an `authenticated` audit event with `details: { bsn_encrypted: true, loa_actual: "substantieel" }`
- Redirects the browser back to the consuming app's callback URL with `code=<sessionId>` as a one-time code (consumed by the next request)

#### Scenario: Reviewer confirms SAML request/response flow

**Reviewer gate**: Every `AuthnRequest` is constructed with SimpleSAMLphp or php-saml library (never custom XML); every assertion is validated via the same library's assertion validator. Grep pattern: `SimpleSAML_Auth_SAML20_ProcessResponse` or equivalent library method used in the session validation path. No hand-rolled XML parsing.

---

## REQ-002: Pseudo-BSN translation

The adapter SHALL support per-app and per-tenant pseudo-BSN modes where the consuming app receives a stable per-app pseudoniem instead of the real BSN, computed via HMAC-SHA256 per-app derivation.

#### Scenario: Per-app pseudo-BSN derivation

**GIVEN** an `AuthAttributeMapping` has `bsnPseudoBsnMode=pseudobsn-per-app` with a configured `pseudobsnSecret` (256-bit, stored encrypted in Nextcloud `IAppConfig` per ADR-016)

**WHEN** the adapter processes the DigiD assertion and extracts the real BSN `123456789`

**THEN** it:
- Derives `pseudo_bsn = HMAC-SHA256( key=pseudobsnSecret, message="123456789" )` truncated to 19 characters to mimic BSN format
- Sets `AuthIdentity.subjectId=<pseudo-bsn>`
- Does NOT persist `bsnEncrypted` on the AuthIdentity (the real BSN is discarded; only the pseudo-BSN is stored)
- Returns the pseudo-BSN to the consuming app; the consuming app can recognise returning citizens using the pseudo-BSN without ever seeing the real BSN

#### Scenario: Per-tenant shared pseudo-BSN

**GIVEN** an `AuthAttributeMapping` has `bsnPseudoBsnMode=pseudobsn-shared` with a tenant-wide secret stored in Nextcloud global settings

**WHEN** multiple consuming apps (A and B) authenticate the same citizen

**THEN** both apps receive the same pseudo-BSN (computed with the tenant-wide secret), so they can recognize the same citizen across apps without sharing the real BSN, AND each app's own `AuthAttributeMapping` can enforce per-app policy (e.g., app A always provisions users, app B requires pre-provisioning).

#### Scenario: No pseudo-BSN (real BSN mode)

**GIVEN** an `AuthAttributeMapping` has `bsnPseudoBsnMode=bsn` (default)

**WHEN** the adapter processes the assertion

**THEN** it:
- Stores `AuthIdentity.subjectId=<real-bsn>` (plaintext on the register object, encrypted at rest via OR's schema encryption per ADR-016)
- Stores `AuthIdentity.bsnEncrypted=true` on the audit event (but not the plaintext; encryption is enforcement-level)
- Returns the real BSN to the consuming app (necessary for apps that need to query BRP, HaalCentraal, etc.)

#### Scenario: Reviewer confirms pseudo-BSN is deterministic HMAC

**Reviewer gate**: Pseudo-BSN derivation uses `hash_hmac('sha256', $realBsn, $appSecret)` or equivalent, never a random hash or JWT. Same input always produces same output. Grep pattern: `hash_hmac.*sha256` in the derivation path.

---

## REQ-003: eHerkenning authentication with service catalog

The adapter SHALL implement eHerkenning SAML2 with the service-catalog registration mandatory for the eIDAS-aligned eHerkenning profile. The broker maintains an `AuthBrokerService` record per registered service; each service is submitted to Logius and registered in the Routeringsdienst service catalog.

#### Scenario: eHerkenning authentication with service registration

**GIVEN** an `AuthBrokerConfig` with `means=eherkenning` and an `AuthBrokerService` record registered for `serviceUuid=12345678-1234-1234-1234-123456789012`, `serviceName="Aanvraag Bouwvergunning"`, `entityConcernedTypesAllowed=["urn:etoegang:1.9:EntityConcernedID:RSIN"]`, `loa=EH3`

**WHEN** a consuming app (e.g., vergunningportaal) initiates eHerkenning auth with `consumingAppId=vergunningportaal&serviceUuid=12345678-1234-1234-1234-123456789012&loa=EH3`

**THEN** the adapter:
- Constructs a SAML AuthnRequest with the service UUID in the `RequestedAuthnContext` using `AuthnContextDeclRef` (NOT `ClassRef` per eIDAS alignment) pointing at the registered service
- Sets `Comparison=minimum` for the LoA
- Redirects to the Routeringsdienst's SSO URL
- Stores `serviceUuid` on the `AuthSession`

#### Scenario: eHerkenning assertion with dual identity extraction

**GIVEN** the company representative authenticates at eHerkenning and returns an assertion containing:
- `legalEntityIdentifier` (RSIN or KvK number of the company)
- `actingSubjectId` (eIDAS-pseudoniem of the natural person acting on behalf of the company)
- Optionally, a multi-level ketenmachtiging chain (intermediary A → company B → company C)

**WHEN** the adapter processes the assertion

**THEN** it:
- Extracts `legalSubjectId=<RSIN-or-KvK>` (the company) and `actingSubjectId=<eidas-pseudoniem>` (the natural person)
- Creates an `AuthIdentity` with both:
  - `subjectId=<acting-subject-id>` (the natural person acting)
  - `legalSubjectId=<rsin-or-kvk>` (the company being represented)
  - `serviceUuid=<from-request>` for audit and billing reconciliation
- Returns both identities to the consuming app, enabling authorization decisions like "user X (natural person) is acting on behalf of company Y (legal entity)"

#### Scenario: Reviewer confirms service-catalog registration

**Reviewer gate**: Every eHerkenning `AuthBrokerService` references a confirmed registration in the Logius service catalog. Admin UI shows a "Confirmed at Logius" timestamp for each service. Grep pattern: `AuthBrokerService` record with populated `logius_confirmed_at` timestamp and audit trail showing the registration submission.

---

## REQ-004: LoA (Level-of-Assurance) enforcement

The adapter SHALL enforce LoA requirements both in the AuthnRequest and in assertion validation, rejecting assertions that fall below the requested LoA.

#### Scenario: LoA enforcement at request and response

**GIVEN** a consuming app requests `loa=substantieel` (eIDAS substantial) for a high-risk service (e.g., subsidy application)

**WHEN** the adapter sends the AuthnRequest to the Routeringsdienst

**THEN** it sets `AuthnContextClassRef` to the Logius URI for substantieel and `Comparison=minimum` to allow higher LoA.

**GIVEN** the Routeringsdienst returns an assertion at `loa=midden` (eIDAS medium, lower than requested)

**WHEN** the adapter validates the assertion

**THEN** it rejects with `assertion-rejected-loa` and writes a `assertion-rejected-loa` audit event, returning a generic error to the citizen ("authenticatie niet voldoende gewaarborgd").

#### Scenario: Higher LoA is always acceptable

**GIVEN** a consuming app requests `loa=basic`

**WHEN** the assertion arrives at `loa=hoog` (higher than requested)

**THEN** the assertion is accepted, and `AuthIdentity.loaActual=hoog` is recorded so the consuming app can grant elevated functionality if appropriate (e.g., "user authenticated at high LoA, grant access to sensitive operations").

#### Scenario: LoA context URIs are per-means

| Means | LoA levels | SAML URI pattern |
|---|---|---|
| DigiD | basic, midden, substantieel, hoog | `urn:nl:bsn:authn:context:digid:loX` where X ∈ [1,2,3,4] |
| eHerkenning | EH2+, EH3, EH4 | `urn:etoegang:1.9:assurance-class:EHX` where X ∈ [2,3,4] |

The URIs are hardcoded per the Logius spec; consuming apps do NOT configure them.

#### Scenario: Reviewer confirms LoA validation

**Reviewer gate**: LoA values are only ever set from the SAML assertion's `AuthnContextClassRef` attribute, never from request parameters or app configuration. Grep pattern: assertion validation extracts the LoA from `assertion->getAuthnContext()` and compares against the session's requested LoA. No trusting of client-supplied LoA values.

---

## REQ-005: DigiD Machtigen and eHerkenning ketenmachtiging

The adapter SHALL detect, persist, and surface machtigingen relationships (delegation, authorization chains) in the returned assertion.

#### Scenario: DigiD Machtigen (citizen acting on behalf of another citizen)

**GIVEN** a citizen logs in with DigiD Machtigen, acting on behalf of another citizen

**WHEN** the assertion contains the `Representee` attribute (e.g., BSN of the represented citizen)

**THEN** the adapter:
- Detects `machtigingType=digid-machtigen`
- Stores `AuthIdentity.machtigingFrom=<represented-bsn-or-pseudobsn>` (the citizen being represented)
- Stores `AuthIdentity.subjectId=<acting-bsn-or-pseudobsn>` (the citizen acting on behalf)
- Writes a `machtigen-detected` audit event with `details: { represented_bsn_encrypted: true, acting_bsn_encrypted: true }`
- Returns both identities to the consuming app so it can show "u handelt namens X" and enforce per-app policy (e.g., only certain apps allow Machtigen, or only certain BSNs can be represented)

#### Scenario: eHerkenning ketenmachtiging (multi-level delegation chain)

**GIVEN** a natural person (intermediary A) is acting on behalf of company B, which has been authorized by company C (multi-level chain: A → B → C)

**WHEN** the assertion contains a multi-level ketenmachtiging chain

**THEN** the adapter:
- Detects `machtigingType=eherkenning-ketenmachtiging`
- Stores `machtigingFrom` as a structured array of the full chain: `[{level: 1, legalSubjectId: "C-RSIN"}, {level: 2, legalSubjectId: "B-RSIN"}, {level: 3, actingSubjectId: "A-pseudoniem"}]`
- Writes a `machtigen-detected` audit event with the full chain in `details`
- Returns the chain to the consuming app so it can enforce policy at each level (e.g., "only authorize if company C is registered at our agency")

#### Scenario: Machtigen is optional (may not appear in every assertion)

Not every DigiD or eHerkenning assertion includes machtigingen. If an assertion does NOT contain the machtiging attributes, the adapter sets `machtigingType=none` and proceeds normally.

#### Scenario: Reviewer confirms machtigen detection

**Reviewer gate**: Every machtigen detection is logged to an audit event with the full chain/representation visible. Grep pattern: `machtigen-detected` audit events contain `machtigingFrom` array with chain details; no machtigen flows silently omitted from audit.

---

## REQ-006: Nextcloud user mapping policy

The adapter SHALL implement four provisioning policies for mapping authenticated identities to Nextcloud users.

#### Scenario: Ephemeral policy (no user provisioning)

**GIVEN** a consuming app's `AuthAttributeMapping.provisioningPolicy=ephemeral` with `linkLifetimeDays=1`

**WHEN** authentication succeeds

**THEN** the adapter:
- Does NOT create a Nextcloud user
- Sets `AuthIdentity.linkedNextcloudUserId=null`
- Returns the `AuthIdentity.id` itself as a bearer token (short-lived, expires in 1 day)
- Consuming app uses the bearer token for a single API call or session; the identity expires after 1 day and garbage collection removes the `AuthIdentity` record

**Use case**: Citizen-portal intake flow where the citizen uploads documents or starts a zaak, then leaves. No persistent Nextcloud user is created; the identity is ephemeral.

#### Scenario: Persistent-on-first-login policy

**GIVEN** a consuming app's `AuthAttributeMapping.provisioningPolicy=persistent-on-first-login` with `defaultGroup="portal-users"` and `mappingRules={email: "urn:nl:digid:saml:email", displayName: "urn:nl:digid:saml:name"}`

**WHEN** an identity authenticates for the first time

**THEN** the adapter:
- Calls `UserManager::createUser()` with username derived from `subjectId` (e.g., "bsn_123456789" or "pseudobsn_<hash>")
- Maps SAML attributes to Nextcloud user fields per `mappingRules`
- Adds the user to `defaultGroup` ("portal-users")
- Sets `linkedNextcloudUserId=<new-user-id>` on the `AuthIdentity`
- Writes a `nextcloud-user-provisioned` audit event
- Returns the `linkedNextcloudUserId`

**WHEN** the same identity authenticates again

**THEN** the adapter:
- Looks up the existing Nextcloud user by username
- Sets `linkedNextcloudUserId=<existing-user-id>` on the new `AuthIdentity`
- Writes a `nextcloud-user-linked` audit event
- Returns the `linkedNextcloudUserId`

**Use case**: Company portal where the company representative needs persistent access across multiple sessions. User is provisioned on first login and reused on subsequent logins.

#### Scenario: Persistent-pre-provisioned policy

**GIVEN** a consuming app's `AuthAttributeMapping.provisioningPolicy=persistent-pre-provisioned`

**WHEN** an identity authenticates and there is no pre-existing Nextcloud user with a username matching the `subjectId`

**THEN** the adapter:
- Rejects the authentication with `user-not-pre-provisioned` error
- Writes a `nextcloud-user-not-found` audit event with an admin-readable message ("user must be pre-provisioned by admin")
- Returns an HTTP 403 to the citizen with a generic message ("account is not yet set up; contact your administrator")

**Use case**: Back-office staff portal where admins must explicitly create user accounts before staff can authenticate.

#### Scenario: Never policy

**GIVEN** a consuming app's `AuthAttributeMapping.provisioningPolicy=never`

**WHEN** authentication succeeds

**THEN** the adapter:
- Does NOT create or link to any Nextcloud user
- Sets `linkedNextcloudUserId=null`
- Returns the `AuthIdentity.id` (like ephemeral, but with a longer lifetime if configured)
- Consuming app is responsible for any user-mapping logic; the adapter provides the identity only

**Use case**: API-only integration where the consuming app manages user relationships independently.

#### Scenario: Reviewer confirms provisioning policy is honored

**Reviewer gate**: Every authentication path checks `AuthAttributeMapping.provisioningPolicy` before calling `UserManager`. Grep pattern: policy check appears before any user-creation or lookup logic. No default behavior that bypasses the configured policy.

---

## REQ-007: Metadata refresh and certificate rotation

The adapter SHALL keep IdP metadata fresh and detect IdP certificate rotation transparently.

#### Scenario: Daily metadata refresh with signature validation

**GIVEN** the openconnector scheduled job `auth-metadata-refresh` runs daily at 02:00 UTC

**WHEN** the job executes

**THEN** the adapter:
- Fetches the Routeringsdienst metadata from `AuthBrokerConfig.metadataUrl`
- Verifies the metadata's XML signature using the Routeringsdienst's signing certificate (hardcoded or configured via a trust anchor)
- Extracts the IdP signing certificates (which may include both the current and next certificate during rotation windows)
- Updates `AuthMetadataCache` with:
  - `metadataXml=<fetched-xml>`
  - `signatureValid=true/false`
  - `fetchedAt=<now>`
  - `expiresAt=<metadata-validUntil-or-now+30days>`
  - `signingCertificates=[cert1, cert2, ...]` (array of PEM-encoded certs)
- From that point onwards, assertions signed with either the current or the next IdP cert are accepted

#### Scenario: Certificate rotation window detection

**GIVEN** the Routeringsdienst rotates the IdP signing certificate from cert-A to cert-B, and the metadata contains both during a transition window

**WHEN** an assertion arrives signed by cert-B (not yet in the local cache)

**THEN** the adapter:
- Fails signature validation against cached certs (cert-A only)
- On failure, immediately refetches metadata (on-demand refresh)
- Now the cache contains both cert-A and cert-B
- Retries assertion signature validation; cert-B now validates successfully
- Proceeds with session completion

#### Scenario: SP certificate expiry monitoring and notification

**GIVEN** the scheduled job `auth-sp-cert-check` runs daily at 06:00 UTC and checks the SP signing and encryption certificates

**WHEN** a certificate is found with expiry in:
- **60-30 days**: Sends a warning notification to the `auth_admin` group: "SP certificate for {entityId} expires in {days} days. Rotation instructions: [link to runbook]"
- **30-7 days**: Sends a critical notification daily
- **< 7 days**: Triggers page-on-call if configured; updates `mydash` widget to red
- **0 days (expired)**: New auth requests are rejected with `sp-certificate-expired` error; existing valid sessions continue until their own expiry

**THEN** the adapter:
- Logs the expiry check result to the audit trail
- Updates the `mydash` certificate-rotation widget with countdown and status
- If expiry is imminent, includes a clickable link to the certificate-rotation runbook in the notification

#### Scenario: Metadata fetch failure handling

**GIVEN** the metadata-refresh job runs but the Routeringsdienst is temporarily unreachable (network error, 5xx response)

**WHEN** the fetch fails

**THEN** the adapter:
- Logs a WARNING to the error log (not an ERROR, since this is transient)
- Continues using the cached metadata (if `expiresAt` is in the future)
- Retries the fetch on the next scheduled run (1 hour later, or immediately if on-demand)
- Does NOT reject new authentication requests unless the cached metadata has actually expired (> expiresAt)

**GIVEN** the cached metadata has expired and a fetch attempt fails

**WHEN** a new authentication request arrives

**THEN** the adapter:
- Rejects the authentication with `metadata-unavailable` error
- Logs a CRITICAL alert
- Notifies the `auth_admin` group immediately (not just the scheduled notification)

---

## REQ-008: Replay protection and assertion uniqueness

The adapter SHALL prevent assertion replay attacks per the SAML 2.0 profile and maintain a replay cache of assertion IDs.

#### Scenario: Assertion ID replay detection

**GIVEN** an assertion arrives at the AssertionConsumer with an `AssertionID` (e.g., `_8e8dc5f69a98cc4c1ff3427e5ce34606fd672b47`)

**WHEN** the adapter checks the replay cache (in-memory cache or redis, per deployment)

**THEN** it:
- Checks if the `AssertionID` has been processed in the last 8 hours
- If YES, rejects the assertion with `assertion-rejected-replay`, logs an error, writes a `assertion-rejected-replay` audit event, and returns HTTP 400 to the browser with a generic error ("authenticatie mislukt")
- If NO, caches the ID for 8 hours and proceeds with validation

**Scenario: Clock-skew-aware validity window validation**

**GIVEN** an assertion's `NotBefore` condition is 2026-05-22T10:00:00Z and the current server time is 2026-05-22T09:59:30Z (30 seconds before NotBefore)

**WHEN** the adapter validates the conditions

**THEN** it:
- Tolerates ±60 seconds of clock skew
- Treats the assertion as valid (because current time - 60 seconds = 09:59:30 - 60 = 09:58:30, which is before NotBefore, but the tolerance brings it into range)
- Proceeds with session completion
- Logs a DEBUG message: "Assertion validated with clock-skew tolerance of +{skew_seconds}s"

**GIVEN** an assertion's `NotOnOrAfter` condition is 2026-05-22T11:00:00Z and the current time is 2026-05-22T11:00:35Z (35 seconds after NotOnOrAfter)

**WHEN** the adapter validates the conditions

**THEN** it:
- Tolerates ±60 seconds of clock skew
- Treats the assertion as valid (because current time + 60 seconds = 11:00:35 + 60 = 11:01:35, which is after NotOnOrAfter but within the tolerance)
- Proceeds with session completion

**GIVEN** an assertion's `NotOnOrAfter` is 2026-05-22T11:00:00Z and the current time is 2026-05-22T11:02:00Z (2 minutes after NotOnOrAfter)

**WHEN** the adapter validates the conditions

**THEN** it:
- Exceeds the 60-second tolerance
- Rejects the assertion with `assertion-rejected-expired`
- Logs an error: "Assertion has expired; clock skew beyond tolerance. Check server time."
- Notifies `auth_admin` group if clock skew >= 300 seconds (possible NTP issue)

---

## REQ-009: OIDC bridging

The adapter SHALL expose a consuming-side OIDC interface for relying parties that prefer OIDC over SAML, even though the upstream Routeringsdienst remains SAML.

#### Scenario: OIDC Authorization Code flow with underlying SAML

**GIVEN** a consuming app (e.g., a third-party service) prefers OIDC and registers itself as an OIDC client at the adapter with:
- `clientId: "thirdparty-portal"`
- `clientSecret: "..."`
- `redirectUris: ["https://thirdparty.example.com/callback"]`

**WHEN** the consuming app initiates an OIDC Authorization Code flow to `/oidc/authorize?client_id=thirdparty-portal&response_type=code&scope=openid&acr_values=substantieel&means=digid&redirect_uri=https://thirdparty.example.com/callback`

**THEN** the adapter:
- Validates the `client_id` and `redirect_uri`
- Interprets `acr_values=substantieel` as the requested LoA
- Interprets `means=digid` as the authentication means
- Transparently performs the underlying SAML flow to the Routeringsdienst (same as REQ-001)
- On successful SAML authentication, exchanges the resulting `AuthIdentity` for an OIDC authorization code
- Returns the `code` to the consuming app's redirect URI

**WHEN** the consuming app exchanges the code at `/oidc/token`

**THEN** the adapter:
- Validates the code and `client_secret`
- Returns an `id_token` (signed JWT) containing:
  - `sub: <subject-id-or-bsn>`
  - `acr: <actual-loa>` (substantieel)
  - `aud: <client-id>`
  - `iss: <adapter-issuer-uri>`
  - `iat: <issued-at>`
  - `exp: <expiry>`
  - Optional claims: `bsn`, `pseudobsn`, `legalSubjectId`, `actingSubjectId`, `machtiging` (if applicable)
- Optionally returns a `refresh_token` (implementation-dependent)

#### Scenario: OIDC UserInfo endpoint

**GIVEN** a consuming app has obtained an `access_token` from the token endpoint

**WHEN** the consuming app calls `/oidc/userinfo` with the `access_token`

**THEN** the adapter:
- Validates the token
- Returns a JSON object with user information (subset of the id_token claims):
  ```json
  {
    "sub": "bsn_123456789",
    "bsn": "123456789",
    "legalSubjectId": "RSIN_123456789",
    "actingSubjectId": "pseudoniem_...",
    "machtiging": { "type": "none" }
  }
  ```

#### Scenario: Reviewer confirms OIDC is built on SAML, not a duplicate auth path

**Reviewer gate**: OIDC endpoints call through to the same SAML authentication flow (REQ-001 / REQ-003). No separate OIDC-only code path. Grep pattern: `/oidc/authorize` endpoint internally redirects to `/auth/{means}/start` with the translated parameters. OIDC is a consumer-side convenience bridge, not a duplicate authentication implementation.

---

## REQ-010: Fraud markers and session blocking

The adapter SHALL support per-identity fraud markers that prevent further authentications from a known-compromised identifier.

#### Scenario: Setting a fraud marker

**GIVEN** an operator with `auth_admin` role opens the fraud-markers admin UI and enters:
- Identifier: "bsn_123456789" (real BSN) or "pseudobsn_<hash>" (if using pseudo-BSN mode)
- Reason: "SIM-swap fraud reported"
- Unblock-after date: "2026-06-22" (30 days from now)

**WHEN** the operator clicks "Set marker"

**THEN** the adapter:
- Creates an `AuthAuditEvent` with:
  - `eventType: fraud-marker-set`
  - `actor: <operator-username>`
  - `details: { identifier: "...", reason: "...", unblock_after: "2026-06-22" }`
  - `timestamp: <now>`
- Stores a fraud marker record (not in the audit trail, but in a separate queryable table) with the identifier and unblock date
- Notifies the `auth_admin` group: "Fraud marker set by {operator} on {identifier} until {date}. Reason: {reason}."
- No notification is sent to the citizen or any external system

#### Scenario: Fraud marker blocking authentication

**GIVEN** a fraud marker exists on "bsn_123456789" until "2026-06-22"

**WHEN** an authentication arrives whose resolved `subjectId` matches the marker (same BSN or pseudo-BSN)

**THEN** the adapter:
- Writes a `fraud-marker-set` audit event with `details: { blocked: true, marker_reason: "SIM-swap fraud..." }`
- Refuses to create an `AuthIdentity` (stops the session at the assertion-received step)
- Returns an HTTP 403 to the browser with a generic message: "authenticatie tijdelijk niet beschikbaar" (service temporarily unavailable) — NO message revealing that a marker exists
- The citizen sees no explanation; from their perspective, the service is temporarily down

#### Scenario: Fraud marker auto-expiry

**GIVEN** a fraud marker with `unblock_after: 2026-06-22` exists

**WHEN** the current date is 2026-06-23 or later

**THEN** the adapter:
- Automatically allows authentication from the identifier
- A daily job removes expired markers from the fraud-marker table
- The removal is itself logged as an audit event: `eventType: fraud-marker-expired`

#### Scenario: Unsetting a fraud marker

**GIVEN** an operator with `auth_admin` role decides to manually unblock an identifier before the auto-expiry date

**WHEN** the operator clicks "Unset marker"

**THEN** the adapter:
- Removes the fraud marker
- Creates an `AuthAuditEvent` with:
  - `eventType: fraud-marker-unset`
  - `actor: <operator-username>`
  - `details: { identifier: "...", reason: "manual unblock" }`
- Notifies the `auth_admin` group: "Fraud marker unset by {operator} on {identifier}."

#### Scenario: Reviewer confirms fraud markers are invisible to citizens

**Reviewer gate**: No fraud marker details are ever returned to the citizen or consuming app in an error message. Grep pattern: fraud-marker rejection path does NOT reference `fraud_marker` in the response payload; it uses a generic "service unavailable" error instead.

---

## Verification

- [ ] All 10 core requirements (REQ-001 through REQ-010) have GIVEN/WHEN/THEN scenarios
- [ ] Every scenario has a reviewer-gate checkpoint naming the grep pattern or expected audit event
- [ ] All entities reference `AuthBrokerConfig`, `AuthSession`, `AuthIdentity`, `AuthAuditEvent` as appropriate
- [ ] LoA URIs are specified per means (DigiD vs eHerkenning)
- [ ] Four provisioning policies are explicitly described
- [ ] SAML library usage (SimpleSAMLphp) is named, never custom XML parsing
- [ ] Audit events are immutable and attributed to actors (operators or system)
- [ ] Clock-skew tolerance is set to ±60 seconds
- [ ] Replay cache is 8 hours
- [ ] Metadata refresh is daily + on-demand
- [ ] Certificate expiry notifications start 60 days before expiry
- [ ] Fraud markers are invisible to citizens
- [ ] Pseudo-BSN is HMAC-SHA256, deterministic, not random
