# Design — LTI Tool-role trust, identity-linking, and resource-mapping seam

## Context

`openspec/changes/archive/2026-07-13-lti-13-platform/` already built the full
LTI 1.3 / LTI Advantage protocol in both directions: inbound Tool-role login
+ launch validation (REQ-LTI-004/005), outbound Platform-role launch +
bidirectional Deep Linking (REQ-LTI-006), inbound AGS/NRPS serving
(REQ-LTI-007/009 Platform-role halves), and outbound AGS/NRPS calling
(REQ-LTI-008/009 Tool-role halves). This change does not touch any of that —
see proposal.md's premise-check section for the file:line evidence. What it
adds is the governance layer the protocol work deliberately left to "the
consuming app" or didn't address at all: who may register as a trusted
Platform, how a validated launch's external identity becomes (or doesn't
become) a Nextcloud user, and how a launch's `resource_link` claim routes to
a specific consuming-app object rather than only a whole deployment.

## Decisions

### D1 — Platform-vs-Tool role separation is unchanged; this change is strictly additive on top of it

The archived change's D1 (two registries, `lti_platform` / `lti_tool`, never
one polymorphic schema) stands. The three additions in this change attach to
existing entities without touching the role split:

- `status` and `identityPolicy`/`defaultProvisionGroup` are new fields on
  `lti_platform`/`lti_tool` (trust + identity are role-symmetric concerns —
  a `pending` `lti_tool` should equally not be launchable, an `lti_tool`'s
  own `sub`-bearing service-token assertions equally benefit from the trust
  gate even though `lti_tool` registrations don't get identity-linked
  end-users the way `lti_platform` launches do).
- `resourceLinkMappings[]` is new on `lti_deployment` only — it is
  inbound-launch-specific (a `resource_link` claim only appears on a launch
  *we receive*, i.e. when the referenced registration is an `lti_platform`).
  A deployment referencing an `lti_tool` simply never populates it.

**What's shared vs role-specific:**

| Concern | Shared (role-agnostic) | Role-specific |
|---|---|---|
| Trust gate (`status`) | Yes — both `lti_platform` and `lti_tool` gain it, same enum, same admin actions | No |
| Identity linking | Data model (`lti_identity_link`) is shared-shape | Populated only from `lti_platform`-side launches (inbound `sub`); an `lti_tool`'s own outbound calls authenticate as *us*, not as an external end-user, so there is no symmetric "identity-link an `lti_tool`'s launched user" case |
| Resource-link mapping | N/A | `lti_deployment.resourceLinkMappings[]` only meaningful when `ltiPlatformId` is set (inbound launches carry `resource_link`; our own outbound Platform-role launches choose the resource_link themselves via `initiatePlatformLaunch()`'s `extraClaims`, so they don't need to *resolve* one) |

### D2 — Identity-linking trust model: conservative default, explicit opt-in to auto-provisioning, never influences launch validation

**Trust model, stated precisely:**

1. A `sub` claim is only ever trustworthy in the context of the specific
   `lti_platform` registration whose signature verified it — REQ-LTI-005
   already guarantees this (the `iss`→registration resolution happens before
   any claim is used, `LtiLaunchService::verifyIdTokenSignature()`,
   `lib/Service/Lti/LtiLaunchService.php:545-637`). `lti_identity_link`
   therefore keys on `(ltiPlatformId, subject)`, never on `subject` alone —
   a bare `sub` string has no meaning outside its issuing platform, and two
   platforms could coincidentally reuse the same value.
2. **Default policy is `manualLinkOnly`.** An unlinked launch is reported as
   such and nothing is auto-created. This is deliberately conservative: the
   alternative (auto-provisioning by default) would mean the trust decision
   for "does this external LMS get to silently create Nextcloud accounts and
   grant them group membership" is made implicitly the moment an admin
   creates an `lti_platform` row for an unrelated reason (e.g. testing), not
   as a distinct, visible decision.
3. **`autoProvisionAsRole` is an explicit, per-platform opt-in** that
   additionally requires naming a `defaultProvisionGroup` — auto-provisioned
   accounts always land in a bounded group, never with ambient/admin
   privilege, and the group assignment is visible and auditable
   (`lti_identity_link.provisioningMethod: auto` + the group membership
   itself, both ordinary Nextcloud/OR-visible state — no separate audit log
   invented for this).
4. **Identity resolution never feeds back into launch trust.** The service
   boundary is intentionally one-directional:
   `validateLaunch()` (cryptographic trust) → `LtiIdentityLinkService`
   (post-trust identity resolution). A misconfigured or malicious
   `identityPolicy` can, at worst, over-provision Nextcloud accounts for
   *already-cryptographically-verified* launches from an *already-approved*
   platform (REQ-LTI-011) — it cannot be used to forge or weaken a launch's
   authenticity, because it never runs before or during
   `verifyIdTokenSignature()`/nonce-consumption/`aud` checks.
5. **No email/name matching.** Auto-linking a launch to an *existing*
   Nextcloud account by email or display-name similarity is explicitly out
   of scope and disallowed by REQ-LTI-012's scenario 1 — that class of
   matching is exactly how account-takeover-by-spoofed-claim attacks work
   (an attacker-controlled or compromised Platform could otherwise claim any
   email string in an unverified profile claim and silently take over an
   existing account). Manual linking to an existing account is always
   possible (an admin creates the `lti_identity_link` row directly, e.g. via
   OR's generic object API, same access path every other LTI registration
   object already uses) but is never automatic.

**Rejected alternative — auto-provision by default, opt-out per platform.**
Rejected because it inverts the safe default: the harmful action
(creating accounts / granting group membership from unauthenticated-by-us
external claims) would happen unless someone remembered to turn it off,
rather than requiring a deliberate, logged decision to turn it on.

**Rejected alternative — match by email claim.** LTI 1.3's optional
`email`/`name` profile claims are asserted by the Platform, not
independently verified by us beyond the JWS signature (which only proves the
*Platform* signed them, not that the email is accurate or unique). Matching
an existing Nextcloud account by email would let a launch silently take over
whatever account holds that address. Rejected outright, not just deferred.

### D3 — Registration trust gate: status lives on the registration, not the deployment; rejection is indistinguishable from "unregistered"

`status` is a field on `lti_platform`/`lti_tool` (the registration), not
`lti_deployment` (the placement) — a `pending` platform has no approved
deployments by definition, so gating at the registration level is sufficient
and avoids a second, redundant status field on every deployment row.

Rejecting a `pending`/`suspended` registration with the *same* HTTP
status/body as an unregistered `iss`/`client_id` (rather than a distinct
"awaiting approval" message) is deliberate: revealing "this issuer IS known
to us, just not approved yet" to an unauthenticated caller is a minor but
avoidable information leak (issuer enumeration), and REQ-LTI-004's existing
reject-before-redirect scenario already establishes the pattern of a flat,
undifferentiated 400 for "this request cannot proceed." The distinction is
preserved server-side in the rejection log only.

**Rejected alternative — a distinct HTTP status/message for "pending
approval."** Rejected for the enumeration reason above; a platform admin
who registered but isn't approved yet already knows they haven't been
approved (they did the registering, or were told by conduction/the school),
so the UX cost of an undifferentiated rejection is low.

### D4 — Resource-link mapping: resolved by the adapter, read/written by the consuming app (mirrors D7's "route, don't own" split)

`resolveResourceMapping()` returns a target, it does not perform the
register/schema read itself — this mirrors the archived change's D7 split
between AGS (adapter emits a CloudEvent, app writes) and NRPS (adapter reads
synchronously via the shared OR mapper path). Resource-link mapping is
neither of those shapes: it's not a write the adapter should perform (the
target may be any consuming-app object, e.g. a Course row scholiq owns and
applies its own authorization/mapping to) and it's not naturally a
synchronous *read* either (the consuming app already has direct access to
its own register/schema — it doesn't need the adapter to proxy the read the
way an external Tool needs the adapter to proxy an NRPS roster read). So the
adapter's job stops at resolution: "here is the `{targetType, targetId}`
pair this `resource_link.id` maps to" — the same shape `gradeSink`/
`rosterSource` already return without the adapter performing their I/O
itself either (`gradeSink` is never written to directly per REQ-LTI-007).

**Rejected alternative — a new `lti_resource_link` schema, one row per
resource-link mapping.** Rejected in favour of an array property on
`lti_deployment` (`resourceLinkMappings[]`) to match the existing
`gradeSink`/`rosterSource`/`signingKeys[]` precedent of embedding
placement-scoped config as properties on the owning registration/deployment
row rather than a separate joined schema — a resource-link mapping has no
independent lifecycle or cross-deployment reuse that would justify its own
schema and OR object type.

## Inbound-JWT security posture (unchanged; explicitly re-affirmed)

This change adds no new inbound JWT verification code and modifies none of
the existing verification methods
(`verifyIdTokenSignature()`, `validateTiming()`, `assertAudience()`,
`assertMessageTypeAndVersion()` in `lib/Service/Lti/LtiLaunchService.php`).
The three additions attach strictly *after* a launch has already been
accepted by that unmodified chain:

1. `verifyIdTokenSignature()` / `validateTiming()` / nonce-consume /
   `deployment_id` match / `message_type` check (existing, REQ-LTI-005) —
   the only place cryptographic trust is decided.
2. **NEW**: registration `status` gate (REQ-LTI-011) — checked *earlier* in
   the flow (at `findPlatformByIssuer()`/`findToolByClientId()` resolution
   time, before signature verification even has a registration's `jwksUri`
   to resolve against), so an unapproved registration fails fast with the
   same "unregistered" shape rather than doing wasted cryptographic work —
   but it is a coarser, non-cryptographic gate layered *before* step 1, not
   inside it.
3. **NEW**: identity resolution (REQ-LTI-012) and resource-link mapping
   (REQ-LTI-013) — both run only after step 1 has already succeeded, and
   both are read-only with respect to trust (identity resolution can create
   a Nextcloud user as a *side effect* for an already-trusted launch;
   resource-mapping resolution has no side effects at all).

No new "warn and continue" or partial-trust path is introduced anywhere;
every new gate is a hard reject (status) or a null/unlinked result
(identity, resource-mapping) that the caller must handle explicitly.

## Non-goals

- Not building a *Beheer > Authenticatie* admin UI for approve/suspend or
  identity-link management in this change — same capacity note as the
  archived change's deferred 4.2/4.3/4.4 (the backend contract
  `approve()`/`suspend()`/`LtiIdentityLinkService` is complete and
  independently testable/callable via OR's generic object API + PHP service
  injection in the meantime).
- Not building the scholiq-side "link this launch to my account" UI or
  admissions/roster-provisioning workflow — consuming-app concern, same
  non-goal precedent as the archived change's Deep Linking content-picker.
- Not adding email/name-based identity matching (D2, rejected outright, not
  deferred).
- Not adding a distinct rejected-registration HTTP status/message (D3,
  rejected outright for the enumeration reason).
