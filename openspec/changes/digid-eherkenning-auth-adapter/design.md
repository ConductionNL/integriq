# Design — DigiD + eHerkenning Authentication Broker

## Context

Dutch law (Wet digitale overheid / Wdo) mandates that any public-facing government service must authenticate citizens via DigiD and legal-entity representatives via eHerkenning. These are the *only* two acceptable means for citizen / company authentication in government portals.

The Routeringsdienst is the single SAML2 Identity Provider that all Dutch government services route through. There is no option to talk directly to DigiD / eHerkenning; you must go through the Routeringsdienst.

Today, Conduction apps have no shared broker for this. Every app that has tried to integrate DigiD / eHerkenning has either:

- Contracted a commercial broker (Signicat, Connectis, KPN Lokale Overheid) — adds cost and operational overhead per app.
- Shipped without public authentication — falls back to anonymous flows or VPN-locked portals.
- Embedded a custom SAML integration — technical debt, no shared patterns, audit burden, certificate management per app.

This change builds a shared broker inside OpenConnector that amortizes the Logius accreditation cost (6-12 weeks, one-time) across every consuming app. The broker is the linchpin for any Conduction app that needs citizen or company authentication.

## Goals

- **Logius accreditation in OpenConnector, not per app** — the adapter handles the SAML2 web-browser SSO profile, the AssertionConsumer endpoint, the metadata refresh cycle, and the certificate rotation. Consuming apps do not need Logius expertise; they plug in via the provisioning policy and the `AuthAttributeMapping` configuration.

- **Support both DigiD and eHerkenning with different authorization models** — DigiD is citizen-centric (BSN, Machtigen); eHerkenning is entity-centric (RSIN/KvK + natural-person acting on behalf, ketenmachtiging chains). The adapter surfaces both identities to the consuming app.

- **Four provisioning policies for Nextcloud user mapping** — ephemeral (no user created, use bearer token), persistent-on-first-login (provision user on first auth), persistent-pre-provisioned (auth fails if user doesn't already exist), never (auth succeeds but no user mapping).

- **Immutable audit trail for fraud investigation** — Logius mandates 18-month audit retention. Every authentication step (initiate, authnrequest-sent, assertion-received, authenticated, failed) is logged immutably and queryable by `auth_admin` for forensics.

- **Transparent metadata refresh and certificate rotation** — the broker autonomously refreshes Routeringsdienst metadata daily, validates signatures, extracts IdP certs (including rotation windows where both old + new cert are valid), and monitors SP certificate expiry with operator notifications.

- **Per-app pseudo-BSN translation** — consuming apps can opt-in to receiving a derived identifier instead of the real BSN, blocking downstream apps from ever seeing citizen identifiers. Per-app (different pseudo-BSN per app) or per-tenant (same across all apps in a Nextcloud instance) variants.

- **Loа enforcement at both request and response layers** — the broker enforces requested LoA in the AuthnRequest and rejects assertions that fall below the requested LoA, even if DigiD returned a valid assertion at a lower level.

- **OIDC bridging for consuming apps that prefer OIDC** — consuming apps can use OIDC Authorization Code flow with the broker as the OIDC server, even though the upstream Routeringsdienst is SAML2 only.

## Non-Goals

- **Direct Idensys / IRMA / eIDAS support** — named as optional means in context-brief; deferred to follow-up changes.
- **BSN-based authorization decisions** — the consuming app is responsible for authorization (e.g., "is this BSN eligible for this benefit?"). The adapter provides the identity; the app uses it.
- **Per-user credential storage** — unlike OAuth integrations, SAML2 DigiD/eHerkenning do not issue per-citizen / per-company-rep tokens. The broker stores no tokens; each authentication generates an ephemeral session.
- **Reverse-proxy / WAM integration** — the adapter is not a WAM (Web Access Management) system. It doesn't intercept HTTP; it's explicitly called by consuming apps via API.

## Decisions

### D1 — Use OpenRegister primitives for the schema layer; no parallel auth table in openconnector

The adapter uses OpenRegister's `RegisterObject` base class for all seven entity types:

| Entity | Why RegisterObject |
|---|---|
| AuthBrokerConfig | one per Routeringsdienst contract; needs versioning, creator/modifier tracking |
| AuthBrokerService | eHerkenning service-catalog registration; part of the auditable contract with Logius |
| AuthSession | every auth attempt; immutable session state machine |
| AuthIdentity | authenticated identity; immutable once created; linked to Nextcloud user |
| AuthAttributeMapping | per-app provisioning policy; owned by the app admin; versioned for policy audits |
| AuthMetadataCache | cached IdP metadata; immutable; timestamped refresh; signature validation |
| AuthAuditEvent | append-only audit trail; non-repudiation of every step; 18-month retention mandate |

**Alternative considered**: Author a parallel `auth_session`, `auth_identity` table in openconnector outside the register. Rejected because:
- RegisterObject gives us audit-trail-immutability for free (per ADR-022), required by Logius.
- Register exports let operators download audit logs for compliance audits.
- Version tracking on AuthAttributeMapping lets us prove to auditors that "app X has always been configured with policy Y."

### D2 — Session ID is opaque UUID; no JWT or bearer-token-embedded claims

When an authentication succeeds, the broker returns a session ID (a UUID) to the consuming app's callback URL. The consuming app exchanges this session ID for the `AuthIdentity` via an API call. The session ID is the only credential handed to the browser.

| Credential type | Choice | Why |
|---|---|---|
| Session ID | Opaque UUID | Prevents token inspection attacks; claims are stored server-side, not in the token |
| Cookie | Not used | Consuming apps manage their own cookie policy; the broker doesn't set cookies |
| Bearer token | Used only for ephemeral policies | When the consuming app's policy is "ephemeral," the returned `AuthIdentity.id` itself is the bearer token |

**Alternative considered**: Return a self-contained JWT with embedded `sub`, `bsn`, `legalSubjectId` claims. Rejected because:
- Consuming apps would need to validate JWT signatures issued by the broker.
- JWTs live longer than the session state on the broker; if we revoke a session (fraud marker set), the JWT is still valid client-side.
- Opaque session IDs force the consuming app to hit the broker API, which is the correct trust model (consuming app authenticates to the broker before getting the identity).

### D3 — AuthAttributeMapping is per-consuming-app, not per-Nextcloud-user

The provisioning policy is set at the consuming-app level, not per-user. All citizens / company reps who authenticate to the same app get the same provisioning treatment.

| Scenario | Behavior |
|---|---|
| App A has `provisioningPolicy: ephemeral` | Every auth creates a session; no Nextcloud user is created. Consuming app uses `AuthIdentity.id` as bearer token. |
| App B has `provisioningPolicy: persistent-on-first-login` | First auth from a new BSN provisions a Nextcloud user. Second auth from the same BSN reuses the existing user. |
| App C has `provisioningPolicy: persistent-pre-provisioned` | Auth fails if no matching Nextcloud user already exists. The consuming app admin must provision users in advance (via LDAP, SCIM, or manual provisioning). |

**Alternative considered**: Let the provisioning policy vary per-user via a user-level configuration. Rejected because:
- Operational complexity — admins would need a UI to manage per-user policies.
- Audit burden — "why does user X get persistent provisioning but user Y doesn't?" is hard to explain to auditors.
- The three options cover 99% of use cases; if an app truly needs per-user variation, they can implement it in their own code.

### D4 — Pseudo-BSN is a deterministic HMAC, never a random hash

When `bsnPseudoBsnMode: per-app` is set, the broker computes:

```
pseudo_bsn = HMAC-SHA256( secret_key=app_secret, message=real_bsn )[:19]  # 19 hex chars to mimic BSN length
```

The same real BSN + app secret always produces the same pseudo-BSN. If a citizen returns to the app a year later, they're recognized by the same pseudo-BSN.

| Mode | Computation | Recognition scope |
|---|---|---|
| `bsn` (default) | No derivation; real BSN stored encrypted | Real citizen recognized everywhere the real BSN is used (risky privacy-wise) |
| `pseudobsn-per-app` | HMAC per app with app-specific secret | Citizen recognized within one app only; different pseudo-BSN in each app |
| `pseudobsn-shared` | HMAC per tenant with tenant-wide secret | Same citizen recognized across all apps in the same Nextcloud instance; different pseudo-BSN per Nextcloud instance |

**Alternative considered**: Use a salted hash (e.g., bcrypt). Rejected because:
- Hashes are one-way; if the app needs to verify "is this citizen the same as before," hashing is useless.
- HMAC is deterministic and efficient; no need for salt.

### D5 — Metadata is fetched daily + on-demand, never cached for >24 hours

The broker fetches Routeringsdienst metadata:
- **At startup** (synchronously; if it fails, the broker logs an error and continues with no metadata, rejecting all authn attempts until metadata is fetched).
- **Every 24 hours** (background job).
- **On metadata signature mismatch** (if an assertion arrives signed by a cert not in the cache, we refetch metadata immediately; this covers IdP cert rotation windows).
- **On-demand via admin API** (operator can force a refresh).

**Alternative considered**: Cache metadata for the full validity window (often 30 days). Rejected because:
- 24-hour refresh lets us pick up IdP certificate rotations quickly.
- Metadata is small (< 100 KB); fetching daily is cheap.
- Failure to refresh in time is caught within a day, not 30 days.

### D6 — SP (Service Provider) certificate expiry monitoring is mandatory; no silent failures

The broker monitors the SP signing and encryption certificates daily:

| Days to expiry | Action |
|---|---|
| > 60 days | No action; everything normal. |
| 60-30 days | Warning sent to `auth_admin` group; `mydash` widget shows countdown. |
| 30-7 days | Critical alert sent to `auth_admin` group (daily); `mydash` widget is red. |
| < 7 days | Page-on-call integration if configured. |
| 0 days (expired) | New auth requests are rejected with "certificate-expired" error (not silent); existing valid sessions continue until their own expiry. |

**Alternative considered**: Silently reject expired-cert requests and let the logs tell the story. Rejected because:
- Operators may not read logs in time.
- Citizens get a generic error with no actionable message.
- Explicit status monitoring (mydash widget, notifications) is the norm in the Conduction stack.

### D7 — Destructive operations (fraud markers, session revocation) are logged immutably and attributed to the operator

Every fraud-marker operation is itself an audit event:

```
AuthAuditEvent {
  eventType: "fraud-marker-set" | "fraud-marker-unset",
  actor: <Nextcloud user who set the marker>,
  details: { bsn_or_pseudobsn: "...", reason: "...", unblock_after: "..." }
}
```

The marker is idempotent (setting the same marker twice is a no-op), but every operation is logged, even no-ops.

**Alternative considered**: Let fraud markers be set by a background job / system task without operator attribution. Rejected because:
- Auditors need to know *who* set a marker and *why*.
- System-level markers are harder to debug ("why did this citizen get blocked?").
- Operator attribution is the norm in the Conduction stack per ADR-023 (action authorization).

### D8 — Nextcloud user linking is explicit and can be unlinked

The `linkedNextcloudUserId` on `AuthIdentity` is set by the broker at provisioning time. If a consuming app's admin wants to unlink an identity (e.g., "this citizen should no longer be able to log in as user X"), they delete the link on the `AuthIdentity` record. Future authentications from the same BSN are no longer mapped to user X.

**Alternative considered**: Use a separate `AuthIdentity -> NextcloudUser` join table. Rejected because:
- Adds complexity; the join is one-to-one in the persistent-policy case.
- The `linkedNextcloudUserId` field on `AuthIdentity` is sufficient and auditable.

## Reuse Analysis

| Capability needed | What already exists | Auth-adapter reuse strategy |
|---|---|---|
| SAML library | SimpleSAMLphp or php-saml | Directly consumed; no reimplementation |
| Register schemas | OpenRegister `RegisterObject` base | Consumed for all 7 entity types; BSN-at-rest encryption service for encrypted fields |
| Audit trail immutability | OR audit-trail-immutable per ADR-022 | Consumed by `AuthAuditEvent` schema |
| Encrypted field storage | OR encryption service (ADR-016) | Consumed for `AuthIdentity.bsnEncrypted` field |
| Nextcloud user provisioning | Nextcloud core `UserManager` API | Consumed for persistent-policy user creation |
| Admin UI components | openconnector's existing admin UI pattern | Consumed for fraud-markers admin surface |
| i18n | Nextcloud's `IL10N` per ADR-007 | Consumed for session error messages, admin UI strings |
| LoA context URIs | SAML 2.0 standard + Logius spec | Hardcoded URIs per spec (not configurable per-app) |
| Pseudo-BSN derivation | HMAC-SHA256 (stdlib) | Consumed; no external library needed |
| Metadata signature validation | SimpleSAMLphp's metadata validator | Consumed; no custom XML signature logic |
| Session state machine | None (custom) | Custom implementation; 7-state machine (initiated, authnrequest-sent, assertion-received, authenticated, failed, logged-out, expired) |
| Fraud markers | None (custom) | Custom implementation; immutable marker records with operator attribution |
| Nextcloud user mapping | Existing `Mapping` pattern in OR (optional for other uses, mandatory here) | Consumed by `AuthAttributeMapping` schema to express provisioning rules |

**Net new code**: One `OCA\OpenConnectorAuth` namespace with 7 entity classes, 3 service classes (AuthBrokerService, SessionManager, MetadataManager), 4 controller classes. No new external dependencies beyond SimpleSAMLphp.

## Declarative-vs-imperative decision (per ADR-031)

Every auth-adapter behaviour was classified:

| Behaviour | Decision | Why |
|---|---|---|
| Adapter registration | Consumed from openconnector's existing service/provider pattern | Integration point is explicit in `openconnector/src/AppInfo/Application.php` |
| SAML AuthnRequest construction | Imperative (SimpleSAMLphp library) | Not expressible declaratively; SAML spec is procedural |
| LoA context selection | Declarative (enum in `AuthAttributeMapping.defaultLoa` + per-request query param) | Mapping rule, not business logic |
| Assertion validation (signature, InResponseTo, NotBefore/NotOnOrAfter) | Imperative (custom glue around SimpleSAMLphp validator) | SAML validation rules are procedural |
| Session state machine | Imperative (SessionManager service) | Too complex for declarative expression; 7 states, 15+ transitions |
| Pseudo-BSN derivation | Imperative (one-line HMAC call in a service method) | Logic is simple but procedural |
| Metadata refresh schedule | Declarative (OR `ScheduledWorkflow` per ADR-031 path 2) | Periodic external trigger |
| Fraud marker enforcement | Imperative (checked at assertion-received step in SessionManager) | Early-return pattern in the session flow |
| Nextcloud user provisioning | Declarative (mapping rule via `AuthAttributeMapping` schema) | Policy, not logic; expressed as configuration |
| Audit logging | Imperative (method-call in each session step) | Immutable append-only constraint requires procedural enforcement |
| Certificate expiry monitoring | Declarative (daily job configured in `ScheduledWorkflow`) | Periodic trigger, declarative threshold (60 days) |
| Error message translation | Declarative (i18n keys in OR enum for `errorCode`) | Translatable strings per ADR-007 |

No service class is authored to encapsulate "SAML logic" (all SAML is delegated to SimpleSAMLphp). Custom logic is confined to `SessionManager` (session state) and `MetadataManager` (metadata refresh), both of which are minimal and single-purpose.

## Seed Data

The adapter ships **no seed data** because there is nothing to seed at the adapter level:

- `AuthBrokerConfig` records (one per Routeringsdienst contract) are created by the openconnector admin when setting up a new broker instance. Each municipality has its own contract; seed data would be meaningless across municipalities.
- `AuthBrokerService` records are submitted to Logius as part of the eHerkenning accreditation and registered in the service catalog. Seed data is not applicable.

Per-app `AuthAttributeMapping` records are created by each consuming app's admin in a follow-up setup step. No seed.

If a consuming app (e.g., zaakafhandelapp) wants to ship with an example / dev-mode auth setup, that app's own change can include seed data for its `AuthAttributeMapping` pointing to a preprod `AuthBrokerConfig`. This change (the broker itself) does not ship seeds.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| SAML implementation complexity (clock skew, metadata timing, etc.) | Use SimpleSAMLphp, not custom XML parsing. Comprehensive test fixtures against real Routeringsdienst test instances. 60-second clock-skew tolerance. Early integration testing. |
| PKIoverheid cert expiry breaks all authentications until renewed | Daily monitoring, 60-day advance notice, `mydash` widget, graceful rejection (not silent), runbook. |
| Pseudo-BSN secret compromise | Secrets stored in encrypted Nextcloud `IAppConfig`. 256-bit keys minimum. Annual rotation via admin UI. Audit log of every rotation. |
| Logius accreditation slow; adapter may not be ready in time | Accreditation starts immediately post-spec approval. Preprod testing in parallel. Production deployment can wait; preprod testing starts early. |
| Metadata fetch failure blocks all authentications | Logged as error; explicit "service unavailable" to citizen; operator is notified. No silent failures. |
| Machtigingen flows are complex | Detailed spec scenarios. Real-world test fixtures from Logius. Audit logs every chain so fraud is detectable. |
| Four provisioning policies may confuse app admins | Clear admin UI. Docs explain each policy's trade-off. Support escalation path for ambiguous cases. |
| 18-month audit retention exceeds Nextcloud's own log retention | Audit stored in separate `AuthAuditEvent` register with independent retention job. No conflict. |

## Migration Plan

Spec-only for this change. When per-consuming-app implementation lands (e.g., zaakafhandelapp):

1. Consuming app author reads this change's eight specs.
2. Consuming app's admin creates one `AuthBrokerConfig` (or reuses an existing one if the broker is already deployed).
3. Consuming app's admin creates one `AuthAttributeMapping` per authentication entry point (e.g., DigiD for citizens, eHerkenning for companies).
4. Consuming app's code adds a route handler for the callback URL (receives the session ID, exchanges it for the identity, creates or links the Nextcloud user as per policy).
5. Optional: consuming app's docs explain to end users "log in with DigiD / eHerkenning here."

Down-direction: If a consuming app no longer needs the adapter, they remove the route handler; the `AuthAttributeMapping` can be deleted or left paused. No schema migration needed.

## Open Questions

1. **Idensys / IRMA / eIDAS support** — should spec stubs for these be included now, or deferred to a follow-up change? **Recommendation**: Defer. Core DigiD + eHerkenning first.

2. **OIDC bridging adoption** — should the spec require OIDC support in the first implementation, or make it optional? **Recommendation**: Include in the spec (REQ-OIDC-001..002) but not required for MVP. Early implementations can omit it if no consuming app requests it.

3. **Per-user re-authentication in persistent policies** — should we add a fifth provisioning policy, "persistent-with-daily-re-auth," or is four policies sufficient? **Recommendation**: Four policies are enough for MVP. If a use case surfaces later, add the fifth policy in a follow-up delta.

4. **eHerkenning service-name i18n** — should `AuthBrokerService.serviceName` be translatable, or always Dutch? **Recommendation**: Dutch only for MVP. Revisit if EU cross-border eIDAS adapter lands and demands multilingual service names.

5. **Session timeout handling** — when a session expires (after the configured `expiresAt`), should the broker automatically delete the `AuthSession` record or keep it for audit? **Recommendation**: Keep for audit (up to 18 months). Expired sessions are queryable by `auth_admin` for forensics. Periodic garbage collection respects the retention window.
