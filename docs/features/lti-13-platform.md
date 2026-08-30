# LTI 1.3 / LTI Advantage Adapter

## Overview

The LTI adapter lets any Conduction app either consume an external LTI 1.3 tool
(this instance acts as **Tool**, launched by an external Platform such as a
Moodle-based state platform) or expose its own content as an LTI tool to an
external Platform (this instance acts as **Platform**, launching an external
Tool). It covers OIDC third-party-initiated login, signed-JWT launch
validation, Deep Linking 2.0, Assignment & Grade Services (AGS), and Names &
Role Provisioning Services (NRPS).

The adapter ships as an *Adapters* catalogue capability (see
`openspec/changes/lti-13-platform/`) — as of this writing the tenant-wide
key-management UI (Beheer > Authenticatie) and the Adapters catalogue card
have **not** been built; only the backend contract described below exists.
Registrations are created via OpenRegister's generic object API.

## Data model

Four OpenRegister schemas in the `openconnector` register
(`lib/Settings/integriq_register.json`):

- **`lti_platform`** — an external Platform that may launch INTO this
  instance (this instance acts as Tool): `issuer`, `clientId`,
  `authLoginUrl`, `authTokenUrl`, `jwksUri`, `signingKeys[]`, `status`
  (`pending | approved | suspended`, see "Registration trust gate" below),
  `identityPolicy` (`manualLinkOnly | autoProvisionAsRole`) and
  `defaultProvisionGroup` (see "Identity linking" below).
- **`lti_tool`** — an external Tool this instance may launch (this instance
  acts as Platform): `clientId`, `oidcLoginUrl`, `launchUrl`, `jwksUri`,
  `signingKeys[]`, `status`.
- **`lti_deployment`** — links exactly one `lti_platform` OR one `lti_tool`
  (enforced by an OR schema-level `oneOf`) to a consuming-app placement:
  `deploymentId` (the LTI claim value), `launchTargetUrl`, `gradeSink`
  (`register/schema` target pair), `rosterSource` (`register/schema` target
  pair), `mappingId`, `resourceLinkMappings[]` (see "Resource-link mapping"
  below).
- **`lti_identity_link`** — a `(ltiPlatformId, subject)` → Nextcloud `userId`
  mapping (see "Identity linking" below).

## Endpoints

All routes are dedicated protocol endpoints (`lib/Controller/LtiController.php`),
not the generic `Endpoint` pipeline — authentication is the protocol itself
(signed `id_token`, RFC 7523 client assertion, or a previously-issued access
token), never an NC session.

| Route | Method | Purpose |
|---|---|---|
| `/api/lti/{deployment}/login` | GET/POST | OIDC third-party-initiated login (Tool role) |
| `/api/lti/{deployment}/launch` | POST | Launch `id_token` validation + dispatch (Tool role) |
| `/api/lti/token` | POST | RFC 7523 JWT-bearer client-credentials grant (Platform role) |
| `/api/lti/{deployment}/ags/lineitems/{lineItemId}/scores` | POST | Inbound AGS score |
| `/api/lti/{deployment}/ags/lineitems/{lineItemId}` | GET | Inbound AGS line-item scope check |
| `/api/lti/{deployment}/nrps/membership` | GET | Inbound NRPS roster read |
| `/.well-known/lti/{registrationType}/{registrationUuid}/jwks.json` | GET | Public JWKS document |
| `/api/lti/{registrationType}/{registrationUuid}/keys/generate` | POST | Admin: generate first signing key |
| `/api/lti/{registrationType}/{registrationUuid}/keys/rotate` | POST | Admin: rotate signing key |
| `/api/lti/{registrationType}/{registrationUuid}/approve` | POST | Admin: approve a `pending`/`suspended` registration |
| `/api/lti/{registrationType}/{registrationUuid}/suspend` | POST | Admin: suspend a registration |

## Consuming-app placement contract

A consuming app fully wires a placement with exactly two objects:

1. One `lti_deployment` naming `launchTargetUrl`, `gradeSink`, `rosterSource`.
2. One `event_subscription` filtered to
   `type = 'nl.conduction.lti.ags.score.received'`.

A launch redirects to `launchTargetUrl` with a short-lived, single-use launch
reference. Roster reads are served synchronously from `rosterSource`. AGS
scores arrive as CloudEvents at the subscription's sink — the adapter never
writes to `gradeSink` directly; the consuming app's own subscription (with
its own mapping/authorization) performs the write. A deployment with no
matching subscription still succeeds (CloudEvent created, simply
undelivered) — this is existing `events-cloudevents` zero-subscriber
behaviour, unmodified.

## Signing-key lifecycle

Each `lti_platform`/`lti_tool` registration carries its own `signingKeys[]`
(never one instance-wide key — a compromised registration's blast radius is
bounded to that registration). Generation produces a fresh RS256/PS256
keypair, stored as a base64-encoded PEM private key (custody:
plaintext-pending-encryption per ADR-007, the same status quo as every other
`Source` credential field) — this exact shape is what
`AuthenticationService::fetchJWTToken()` already expects, so the Tool-role
outbound AGS/NRPS calls reuse it unmodified.

Rotation moves the current `active` key to `previous` (still published, still
valid for verifying already-signed tokens) for a **7-day grace window**
before `LtiKeyRetirementJob` (hourly sweep) flips it to `retired` and it
drops from the published JWKS. The 7-day window (longer than
`webhook-signing`'s 24h) accounts for a stale platform/tool-side JWKS cache
on the far side being normal and outside this instance's control.

## Registration trust gate

A newly-created `lti_platform`/`lti_tool` registration defaults to
`status: pending` and **cannot complete a login, launch, or token-issuance
flow** until an admin explicitly transitions it to `approved` (via
`LtiController::approve()` — `POST
/api/lti/{registrationType}/{registrationUuid}/approve`, admin-gated). A
`pending` or `suspended` registration is rejected with the exact same HTTP
400 and error body an unregistered issuer/`client_id` gets — from the
outside, "never registered" and "registered but awaiting approval" look
identical; the distinction is only visible in the server-side debug log
(`LtiRegistrationResolverService`'s rejection log carries the actual
`status`). Practically: creating an `lti_platform`/`lti_tool` row via OR's
generic object API (e.g. while onboarding a new school's Moodle instance) is
not by itself enough to let that platform launch into this instance — an
admin must separately call `approve()`. `suspend()` is the reverse,
reversible action (a previously-approved registration can always be
re-approved).

## Identity linking

A validated launch's `sub` claim (the external Platform's opaque learner/staff
identifier) does not automatically become a Nextcloud user. Each
`lti_platform` carries an `identityPolicy`:

- **`manualLinkOnly` (default)** — conservative. A launch from a `sub` with
  no existing `lti_identity_link` row is reported "unlinked" by
  `LtiIdentityLinkService::resolveIdentity()`; no Nextcloud account is
  created and no email/name-based guessing is ever performed (an unverified
  profile claim is not a safe basis for silently attaching a launch to an
  existing account). An admin links the launch to an account manually — today
  that means writing an `lti_identity_link` row directly via OR's generic
  object API (`(ltiPlatformId, subject, userId, provisioningMethod: manual,
  linkedByUserId)`); no dedicated UI exists yet (see "Not yet built").
- **`autoProvisionAsRole` (explicit opt-in)** — requires also setting
  `defaultProvisionGroup` on the same `lti_platform`. A first-seen `sub`
  under this policy provisions a new Nextcloud user and adds it to that
  group; the resulting `lti_identity_link` row records
  `provisioningMethod: auto`. Turning this on is a deliberate, per-platform
  decision an admin makes — it is never the default, and a misconfigured
  `autoProvisionAsRole` with no group named fails closed to "unlinked"
  rather than provisioning into an unbounded scope.

Identity resolution always runs **after** `LtiLaunchService::validateLaunch()`
has already cryptographically accepted a launch — a forged, expired, or
replayed launch never reaches `LtiIdentityLinkService`, and identity policy
can never make an otherwise-invalid launch succeed. `lti_identity_link` keys
on `(ltiPlatformId, subject)`, never on `subject` alone, so the same `sub`
value presented by two different platforms resolves to two independent
accounts.

`LtiIdentityLinkService` has no HTTP route — it is consumed cross-app via PHP
service injection, the same pattern `LtiLaunchService::consumeLaunchReference()`
already establishes for handing a validated launch to the consuming app.

## Resource-link mapping

An `lti_deployment` covers one whole LMS deployment, but a real Moodle site
typically places many `resource_link`s (courses, individual assignments)
under a single deployment. `lti_deployment.resourceLinkMappings[]` lets a
consuming app declare, per-deployment, which of its own objects a given
`resource_link.id` claim maps to — an array of `{resourceLinkId, targetType:
'register/schema', targetId}` entries, mirroring `gradeSink`/`rosterSource`'s
existing shape. `LtiLaunchService::resolveResourceMapping(deploymentUuid,
resourceLinkId)` resolves the target: an exact `resourceLinkId` match wins;
failing that, an entry with an empty `resourceLinkId` acts as the
deployment's default; failing that, it returns `null` and the consuming app
falls back to its own default handling (e.g. `launchTargetUrl` alone), same
as before this method existed. Like `gradeSink`/`rosterSource`, the adapter
only resolves the target — it never performs the register/schema read or
write itself; that stays the consuming app's own concern.

## Security notes

- Launch/assertion validation is a hard reject (HTTP 400/401/403) with no
  partial-trust fallback — see `LtiLaunchService::validateLaunch()` and
  `verifyIdTokenSignature()`.
- `iat`/`exp`/`nbf`/`jti`-replay checks reuse `AuthorizationService::validatePayload()`
  unmodified.
- The `nonce` claim is single-use, consumed atomically (get-then-delete) from
  a dedicated distributed-cache namespace (`integriq.lti.nonce`),
  separate from the existing `integriq.jti` namespace.
- The resolved JWK's own `alg` is pinned and compared against the token
  header's `alg` before verification — defends against algorithm-confusion
  attacks (mirrors `AuthorizationService::authorizeJwt()`'s existing guard).
- External JWKS resolution is cached per registration (not per `jwks_uri`
  string, so two registrations sharing a URI cannot cross-poison each
  other's cache) with a refetch guard capped at once per 60 seconds per
  registration — defends against SSRF-amplification via a flood of unknown
  `kid`s.
- AGS/NRPS access tokens are scoped to exactly one `lti_deployment`; a token
  issued for one deployment is rejected (403) against another, even under
  the same `lti_tool` registration.

## Not yet built

- Tenant-wide key-management UI (Beheer > Authenticatie) and the Adapters
  catalogue card (`openspec/changes/lti-13-platform/tasks.md` 4.2–4.4).
- A Newman/API collection happy-path exercise against a live instance
  (tasks.md 5.9) — covered by PHPUnit in isolation instead.
- A Beheer > Authenticatie UI for the approve/suspend actions or
  `lti_identity_link` management (`openspec/changes/lti-tool-provider-role`) —
  the backend contract is complete and callable via the admin-gated routes /
  PHP service injection / OR's generic object API in the meantime.
- The scholiq-side "link this launch to my account" UI or
  admissions/roster-provisioning workflow that would consume
  `LtiIdentityLinkService`/`resolveResourceMapping()` — separate repo, the
  `lti-tool-placement` leaf.

## Reference consumer

The scholiq `lti-tool-placement` leaf (separate repository/change) is the
intended reference consumer of `LtiIdentityLinkService::resolveIdentity()`
and `LtiLaunchService::resolveResourceMapping()` — both are plain PHP service
calls a consuming app makes after its own
`LtiLaunchService::consumeLaunchReference()` call, not a new HTTP surface.
