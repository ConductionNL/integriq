# Design — LTI 1.3 / LTI Advantage adapter (Platform + Tool roles)

## Context

LTI 1.3 replaces LTI 1.1's shared-secret OAuth1 with an OIDC-based launch:
the Platform (LMS) redirects the user's browser through a third-party-initiated
login, then POSTs a signed `id_token` (JWS, RS256/PS256) to the Tool's launch
URL. The Tool verifies the signature against the Platform's published JWKS.
LTI Advantage adds three services on top of the launch: **Deep Linking 2.0**
(content selection — same signed-JWT message pattern, different
`message_type`), **AGS** (Assignment & Grade Services — line items, scores,
results, reached over a bearer-token-authenticated REST API), and **NRPS**
(Names & Role Provisioning Services — roster reads, same bearer-token
mechanism). Advantage service calls use RFC 7523 JWT-bearer client-credentials:
the caller (whichever side is acting as the *client* for that call) signs a
JWT assertion with its own private key and exchanges it at the other side's
token endpoint for a scoped access token.

Because a single OpenConnector instance may need to act as **both** roles
simultaneously for different consuming apps (scholiq launches an external
content tool → we are Platform; scholiq itself is launched from a
Moodle-based state platform → we are Tool, for a *different* registration),
"Platform" and "Tool" are not a single instance-wide mode switch — they are
two independent registries (`lti_platform`, `lti_tool`) that can both be
populated at once, each with its own outbound/inbound behaviour.

## Decisions

### D1 — Two registries, not one generic "LTI connection" schema

`lti_platform` rows describe platforms that launch **into** us (we are Tool
for that row); `lti_tool` rows describe tools we launch (we are Platform for
that row). Keeping them separate schemas — rather than one polymorphic
`lti_registration` with a `role` discriminator — mirrors the existing
`Source`/`Endpoint` split (inbound vs outbound are different entities with
different field sets in this codebase, not one entity with a direction flag)
and keeps the *Adapters* catalogue picker and the *Beheer > Authenticatie*
registration list each showing one unambiguous list. `lti_deployment` is the
join: it always references exactly one `lti_platform` OR one `lti_tool`
(never both) plus the consuming-app placement data (`launchTargetUrl`,
`gradeSink`, `rosterSource`).

**Rejected alternative**: a single `lti_registration` schema with
`role: platform|tool`. Rejected because the field sets genuinely diverge
(a `lti_platform` row needs the *platform's* `auth_login_url`/`auth_token_url`;
an `lti_tool` row needs the *tool's* `oidc_login_url`/`launch_url`) and a
shared schema would need every field nullable with role-conditional
validation duplicated everywhere the schema is read — the same reasoning
ADR-005 already applied to keep `Source`/`Synchronization`/`Contract` as
three entities.

### D2 — IA placement follows the DigiD/eHerkenning split (ADR-017 Rule 7), not a new exception

- ***Adapters*** catalogue: an "LTI 1.3 / LTI Advantage" card (discovery,
  capability description, config schema for `lti_platform`/`lti_tool`/`lti_deployment`)
  plus a step in the *Verbindingen* new-connection wizard — no new top-level
  menu (Rule 1).
- ***Beheer > Authenticatie***: the signing-keypair lifecycle (generate,
  rotate, view active `kid`) and the registration list — tenant-wide
  infrastructure, per Rule 3 ("JWT/JWE keys ... key rotation policy lives in
  *Beheer > Authenticatie*"), exactly like `digid-eherkenning-auth-adapter`'s
  sanctioned split (Rule 7).
- Dedicated public routes (`/api/lti/...`) for the protocol endpoints
  themselves — these are not configuration surfaces at all, so they don't
  enter the IA discussion; they follow the `dso-omgevingsloket`
  dedicated-controller precedent (protocol-specific inbound
  verification the generic `Endpoint` pipeline can't express).

### D3 — Own signing keys: per-registration, not instance-wide; custody follows the existing (not the aspirational) pattern

Each `lti_platform` and `lti_tool` row carries its own `signingKeys[]` array
(`kid`, `algorithm`, `publicJwk`, `privateKeySecret`, `status: active|previous|retired`,
`rotatedAt`), **not** one instance-wide keypair. Rationale — this is the
per-deployment-isolation requirement from the change brief: if one
registration's key material were ever compromised, an instance-wide key would
let the attacker forge launches/assertions for every other registration too.
Per-registration keys bound the blast radius to that one platform/tool
relationship.

Key rotation follows the `webhook-signing` REQ-WHS-002 shape (generate →
active; rotate → old key moves to `previous` with a grace window; `previous`
keys remain in the published JWKS and remain valid for verifying tokens
*we already signed* during the grace window, but a new signature is always
produced with the current `active` key). Default grace window: 7 days
(longer than webhook-signing's 24h, because a stale platform-side JWKS cache
on the *far side* is normal and outside our control — 24h risks a launch
failing because the platform hasn't refreshed our previous `kid` yet).

**Custody**: the private key material is stored the same way
`AuthenticationService::fetchJWTToken`'s `secret` configuration is stored
today — plaintext-pending-encryption, per **ADR-007**'s already-accepted,
fleet-wide status quo for every `Source` credential field (`secret`,
`password`, `apikey`, `jwt`, `jwtId`, `username` — see
`openspec/architecture/adr-007-source-credentials-stored-plaintext-pending-encryption.md`).
This is the norm this codebase already ships, not an oversight, and `source-broker-credentials`
(in-flight) cannot yet hand back raw key material for in-process signing
either way.

Note this is a **deliberate divergence** from, not a repeat of,
`digikoppeling-adapter`'s design decision D5
(`openspec/changes/archive/2026-07-07-digikoppeling-adapter/design.md:51-58`):
D5 is titled "PKIoverheid keys via the credential broker, **never** plaintext"
and has the adapter fail closed rather than write PKIoverheid certificate
material to disk at all. That stricter posture fits digikoppeling's threat
model — a formally-issued, externally-audited PKIoverheid certificate with its
own custody chain, where OpenConnector is a *custodian* of someone else's key.
LTI's signing keys are the opposite case: OpenConnector *generates* them
itself (REQ-LTI-002) purely for its own launch/assertion signing, the same
kind of self-generated secret every other `Source` `secret`/`jwt` field
already is under ADR-007. Applying digikoppeling's fail-closed rule here would
block a table-stakes, broadly-demanded capability on unrelated broker work for
no matching threat-model reason. Migrating LTI key custody to the broker is
still tracked as a follow-on once it grows a signing-material issuance
capability — at that point it can adopt digikoppeling's stricter posture too,
but the two are not "the same decision" today.

**Rejected alternative**: block this change on the broker gaining raw-key
support. Rejected — that would make LTI (a table-stakes, broadly-demanded
capability) hostage to unrelated broker work, and the plaintext-pending
posture is already the accepted, documented status quo for every other
signing secret in this codebase (Source `secret`/`jwt` fields, ADR-007).

### D4 — JWKS resolution: fetch-and-cache with kid-scoped, rate-limited refetch

`LtiJwksResolverService` resolves an external `jwks_uri` (the platform's, when
we're Tool; the tool's, when we're Platform) to a `JWKSet`, then looks up the
token header's `kid`. Cache design:

- Cache backend: `ICacheFactory::createDistributed('openconnector.lti.jwks')`
  — the same distributed-cache mechanism already used for jti replay
  (`AuthorizationService::__construct`, `lib/Service/AuthorizationService.php:116-119`),
  not a new caching layer.
- Cache key: `jwks:<registrationType>:<registrationUuid>` — namespaced per
  registration (not per `jwks_uri` string) so that two registrations which
  happen to share a `jwks_uri` (a platform reusing keys across deployments)
  cannot cross-poison each other's cache entry, and so revoking one
  registration cannot be defeated by another registration's still-warm cache.
- TTL: 3600s (1h) default, configurable per registration.
- On a `kid` not found in the cached set, the resolver refetches **at most
  once per 60 seconds per registration** (tracked via the same distributed
  cache, a `jwks:refetch:<registrationUuid>` guard key with a 60s TTL) before
  falling back to rejecting the token. Without this guard, an attacker who
  controls (or has compromised) a registered platform/tool could submit a
  stream of tokens with fabricated unknown `kid`s to force unbounded JWKS
  refetches against the registered `jwks_uri` — a DoS/SSRF-amplification
  vector against whatever host that URI names. The 60s floor caps the
  refetch rate to what legitimate key rotation actually needs (rotations are
  not a sub-minute event) while remaining fast enough that a genuine rotation
  is picked up within one retry.
- The outbound fetch itself goes through the existing `CallService`/source
  machinery (`http-call-engine`), not a bare Guzzle call, so it inherits
  existing timeout/retry/CallLog observability — the `jwks_uri` is resolved
  as an ad-hoc outbound call, not a persisted `Source` (no `Source` row is
  created per registration; that would leak into the *Bronnen* list for
  something that isn't a wired connection).

### D5 — Nonce/state: single-use via the same replay-cache pattern as jti, new namespace

The OIDC login step generates `state` and `nonce`, both stored server-side in
`ICacheFactory::createDistributed('openconnector.lti.nonce')` keyed by
`nonce:<registrationUuid>:<nonce>` with a 10-minute TTL — deliberately not
reusing the `openconnector.jti` namespace (different claim, different
registrations, and a compromise or bug in one must not affect the other).
The launch handler `get()`-then-`delete()`s the nonce atomically (single
lookup consumes it — a second launch presenting the same `nonce` finds
nothing and is rejected as a replay, matching `AuthorizationService::validatePayload`'s
jti-replay shape at `lib/Service/AuthorizationService.php:343-357`). The
`state` value is additionally round-tripped through a `SameSite=None; Secure`
cookie per the IMS Security Framework's recommendation, because the
login-initiation request and the launch POST are cross-site by construction
(the browser is redirected through the platform) and `SameSite=Lax` cookies
would not survive the round trip in browsers that default to `Lax`.

### D6 — Launch validation reuses `AuthorizationService::validatePayload` for the generic JWT-timing checks, adds LTI-specific claim checks on top

`LtiLaunchService::validateLaunch()` calls the *existing*
`AuthorizationService::validatePayload()` for `iat`/`exp`/`nbf`
clock-skew-clamped validation (no duplicated timing logic), then layers LTI-
specific checks that have no existing equivalent: `aud` (and `azp` when `aud`
is an array) matches the registration's `client_id` exactly; `nonce` is
present and single-use-consumed (D5); `https://purl.imsglobal.org/spec/lti/claim/deployment_id`
matches a registered `lti_deployment` under this `lti_platform`;
`https://purl.imsglobal.org/spec/lti/claim/message_type` and
`.../version` (`"1.3.0"`) are present and recognised; the token's signature
verifies against a JWK resolved via D4. Any failure is a hard reject (HTTP
401/400) with no partial-trust fallback — there is no "warn and continue"
mode for a launch, because a forged launch grants the LTI-claimed roles/user
identity to whatever the consuming app's placement does with it.

### D7 — AGS passback: CloudEvent, not a direct write; NRPS roster: ADR-008 targetType read, not an event

These two Advantage services have different shapes (a Tool posting a score is
a *write we receive*; a Tool requesting a roster is a *read we serve*), so
they get different mechanisms rather than being forced into one:

- **AGS score received (Platform role)** → published as a
  `nl.conduction.lti.ags.score.received` CloudEvent via the existing
  `EventService::processEvent()` (unmodified). The consuming app's
  `lti_deployment.gradeSink` names the `register/schema` the app expects the
  translated score to end up in, but this change does **not** write there
  directly — it publishes the event and lets the consuming app's own
  `event_subscription` (with its own mapping/authorization) do the write.
  This matches the explicit ask ("receives AGS passback events") and avoids
  openconnector reaching into an app-owned register with app-specific write
  authorization it has no business asserting (the same reasoning `ADR-022`-
  style rules apply elsewhere: the integration platform emits, the app
  decides how to persist).
- **NRPS roster request (Platform role)** → dispatched synchronously via
  `lti_deployment.rosterSource` expressed as an ADR-008 `targetType: 'register/schema'`
  / `targetId: '{registerId}/{schemaId}'` pair, read through the same
  OpenRegister mapper path `EndpointService::handleSchemaRequest()` already
  uses for `Endpoint` GETs — reusing the read path, not inventing a second
  query mechanism. A roster read has no meaningful "fire and maybe retry
  later" semantics (the Tool is blocked on the HTTP response), so the
  async CloudEvent shape from AGS does not fit here.

### D8 — Per-deployment isolation, summarized

Every principal in this design is scoped by `lti_deployment`/registration id,
never global: JWKS cache keys (D4), nonce cache keys (D5), signing keys (D3),
and AGS access-token scopes (a token issued to `lti_tool` X's assertion is
minted with an audience/scope tied to that tool's specific `lti_deployment`
rows only — never a token valid across every deployment). A compromised or
malicious registration can therefore forge launches/read rosters/post scores
only within its own deployment(s), not laterally across other schools',
courses', or platforms' data.

## Standards references

- 1EdTech LTI 1.3 Core Specification.
- 1EdTech Security Framework (OIDC third-party-initiated login, nonce/state
  handling recommendations).
- 1EdTech LTI Advantage: Deep Linking 2.0, Assignment and Grade Services 2.0,
  Names and Role Provisioning Services 2.0.
- RFC 7523 (JSON Web Token (JWT) Profile for OAuth 2.0 Client Authentication
  and Authorization Grants) — the service-token grant AGS/NRPS use.
- RFC 7517 (JSON Web Key), RFC 7515 (JWS) — JWKS + signature format.

## Non-goals

- Not building a UI content-picker for Deep Linking in this change — the
  Deep Linking *protocol* (signed request/response JWT exchange) is in
  scope; a rich in-app "browse and select tool content" UI is a consuming-app
  concern (scholiq `lti-tool-placement` or equivalent).
- Not migrating own-key custody to the OpenRegister credential broker in v1
  (D3) — tracked as a shared follow-on with `digikoppeling-adapter`.
- Not implementing LTI 1.1 (OAuth1) backward-compatibility launches — 1.3-only,
  matching where the ecosystem (and the DE state-platform on-ramp) already is.
- Not a CASA/1EdTech certification submission in this change — the adapter is
  built to the certification-relevant requirements (this is explicitly named
  as evidence in the proposal) but certification itself is a separate,
  later process step.
