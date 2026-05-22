# Proposal: digid-eherkenning-auth-adapter

`kind: service` per ADR-032 — a broker that lives inside OpenConnector and exposes DigiD and eHerkenning as a clean SAML2 / OIDC bridge that consuming Nextcloud apps can plug into via the existing Nextcloud user-mapping infrastructure.

## Summary

Introduce **DigiD + eHerkenning authentication broker** — a service that handles SAML2 authentication flows with the Dutch Routeringsdienst on behalf of consuming Nextcloud applications. The adapter is the missing middleware that closes the gap between "no public authentication at all" and "contract with a commercial broker like Signicat or Connectis."

The adapter exposes:

- **DigiD SAML2 authentication** for citizen self-service portals at four levels of assurance (LoA basic / midden / substantieel / hoog)
- **eHerkenning SAML2 authentication** for company / legal-entity portals with mandatory service-catalog registration
- **DigiD Machtigen support** (citizen acting on behalf of another citizen)
- **eHerkenning ketenmachtiging support** (multi-level delegation chains)
- **Pseudo-BSN translation** to shield apps from actual BSN storage
- **LoA enforcement** both in AuthnRequest and assertion validation
- **Metadata refresh and certificate rotation** from the Routeringsdienst
- **Per-session audit trail** immutable and compliant with Logius fraud-investigation windows
- **OIDC bridging** for relying parties that prefer OIDC over SAML
- **Fraud markers** for known-compromised identities

The adapter is **NOT** a replacement for Nextcloud's own user authentication — Nextcloud users still log in with Nextcloud credentials for admin/back-office access. This adapter is strictly for **public-facing portals** where an external citizen or company representative authenticates with their national means, and where the resulting identity is either ephemeral (used to authorise a single API call) or mapped to a long-lived Nextcloud user with limited rights.

## Motivation

Today, every Nextcloud app that needs DigiD or eHerkenning has bounced off the Logius accreditation process, the Routeringsdienst contract, the SAML metadata exchange, and the PKIoverheid certificate management:

- **Logius accreditation** takes 6-12 weeks and requires substantial SAML expertise in-house.
- **Routeringsdienst contract** is non-negotiable; there is no option to bypass it.
- **SAML metadata exchange** is finicky and prone to certificate-mismatch errors.
- **PKIoverheid certificate rotation** is a high-touch operational task.

The net result: Conduction apps have shipped either with no public authentication (falling back to anonymous citizen flows), or with a direct contract to a commercial broker. This change moves the accreditation cost to a single shared broker inside OpenConnector, amortizing it across every consuming app.

The adapter is **essential infrastructure** for the Conduction ecosystem. Every municipality has 342 mayors and every one of them has a citizen portal that legally **must** use DigiD (Wet digitale overheid / Wdo). Every SMB using Conduction's company portals legally **must** support eHerkenning for the company representative to authenticate.

## Affected Projects

- [x] **Project: openconnector** — adds the broker service with 7 new schemas in a dedicated `openconnector-auth` register. Exposes `/auth/{means}/start`, `/auth/{means}/callback`, `/auth/{means}/status`, `/auth/fraud-markers/*` admin endpoints. Ships with supporting jobs for metadata refresh, certificate rotation, audit retention.
- [ ] **Project: openregister** — provides the schema primitives (RegisterObject base, BSN-at-rest encryption, append-only audit-trail enforcement). No new abstractions needed; existing primitives suffice.
- [ ] **Project: digikoppeling-adapter** — shares the PKIoverheid certificate schema (`DigikoppelingCertificate`) for SP signing/encryption certificates. No change needed; schema already exists.
- [ ] **Project: haalcentraal-personen-bag-hra-adapter** — when a citizen lands in a consuming app after DigiD auth, the BSN is immediately usable for BRP queries. No change needed; HaalCentraal adapter already accepts BSN input.
- [ ] **Sibling apps** (zaakafhandelapp, procest, opencatalogi, docudesk, mydash, softwarecatalog, etc.) — consuming apps integrate via the `AuthAttributeMapping` configuration + Nextcloud user provisioning. No source changes required in sibling apps; they use the provisioned Nextcloud user or the ephemeral `AuthIdentity` bearer token as normal.
- [ ] **Nextcloud core** — leverages existing user-mapping infrastructure; no changes needed.

## Scope

### In Scope

- **Seven register schemas** in the `openconnector-auth` register:
  - `AuthBrokerConfig` (one per Routeringsdienst contract)
  - `AuthBrokerService` (eHerkenning service-catalog registration)
  - `AuthSession` (every authentication attempt)
  - `AuthIdentity` (result of successful authentication)
  - `AuthAttributeMapping` (per-app provisioning policy)
  - `AuthMetadataCache` (cached IdP metadata)
  - `AuthAuditEvent` (append-only audit trail)

- **SAML2 Web Browser SSO Profile** via the Routeringsdienst for both DigiD and eHerkenning.

- **Ten core requirements** (REQ-001 through REQ-010):
  - DigiD SAML2 authentication with LoA enforcement
  - eHerkenning with service-catalog registration
  - Pseudo-BSN translation (per-app and per-tenant variants)
  - DigiD Machtigen + eHerkenning ketenmachtiging
  - Four provisioning policies (ephemeral, persistent-on-first-login, persistent-pre-provisioned, never)
  - Metadata refresh and certificate rotation with operator notifications
  - Replay protection and assertion uniqueness
  - OIDC bridging for consuming apps that prefer OIDC
  - Fraud markers and session blocking

- **Operational support**:
  - Daily metadata refresh from the Routeringsdienst with signature verification
  - Certificate expiry monitoring with `mydash` widget and `auth_admin` notifications
  - 18-month audit-event retention per Logius DigiD afsprakenstelsel
  - Per-session immutable audit trail for fraud investigation

- **Admin UI** surface for:
  - Creating and managing `AuthBrokerConfig` records
  - Registering eHerkenning services in the service catalog
  - Setting fraud markers on identities
  - Viewing active sessions, failed-auth rates, LoA distributions
  - Certificate rotation instructions

### Out of Scope

- **BSN-based authorization decisions** — those belong in the consuming app (e.g., "is this BSN eligible for this benefit?"). The adapter provides the BSN; the app uses it.
- **DigiD-app push notifications** — handled by Logius.
- **User-side Nextcloud user provisioning for back-office staff** — handled by Nextcloud LDAP/SSO modules.
- **Offering the adapter as an Identity Provider to external systems** — the adapter is strictly a Service Provider towards DigiD/eHerkenning. Any reverse bridge (consuming apps exposing authentication to external systems) is a separate change.
- **Idensys, IRMA/Yivi, eIDAS support** — named as "optional sister means" in context-brief but deferred to follow-up changes after the core DigiD+eHerkenning foundation is stable.
- **Integration with OWASP SAML security profiles beyond the basics** — the adapter implements the SAML 2.0 Web Browser SSO Profile per Logius spec; advanced profiles like Holder-of-Key are deferred.

## Approach

Five spec deltas, each adding ADDED Requirements to brand-new capability specs, in logical dependency order:

1. **`authentication-sessions`** (REQ-AUTH-SESS-001..008) — foundation: session lifecycle, state machine, SAML RequestId matching, replay protection.
2. **`digid-saml2-broker`** (REQ-DIGID-001..006) — DigiD-specific: LoA contexts, BSN extraction, Machtigen detection.
3. **`eherkenning-saml2-broker`** (REQ-EH-001..005) — eHerkenning-specific: service catalog, legal-subject + acting-subject extraction, ketenmachtiging chains.
4. **`pseudo-bsn-translation`** (REQ-PBS-001..003) — shared by DigiD and eHerkenning: per-app vs per-tenant derivation.
5. **`nextcloud-user-provisioning`** (REQ-PROV-001..004) — mapping policy, ephemeral vs persistent user creation.
6. **`authentication-metadata-and-certs`** (REQ-META-001..004) — Routeringsdienst metadata refresh, PKIoverheid cert rotation, expiry monitoring.
7. **`authentication-audit-and-fraud`** (REQ-AUDIT-001..004) — immutable audit trail, fraud markers, session blocking.
8. **`oidc-bridging`** (REQ-OIDC-001..002) — optional OIDC surface for consuming apps.

All specs follow the conduction-schema format (RFC 2119, `### REQ-{Abbrev}-NNN: <name>`, `#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN).

## New Dependencies

- **OpenRegister** — uses existing `RegisterObject` base, BSN-at-rest encryption service, append-only audit-trail enforcement, existing `DigikoppelingCertificate` schema for PKIoverheid certs.
- **Logius standards** — DigiD Aansluitvoorwaarden, eHerkenning Afsprakenstelsel, Routeringsdienst Koppelvlakspecificatie.
- **eIDAS / Forum Standaardisatie** — SAML 2.0 Core, Bindings, Profiles; eIDAS Level-of-Assurance framework.
- **PKIoverheid** — SP and IdP signing/encryption certificates via the existing accreditation process.
- No new libraries or version bumps beyond what OpenRegister + Nextcloud already ship.

## Impact

- `openspec/changes/digid-eherkenning-auth-adapter/` is a new folder containing one `proposal.md`, one `design.md`, one `tasks.md`, and eight `specs/{slug}/spec.md` files.
- In the openconnector source tree:
  - New `OCA\OpenConnectorAuth` namespace with 7 entity classes, 3 service classes (AuthBrokerService, SessionManager, MetadataManager), and 4 controller classes.
  - New `src/manifest.json` entries for `/auth/{means}/start`, `/auth/{means}/callback`, `/auth/fraud-markers/*` endpoints.
  - New migrations for the seven schemas in the `openconnector-auth` register.
  - New scheduled jobs for metadata refresh, certificate expiry monitoring, audit retention.
  - Updates to existing `openconnector_admin.vue` component to integrate the fraud-markers admin surface.

## Cross-Project Dependencies

- **OpenRegister** — depends on the existing `RegisterObject` + encryption service being stable. No OR change required for the auth adapter; all primitives already exist.
- **Digikoppeling adapter** — depends on `DigikoppelingCertificate` schema being consumable for SP certs. Already available.
- **HaalCentraal adapter** — will receive BSN inputs from this adapter's authenticated citizens. No HaalCentraal change needed.
- **Sibling apps** (zaakafhandelapp, procest, opencatalogi, etc.) — will consume the `AuthIdentity` bearer token or the provisioned Nextcloud user. No sibling-app source changes required beyond optional integration code to handle the auth flow entry point.

## Risks

### Risk 1: SAML implementation complexity — clock skew, signed assertions, metadata refresh timing

**Severity**: Medium
**Mitigation**: Leverage a mature SAML library (php-saml / SimpleSAMLphp), never author XML parsing from scratch. Include clock-skew tolerance (±60 seconds) in assertion validation. Comprehensive test fixtures against real Routeringsdienst metadata (preprod). Early integration testing in the spec phase, not just unit tests.

### Risk 2: PKIoverheid certificate rotation coordination — SP cert expiry breaks authentications until renewed

**Severity**: Medium
**Mitigation**: Daily certificate expiry monitoring with 60-day advance notification to `auth_admin` group. `mydash` widget for instant visibility. Ops runbook in the change's documentation. Graceful degradation: on actual expiry, existing valid sessions continue; new authentications are rejected with a clear "certificate rotation required" error until the new cert is deployed. No silent failures.

### Risk 3: Pseudo-BSN derivation leaks identifiers if the per-app secret is compromised

**Severity**: Medium
**Mitigation**: Per-app secrets stored in Nextcloud's `IAppConfig` (encrypted at rest per ADR-016). Never derive pseudo-BSN from the real BSN using a weak hash (e.g., SHA1). Use HMAC-SHA256 with a 256-bit key minimum. Pre-shared secrets are rotated annually via the admin UI and logged to the audit trail.

### Risk 4: Logius accreditation is slow; the adapter may not be production-ready by the time consuming apps need it

**Severity**: Low-Medium
**Mitigation**: Spec-only change for the first delta. First concrete adapter implementation will be zaakafhandelapp's citizen-portal intake (a high-value consumer). Accreditation begins as soon as the implementation PR is review-ready (not waiting for production deployment). Preprod testing in parallel with the accreditation cycle. The adapter can deploy to production in preprod-only mode while production accreditation is in-flight.

### Risk 5: Routeringsdienst metadata validation fails; assertions can't be verified until metadata is refreshed

**Severity**: Low
**Mitigation**: Metadata fetch is synchronous at broker startup and hourly thereafter. Metadata expiry triggers a warning in logs. If metadata fetch fails, the broker logs the error; new authentications are rejected with "service temporarily unavailable" (not a data-loss or silent-fail scenario). Metadata is signed by the Routeringsdienst; signature validation is non-negotiable and prevents poison-pill attacks.

### Risk 6: Machtigingen flows (DigiD Machtigen, eHerkenning ketenmachtiging) are complex; the adapter may not capture all chains correctly

**Severity**: Low
**Mitigation**: Detailed GIVEN/WHEN/THEN scenarios in REQ-005 for both flows. Automated test fixtures capturing real assertions from Logius test instances. Consuming apps are explicitly responsible for policy enforcement ("is this machtigen chain authorized?"); the adapter only surfaces what the assertion contains. Audit trail logs every detected machtigen so fraudsters can't use a chain they're not aware of.

### Risk 7: Pseudo-BSN translation introduces a new level of operational complexity; app developers may misunderstand when to use per-app vs per-tenant mode

**Severity**: Low
**Mitigation**: Clear guidance in the spec: per-app mode (default) for apps that do NOT share their citizen database across tenants. Per-tenant mode only if the same Nextcloud instance is serving multiple separate municipalities and they agree to share citizen identity. Admin UI makes the choice explicit at `AuthAttributeMapping` creation time. Ops runbook explains the implications.

### Risk 8: Audit-event retention at 18 months is mandated by Logius but may exceed Nextcloud's own retention policies

**Severity**: Low
**Mitigation**: Audit events are stored in an append-only `AuthAuditEvent` register, separate from session records. Retention is enforced by a separate garbage-collection job that respects the per-app configured retention window (default 18 months, min 12, max 36). Audit export (for Logius audits) is a documented admin operation. No conflict with Nextcloud's built-in log retention.

## Rollback Strategy

This is a spec change + service implementation. To roll back:

1. **Spec phase**: Revert the change folder; no runtime impact.
2. **After implementation lands**:
   - Revert the PR; delete the `OCA\OpenConnectorAuth` namespace.
   - Drop the eight register tables.
   - Consuming apps that rely on the auth adapter can no longer use it; they fall back to anonymous citizen flows (or they contract a commercial broker).
   - Audit events are archived (exported for Logius) before the rollback.

## Open Questions

1. **Idensys, IRMA/Yivi, eIDAS support** — the context-brief names these as "optional sister means." Should this change include spec stubs for them, or defer entirely to follow-up changes after core DigiD+eHerkenning is stable in production? **Recommendation**: Defer. The core change is large enough; sister means can land in a separate change once the core is battle-tested.

2. **OIDC bridging optional?** — REQ-009 defines OIDC bridging as part of the core. Some consuming apps may never need it (DigiD / eHerkenning are SAML-only on the Routeringsdienst side). Should OIDC bridging be required in every deployment or optional / opt-in? **Recommendation**: Include in the spec but ship as a non-required capability; early implementations can omit it if no consuming app requests it.

3. **Nextcloud user provisioning scope** — the context-brief lists four provisioning policies (ephemeral, persistent-on-first-login, persistent-pre-provisioned, never). Should the adapter also support a fifth policy: "persistent-on-first-login but enforce daily re-authentication"? **Recommendation**: Defer. The four policies cover 99% of use cases; a fifth can be added later if a consuming need surfaces.

4. **Multilingual eHerkenning service names** — when registering services in the service catalog, the `serviceName` and `serviceDescription` are submitted to Logius. Should the adapter support i18n translations of these names, or are they always in Dutch? **Recommendation**: Dutch only for first iteration. If a EU cross-border eIDAS adapter lands later, revisit multilingual service names then.
