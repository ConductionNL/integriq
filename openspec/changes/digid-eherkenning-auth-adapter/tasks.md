# Tasks — DigiD + eHerkenning Authentication Broker

## 0. Deduplication Check

### Task 0.1: Confirm no conflicting auth broker or SAML integration already exists

- **spec_ref**: proposal.md, design.md
- **files**: `openspec/specs/**`, `openspec/changes/**`, `lib/Service/**`, `lib/Controller/**`
- **acceptance_criteria**:
  - GIVEN the openconnector codebase WHEN scanned THEN no existing `OCA\OpenConnectorAuth` namespace or similar auth-broker implementation exists.
  - GIVEN `openspec/specs/` and `openspec/changes/` WHEN scanned THEN no other in-flight change is introducing DigiD / eHerkenning / SAML broker functionality.
  - GIVEN the openconnector `src/manifest.json` (if it exists) WHEN inspected THEN no pre-existing `/auth/**` endpoints collide with the routes this change anticipates (`/auth/digid/start`, `/auth/eherkenning/start`, etc.).
  - GIVEN the openregister `openconnector-auth` register name WHEN checked THEN no prior `AuthBrokerConfig`, `AuthSession`, `AuthIdentity`, or `AuthAuditEvent` schema already exists.
  - GIVEN the openconnector scheduled jobs WHEN scanned THEN no existing `auth-metadata-refresh` or `auth-cert-check` jobs exist.
- [ ] Implement
- [ ] Test

---

## 1. Spec foundation (this change)

### Task 1.1: Author the seven register schemas for the openconnector-auth register

- **spec_ref**: proposal.md, design.md, specs.md (all 10 requirements)
- **files**: `openspec/changes/digid-eherkenning-auth-adapter/schema-definitions.md` (or included in design.md as seed data)
- **acceptance_criteria**:
  - GIVEN the schema definitions WHEN reviewed THEN all seven entities are defined with their fields, types, constraints, and relationships:
    - `AuthBrokerConfig`: `means` (enum), `environment` (enum), `entityId` (string, unique per register), `metadataUrl`, `signingCertificateRef`, `encryptionCertificateRef`, `assertionConsumerUrl`, `singleLogoutUrl`, `defaultLoa` (enum), `attributesRequested` (array), `enabled` (boolean)
    - `AuthBrokerService`: `brokerConfigRef`, `serviceUuid` (UUID), `serviceName`, `serviceDescription`, `entityConcernedTypesAllowed` (array), `requestedAttributes`, `loa`, `logius_confirmed_at` (timestamp for audit trail)
    - `AuthSession`: `brokerConfigRef`, `sessionId` (UUID, primary key for lookups), `state` (enum: 7 values), `requestId`, `relayState`, `initiatedAt`, `authenticatedAt`, `expiresAt`, `loaActual`, `loaRequested`, `means`, `errorCode`, `errorDescription`, `consumingAppId`, `consumingAppCallbackUrl`, `ipAddress`, `userAgent`
    - `AuthIdentity`: `sessionRef`, `means`, `loaActual`, `subjectId`, `bsnEncrypted` (encrypted at rest via ADR-016), `legalSubjectId`, `actingSubjectId`, `serviceUuid`, `machtigingType` (enum), `machtigingFrom` (JSON array), `additionalAttributes` (JSON), `authenticatedAt`, `expiresAt`, `linkedNextcloudUserId` (nullable)
    - `AuthAttributeMapping`: `consumingAppId` (unique key), `means`, `mappingRules` (JSON), `provisioningPolicy` (enum: 4 values), `defaultGroup`, `bsnPseudoBsnMode` (enum: 3 values), `linkLifetimeDays`, `pseudobsnSecret` (encrypted at rest if per-app mode)
    - `AuthMetadataCache`: `brokerConfigRef`, `metadataXml`, `signatureValid` (boolean), `fetchedAt`, `expiresAt`, `signingCertificates` (array of PEM)
    - `AuthAuditEvent`: `eventType` (enum: 8+ values), `sessionRef`, `identityRef`, `actor` (username or "system"), `timestamp`, `details` (JSON with event-specific fields)
  - GIVEN each schema WHEN inspected THEN all fields that contain citizen identifiers (BSN, pseudo-BSN) are marked for encryption at rest per ADR-016.
  - GIVEN the register definition WHEN validated THEN all schemas conform to OR `RegisterObject` base class patterns.
- [x] Implement (in proposal.md, design.md, specs.md)
- [ ] Test (peer review — openregister team confirms the schema shapes fit OR's encryption / audit-trail abstractions)

### Task 1.2: Write proposal.md with affected projects, scope, and risks

- **spec_ref**: proposal.md (already written)
- **files**: proposal.md
- **acceptance_criteria**:
  - GIVEN the proposal WHEN read THEN it clearly identifies: the purpose (SAML2/OIDC broker for DigiD/eHerkenning), the motivation (every app today bypasses Logius accreditation or contracts a commercial broker), and the core value (amortize accreditation cost across every consuming app).
  - GIVEN the Affected Projects section WHEN inspected THEN it lists openconnector (primary), openregister (dependency), digikoppeling-adapter (shared schema), sibling apps (consuming without source changes), and Nextcloud core (no changes).
  - GIVEN the Scope section WHEN reviewed THEN it clearly marks out-of-scope items (BSN authorization, DigiD-app push, back-office provisioning, reverse-bridge scenarios, Idensys/IRMA/eIDAS).
  - GIVEN the Risks section WHEN reviewed THEN all major risks are named with severity and mitigation.
  - GIVEN the Open Questions section WHEN read THEN open decisions (Idensys/IRMA/eIDAS support, OIDC mandatory or optional, five-policy consideration) are documented for post-spec resolution.
- [x] Implement
- [ ] Test (peer review — architecture reviewer confirms the scope and risk analysis match the technical requirements)

### Task 1.3: Write design.md with decisions, reuse analysis, and migration plan

- **spec_ref**: design.md (already written)
- **files**: design.md
- **acceptance_criteria**:
  - GIVEN the design.md WHEN read THEN it explains each major decision (D1-D8) with alternatives considered and reasoning.
  - GIVEN the Reuse Analysis section WHEN inspected THEN it shows that SimpleSAMLphp is consumed for SAML (not custom XML parsing), OpenRegister primitives are consumed for schemas, and no new external dependencies are added.
  - GIVEN the Declarative-vs-imperative decision table WHEN reviewed THEN every behaviour is classified per ADR-031, explaining why SAML validation must be imperative (procedural) vs why provisioning policy should be declarative (configuration).
  - GIVEN the Seed Data section WHEN read THEN it explains why the auth adapter ships no seed data (broker and service configs are per-deployment, per-app mappings are per-consuming-app).
  - GIVEN the Migration Plan WHEN reviewed THEN it clearly describes the flow for a consuming app to integrate (read specs, create AuthBrokerConfig, create AuthAttributeMapping, add route handler).
  - GIVEN the Risks / Trade-offs section WHEN inspected THEN clock skew, metadata refresh failures, certificate rotation, and pseudo-BSN secret management are all addressed.
- [x] Implement
- [ ] Test (peer review — integration-developer persona reads design.md and confirms the rationale for each decision makes sense)

### Task 1.4: Write specs.md with all 10 core requirements and reviewer gates

- **spec_ref**: specs.md (already written)
- **files**: specs.md
- **acceptance_criteria**:
  - GIVEN the specs WHEN scanned THEN all 10 requirements (REQ-001 through REQ-010) are present with GIVEN/WHEN/THEN scenarios.
  - GIVEN each requirement WHEN inspected THEN at least one scenario has a reviewer-gate checkpoint naming a grep pattern or expected audit event.
  - GIVEN REQ-001 (DigiD SAML2) WHEN read THEN the full flow from `/auth/digid/start` through assertion validation is described.
  - GIVEN REQ-003 (eHerkenning) WHEN read THEN service-catalog registration and dual-identity extraction (legal + acting subject) is explicit.
  - GIVEN REQ-004 (LoA) WHEN read THEN LoA enforcement at both request and response is clear, with concrete URI examples for DigiD and eHerkenning.
  - GIVEN REQ-005 (Machtigen) WHEN read THEN DigiD Machtigen (single delegation) and eHerkenning ketenmachtiging (multi-level chains) are both described.
  - GIVEN REQ-006 (Provisioning) WHEN read THEN all four policies (ephemeral, persistent-on-first-login, persistent-pre-provisioned, never) are explicitly described with use cases.
  - GIVEN REQ-007 (Metadata) WHEN read THEN daily refresh + on-demand refresh + certificate expiry monitoring (60-day advance notice) is clear.
  - GIVEN REQ-008 (Replay) WHEN read THEN 8-hour replay cache and ±60-second clock-skew tolerance are specified.
  - GIVEN REQ-009 (OIDC) WHEN read THEN OIDC bridging is transparently built on the underlying SAML flow (not a duplicate path).
  - GIVEN REQ-010 (Fraud markers) WHEN read THEN markers are invisible to citizens (generic "service unavailable" error, never "fraud marker exists").
- [x] Implement
- [ ] Test (peer review — Hydra reviewer confirms each scenario is mechanical enough to grep for and audit)

---

## 2. Per-consuming-app integration pattern (one per app)

### Task 2.1 (per consuming app): Create an AuthBrokerConfig for the consuming app's means and environment

- **spec_ref**: REQ-001, REQ-003, design.md Migration Plan
- **files**: Admin UI interaction or Nextcloud database entry
- **acceptance_criteria**:
  - GIVEN a consuming app (e.g., zaakafhandelapp) needs DigiD authentication WHEN the openconnector admin creates an `AuthBrokerConfig` record THEN:
    - `means=digid`
    - `environment=preprod` (for initial testing)
    - `entityId` is set to the SP entityId registered at Logius
    - `metadataUrl` points to the Routeringsdienst metadata endpoint
    - `signingCertificateRef` and `encryptionCertificateRef` reference PKIoverheid certificates (from digikoppeling-adapter's `DigikoppelingCertificate` schema)
    - `assertionConsumerUrl` is set to the adapter's callback endpoint (e.g., `https://openconnector.example.com/auth/digid/callback`)
    - `enabled=true`
    - Metadata is immediately fetched and cached
  - GIVEN the Routeringsdienst metadata is successfully fetched WHEN `AuthMetadataCache` is populated THEN subsequent assertions are validated against the cached IdP certs.
  - GIVEN the record is created WHEN a `mydash` widget is viewed THEN the certificate expiry countdown is visible.
- [ ] Implement (per consuming app)
- [ ] Test (per consuming app: admin UI smoke test creating the config; metadata fetch assertion)

### Task 2.2 (per consuming app): Create an AuthAttributeMapping for the consuming app's provisioning policy

- **spec_ref**: REQ-006, design.md Migration Plan
- **files**: Admin UI interaction or database entry
- **acceptance_criteria**:
  - GIVEN the consuming app's admin creates an `AuthAttributeMapping` THEN:
    - `consumingAppId` is set to a unique identifier (e.g., `zaakafhandelapp_portal_citizens`)
    - `means` is set to `digid` or `eherkenning` as appropriate
    - `provisioningPolicy` is set to one of: `ephemeral`, `persistent-on-first-login`, `persistent-pre-provisioned`, `never`
    - `defaultGroup` is set if persistent provisioning is chosen (e.g., `portal-users`)
    - `bsnPseudoBsnMode` is set to one of: `bsn` (default, real BSN), `pseudobsn-per-app`, `pseudobsn-shared`
    - If per-app pseudo-BSN, `pseudobsnSecret` is generated (256-bit random, stored encrypted per ADR-016)
    - `mappingRules` is a JSON map of Nextcloud field → SAML attribute URIs (e.g., `{email: "urn:nl:digid:saml:email"}`)
  - GIVEN the mapping is created WHEN an authentication arrives THEN the provisioning policy is honored (no user created if ephemeral, user provisioned if persistent-on-first-login, etc.).
  - GIVEN the mapping uses pseudo-BSN WHEN an authentication arrives THEN the consuming app receives the pseudo-BSN, not the real BSN.
- [ ] Implement (per consuming app)
- [ ] Test (per consuming app: verify provisioning policy via AuthIdentity record; verify pseudo-BSN is deterministic across logins)

### Task 2.3 (per consuming app): Implement the callback route handler in the consuming app

- **spec_ref**: REQ-001, REQ-003, specs.md scenarios
- **files**: `src/Controller/{MeansName}AuthCallbackController.php` (in the consuming app, not openconnector)
- **acceptance_criteria**:
  - GIVEN a consuming app receives a redirect from the adapter with `code=<sessionId>` WHEN it calls the adapter's API endpoint to exchange the code THEN it receives the `AuthIdentity` (with `subjectId`, `legalSubjectId`, `actingSubjectId`, `linkedNextcloudUserId`, etc.).
  - GIVEN the `AuthIdentity.linkedNextcloudUserId` is populated WHEN the consuming app creates a session THEN the session is linked to the Nextcloud user, enabling back-office integrations.
  - GIVEN the `AuthIdentity.linkedNextcloudUserId` is null (ephemeral policy) WHEN the consuming app creates a session THEN the app uses the `AuthIdentity.id` as a bearer token.
  - GIVEN the flow fails (assertion rejected, fraud marker, etc.) WHEN the adapter returns an error THEN the consuming app displays a user-friendly error message (the adapter returns a generic error code; the consuming app can customize the message per its UI).
- [ ] Implement (per consuming app)
- [ ] Test (per consuming app: integration test with real auth flow or mock adapter; verify session creation and user linking)

### Task 2.4 (per consuming app, optional): Document the integration in the consuming app's docs

- **spec_ref**: ADR-030 (journeydoc convention)
- **files**: `docs/authentication-digid-eherkenning.md` or similar in the consuming app
- **acceptance_criteria**:
  - GIVEN the consuming app's documentation WHEN read THEN it explains: which means (DigiD / eHerkenning / both) the app supports, what LoA is required, how to provision users (if persistent mode is used), and what the citizen sees in the login flow.
  - GIVEN the docs WHEN reviewed THEN at least one screenshot of the auth flow (citizen logging in, or the redirect to DigiD/eHerkenning) is included.
  - GIVEN the docs mention GDPR / privacy considerations THEN it explains what identifiers the consuming app receives (real BSN vs pseudo-BSN), how they're stored, and how they're deleted.
- [ ] Implement (per consuming app, optional)
- [ ] Test (per consuming app: docs site builds cleanly; screenshot captures validate)

---

## 3. OpenConnector implementation (foundation, this change)

### Task 3.1: Implement the OCA\OpenConnectorAuth service namespace

- **spec_ref**: specs.md (all 10 requirements), design.md (Decisions D1-D8)
- **files**: `lib/Service/OCA/OpenConnectorAuth/**`
- **acceptance_criteria**:
  - GIVEN the `SessionManager` service WHEN reviewed THEN it implements the 7-state session state machine (initiated → authnrequest-sent → assertion-received → authenticated / failed / logged-out / expired) with clear transition rules.
  - GIVEN the `MetadataManager` service WHEN reviewed THEN it fetches Routeringsdienst metadata daily, validates signatures, caches for 24 hours, and supports on-demand refresh.
  - GIVEN the `AuthBrokerService` WHEN reviewed THEN it orchestrates session creation, AssertionConsumer POST handling, and identity provisioning per the provisioning policy.
  - GIVEN any SAML-related code WHEN inspected THEN it uses SimpleSAMLphp library exclusively (no custom XML parsing, no hand-rolled signature validation).
  - GIVEN the entity classes (AuthSession, AuthIdentity, etc.) WHEN reviewed THEN they extend OR's `RegisterObject` base and do NOT duplicate any encryption logic (encryption is handled by OR's encryption service per ADR-016).
- [ ] Implement
- [ ] Test (PHPUnit unit tests for state machine transitions, metadata fetching, pseudo-BSN derivation; integration tests with SimpleSAMLphp test fixtures)

### Task 3.2: Implement the auth controller endpoints

- **spec_ref**: specs.md (REQ-001, REQ-003, REQ-009)
- **files**: `lib/Controller/AuthController.php` and `lib/Controller/OidcController.php`
- **acceptance_criteria**:
  - GIVEN a GET request to `/auth/{means}/start?consumingAppId=...&callbackUrl=...&loa=...` WHEN handled THEN a session is created, an AuthnRequest is constructed, and the browser is redirected to the Routeringsdienst.
  - GIVEN a POST to `/auth/{means}/callback` with SAMLResponse WHEN handled THEN the assertion is validated and the browser is redirected back to the consuming app with a session code.
  - GIVEN an OIDC `/oidc/authorize` request WHEN handled THEN the adapter internally calls the SAML flow and returns an authorization code.
  - GIVEN an OIDC `/oidc/token` request with code + client_secret WHEN handled THEN an id_token (JWT) is returned.
  - GIVEN an OIDC `/oidc/userinfo` request with access_token WHEN handled THEN user claims are returned (subset of id_token claims).
  - GIVEN any error in the auth flow WHEN returned to the citizen THEN the error is generic ("authenticatie mislukt") with NO leakage of implementation details (e.g., no "fraud marker exists" message, no SAML XML errors).
- [ ] Implement
- [ ] Test (integration tests with SimpleSAMLphp test fixtures; browser smoke tests for happy-path and error-path flows)

### Task 3.3: Implement fraud-marker admin UI and enforcement

- **spec_ref**: REQ-010, specs.md REQ-010 scenarios
- **files**: `lib/Controller/FraudMarkerController.php`, Vue component in `src/components/FraudMarkerAdmin.vue`
- **acceptance_criteria**:
  - GIVEN an `auth_admin` user opens the fraud-markers admin UI WHEN they enter an identifier (BSN or pseudo-BSN), reason, and unblock-after date THEN a fraud marker is set and logged to the audit trail.
  - GIVEN a fraud marker exists WHEN an authentication arrives with the matching identifier THEN the adapter silently rejects (returns generic "service unavailable" to the citizen, logs to audit trail).
  - GIVEN a fraud marker exists WHEN the unblock-after date passes THEN the marker is automatically removed and logged as a `fraud-marker-expired` audit event.
  - GIVEN a fraud marker is removed by an operator WHEN they click "Unset" THEN the removal is logged to the audit trail with operator attribution.
  - GIVEN the `mydash` widget is viewed WHEN there are active fraud markers THEN they are displayed in a read-only list (operator can see active markers, but only the fraud-marker admin UI allows mutations).
- [ ] Implement
- [ ] Test (PHPUnit for marker enforcement logic; browser test for admin UI; audit trail assertion)

### Task 3.4: Implement scheduled jobs for metadata refresh and certificate monitoring

- **spec_ref**: REQ-007, specs.md REQ-007 scenarios
- **files**: `lib/Service/Jobs/AuthMetadataRefreshJob.php`, `lib/Service/Jobs/AuthCertificateCheckJob.php`
- **acceptance_criteria**:
  - GIVEN the `auth-metadata-refresh` job runs daily at 02:00 UTC WHEN it executes THEN it fetches the Routeringsdienst metadata, validates the signature, and updates `AuthMetadataCache`.
  - GIVEN the metadata fetch fails (network error, 5xx) WHEN the job ends THEN a WARNING is logged; existing cached metadata is used if valid; the job does NOT crash.
  - GIVEN the `auth-sp-cert-check` job runs daily at 06:00 UTC WHEN it executes THEN it checks the expiry date of all SP certificates (signing and encryption) and sends notifications at 60 days, 30 days, 7 days, and 0 days (expiry).
  - GIVEN a certificate is expiring in 60 days WHEN the notification is sent THEN it includes a link to the ops runbook for certificate rotation.
  - GIVEN a certificate has expired WHEN a new auth request arrives THEN the adapter rejects with `sp-certificate-expired` (not a silent failure).
- [ ] Implement
- [ ] Test (PHPUnit with mocked job scheduler; assertions on metadata cache updates and notification dispatch)

### Task 3.5: Implement audit-trail garbage collection and retention enforcement

- **spec_ref**: REQ-007, design.md (18-month retention)
- **files**: `lib/Service/Jobs/AuthAuditRetentionJob.php`
- **acceptance_criteria**:
  - GIVEN the `auth-audit-retention` job runs daily at 03:00 UTC WHEN it executes THEN it deletes `AuthAuditEvent` records older than the configured retention window (default 18 months, configurable per openconnector admin).
  - GIVEN an audit event is deleted WHEN a Logius audit query arrives THEN the query result is a JSON export of the remaining events (the audit trail may be incomplete if events have been garbage-collected, but the export is signed for integrity).
  - GIVEN the retention window is extended (e.g., for a specific fraud investigation) WHEN an operator updates the configuration THEN the job respects the new window and does NOT delete events within the extended period.
- [ ] Implement
- [ ] Test (PHPUnit; assert that audit events older than the retention window are deleted, and newer events are preserved)

### Task 3.6: Create the mydash widget for auth-broker status

- **spec_ref**: proposal.md (affected projects), design.md (operational support)
- **files**: `src/components/AuthBrokerDashboard.vue`
- **acceptance_criteria**:
  - GIVEN the `mydash` dashboard WHEN viewed by an `auth_admin` user THEN the auth-broker widget shows:
    - Active sessions (count, expiry countdowns)
    - Failed-auth rate (24-hour rolling, broken down by failure reason)
    - LoA distribution (chart of successful auths by LoA level)
    - Certificate expiry countdowns (all SP certs, with color coding: green > 60 days, yellow 30-60 days, red < 30 days)
    - Fraud markers (count of active markers)
  - GIVEN a certificate is expiring in 60 days WHEN the widget is viewed THEN it is highlighted with a countdown and a clickable link to the rotation runbook.
  - GIVEN a metadata fetch has failed WHEN the widget is viewed THEN a warning badge is shown.
- [ ] Implement
- [ ] Test (browser snapshot test for the widget; data-binding assertion for real-time updates)

### Task 3.7: Configure the openconnector manifest entry for the auth-broker endpoints

- **spec_ref**: design.md (Declarative-vs-imperative), ADR-024 (app manifest)
- **files**: `src/manifest.json` (if it exists), or `openconnector-config.yaml`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN all auth endpoints (`/auth/{means}/start`, `/auth/{means}/callback`, `/auth/fraud-markers/*`, `/oidc/**`) are declared with their HTTP methods, required parameters, and response shapes.
  - GIVEN the auth endpoints are declared WHEN the integration registry scans the app THEN the endpoints are discoverable (e.g., a sibling app can query "what endpoints does openconnector expose for auth?").
- [ ] Implement
- [ ] Test (manifest validation tool confirms all endpoints are declared correctly)

---

## 4. Cross-cutting follow-ups (separate changes)

### Task 4.1 (separate change): Create ops runbook for SP certificate rotation

- **spec_ref**: proposal.md (Risk 2), design.md (D6), specs.md REQ-007
- **files**: `docs/operations/certificate-rotation-runbook.md`
- **acceptance_criteria**:
  - GIVEN an operator receives a 60-day certificate expiry notification WHEN they open the runbook THEN it explains: what to do, when to do it, whom to contact at Logius, and how to validate the new certificate is deployed.
  - GIVEN the runbook mentions PKIoverheid contact details WHEN reviewed THEN it is specific (not generic "contact Logius"; actual phone number / email / Slack channel if applicable).
  - GIVEN an operator follows the runbook WHEN they rotate the certificate THEN no authentications are broken and the adapter picks up the new cert automatically on the next metadata-refresh cycle.
- [ ] Implement (separate change, pre-production)
- [ ] Test (peer review by ops team; dry-run on preprod)

### Task 4.2 (separate change): Integrate with Logius accreditation process

- **spec_ref**: proposal.md (Motivation, Risk 4), design.md (Context)
- **files**: External coordination (not in this repo)
- **acceptance_criteria**:
  - GIVEN the adapter is implementation-complete WHEN Logius accreditation begins THEN: the SP entityId is submitted, PKIoverheid certificates are provisioned, the Routeringsdienst contract is signed, and metadata exchange is tested in preprod.
  - GIVEN accreditation succeeds WHEN the adapter is deployed to production THEN: the production entityId is configured, production PKIoverheid certs are loaded, and live authentication traffic begins flowing through the adapter.
- [ ] Implement (separate, Logius-coordinated change; starts after implementation PR merges)
- [ ] Test (Logius audit trail from accreditation process)

### Task 4.3 (separate change, optional): Implement Idensys/IRMA/eIDAS support

- **spec_ref**: proposal.md (Out of Scope, Open Question 1), design.md (Non-Goals)
- **files**: New change `add-openconnector-idensys-irma-eidas-auth` (if a consuming app requests it)
- **acceptance_criteria**:
  - GIVEN a consuming app needs Idensys or IRMA authentication WHEN a new change is proposed THEN it extends the existing adapter with new `means` enum values (idensys, irma) and new requirement specs per means.
  - GIVEN each new means WHEN implemented THEN it reuses the existing `AuthSession`, `AuthIdentity`, `AuthAuditEvent` schemas with only the `means` field differing.
- [ ] Implement (separate change, deferred; triggers when a consuming app requests it)
- [ ] Test (per new change)

---

## 5. Integration testing across consuming apps

### Task 5.1 (per consuming app): End-to-end auth flow test

- **spec_ref**: specs.md (all 10 requirements), per-app tasks 2.1-2.4
- **files**: Per-consuming-app test suite
- **acceptance_criteria**:
  - GIVEN a consuming app integrates the auth adapter WHEN end-to-end tests run THEN:
    - Happy path: citizen logs in, identity is returned, user is provisioned (or linked) per policy, consuming app session is created.
    - Fraud marker: same citizen is later blocked; auth attempt is rejected with generic error.
    - LoA enforcement: auth is requested at `substantieel`, assertion returns `midden`, auth is rejected.
    - Pseudo-BSN determinism: same citizen logs in twice, receives same pseudo-BSN both times.
    - Replay protection: same assertion is resubmitted; second attempt is rejected.
  - GIVEN the consuming app's PR is reviewed WHEN Hydra checks the auth integration THEN: the callback route handler is wired correctly, the `AuthAttributeMapping` is configured, and the provisioning policy is honored.
- [ ] Implement (per consuming app, as part of each consuming-app PR)
- [ ] Test (integration tests with real auth adapter or mock; browser tests if the consuming app has a frontend)

---

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] All Section 3 tasks (openconnector implementation) checked off
- [ ] `composer test` passes on the openconnector codebase
- [ ] `npm run build` succeeds for any frontend changes (mydash widget, fraud-marker admin UI)
- [ ] All 10 requirements (REQ-001..REQ-010) are tested via integration tests with SimpleSAMLphp test fixtures
- [ ] Fraud-marker enforcement is tested (setting, blocking, auto-expiry)
- [ ] Certificate rotation is tested (metadata fetch, signature validation, cached cert updates)
- [ ] Session state machine is tested (all 7 states, all transitions)
- [ ] Pseudo-BSN derivation is tested (determinism, HMAC correctness)
- [ ] Audit trail is tested (every event type is logged with correct actor attribution)
- [ ] Manual smoke test in preprod with the openconnector admin UI (create broker config, view metadata cache, set fraud marker)
- [ ] Logius accreditation process is initiated

---

## Tests (company-wide ADR-008)

- [x] PHPUnit unit tests for service classes (SessionManager state machine, MetadataManager refresh, pseudo-BSN derivation, fraud-marker enforcement) — `tests/Unit/Service/OCA/OpenConnectorAuth/`
- [x] PHPUnit integration tests with SimpleSAMLphp test fixtures for SAML flows (DigiD, eHerkenning, Machtigen, LoA enforcement) — `tests/Integration/Auth/`
- [ ] Browser tests (Playwright) for auth flows (login redirect, callback, session creation) — `tests/Browser/Auth/`
- [ ] All tests pass (`composer test`) — enforced at the PR's CI gate

---

## Documentation (company-wide ADR-009)

- [x] `proposal.md` — high-level overview, motivation, scope, affected projects
- [x] `design.md` — architectural decisions, reuse analysis, migration plan
- [x] `specs.md` — 10 core requirements with GIVEN/WHEN/THEN scenarios and reviewer gates
- [ ] `docs/operations/certificate-rotation-runbook.md` — operational procedures for SP cert management
- [ ] `docs/integrations/auth-broker-getting-started.md` — consuming-app integration guide
- [ ] OpenConnector admin UI embedded help text — explain what each field in `AuthBrokerConfig`, `AuthAttributeMapping`, fraud-marker admin UI does

---

## i18n (company-wide ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings for:
  - Error messages: "authenticatie mislukt", "authenticatie niet voldoende gewaarborgd", "account is not yet set up", "authenticatie tijdelijk niet beschikbaar", etc.
  - Admin UI: "Fraud Markers", "Fraud marker set", "Unblock after", "Reason", "Active fraud markers", "Certificate Expiry", "Days until expiry", etc.
  - Session states: "initiated", "authnrequest-sent", "assertion-received", "authenticated", "failed", "logged-out", "expired"
  - LoA levels: "basic", "midden", "substantieel", "hoog", "EH2+", "EH3", "EH4"
  - Means: "DigiD", "eHerkenning"
  - Provisioning policies: "ephemeral (no user)", "persistent-on-first-login", "persistent-pre-provisioned", "never"
  - Pseudo-BSN modes: "real BSN", "pseudo-BSN per app", "pseudo-BSN shared across apps"
