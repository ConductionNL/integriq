# lti-platform Specification

## Purpose
TBD - created by archiving change lti-13-platform. Update Purpose after archive.
## Requirements
### Requirement: LTI registration model is a catalogue entry, not a menu (REQ-LTI-001)

The system MUST declare three new OpenRegister schemas in the `openconnector`
register — `lti_platform` (an external Platform that may launch into this
instance; this instance acts as Tool), `lti_tool` (an external Tool this
instance may launch; this instance acts as Platform), and `lti_deployment`
(links exactly one `lti_platform` OR one `lti_tool` to a consuming-app
placement: the LTI `deployment_id` claim value, `launchTargetUrl`, a
`gradeSink` and `rosterSource` each expressed as an ADR-008
`targetType`/`targetId` pair, and a mapping reference). The LTI adapter MUST
be delivered as a catalogue entry in the *Adapters* section with these three
schemas as its configuration surface, per ADR-017 Rule 1. It MUST NOT add a
top-level navigation menu or a per-adapter settings page. Tenant-wide signing
key management (REQ-LTI-002) MUST surface under *Beheer > Authenticatie*, per
ADR-017 Rule 3/Rule 7 (the same sanctioned split as
`digid-eherkenning-auth-adapter`).

@e2e exclude adapter catalogue registration + schema declaration — covered by PHPUnit, no dedicated browser journey

#### Scenario: LTI ships as an Adapters card referencing three schemas

- **GIVEN** the LTI adapter is installed
- **WHEN** the *Adapters* catalogue is inspected
- **THEN** it SHALL show an "LTI 1.3 / LTI Advantage" catalogue entry backed
  by the `lti_platform`, `lti_tool`, and `lti_deployment` schemas
- **AND** no top-level menu item SHALL be added for it
- @e2e exclude catalogue registration — covered by PHPUnit

#### Scenario: an lti_deployment references exactly one registration

- **GIVEN** an `lti_deployment` record
- **WHEN** it is validated
- **THEN** it SHALL reference exactly one of `lti_platform` or `lti_tool`
  (never both, never neither)
- @e2e exclude schema validation — covered by PHPUnit

### Requirement: Own signing-key lifecycle with rotation and a per-registration JWKS publish endpoint (REQ-LTI-002)

Each `lti_platform` and `lti_tool` registration MUST carry its own
`signingKeys[]` array (`kid`, `algorithm` (RS256 or PS256), `publicJwk`,
`privateKeySecret`, `status: active | previous | retired`, `rotatedAt`),
scoped per registration (never one instance-wide key). Generation MUST
produce a fresh asymmetric keypair and set it `active`. Rotation MUST move
the current `active` key to `previous` (retained, still published, still
valid for verifying tokens signed before rotation) for a 7-day grace window
before it is marked `retired` and removed from the published set, and MUST
generate a new `active` key. New outbound signatures (launches, service-token
assertions) MUST always use the current `active` key, never a `previous` one.
The system MUST expose a public
`GET /.well-known/lti/{registrationType}/{registrationUuid}/jwks.json`
endpoint per registration returning the `active` and any `previous` (grace
window) public keys as a JWKS document; `retired` keys MUST NOT appear.

@e2e exclude backend key lifecycle + JWKS publish endpoint — covered by PHPUnit/Newman

#### Scenario: rotation keeps previously-signed tokens verifiable through the grace window

- **GIVEN** a registration rotated 1 day ago
- **WHEN** its JWKS endpoint is fetched
- **THEN** the response SHALL contain both the new `active` key and the
  `previous` key
- **AND** a token signed with the `previous` key before rotation SHALL still
  verify successfully against the published set
- @e2e exclude backend rotation — covered by PHPUnit

#### Scenario: a retired key is removed from the published set

- **GIVEN** a registration whose `previous` key's 7-day grace window has
  elapsed
- **WHEN** its JWKS endpoint is fetched
- **THEN** the retired key SHALL NOT appear in the response
- @e2e exclude backend retirement — covered by PHPUnit

### Requirement: External JWKS resolution with kid lookup, per-registration caching, and rate-limited refetch (REQ-LTI-003)

`LtiJwksResolverService` MUST resolve an external `jwks_uri` (the Platform's,
when this instance is Tool; the Tool's, when this instance is Platform) to a
`JWKSet` and look up the presented token's `kid`. Results MUST be cached via
`ICacheFactory::createDistributed('openconnector.lti.jwks')` (the same
distributed-cache mechanism `AuthorizationService` already uses for jti
replay) under a key namespaced by registration id
(`jwks:<registrationType>:<registrationUuid>`), not by the raw `jwks_uri`
string, with a default 1-hour TTL. When the requested `kid` is not present in
the cached set, the resolver MUST refetch the `jwks_uri`, but MUST NOT
refetch more than once per 60 seconds per registration (tracked via a guard
key in the same cache); while the guard is active, an unresolved `kid` MUST
fail closed (token rejected), never fall back to accepting an unverified
token. The outbound fetch MUST go through the existing `CallService` HTTP
call machinery (inheriting timeout/CallLog observability), not a bare,
unlogged HTTP client call.

@e2e exclude backend JWKS resolution and caching — covered by PHPUnit

#### Scenario: an unknown kid triggers exactly one refetch within the guard window

- **GIVEN** a registration's cached JWKS does not contain the presented
  token's `kid`
- **WHEN** three launches with the same unknown `kid` arrive within 60
  seconds
- **THEN** the resolver SHALL issue exactly one outbound JWKS fetch
- **AND** all three launches SHALL be rejected if the fetched set still does
  not contain that `kid`
- @e2e exclude backend refetch guard — covered by PHPUnit

#### Scenario: two registrations sharing a jwks_uri do not share a cache entry

- **GIVEN** `lti_platform` A and `lti_platform` B both configure the same
  `jwks_uri`
- **WHEN** A's JWKS is resolved and cached
- **THEN** B's cache lookup SHALL be a separate miss (namespaced by
  registration id, not by `jwks_uri`)
- @e2e exclude backend cache namespacing — covered by PHPUnit

### Requirement: OIDC third-party-initiated login (Tool role) (REQ-LTI-004)

`GET/POST /api/lti/{deployment}/login` MUST implement the 1EdTech Security
Framework's third-party-initiated login: validate that the request's `iss`
and `client_id` match a registered `lti_platform` (and that `login_hint`/
`target_link_uri` are present), generate a `state` and a `nonce`, persist the
`nonce` in `ICacheFactory::createDistributed('openconnector.lti.nonce')`
under key `nonce:<registrationUuid>:<nonce>` with a 10-minute TTL, set
`state` in a `SameSite=None; Secure` cookie, and redirect the browser (HTTP
302) to the platform's `auth_login_url` with the required OIDC auth-request
parameters (`scope=openid`, `response_type=id_token`,
`response_mode=form_post`, `client_id`, `redirect_uri` pointing at the
launch route, `state`, `nonce`, `prompt=none`). A request whose `iss`/
`client_id` does not match any registered `lti_platform` MUST be rejected
with HTTP 400 before any redirect is issued.

@e2e exclude backend OIDC login initiation — covered by PHPUnit/Newman, no browser UI (external platform is the caller)

#### Scenario: a valid login-initiation request redirects with state and nonce

- **GIVEN** a registered `lti_platform` and a login-initiation request
  carrying its `iss`/`client_id`
- **WHEN** the login route runs
- **THEN** the response SHALL be an HTTP 302 to the platform's
  `auth_login_url`
- **AND** the redirect SHALL carry `state` and `nonce` query parameters
- **AND** the `nonce` SHALL be persisted server-side with a 10-minute TTL

#### Scenario: an unregistered issuer is rejected before any redirect

- **GIVEN** a login-initiation request whose `iss`/`client_id` matches no
  `lti_platform`
- **WHEN** the login route runs
- **THEN** the response SHALL be HTTP 400
- **AND** no redirect SHALL be issued and no `nonce` SHALL be persisted

### Requirement: Launch id_token validation and dispatch to the consuming app (Tool role) (REQ-LTI-005)

`POST /api/lti/{deployment}/launch` MUST verify the posted `id_token`'s JWS
signature against a JWK resolved via REQ-LTI-003 (using the platform's
`jwks_uri` and the token's `kid`), then validate: `iat`/`exp`/`nbf` via the
existing `AuthorizationService::validatePayload()` (reused, not
reimplemented); `aud` (and `azp` when `aud` is an array) equals the
registration's `client_id`; the presented `state` matches the login's
`SameSite=None` cookie; the `nonce` claim is present and is consumed
atomically (get-then-delete) from the REQ-LTI-004 cache entry — a missing or
already-consumed nonce MUST reject the launch as a replay;
`https://purl.imsglobal.org/spec/lti/claim/deployment_id` matches a
registered `lti_deployment` under the resolved `lti_platform`; and
`https://purl.imsglobal.org/spec/lti/claim/message_type` /
`.../version` (`"1.3.0"`) are present and recognised. Any failure MUST
reject the launch (HTTP 401/400) with no partial-trust fallback. On success
the browser MUST be redirected to the `lti_deployment.launchTargetUrl` with a
short-lived, single-use launch reference (not the raw claims) that the
consuming app resolves server-side.

@e2e exclude backend launch validation and dispatch — covered by PHPUnit/Newman, no browser UI (external platform is the caller; consuming-app redirect target is app-owned)

#### Scenario: a valid, freshly-issued launch redirects into the consuming app

- **GIVEN** a validly signed `id_token` with a fresh, unconsumed `nonce` and
  a `deployment_id` matching a registered `lti_deployment`
- **WHEN** the launch route runs
- **THEN** the browser SHALL be redirected to `lti_deployment.launchTargetUrl`
  carrying a single-use launch reference
- **AND** the `nonce` SHALL be removed from the replay cache

#### Scenario: a replayed nonce is rejected

- **GIVEN** an `id_token` whose `nonce` was already consumed by a prior
  launch
- **WHEN** the launch route runs again with the same `nonce`
- **THEN** the response SHALL be HTTP 401
- **AND** no redirect to the consuming app SHALL occur

#### Scenario: a deployment_id not registered under the resolved platform is rejected

- **GIVEN** a validly signed `id_token` whose `deployment_id` claim does not
  match any `lti_deployment` registered under the token's issuing
  `lti_platform`
- **WHEN** the launch route runs
- **THEN** the response SHALL be HTTP 400
- **AND** no launch reference SHALL be created

### Requirement: Platform-role launch initiation and Deep Linking 2.0 (both directions) (REQ-LTI-006)

The system MUST expose an internal service method a consuming app calls to
start a launch of a registered `lti_tool`: it MUST perform the Platform-side
third-party-initiated login redirect to the tool's `oidc_login_url`, then
render an auto-submitting HTML form POSTing a fresh `id_token` — signed with
the `lti_tool` registration's `active` key (REQ-LTI-002) — to the tool's
`launch_url`, with `message_type` set per the requested launch kind
(`LtiResourceLinkRequest` or, for content selection, `LtiDeepLinkingRequest`
carrying the Deep Linking settings claim). When acting as Tool and asked to
respond to a Deep Linking request, the system MUST construct and sign an
`LtiDeepLinkingResponse` JWT carrying the selected content items and POST it
(auto-submitting form) to the platform's `deep_link_return_url`. When acting
as Platform and receiving a `LtiDeepLinkingResponse` from a launched tool,
the system MUST verify it identically to a resource-link launch (REQ-LTI-005)
and pass the parsed content items back to the consuming app that initiated
the deep-linking flow.

@e2e exclude backend Platform-role launch + Deep Linking JWT construction/verification — covered by PHPUnit, no browser UI (the auto-submit form target is an external tool/platform)

#### Scenario: a Platform-role launch is signed with the tool registration's active key

- **GIVEN** a consuming app requests a launch of a registered `lti_tool`
- **WHEN** the launch-initiation service method runs
- **THEN** the resulting `id_token` SHALL be signed with that `lti_tool`
  registration's current `active` key
- **AND** the auto-submit form SHALL target the tool's `launch_url`

#### Scenario: a Deep Linking response is verified before content items are handed to the consuming app

- **GIVEN** a launched `lti_tool` POSTs an `LtiDeepLinkingResponse` id_token
- **WHEN** the response is received
- **THEN** it SHALL be verified identically to REQ-LTI-005 (signature,
  claims, nonce/state where applicable)
- **AND** only on successful verification SHALL the parsed content items be
  returned to the initiating consuming app

### Requirement: AGS service-token issuance and inbound score/line-item endpoints (Platform role), fanned out as a CloudEvent (REQ-LTI-007)

`POST /api/lti/token` MUST implement the RFC 7523 JWT-bearer
client-credentials grant: given a `client_assertion` JWT, resolve the issuing
`lti_tool` registration by `iss`/`sub` (the tool's assigned `client_id`),
verify the assertion's signature against that tool's `jwks_uri` (REQ-LTI-003),
and — on success — issue a short-lived OAuth2 access token scoped to that
specific `lti_deployment` and the requested AGS/NRPS scope(s) only (never a
token valid across other deployments or registrations, per the
per-deployment-isolation design). Inbound AGS line-item read and score-POST
endpoints MUST require a valid access token issued by this flow, scoped to
`https://purl.imsglobal.org/spec/lti-ags/scope/lineitem` (read) or
`.../score` (write) as appropriate. A received score MUST be translated and
published as a `nl.conduction.lti.ags.score.received` CloudEvent via the
existing `EventService::processEvent()` (unmodified) — the system MUST NOT
write the score directly into any consuming-app-owned register.

@e2e exclude backend token issuance, AGS endpoints, CloudEvent fan-out — covered by PHPUnit/Newman

#### Scenario: a valid client assertion is exchanged for a deployment-scoped access token

- **GIVEN** a registered `lti_tool` signs a `client_assertion` with its
  registration's private key
- **WHEN** `POST /api/lti/token` runs
- **THEN** an access token SHALL be issued scoped to that tool's specific
  `lti_deployment`(s) and the requested scope only

#### Scenario: a received score is published as a CloudEvent, not written directly

- **GIVEN** an authorized AGS score-POST for a line item under a registered
  `lti_deployment`
- **WHEN** the score endpoint processes the request
- **THEN** a `nl.conduction.lti.ags.score.received` CloudEvent SHALL be
  published carrying the score, the `lti_deployment` reference, and the
  originating user/line-item identifiers
- **AND** no direct write SHALL occur to the `lti_deployment.gradeSink`
  register/schema

#### Scenario: an access token scoped to one deployment cannot read another deployment's line items

- **GIVEN** an access token issued for `lti_deployment` A
- **WHEN** it is presented against a line-item endpoint under
  `lti_deployment` B (same `lti_tool` registration, different deployment)
- **THEN** the request SHALL be rejected (HTTP 403)

### Requirement: AGS outbound score publish and result read (Tool role) (REQ-LTI-008)

When acting as Tool, the system MUST reuse `AuthenticationService::fetchOAuthTokens()`
to publish a score to a Platform's line item and to read a result, with
`grant_type=client_credentials` and
`client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer`
(unmodified) against the `lti_platform` registration's `auth_token_url`,
requesting the `.../score` (publish) or `.../result` (read) scope, then
dispatch the AGS REST call through the existing outbound HTTP call machinery
(`http-call-engine`). No new outbound-authentication code path MUST be
introduced for this flow.

@e2e exclude backend outbound AGS calls reusing existing auth/call machinery — covered by PHPUnit

#### Scenario: a score publish reuses the existing JWT-bearer client-credentials grant

- **GIVEN** a registered `lti_platform` and a resolved line item
- **WHEN** the Tool-role score-publish flow runs
- **THEN** `AuthenticationService::fetchOAuthTokens()` SHALL be called with
  `client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer`
- **AND** the resulting access token SHALL be used as the AGS score-POST's
  bearer token

#### Scenario: a token-endpoint failure surfaces as a CallLog error, not a silent drop

- **GIVEN** the `lti_platform`'s `auth_token_url` returns an error
- **WHEN** the Tool-role score-publish flow runs
- **THEN** the failure SHALL be recorded in the outbound call machinery's
  observability surface (CallLog) and the score SHALL NOT be silently
  discarded

### Requirement: NRPS inbound roster read (Platform role) via the ADR-008 register/schema dispatch, and outbound roster pull (Tool role) (REQ-LTI-009)

When acting as Platform, the system MUST serve an inbound, access-token-authorized
NRPS membership request (scope
`https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly`)
by dispatching `lti_deployment.rosterSource` as an ADR-008
`targetType: 'register/schema'` / `targetId: '{registerId}/{schemaId}'` read
through the same OpenRegister mapper path `EndpointService::handleSchemaRequest()`
uses for `Endpoint` GETs, transformed via the deployment's configured mapping
into the IMS Names/Roles JSON shape, and returned synchronously — not
published as an event (unlike REQ-LTI-007's score write, a roster read has no
useful async/retry semantics; the caller is blocked on the HTTP response).
When acting as Tool, pulling a roster from a Platform's NRPS endpoint MUST
reuse the same outbound OAuth mechanism as REQ-LTI-008 (JWT-bearer
client-credentials against the platform's token endpoint, scoped to
`.../contextmembership.readonly`) and the existing outbound HTTP call
machinery.

@e2e exclude backend NRPS dispatch (both directions) — covered by PHPUnit/Newman

#### Scenario: a roster request is served from the deployment's configured register/schema

- **GIVEN** an `lti_deployment` with `rosterSource = {targetType: 'register/schema', targetId: '20/111'}`
- **WHEN** an authorized NRPS membership request arrives for that deployment
- **THEN** the response SHALL be built by reading register 20 / schema 111
  through the existing OR mapper read path
- **AND** the response SHALL be synchronous (no CloudEvent published)

#### Scenario: an unauthorized NRPS request (missing/wrong scope) is rejected

- **GIVEN** an access token that does not carry the
  `contextmembership.readonly` scope
- **WHEN** it is presented against a roster endpoint
- **THEN** the response SHALL be HTTP 403

### Requirement: Consuming-app placement contract (REQ-LTI-010)

An `lti_deployment` record MUST be the complete, self-describing contract a
consuming app uses to register a placement: `deploymentId` (the LTI claim
value to match at launch), `launchTargetUrl` (where a validated launch
redirects), `gradeSink` (a `register/schema` targetType/targetId pair the app
expects AGS-derived data to eventually land in, via its own
`event_subscription` to `nl.conduction.lti.ags.score.received` — the adapter
does not write there itself, per REQ-LTI-007), `rosterSource` (a
`register/schema` targetType/targetId pair the adapter reads from directly
for NRPS, per REQ-LTI-009), and a mapping reference used on both the AGS
event payload and the NRPS response shape. A consuming app therefore needs
exactly two integration points to fully consume this capability: (1) create
an `lti_deployment` row naming its `launchTargetUrl`/`gradeSink`/`rosterSource`,
and (2) create an `event_subscription` (existing `events-cloudevents`
mechanism, unmodified) filtering on `type = 'nl.conduction.lti.ags.score.received'`
and `source` matching its own deployment.

@e2e exclude backend contract shape — covered by PHPUnit, consumed by the scholiq lti-tool-placement leaf (separate repo/change)

#### Scenario: a consuming app fully wires a placement with two objects

- **GIVEN** a consuming app wants to place an external tool and receive grade
  passback
- **WHEN** it creates one `lti_deployment` (naming `launchTargetUrl`,
  `gradeSink`, `rosterSource`) and one `event_subscription` filtered to
  `nl.conduction.lti.ags.score.received`
- **THEN** launches redirect to its `launchTargetUrl`, roster reads are
  served from its `rosterSource`, and AGS scores arrive as CloudEvents at its
  subscription's sink — with no other openconnector-side configuration
  required

#### Scenario: an lti_deployment without a matching event_subscription still records the score, undelivered

- **GIVEN** an `lti_deployment` with no corresponding `event_subscription`
- **WHEN** an AGS score is received for it
- **THEN** the CloudEvent SHALL still be created (existing `events-cloudevents`
  behaviour: zero matching subscriptions produces zero messages, not an
  error) and the consuming app SHALL simply not have received it until it
  subscribes
- @e2e exclude backend event fan-out edge case — covered by PHPUnit

### Requirement: Registration trust gate — a Platform/Tool is unusable until an admin approves it (REQ-LTI-011)

Every `lti_platform` and `lti_tool` registration MUST carry a `status`
(`pending | approved | suspended`) field, defaulting to `pending` on
creation. `LtiRegistrationResolverService::findPlatformByIssuer()` and
`::findToolByClientId()` MUST only resolve a registration whose `status` is
`approved`; a `pending` or `suspended` registration MUST be treated
identically to an unregistered issuer/`client_id` by every caller that
depends on those lookups — login initiation (REQ-LTI-004), launch validation
(REQ-LTI-005), Platform-role launch initiation (REQ-LTI-006), and
service-token issuance (REQ-LTI-007) — returning the same HTTP status and
error shape as the "unregistered" case, so a caller cannot distinguish
"never registered" from "registered but not yet approved" (no
status-enumeration side channel). The distinction MUST still be visible
server-side, via the existing rejection log
(`LtiController::renderRejection()`) carrying the resolved `status` when a
registration was found but not approved. Transitioning a registration's
`status` (`approve`/`suspend`) MUST be an admin-gated action
(`#[AuthorizedAdminSetting]`, mirroring `LtiController::generateKey()`/`rotateKey()`),
never reachable from the public protocol routes.

@e2e exclude backend registration trust gate — covered by PHPUnit, no browser UI (admin approve/suspend actions are OR object edits + two thin controller actions, not a dedicated screen in this change)

#### Scenario: a pending platform's login-initiation request is rejected exactly like an unregistered one

- **GIVEN** an `lti_platform` registration exists with `status: pending`
- **WHEN** a login-initiation request carrying its `iss`/`client_id` arrives
- **THEN** the response SHALL be HTTP 400 with the same error shape as an
  unregistered issuer
- **AND** no redirect SHALL be issued and no `nonce` SHALL be persisted

#### Scenario: an approved platform's launch succeeds; the same platform suspended afterward is rejected

- **GIVEN** an `lti_platform` registration transitions from `pending` to
  `approved` via the admin-gated `approve` action
- **WHEN** a valid login + launch is completed
- **THEN** the launch SHALL succeed as REQ-LTI-005 already specifies
- **GIVEN** an admin then transitions the same registration to `suspended`
- **WHEN** a subsequent login-initiation request arrives for it
- **THEN** the response SHALL be HTTP 400, identical in shape to an
  unregistered issuer

### Requirement: Identity linking — a validated launch's `sub` resolves to a Nextcloud user only under an explicit, per-Platform policy (REQ-LTI-012)

The system MUST declare an `lti_identity_link` schema recording a
`(ltiPlatformId, subject)` → Nextcloud `userId` mapping (`subject` is the
verified `id_token`'s `sub` claim value; `ltiPlatformId` scopes it — the same
`sub` value from two different platforms MUST NOT collide). Each
`lti_platform` registration MUST carry an `identityPolicy`
(`manualLinkOnly | autoProvisionAsRole`), defaulting to `manualLinkOnly`.
Under `manualLinkOnly`, a launch whose `sub` has no existing
`lti_identity_link` row MUST be reported by `LtiIdentityLinkService` as
"unlinked" — the caller (consuming app) decides how to present that (e.g. an
admin/teacher linking flow); the system MUST NOT create a Nextcloud user or
guess a match by email/name. Under `autoProvisionAsRole`, the registration
MUST additionally carry a `defaultProvisionGroup` (a Nextcloud group id); on
a launch's first-seen `(ltiPlatformId, subject)`, `LtiIdentityLinkService`
MAY provision a new Nextcloud user and MUST add it to `defaultProvisionGroup`,
recording the resulting `lti_identity_link` row with `provisioningMethod: auto`.
Identity resolution MUST run strictly after `LtiLaunchService::validateLaunch()`
has already cryptographically accepted the launch (REQ-LTI-005) — it MUST
NOT be consulted as part of, or able to relax, the launch's own trust
decision (signature/nonce/`aud`/`deployment_id` checks are unaffected by
identity-linking policy). A manually-linked `lti_identity_link` row MUST
record the linking admin's `userId` and a timestamp; an auto-provisioned row
MUST record `provisioningMethod: auto` and the same timestamp field.

@e2e exclude backend identity-linking service and provisioning policy — covered by PHPUnit, no browser UI in this change (the human-facing "link this launch to my account" flow is a consuming-app concern, same non-goal precedent as the archived change's Deep Linking content-picker UI)

#### Scenario: an unlinked subject under manualLinkOnly is reported unlinked, never guessed

- **GIVEN** an `lti_platform` with `identityPolicy: manualLinkOnly` and a
  validated launch whose `sub` has no existing `lti_identity_link` row
- **WHEN** the consuming app asks `LtiIdentityLinkService` to resolve the
  identity
- **THEN** the result SHALL indicate "unlinked"
- **AND** no Nextcloud user SHALL be created or matched by email/name

#### Scenario: a first-seen subject under autoProvisionAsRole is provisioned into the configured group

- **GIVEN** an `lti_platform` with `identityPolicy: autoProvisionAsRole` and
  `defaultProvisionGroup: "scholiq-lti-learners"`, and a validated launch
  whose `sub` has no existing `lti_identity_link` row
- **WHEN** the consuming app asks `LtiIdentityLinkService` to resolve the
  identity
- **THEN** a new Nextcloud user SHALL be provisioned and added to
  `scholiq-lti-learners`
- **AND** an `lti_identity_link` row SHALL be created with
  `provisioningMethod: auto`

#### Scenario: the same subject from two different platforms never collides

- **GIVEN** `lti_platform` A and `lti_platform` B both present a launch with
  `sub: "user-42"`
- **WHEN** each is resolved by `LtiIdentityLinkService`
- **THEN** they SHALL resolve against two independent `lti_identity_link`
  rows (keyed by `(ltiPlatformId, subject)`), never the same row

#### Scenario: identity-link policy never relaxes launch validation

- **GIVEN** a launch whose `id_token` signature is invalid
- **WHEN** the launch is processed, regardless of the resolved
  `lti_platform`'s `identityPolicy`
- **THEN** `validateLaunch()` SHALL reject it per REQ-LTI-005 before any
  identity-linking logic runs

### Requirement: Resource-link-to-consuming-app-object mapping seam (REQ-LTI-013)

An `lti_deployment` MUST support an optional `resourceLinkMappings[]` array,
each entry an ADR-008 `{resourceLinkId, targetType: 'register/schema', targetId}`
tuple — mirroring `gradeSink`/`rosterSource`'s existing shape (REQ-LTI-010).
`resourceLinkId` MAY be empty, denoting a deployment-default mapping. The
system MUST expose `LtiLaunchService::resolveResourceMapping(deploymentUuid, resourceLinkId)`:
given a validated launch's deployment and its
`https://purl.imsglobal.org/spec/lti/claim/resource_link` claim's `id`, it
MUST return the first `resourceLinkMappings[]` entry whose `resourceLinkId`
exactly matches; if none matches, it MUST fall back to an entry with an
empty `resourceLinkId`; if still no match, it MUST return `null` (the
consuming app falls back to its own default handling of
`launchTargetUrl`/raw claims, exactly as it does today). This method MUST NOT
perform the register/schema read itself (unlike REQ-LTI-009's NRPS dispatch,
which is a service the adapter serves synchronously) — it only resolves
*which* target a consuming app should read/write, leaving the read/write
itself to the consuming app, consistent with the adapter's "emit or route,
don't own the write" posture (design.md D7 of the archived change).

@e2e exclude backend resource-link mapping resolution — covered by PHPUnit, no browser UI (consumed by the scholiq lti-tool-placement leaf, separate repo/change)

#### Scenario: a resource_link with a configured mapping resolves to its target

- **GIVEN** an `lti_deployment` with `resourceLinkMappings: [{resourceLinkId: "course-101", targetType: "register/schema", targetId: "20/145"}]`
- **WHEN** a validated launch whose `resource_link.id` claim is `"course-101"`
  is resolved
- **THEN** `resolveResourceMapping()` SHALL return `{targetType: "register/schema", targetId: "20/145"}`

#### Scenario: an unmapped resource_link falls back to the deployment default, then to null

- **GIVEN** an `lti_deployment` with `resourceLinkMappings: [{resourceLinkId: "", targetType: "register/schema", targetId: "20/999"}]`
  and a launch whose `resource_link.id` is `"course-777"` (no exact match)
- **WHEN** `resolveResourceMapping()` runs
- **THEN** it SHALL return the empty-`resourceLinkId` default entry
  (`{targetType: "register/schema", targetId: "20/999"}`)
- **GIVEN** a deployment with no `resourceLinkMappings[]` configured at all
- **WHEN** `resolveResourceMapping()` runs for any `resourceLinkId`
- **THEN** it SHALL return `null`

