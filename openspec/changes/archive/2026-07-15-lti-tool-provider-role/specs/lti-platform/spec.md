# lti-platform Specification Delta

## ADDED Requirements

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
