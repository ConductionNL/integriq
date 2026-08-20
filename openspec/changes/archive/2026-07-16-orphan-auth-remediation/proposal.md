---
kind: code
depends_on: []
status: done
archived: 2026-07-16
---

# openconnector — orphan-auth remediation (Hydra gate-6)

## Why

Hydra gate-6 (`orphan-auth`, OWASP A01:2021) was recently un-blinded: its file
enumeration went from a non-recursive shell glob to a recursive
`git ls-files`, so it now opens every `lib/Service`/`lib/Controller` file
regardless of namespace depth. A **defined-but-never-called** authorization or
validation method is identical to having no check at all — it looks healthy
(implemented, tested, spec-referenced) while the action it is meant to guard
runs unguarded (or does not run at all). openconnector is the fleet's
integration gateway, so a dead check here is inherited by every consuming app;
precedent: ocon#159 (an `apiKey` check that was inert) and ocon#210
(domains/ips guards that were fabricated).

On clean `origin/development`, gate-6 reports **2** orphan auth/validation
methods, both in the LTI 1.3 adapter:

1. `LtiRegistrationResolverService::assertSingleRegistrationReference()`
   (`lib/Service/Lti/LtiRegistrationResolverService.php:279`) — the
   defensive read-time re-check of the `lti_deployment` `oneOf` constraint
   (REQ-LTI-001 scenario 2). Its own docblock states it must run "at
   launch/AGS/NRPS dispatch time", but **nothing called it** — the read-time
   defence the docblock promises did not exist.
2. `LtiLaunchService::verifyDeepLinkingResponse()`
   (`lib/Service/Lti/LtiLaunchService.php:549`) — verification of an inbound
   Platform-role `LtiDeepLinkingResponse`.

Full verdict table with file:line evidence in `design.md`.

## What Changes

- **WIRE** `assertSingleRegistrationReference()` into `findDeploymentByUuid()`
  — the single owner of deployment-by-uuid resolution that every live AGS
  token-issuance and NRPS roster-read dispatch calls. An ambiguous
  `lti_deployment` row (both `ltiPlatformId` and `ltiToolId`, or neither) that
  reached storage bypassing OR's write-time `oneOf` validation now **fails
  closed at resolution time** instead of silently resolving to an ambiguous
  registration at token/roster time. Proven with a test that rejects a
  both-references row on the live resolution path.
- **LEAVE (documented seam)** `verifyDeepLinkingResponse()` — it is one of the
  five uncalled public methods of the intentionally-unwired Platform-role
  consuming-app service surface (REQ-LTI-006), not an unprotected live path.
  Deleting it would drop a spec-mandated capability; wiring it means building
  the Platform-role HTTP surface, which is a feature, not a remediation. Named
  as a follow-up on ocon#210 rather than force-fit here.

No schema, no seed data, no notification-dialect (ADR-031) surface is touched.

## Impact

- Affected specs: `lti-platform` (REQ-LTI-001 — read-time enforcement scenario)
- Affected code: `lib/Service/Lti/LtiRegistrationResolverService.php` (+ test)
- Risk: low — the wired method only throws on malformed deployment rows; every
  well-formed deployment (exactly one FK) resolves unchanged. Full suite green
  before and after (1768 → 1771 tests).
