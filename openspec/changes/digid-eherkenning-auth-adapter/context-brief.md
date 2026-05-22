---
status: draft
---
# DigiD + eHerkenning authentication broker

## Purpose

DigiD (for natural persons / Dutch citizens) and eHerkenning (for legal entities and the people who act on their behalf) are the two mandatory authentication means for accessing Dutch government services. Any Conduction app that exposes a portal where a citizen logs in to inspect their own zaak, file a request, or upload documents needs DigiD; any app that exposes a portal where a company acts (vergunningaanvraag, subsidie, aanbesteding) needs eHerkenning. Optional sister means — Idensys (already deprecated for new connections but still live for some), IRMA / Yivi (privacy-friendly attribute-based credentials gaining traction at municipalities), and EU-eIDAS notified schemes (Belgian itsme, German Personalausweis, etc.) for cross-border use — round out the field. Today no Conduction app speaks any of these directly: each app that has tried has bounced off the Logius accreditation process, the Routeringsdienst contract, the SAML metadata exchange, and the PKIoverheid certificate management, and has ended up either contracting a commercial broker (Signicat, Connectis, KPN Lokale Overheid) or shipping no public authentication at all.

This spec defines `digid-eherkenning-auth-adapter` — a broker that lives inside OpenConnector and exposes DigiD and eHerkenning (with optional Idensys and IRMA/Yivi support) as a clean SAML2 / OIDC bridge that consuming Nextcloud apps can plug into via the existing Nextcloud user-mapping infrastructure. The adapter handles the SAML AuthnRequest construction, the AssertionConsumer endpoint, the LoA (level-of-assurance) enforcement, the BSN ↔ Pseudo-BSN translation, the eHerkenning service catalog registration, the machtigingen flow (DigiD Machtigen, eHerkenning ketenmachtiging), the metadata refresh from the Routeringsdienst, the certificate rotation, the per-session audit trail required for fraud investigation, and the optional fallback to OIDC for relying parties that prefer the modern profile.

Critically, this adapter is NOT a replacement for Nextcloud's own user authentication — Nextcloud users still log in with Nextcloud credentials for admin/back-office access. This adapter is for the public-facing portals where an external citizen or company representative authenticates with their national means, and where the resulting identity is either ephemeral (used to authorise a single API call) or mapped to a long-lived Nextcloud user with limited rights (for portal access). The mapping policy is explicit and per-app.

Out of scope: BSN-based authorization decisions (those belong in the consuming app); DigiD-app push notifications (handled by Logius); the user-side Nextcloud user provisioning for back-office staff (handled by Nextcloud LDAP/SSO modules); offering the adapter as an Identity Provider to external systems (the adapter is strictly a Service Provider towards DigiD/eHerkenning).

## Data Model

The adapter introduces seven OpenRegister schemas in a dedicated `openconnector-auth` register:

**AuthBrokerConfig** — one row per Routeringsdienst aansluiting. Fields: `means` (enum: digid / eherkenning / idensys / irma / eidas), `environment` (enum: preprod / production), `entityId` (the SP entityId as registered at Logius / the Routeringsdienst), `metadataUrl` (the IdP metadata URL of the Routeringsdienst), `routeringsdienstContractRef` (a Nextcloud Files reference to the signed contract), `signingCertificateRef` (link to the PKIoverheid certificate used to sign AuthnRequests — same DigikoppelingCertificate schema reused), `encryptionCertificateRef` (separate PKIoverheid cert used for assertion decryption), `assertionConsumerUrl`, `singleLogoutUrl`, `defaultLoa` (Level-of-Assurance: for DigiD `basic` / `midden` / `substantieel` / `hoog`; for eHerkenning `EH2+` / `EH3` / `EH4`), `attributesRequested` (array of SAML attribute URIs), `enabled`.

**AuthBrokerService** — for eHerkenning specifically, every "service" the SP offers must be registered in the eHerkenning service catalog. Fields: `brokerConfigRef`, `serviceUuid` (the OIN-anchored service UUID), `serviceName`, `serviceDescription`, `entityConcernedTypesAllowed` (e.g., `urn:etoegang:1.9:EntityConcernedID:RSIN`, `urn:etoegang:1.9:EntityConcernedID:KvKnr`), `requestedAttributes`, `loa`, `serviceRestrictionsKnown` (e.g., specific branche). DigiD does not use services — this schema is empty for DigiD configurations.

**AuthSession** — every authentication attempt creates a session row. Fields: `brokerConfigRef`, `sessionId` (UUID, the cookie value handed to the browser), `state` (enum: initiated / authnrequest-sent / assertion-received / authenticated / failed / logged-out / expired), `requestId` (SAML RequestId for matching the response), `relayState`, `initiatedAt`, `authenticatedAt`, `expiresAt`, `loaActual` (the LoA actually delivered, which may be higher than `loaRequested`), `loaRequested`, `means`, `errorCode`, `errorDescription`, `consumingAppId` (which Conduction app requested this auth), `consumingAppCallbackUrl`, `ipAddress`, `userAgent`.

**AuthIdentity** — the result of a successful authentication, ephemeral or persisted. Fields: `sessionRef`, `means`, `loaActual`, `subjectId` (BSN for DigiD without pseudoBSN; pseudoBSN if pseudoBSN-mode is on for this consuming app; for eHerkenning the actingSubjectId which is the eIDAS-pseudoniem of the natural person, plus the legalSubjectId which is the RSIN/KvK of the company), `bsnEncrypted` (encrypted at rest, only populated for DigiD non-pseudoBSN flows), `legalSubjectId` (RSIN or KvK number for eHerkenning), `actingSubjectId` (the natural-person eIDAS-pseudoniem who is acting on behalf of the legal subject), `serviceUuid` (for eHerkenning), `machtigingType` (enum: none / digid-machtigen / eherkenning-ketenmachtiging), `machtigingFrom` (the represented natural person for DigiD Machtigen, or the chain of represented entities for eHerkenning), `additionalAttributes` (JSON map of returned attributes beyond the identifier), `authenticatedAt`, `expiresAt`, `linkedNextcloudUserId` (nullable — only set when the consuming app's policy maps the identity to a persistent NC user).

**AuthAttributeMapping** — per-consuming-app rule for mapping returned SAML attributes to Nextcloud user fields (when a NC user is provisioned). Fields: `consumingAppId`, `means`, `mappingRules` (JSON: `{nextcloudFieldName: samlAttributeUri}`), `provisioningPolicy` (enum: never / ephemeral / persistent-on-first-login / persistent-pre-provisioned), `defaultGroup`, `bsnPseudoBsnMode` (enum: bsn / pseudobsn-per-app / pseudobsn-shared), `linkLifetimeDays` (for ephemeral, how long the AuthIdentity row lives before garbage collection).

**AuthMetadataCache** — cached IdP metadata from the Routeringsdienst. Fields: `brokerConfigRef`, `metadataXml`, `signatureValid` (boolean — metadata is signed by the Routeringsdienst), `fetchedAt`, `expiresAt`, `signingCertificates` (extracted IdP signing certificates for assertion verification). Refreshed every 24 hours and on the metadata's `validUntil` boundary.

**AuthAuditEvent** — append-only audit, separate from session because audit must outlive session expiry. Fields: `eventType` (enum: session-initiated / authnrequest-sent / assertion-received / assertion-rejected-signature / assertion-rejected-loa / assertion-rejected-replay / authenticated / machtigen-detected / nextcloud-user-provisioned / nextcloud-user-linked / logout / fraud-marker-set), `sessionRef`, `identityRef`, `actor` (system for SAML events, NC user for back-office events), `timestamp`, `details` (JSON: assertion ID, in-response-to, LoA in vs out, machtigen chain). Retention: 18 months minimum per Logius DigiD afsprakenstelsel for fraud investigation; consuming apps may extend.

## Requirements

### REQ-001: DigiD SAML2 authentication flow
The adapter SHALL implement the DigiD SAML2 Web Browser SSO Profile via the Routeringsdienst.

**GIVEN** a configured DigiD `AuthBrokerConfig` with valid PKIoverheid certificates and a consuming app that redirects a citizen to `/auth/digid/start?app={appId}&callback={url}&loa={level}`
**WHEN** the adapter handles the start request
**THEN** it creates an `AuthSession` with `state=initiated`, constructs a signed SAML AuthnRequest with `AuthnContextClassRef` matching the requested LoA, redirects the browser to the Routeringsdienst's SSO URL with the AuthnRequest in the `SAMLRequest` parameter, persists the SAML `RequestId` for later matching, and writes an `authnrequest-sent` audit event.

**GIVEN** the citizen authenticates at DigiD and is redirected back to the AssertionConsumer endpoint with a signed assertion
**WHEN** the adapter handles the AssertionConsumer POST
**THEN** it verifies the assertion's signature against the IdP cert from `AuthMetadataCache`, verifies `InResponseTo` matches a persisted `RequestId`, verifies the assertion has not been replayed (rejecting if the assertion ID has been seen in the last 8 hours), verifies the actual LoA is at least the requested LoA, extracts the BSN, persists an `AuthIdentity` with the BSN encrypted, writes an `authenticated` audit event, and redirects the browser back to the consuming app's callback URL with the session ID as a one-time code.

### REQ-002: Pseudo-BSN translation
The adapter SHALL support per-app pseudo-BSN mode where the consuming app receives a stable per-app pseudoniem instead of the real BSN, computed via the official Logius pseudo-BSN service or per-app HMAC derivation.

**GIVEN** an `AuthAttributeMapping` for a consuming app has `bsnPseudoBsnMode=pseudobsn-per-app`
**WHEN** the adapter processes the DigiD assertion
**THEN** the BSN is converted to a pseudo-BSN using HMAC-SHA256 with a per-app secret key, the real BSN is NOT persisted on the `AuthIdentity` (only the pseudo-BSN goes in `subjectId`), and the consuming app receives the pseudo-BSN such that it can recognise returning citizens without ever seeing the actual BSN.

**GIVEN** an `AuthAttributeMapping` has `bsnPseudoBsnMode=pseudobsn-shared`
**WHEN** the assertion is processed
**THEN** the same pseudo-BSN is computed using a fleet-wide (per-tenant) secret so multiple consuming apps within the same Nextcloud instance can recognise the same citizen across apps without sharing the real BSN.

### REQ-003: eHerkenning authentication with service catalog
The adapter SHALL implement eHerkenning SAML2 with the service-catalog registration mandatory for the eIDAS-aligned eHerkenning profile.

**GIVEN** a configured eHerkenning `AuthBrokerConfig` with registered `AuthBrokerService` rows in the service catalog at the Routeringsdienst
**WHEN** a consuming app starts an eHerkenning flow with a specific `serviceUuid`
**THEN** the adapter constructs the AuthnRequest with the service-catalog reference in the `RequestedAuthnContext`'s `AuthnContextDeclRef` (NOT `ClassRef`, per eIDAS) pointing at the registered service, sets `Comparison=minimum` for the LoA, and proceeds as in REQ-001 but with the eHerkenning-specific `EntityConcernedId` attribute requests.

**GIVEN** a successful eHerkenning assertion arrives
**WHEN** the adapter processes it
**THEN** the `legalSubjectId` (RSIN or KvK) and `actingSubjectId` (eIDAS-pseudoniem of the natural-person acting) are both extracted and persisted on `AuthIdentity`, the consuming app receives both so it can do "user X is acting on behalf of company Y" authorization decisions, and the `serviceUuid` is recorded on the audit event for billing reconciliation.

### REQ-004: LoA (Level-of-Assurance) enforcement
The adapter SHALL enforce LoA requirements both in the AuthnRequest and in assertion validation.

**GIVEN** a consuming app requests `loa=substantieel` (eIDAS substantial / DigiD Substantieel)
**WHEN** the adapter sends the AuthnRequest
**THEN** it sets `AuthnContextClassRef` to the substantial-LoA URI and `Comparison=minimum`, and on the returning assertion it rejects the assertion if the actual LoA is lower than substantial — even if DigiD returned a valid assertion at a lower level — by writing an `assertion-rejected-loa` audit event and surfacing a `loa-too-low` error to the consuming app.

**GIVEN** a consuming app requests `loa=basic` for a low-risk service
**WHEN** the assertion comes back at LoA `hoog`
**THEN** the assertion is accepted (higher is always acceptable), and `loaActual=hoog` is recorded on the `AuthIdentity` so the consuming app can grant elevated functionality if appropriate.

### REQ-005: DigiD Machtigen and eHerkenning ketenmachtiging
The adapter SHALL detect, persist, and surface machtigingen relationships in the returned assertion.

**GIVEN** a DigiD assertion contains the DigiD Machtigen `Representee` attribute (citizen acting on behalf of another citizen)
**WHEN** the adapter processes the assertion
**THEN** the `AuthIdentity.machtigingType=digid-machtigen`, `machtigingFrom=<represented BSN>` (or pseudo-BSN), the acting party is the primary `subjectId`, a `machtigen-detected` audit event is written, and the consuming app receives both parties so it can show "u handelt namens X" in the UI.

**GIVEN** an eHerkenning assertion contains a multi-level ketenmachtiging chain (e.g., intermediair A acting on behalf of company B which has been authorised by company C)
**WHEN** the adapter processes the assertion
**THEN** the full chain is persisted as a structured array on `machtigingFrom`, each level's `legalSubjectId` is recorded, and the consuming app can enforce policy at each level (e.g., "only authorise if every level in the chain is registered at our agency").

### REQ-006: Nextcloud user mapping policy
The adapter SHALL implement four provisioning policies for mapping authenticated identities to Nextcloud users.

**GIVEN** a consuming app's `AuthAttributeMapping.provisioningPolicy=ephemeral`
**WHEN** authentication succeeds
**THEN** NO Nextcloud user is created, the `AuthIdentity` lives for `linkLifetimeDays` (default 1), and the consuming app uses the `AuthIdentity.id` as a short-lived bearer credential for API calls.

**GIVEN** the policy is `persistent-on-first-login`
**WHEN** an identity authenticates for the first time
**THEN** a Nextcloud user is provisioned with username derived from `subjectId` (BSN, pseudo-BSN, or RSIN), the user is added to `defaultGroup`, attributes are mapped per `mappingRules`, `linkedNextcloudUserId` is set on the `AuthIdentity`, and subsequent logins from the same `subjectId` reuse the existing NC user.

**GIVEN** the policy is `persistent-pre-provisioned`
**WHEN** an identity authenticates and there is no matching NC user
**THEN** the auth fails with `user-not-pre-provisioned` and an admin-readable audit event so a back-office user can be informed without exposing details to the citizen.

### REQ-007: Metadata refresh and certificate rotation
The adapter SHALL keep IdP metadata fresh and detect IdP certificate rotation transparently.

**GIVEN** the daily metadata-refresh job runs
**WHEN** the job executes
**THEN** it fetches the Routeringsdienst metadata, verifies its signature, extracts the IdP signing certificates (which may include both the current and next certificate during rotation windows), updates `AuthMetadataCache`, and from that point onwards assertions signed with either the current or the next IdP cert are accepted.

**GIVEN** a local SP certificate is within 60 days of expiry
**WHEN** the daily cert-check job runs
**THEN** a notification is sent to the `auth_admin` group with rotation instructions including the Routeringsdienst contact details, the `mydash` widget highlights the expiry, and on actual expiry the SP entityId can no longer initiate authentications but existing valid sessions continue until their own expiry.

### REQ-008: Replay protection and assertion uniqueness
The adapter SHALL prevent assertion replay attacks per the SAML 2.0 profile.

**GIVEN** an assertion arrives at the AssertionConsumer with an `AssertionID` that has been processed within the last 8 hours
**WHEN** the adapter checks the replay cache
**THEN** the assertion is rejected with `assertion-rejected-replay`, an audit event is written, no `AuthIdentity` is created, and the citizen is shown a generic "authenticatie mislukt" error.

**GIVEN** an assertion arrives outside its `NotBefore` / `NotOnOrAfter` validity window
**WHEN** the adapter validates the conditions
**THEN** the assertion is rejected with a clock-skew-aware tolerance of 60 seconds, an audit event is written, and the error is logged for ops to investigate potential clock-drift.

### REQ-009: OIDC bridging
The adapter SHALL expose a consuming-side OIDC interface for relying parties that prefer OIDC over SAML, even though the upstream Routeringsdienst remains SAML.

**GIVEN** a consuming app prefers OIDC and registers itself as an OIDC client at the adapter
**WHEN** the consuming app initiates an Authorization Code flow with `acr_values` matching the desired LoA and `means` parameter selecting DigiD or eHerkenning
**THEN** the adapter performs the underlying SAML flow to the Routeringsdienst, on success exchanges the resulting `AuthIdentity` for an OIDC `id_token` (signed JWT containing `sub`, `acr`, optionally `bsn` or `pseudobsn`, `legalSubjectId`, `actingSubjectId`, `machtiging`), and returns the `code` to the consuming app's redirect URI for exchange at the token endpoint.

### REQ-010: Fraud markers and session blocking
The adapter SHALL support per-identity fraud markers that prevent further authentications from a known-compromised identifier.

**GIVEN** an operator (with `auth_admin` role) sets a fraud marker on a BSN or pseudo-BSN (via admin UI, providing a reason and an unblock-after date)
**WHEN** an authentication arrives whose resolved `subjectId` matches the marker
**THEN** the adapter writes a `fraud-marker-set` audit event, refuses to issue an `AuthIdentity`, and returns a generic "authenticatie tijdelijk niet beschikbaar" error to the citizen without revealing that a marker exists. Markers auto-expire on their unblock date, and all marker set/unset operations are themselves audit-logged with the operator's identity.

## Standards & Sources

- Logius — DigiD Aansluitvoorwaarden + Koppelvlakspecificatie DigiD SAML.
- Logius — eHerkenning Afsprakenstelsel (currently v1.13 / v1.20 transitional).
- Logius — Routeringsdienst Koppelvlakspecificatie.
- Forum Standaardisatie — SAML 2.0 op de pas-toe-of-leg-uit lijst; eIDAS verordening.
- OASIS — SAML 2.0 Core, Bindings, Profiles; SAML V2.0 Holder-of-Key Web Browser SSO Profile.
- eIDAS — Verordening (EU) 910/2014 + uitvoeringsverordeningen 2015/1502 (LoA niveaus) en 2015/1501 (SAML interop profile).
- NORA — Burger Service Nummer kader; Pseudoniemisatie BSN.
- Wet BSN — BSN/Pseudo-BSN gebruiksregels.
- Wet digitale overheid (Wdo) — straks verplicht voor publieke dienstverlening.
- OpenID Connect Core 1.0 + Discovery + Dynamic Client Registration (for the OIDC bridge).
- BIO — toegangsbeveiliging, sessiemanagement, logging.
- ENSIA / DigiD assessment — jaarlijkse audit waaraan gemeenten moeten voldoen; deze adapter levert per-bewijs.

## Cross-app integration

- **openconnector base** — adapter ships inside openconnector; broker configs surface in the admin UI; auth flows are jobs of type `auth-broker`.
- **digikoppeling-adapter** — shares the PKIoverheid certificate schema; SP signing/encryption certs use the same `DigikoppelingCertificate` schema.
- **haalcentraal-personen-bag-hra-adapter** — when an authenticated citizen lands in a consuming app, the BSN is immediately usable for BRP queries via the HaalCentraal adapter, with the authentication's wettelijke grondslag carried into the doelbinding.
- **zaakafhandelapp** — citizen-portal zaak intake authenticates with DigiD; company portal aanvragen authenticate with eHerkenning.
- **procest** — process-start triggers can require an authenticated initiator at a specific LoA; the adapter delivers the identity to the procest engine.
- **opencatalogi** — services in the catalogi can declare their required authentication means and LoA, surfaced to citizens before they start a flow.
- **docudesk** — documents signed in a citizen self-service flow embed the authentication's LoA and audit-event ID in the signature metadata.
- **softwarecatalog** — supplier representatives authenticating to manage their product entries use eHerkenning EH3.
- **openregister** — provides the schemas (with BSN-at-rest encryption), the append-only audit-event enforcement, and the file-attachment for routeringsdienst-contracten en service-catalog-bewijsstukken.
- **mydash** — dashboards for: active sessions, failed-auth rate, LoA distribution, machtigingen prevalence, fraud markers, certificate-expiry countdowns, and consuming-app breakdown for billing.

## Target users

- **NL gemeenten** — every citizen-facing portal needs DigiD; every company-facing portal needs eHerkenning. There are 342 municipalities and every one of them buys this capability somewhere today; this adapter offers an open-source alternative inside Conduction's stack.
- **Uitvoeringsorganisaties** — UWV's mijn-omgeving, SVB's portaal, RVO's subsidieportaal — all DigiD/eHerkenning-bound.
- **Waterschappen, provincies, ministeries** — for any portaal with a citizen or company-facing surface.
- **Semi-public sectors with afsprakenstelsel-aansluiting** — pensioenfondsen, zorgverzekeraars (DigiD voor verzekerden), woningcorporaties (verhuurportaal met eHerkenning).
- **EU cross-border use cases** — Belgian citizens authenticating with itsme to a Dutch service via eIDAS notified scheme — the adapter handles this transparently via the Routeringsdienst.
- **Conduction app teams** — every team building a public-facing surface depends on this adapter so they do not have to negotiate with Logius themselves.
- **Security and fraud teams** — the per-session audit trail, the assertion-replay protection, the fraud-marker mechanism, and the LoA enforcement give security teams the visibility needed for ENSIA / DigiD jaarassessment.
- **DPO / FG'ers** — pseudo-BSN per app + per-app provisioning policies + machtigingen audit close the privacy gaps that ad-hoc DigiD integrations typically leave open.
