---
kind: code
depends_on: []
---

# openconnector — LTI 1.3 / LTI Advantage adapter (Platform + Tool roles)

## Why

**LTI 1.3** (1EdTech, formerly IMS Global) is the interoperability standard that
lets a learning platform embed external tools (and vice versa) with a signed,
role-aware launch, plus three "Advantage" services — Deep Linking 2.0,
Assignment & Grade Services (AGS), Names & Role Provisioning Services (NRPS).
OpenConnector has no LTI capability today and no reserved catalogue slot for
one:

- `grep -rliP '\bLTI\b' openspec/specs/*/spec.md` finds zero hits (a
  non-word-boundary `grep -rli "lti"` over the same tree returns 21 false
  positives — `multiple`, `resulting`, `multi-*`, etc. — none of them the
  standard) — unlike Digikoppeling, HaalCentraal, or DigiD/eHerkenning, LTI is
  not even named as a reserved adapter in **ADR-017**'s catalogue inventory
  (`openspec/architecture/adr-017-information-architecture.md:24-31`).
- `lib/Settings/openconnector_register.json` declares 16 schemas today
  (`source`, `consumer`, `endpoint`, `event`, `event_message`,
  `event_subscription`, `job`, `mapping`, `rule`, `synchronization`,
  `synchronization_contract`, `call_log`, `job_log`, `synchronization_log`,
  `synchronization_contract_log`, `ris_sync_record` — verified by parsing the
  file's `components.schemas` keys) — none of them model an LTI platform/tool
  registration, a deployment, or a JWKS keyset. The canonical
  `openconnector-register-schema` spec (REQ-A-002) is itself stale here —
  it still says "15 schemas" and omits `ris_sync_record`; this change's
  register-schema delta corrects that count on its way to 19 (see Impact).
- `grep -rliP '\b(lti|1EdTech|imsglobal)\b' lib/` returns nothing under `lib/`.

**Existing seams this change reuses instead of duplicating:**

- `AuthenticationService::fetchOAuthTokens()` (`lib/Service/AuthenticationService.php`,
  spec `authentication-twig` REQ-001) **already implements** the exact grant
  LTI Advantage services need on the Tool side: `client_credentials` with
  `client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer`,
  minting the assertion via `fetchJWTToken(algorithm=PS256, ...)`. This is RFC
  7523 JWT-bearer client-credentials — the same mechanism LTI Advantage service
  calls (AGS score publish, NRPS roster pull) require to obtain an access
  token from a platform's token endpoint. No new outbound-auth code is needed
  for the Tool-role service calls; they are a new `grant_type` consumer of code
  that already exists.
- `AuthorizationService::validatePayload()` (`lib/Service/AuthorizationService.php:268-358`)
  already validates `iat`/`exp`/`nbf` with clock-skew clamping, clamps
  caller-supplied `exp` to a maximum lifetime, and — critically — implements
  single-use replay prevention via `ICacheFactory::createDistributed('openconnector.jti')`
  (`lib/Service/AuthorizationService.php:116-119`, `:343-357`). LTI's launch
  `nonce` claim needs the identical single-use-via-distributed-cache pattern;
  this change reuses the mechanism (new cache namespace `openconnector.lti.nonce`)
  rather than inventing a second replay store.
- `AuthorizationService::getJWK()` (`:190-240`) already pins the JWK's `alg` to
  the token header's algorithm (defends against algorithm-confusion attacks)
  and — as of the current HEAD — writes key material to a `tempnam()`-allocated,
  `chmod 0600` file inside a `try/finally` (fixed; see `#1012(a)` comment at
  `lib/Service/AuthenticationService.php:369-377`). The one gap it has for LTI:
  it only accepts a single static `$publicKey` string, never a JWKS URL + `kid`
  lookup. LTI platforms and tools rotate keys and publish a `/jwks.json` set
  keyed by `kid` — this change adds JWKS-URI resolution with `kid` lookup and
  caching (REQ-LTI-003), it does not touch or duplicate the existing
  static-key path.
- `EventService::processEvent()` (spec `events-cloudevents` REQ-001,
  `lib/Service/EventService.php`) already fans an OR object out to every
  matching `event_subscription` (push or pull), with retry/backoff/dead-letter
  (`webhook-signing` covers signing). AGS score passback — "a consuming app
  ... receives AGS passback events" (the scholiq `lti-tool-placement` leaf's
  stated need) — is modelled as a new CloudEvent `type` on this existing
  mechanism, not a bespoke webhook.
- The `Endpoint.targetType`/`targetId` polymorphic dispatch (**ADR-008**,
  `lib/Service/EndpointService.php:364` — `$endpointData['targetType'] === 'register/schema'`
  — read through `handleSchemaRequest()` at `:1052`) is the precedent this
  change follows for NRPS roster reads: a consuming app's roster data is read
  via the same `register/schema` targetType dispatch Endpoint already uses
  for GET, not a new query language.
- `DSOController::receiveVerzoek` (`dso-omgevingsloket` REQ-DSO-001,
  `lib/Controller/DSOController.php`) is the precedent for **why LTI's OIDC
  login/launch/AGS/NRPS endpoints are dedicated controller routes, not generic
  `Endpoint` configs**: DSO's STAM koppelvlak needed protocol-specific inbound
  signature verification that the generic `Endpoint` pipeline cannot express,
  and was shipped as its own `#[PublicPage]` route in `appinfo/routes.php`.
  LTI's launch/token/AGS/NRPS endpoints need the same (JWT validation, nonce
  replay, OAuth2 token issuance) and follow the same shape.
- **ADR-017 Rule 7** ("deliberate cross-menu splits are allowed") already
  sanctions the exact split LTI needs: `digid-eherkenning-auth-adapter` ships
  as an *Adapters* catalogue entry (discovery) **and** *Beheer > Authenticatie*
  config (tenant-wide broker/key material) — LTI's registrations (discovery:
  "this connector supports LTI 1.3") and its signing-key rotation (tenant-wide,
  per **Rule 3**: "JWT/JWE keys ... key rotation policy lives in
  *Beheer > Authenticatie*") are the same split, not a new IA exception.

**Demand evidence** (Spectr, cited by the gap report this change answers):
insight 1005 (Moodle-coexistence on-ramp), insight 1033 ("LTI belongs in
openconnector"); the standard is tracked in `nl_standards` id 215; 15 of 53
surveyed competitors ship LTI (Moodle, Blackboard, Brightspace — 1EdTech
certified, Sakai, ILIAS, OpenOLAT, Chamilo, GoodHabitz, Docebo, ProctorU,
Coursera, Questionmark, MS Teams, It's Learning, ATutor) — the single broadest
competitor-coverage gap identified across the scholiq gap sweep. LTI is also
the DE state-platform migration on-ramp (LOGINEO NRW, BayernCloud Schule,
HPI Schul-Cloud/dBildungscloud all run Moodle-compatible LTI 1.3 tool
consumers), and 1EdTech LTI Advantage certification is a named public-tender
requirement in HE/MBO procurements.

## What Changes

Introduce an `lti-platform` capability delivered per **ADR-017 Rule 1 + Rule 7**
as an *Adapters* catalogue entry (discovery: LTI 1.3 / LTI Advantage,
Platform + Tool capable) with tenant-wide signing-key and registration
management surfaced in *Beheer > Authenticatie*, plus dedicated public routes
for the protocol endpoints (mirroring the `dso-omgevingsloket` precedent):

- **Data model** — three new OpenRegister schemas in
  `lib/Settings/openconnector_register.json` (16 → 19 schemas):
  `lti_platform` (an external platform that may launch INTO this instance —
  we act as **Tool**: issuer, our assigned `client_id`, the platform's
  `auth_login_url`/`auth_token_url`/`jwks_uri`, our own rotating signing
  keypair used for outbound service-token JWT assertions), `lti_tool` (an
  external tool this instance may launch — we act as **Platform**: the
  `client_id` we assign, the tool's OIDC-login/launch/`jwks_uri`, our own
  rotating signing keypair used to sign id_token launches and to verify the
  tool's inbound service-token assertions), and `lti_deployment` (links one
  `lti_platform` or `lti_tool` registration to a specific consuming-app
  placement: the LTI `deployment_id` claim value, `launchTargetUrl`, an
  AGS `gradeSink` and NRPS `rosterSource` each expressed as a
  `register/schema` targetType/targetId pair — the ADR-008 pattern — plus a
  mapping reference).
- **OIDC third-party-initiated login + launch (Tool role)** — new public
  routes `POST/GET /api/lti/{deployment}/login` and
  `POST /api/lti/{deployment}/launch`, mirroring the `dso-omgevingsloket`
  dedicated-controller precedent, not the generic `Endpoint` pipeline.
- **Platform-role launch initiation** — a new internal service method
  consuming apps call to start a launch of a registered `lti_tool`
  (auto-submitting signed-JWT form POST), plus Deep Linking 2.0
  request/response in both roles.
- **LTI Advantage service-token issuance (Platform role)** — a new
  `POST /api/lti/token` implementing the JWT-bearer client-credentials grant
  (RFC 7523) inbound, verifying the caller's assertion against the
  registered `lti_tool.jwks_uri`, scoped to AGS/NRPS.
- **AGS** — Platform-role inbound line-item/score endpoints that fan the
  received score out as a `nl.conduction.lti.ags.score.received` CloudEvent
  (reusing `events-cloudevents` REQ-001/REQ-002 unmodified) for a consuming
  app's `event_subscription` to pick up; Tool-role outbound score-publish and
  result-read reusing `AuthenticationService::fetchOAuthTokens` unmodified.
- **NRPS** — Platform-role inbound roster read dispatched via the
  `lti_deployment.rosterSource` `register/schema` targetType (ADR-008
  pattern, synchronous — unlike AGS's async CloudEvent fan-out, because NRPS
  is a read); Tool-role outbound roster pull via the same OAuth mechanism as
  AGS.
- **JWKS** — our own signing-key lifecycle (generate, active + previous with
  a rotation grace window mirroring `webhook-signing` REQ-WHS-002) and a
  public `/.well-known/lti/{registrationType}/{uuid}/jwks.json` endpoint per
  registration; external JWKS resolution with `kid` lookup, per-registration
  cache namespacing, and rate-limited refetch-on-unknown-`kid` (new — the
  existing `AuthorizationService::getJWK()` only accepts a static key).

## Cross-repo / cross-change relationships (prose)

- **scholiq `lti-tool-placement`** (leaf, other repo) — the consuming-app
  side: registers an `lti_deployment` placing an external tool inside a
  lesson/course, and subscribes an `event_subscription` to
  `nl.conduction.lti.ags.score.received` to create a `GradeEntry`. This
  change defines the contract that leaf consumes (`lti_deployment` shape,
  CloudEvent type/schema, `rosterSource` mapping); it does not implement the
  scholiq side.
- **`source-broker-credentials`** (this repo, in-flight) — evaluated and
  **not** used for our own LTI signing-key custody in this change: the
  current broker is a constrained request-only proxy that cannot hand back
  raw key material for in-process signing — and LTI id_token/assertion
  signing is in-process, hot-path, non-negotiable. Keys are held the same way
  `AuthenticationService::fetchJWTToken` already holds every other `Source`
  signing secret (plaintext-pending-encryption per **ADR-007**'s
  already-accepted, fleet-wide status quo, not a regression), with a
  migration to the broker noted as a follow-on once it grows a
  signing-material capability. Note this is a *different* call than
  `digikoppeling-adapter`'s design decision D5
  (`openspec/changes/archive/2026-07-07-digikoppeling-adapter/design.md:51-58`),
  which fails closed rather than ever writing its externally-issued
  PKIoverheid certificate to disk — that stricter rule fits digikoppeling's
  threat model (custodian of a third-party-issued cert), not LTI's
  (OpenConnector generates its own signing keypair); see design.md D3 for the
  full reasoning.
- **`events-cloudevents`** (this repo, done) — reused unmodified for AGS
  passback fan-out; no spec change needed since REQ-001 already matches on
  any `event.type`.
- **`webhook-signing`** (this repo, done) — the rotation-with-grace-window
  pattern (REQ-WHS-002) is the model for LTI's own-key rotation; not
  literally reused (webhook-signing rotates an HMAC secret, LTI rotates an
  asymmetric keypair) but the same lifecycle shape.

## Impact

- Affected specs: NEW `lti-platform` capability (10 ADDED requirements);
  MODIFIED `openconnector-register-schema` (schema count 16 → 19, three new
  schema declarations).
- Affected code (implementation phase, not this change): `lib/Settings/openconnector_register.json`
  (3 new schemas); new `lib/Controller/LtiController.php` (login, launch,
  token, AGS, NRPS, JWKS-publish routes) mirroring `DSOController`'s
  dedicated-controller shape; new `lib/Service/Lti/` namespace
  (`LtiLaunchService`, `LtiJwksResolverService`, `LtiKeyService`,
  `LtiAgsService`, `LtiNrpsService`) per **ADR-008** (Controller → Service →
  Mapper); `appinfo/routes.php` new `#[PublicPage]` entries; new CloudEvent
  type constant(s) consumed by `events-cloudevents`'s existing fan-out; admin
  UI additions to the *Adapters* catalogue card and *Beheer > Authenticatie*
  registration/rotation screens.
- Not affected: `Source`/`Endpoint` entities and their existing `targetType`
  values (LTI adds no new `Endpoint.targetType`; NRPS reuses the existing
  `register/schema` value verbatim), `AuthenticationService::fetchOAuthTokens`/`fetchJWTToken`
  (consumed, not modified), `AuthorizationService::validatePayload`/`getJWK`
  (the static-key path is untouched; JWKS-URI resolution is new, additive
  code), `events-cloudevents` (consumed, not modified).
