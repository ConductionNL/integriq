---
kind: code
depends_on: []
---

# openconnector — LTI Tool-role trust, identity-linking, and resource-mapping seam

## Why

### Premise check: the merged `lti-platform` adapter already implements BOTH roles at the protocol level

The wave-2 brief for this change starts from the premise that the merged LTI
1.3 adapter (`openspec/changes/archive/2026-07-13-lti-13-platform/`,
`openspec/specs/lti-platform/spec.md`) "implements the Platform role
(openconnector-as-consumer)... It does NOT implement the Tool (provider)
role — an external LMS cannot launch INTO a Conduction app." **Re-verified at
HEAD, this premise is false for the protocol layer.** The merged spec and
code already implement inbound Tool-role launches:

- `openspec/specs/lti-platform/spec.md:119-202` — **REQ-LTI-004** ("OIDC
  third-party-initiated login (Tool role)") and **REQ-LTI-005** ("Launch
  id_token validation and dispatch to the consuming app (Tool role)") are the
  literal inbound handshake: an external Platform (e.g. Moodle) hits
  `GET/POST /api/lti/{deployment}/login`
  (`lib/Controller/LtiController.php:158-180`,
  `lib/Service/Lti/LtiLaunchService.php:158-224`), we redirect it through its
  own OIDC auth endpoint, it POSTs a signed `id_token` to
  `POST /api/lti/{deployment}/launch`
  (`lib/Controller/LtiController.php:193-221`,
  `LtiLaunchService::validateLaunch()` at `lib/Service/Lti/LtiLaunchService.php:245-326`),
  we verify signature/`aud`/`azp`/nonce-replay/`deployment_id`/`message_type`,
  and on success redirect the browser into the *consuming app's*
  `lti_deployment.launchTargetUrl`. That is an external LMS launching into a
  Conduction app; the schema itself says so —
  `lib/Settings/openconnector_register.json`'s `lti_platform.description`:
  "launches a signed id_token INTO this instance via OIDC third-party-initiated
  login. This instance acts as Tool for every launch under this registration."
- Deep Linking is implemented **in both directions**, not just outbound:
  `LtiLaunchService::buildDeepLinkingResponse()`
  (`lib/Service/Lti/LtiLaunchService.php:442-480`) signs and returns an
  `LtiDeepLinkingResponse` with the `lti_platform` registration's own active
  key and POSTs it to the platform's `deep_link_return_url` — i.e. "a
  Platform's teacher picks a scholiq Course/Assessment to embed" is already
  built (REQ-LTI-006, `openspec/specs/lti-platform/spec.md:203-238`).
- AGS and NRPS are implemented **in both directions**: `LtiAgsService::publishScore()`
  and `::readResult()` (`lib/Service/Lti/LtiAgsService.php:393,433`) push a
  grade to an external Platform's gradebook reusing
  `AuthenticationService::fetchOAuthTokens()`, exactly the "mirror of the
  consumer-side AGS" the brief asked for (REQ-LTI-008,
  `openspec/specs/lti-platform/spec.md:285-315`); `LtiNrpsService::pullRoster()`
  (`lib/Service/Lti/LtiNrpsService.php:184-198`) pulls a Platform's roster
  outbound (REQ-LTI-009 Tool-role half). All of this is exercised by 39 net
  new PHPUnit tests per the archived change's tasks.md §5, and confirmed
  present (not stub text) by reading the method bodies, not just the spec
  prose — `grep -n "public function" lib/Service/Lti/*.php` returns
  `publishScore`, `readResult`, `pullResourceForDeployment`, `pullRoster`
  alongside the inbound methods.

So "half of LTI's value is unbuilt" is not accurate as a description of the
protocol surface. **What is real, and what this change actually builds**, is
three gaps the archived change explicitly left open or never addressed at
all — verified by reading every file the protocol surface touches:

### Gap 1 — no trust gate: any registration is live the instant it's created

`lib/Settings/openconnector_register.json`'s `lti_platform`/`lti_tool`
schemas carry no `status`/`approved`/`active` field
(`python3 -c "…schemas['lti_platform']['properties'].keys()…"` →
`uuid, name, description, issuer, clientId, authLoginUrl, authTokenUrl,
jwksUri, signingKeys, created, updated` — no trust field). Per the archived
change's own tasks.md §4.4, registrations "are fully creatable today via OR's
generic `/api/objects/openconnector/{lti_platform,lti_tool,lti_deployment}`
API" — no dedicated wizard, no approval step. `LtiRegistrationResolverService::findPlatformByIssuer()`
(`lib/Service/Lti/LtiRegistrationResolverService.php:61-93`) and `::findToolByClientId()`
(`:95-126`) match on `issuer`/`clientId` alone; `grep -n -i "status\|approv\|active\|enabled"` over
that file returns nothing. The task's own ask — "how a Platform is authorised
in the first place" — has no answer today: whoever can write an
`lti_platform` object into the openconnector register can immediately
complete inbound launches under it, with no admin sign-off gate. This is the
same "table-stakes trust boundary" class of gap other adapters in this
codebase gate deliberately (e.g. Beheer > Authenticatie's own key-rotation
gate on this same adapter, REQ-LTI-002).

### Gap 2 — no identity-linking primitive: `sub` is passed through, never resolved

`grep -rn -i "getUID\|IUserSession\|IUserManager\|provision\|identity" lib/Service/Lti/ lib/Controller/LtiController.php`
returns **zero hits** on any of Nextcloud's user-identity APIs. The verified
`id_token`'s `sub` claim is cached verbatim inside the launch reference
(`LtiLaunchService::validateLaunch()`, `lib/Service/Lti/LtiLaunchService.php:302-308`:
`$this->launchCache->set('launch:'.$launchReference, json_encode(['claims' => $payload, ...`)
and handed to the consuming app raw — there is no shared primitive anywhere
in openconnector for mapping that `sub` (scoped to a specific `lti_platform`)
to a Nextcloud user, and no admin-controlled policy for whether a first-seen
`sub` may auto-provision an account or must be linked manually. Every
consuming app that wants to resolve a launch today has to invent its own
`sub`→user mapping and its own provisioning judgment call — exactly the
"identity linking is the security crux" risk the brief flags, and it is
unaddressed, not just under-built.

### Gap 3 — no resource-link-level mapping: the contract stops at the deployment

`lti_deployment`'s placement contract (REQ-LTI-010,
`openspec/specs/lti-platform/spec.md:351-391`) is deployment-granular only:
one `launchTargetUrl`, one `gradeSink`, one `rosterSource` per deployment.
But LTI deliberately allows many `resource_link` placements (many courses,
many assignments) to share a single `deployment_id` — a Moodle site typically
registers one deployment and places dozens of scholiq resource links under
it. Nothing in `lti_deployment`'s schema
(`lib/Settings/openconnector_register.json`, properties:
`uuid, name, description, deploymentId, ltiPlatformId, ltiToolId,
launchTargetUrl, gradeSink, rosterSource, mappingId, created, updated`) lets
a consuming app register "resource_link `abc123` maps to my Course object
`X`" the way `gradeSink`/`rosterSource` already let it declare "AGS/NRPS
data for this deployment routes to register/schema `Y`". The brief's ask —
"an app registers 'this LTI resource-link maps to my object X' ... mirroring
how the Platform-role side exposes its contract" — names exactly this
asymmetry: REQ-LTI-010 gives the *outbound* (openconnector-as-Platform)
contract a precise, declarative shape; the *inbound* per-resource-link
routing has no equivalent.

### Why it's still worth building despite the corrected premise

The strategic case supplied for this change stands independent of the
premise correction: the German state education platforms — dBildungscloud
(~4,000 schools / 1.4M users), LOGINEO NRW, BayernCloud Schule — and most
national LAS deployments are Moodle-centric collaboration clouds with no
student-administration layer, so scholiq is adoptable there only if a school
can register scholiq as a Tool inside their existing platform and trust the
launches that arrive. The *plumbing* for that (REQ-LTI-004/005/006/008/009)
already exists; what's missing is the governance layer around it — who is
allowed to register as a trusted Platform, whose learners get linked to
which Nextcloud account, and which of scholiq's resources a given launch
actually opens. Competitor evidence supplied for this wedge (Moodle shipping
both Provider and Consumer roles, GoodHabitz launching remote-LTI into 50+
LMSs, MS Teams Education/Sakai/ILIAS all shipping tool-role) is evidence for
LTI-as-distribution generally; it does not by itself confirm those
competitors solved trust/identity/resource-routing the way this change
proposes — that judgment is this change's own design, not an imported claim.

## What Changes

- **Registration trust gate (`lti_platform`/`lti_tool`)** — a `status`
  (`pending | approved | suspended`) field, defaulting to `pending`.
  `LtiRegistrationResolverService`'s lookups only match `approved`
  registrations for login/launch/token-issuance purposes; a `pending` or
  `suspended` registration is rejected identically to an unregistered one at
  the HTTP boundary (no status-enumeration side channel), distinguished only
  in server-side logs. Admin-gated `approve()`/`suspend()` actions on
  `LtiController`, mirroring the existing `generateKey()`/`rotateKey()`
  `#[AuthorizedAdminSetting]` shape.
- **Identity linking (`lti_identity_link`, new schema)** — a
  `(ltiPlatformId, subject)` → Nextcloud `userId` mapping, plus a per-`lti_platform`
  `identityPolicy` (`manualLinkOnly` default, or `autoProvisionAsRole` with a
  required `defaultProvisionGroup`). A new `LtiIdentityLinkService` resolves
  or provisions the link when a consuming app asks it to, after the launch is
  already cryptographically validated — it never influences the trust
  decision made by `validateLaunch()`, only what happens after.
- **Resource-link mapping seam (`lti_deployment.resourceLinkMappings[]`)** —
  an array of `{resourceLinkId, targetType, targetId}` ADR-008 pairs, mirroring
  `gradeSink`/`rosterSource`'s existing shape. A new
  `LtiLaunchService::resolveResourceMapping()` looks up the launch's
  `resource_link.id` claim against a deployment's configured mappings
  (falling back to an empty-`resourceLinkId` deployment-default entry, then to
  `null` if unconfigured), so a consuming app doesn't have to invent its own
  resource-link routing.

None of REQ-LTI-001 through REQ-LTI-010 are modified — every existing
protocol behaviour (signature verification, nonce replay, AGS/NRPS scoping,
key rotation) is reused unmodified; the new checks are layered strictly
after the existing cryptographic trust decision, never inside it, so the
"reject before redirect, no partial trust" invariant (design.md D6 of the
archived change) is preserved rather than re-litigated.

## Impact

- `lib/Settings/openconnector_register.json`: adds `status` (+ `identityPolicy`,
  `defaultProvisionGroup`) properties to `lti_platform`/`lti_tool`, adds
  `resourceLinkMappings[]` to `lti_deployment`, adds one new schema
  (`lti_identity_link`) — register schema count moves from 20 to 21 (per
  `openconnector-register-schema` REQ-A-002; re-verify the exact count at
  apply time per that spec's own HEAD-drift note in the archived change's
  tasks.md §1.4).
- `lib/Service/Lti/LtiRegistrationResolverService.php`: lookups gain a status
  filter.
- `lib/Service/Lti/LtiLaunchService.php`: gains `resolveResourceMapping()`.
- New `lib/Service/Lti/LtiIdentityLinkService.php`.
- `lib/Controller/LtiController.php` + `appinfo/routes.php`: two new
  admin-gated routes (`approve`, `suspend`).
- Consuming apps (scholiq's `lti-tool-placement` leaf, separate repo) are the
  intended callers of `LtiIdentityLinkService`/`resolveResourceMapping()` —
  cross-app service consumption, same pattern `consumeLaunchReference()`
  already establishes; no consuming-app code is written by this change.
