# Design — openconnector orphan-auth remediation

## Verdict table

Gate-6 (`orphan-auth`) findings on clean `origin/development`, each triaged to
exactly one verdict with file:line evidence. `class-injected ≠ method-called`
— caller search is `grep -rnE -- '->method\(' lib/ src/`.

| # | Method (file:line) | Callers (`->m(`) | Verdict | Evidence / superseder |
|---|---|---|---|---|
| 1 | `LtiRegistrationResolverService::assertSingleRegistrationReference()` — `lib/Service/Lti/LtiRegistrationResolverService.php:279` | 0 → **1** | **WIRE** | Docblock: "Defensive, in-code re-check … must not silently resolve to an ambiguous registration at launch/AGS/NRPS dispatch time." Wired into `findDeploymentByUuid()` (`:212`), the sole deployment-by-uuid resolver called by every live dispatch: `LtiAgsService:150,291,489`, `LtiNrpsService:90`. OR schema `oneOf` (`lib/Settings/openconnector_register.json:4984-5001`) is the **write-time** superseder only; this is the **read-time** counterpart the docblock mandates. |
| 2 | `LtiLaunchService::verifyDeepLinkingResponse()` — `lib/Service/Lti/LtiLaunchService.php:549` | 0 | **LEAVE (documented seam)** | One of five uncalled public methods of the Platform-role consuming-app service surface: `initiatePlatformLaunch()`, `buildDeepLinkingResponse()`, `verifyDeepLinkingResponse()`, `consumeLaunchReference()`, `resolveResourceMapping()` — all 0 in-repo callers. REQ-LTI-006: "The system MUST expose an internal service method a consuming app calls." Not superseded (do not delete); wiring needs a Platform-role HTTP surface (route + controller) that does not exist — a feature, not a remediation. Follow-up on ocon#210. |

## Why not the other verdicts, per finding

### #1 — why WIRE, not DELETE or LEAVE

The naive read is "the schema `oneOf` already guarantees exactly-one, so the
in-code re-check is redundant → delete." That is wrong on the live AGS path.
`LtiAgsService::…` resolves the deployment via `findDeploymentByUuid()` and
then checks `($deploymentData['ltiToolId'] ?? null) !== $tool->getUuid()`
(`lib/Service/Lti/LtiAgsService.php:156`). An ambiguous row carrying BOTH a
matching `ltiToolId` **and** an `ltiPlatformId` would pass that isolation
check and issue an AGS service token scoped to a deployment that also belongs
to a platform. The schema `oneOf` runs only at OR write time; a row that
predates the constraint or reached storage via any path that bypasses OR
validation is exactly the docblock's stated threat. Wiring the re-check at the
single resolution owner closes it for AGS **and** NRPS in one place. It is a
genuine, provable hardening on a live, authenticated endpoint — not a no-op.

### #2 — why LEAVE, not WIRE or DELETE

`verifyDeepLinkingResponse()` verifies content items handed back to a consuming
app after a Platform-role deep-linking round-trip. openconnector exposes the
whole Platform-role flow (initiate launch → build/verify deep-linking
response → consume launch reference → resolve resource mapping) as a **DI
service API a consuming app calls**, per REQ-LTI-006's literal wording. No such
consuming app exists in-repo yet, so all five methods read as orphans; only
`verify*` trips gate-6's auth-verb filter. There is **no reachable HTTP path
that processes a deep-linking response without this verification** — there is
no such route at all — so this is an orphaned *capability*, not an unprotected
*live path*. Deleting it removes a spec-mandated method; completing the wiring
means adding a controller/route pair for the Platform-role surface, which is
out of scope for a remediation change. Gate-6 has no `exclude` annotation
mechanism (unlike the spec/e2e gates), so this method stays as a single known
gate-6 entry, tracked on ocon#210.

## Seed Data

None. No OpenRegister schema, register descriptor, or seed object is added or
modified. `lib/Settings/openconnector_register.json` is read-only reference
here (the existing `lti_deployment.oneOf` constraint is the write-time
superseder cited above), not changed.

## ADR-031 (notification dialect)

Not applicable. This change dispatches no object notifications and touches no
`lib/Settings/*register*.json` notification block. The canonical
`x-openregister-notifications` dialect is unaffected.

## Test strategy

- Reuse `LtiRegistrationResolverServiceTest`'s in-memory `ObjectService`
  double. Add three cases against `findDeploymentByUuid()`:
  - single-reference (`ltiToolId` only) → resolves normally (regression guard);
  - both references set → `LtiValidationException` (the bad-path rejection);
  - neither reference set → `LtiValidationException`.
- The both-references case is the load-bearing proof: it is the exact row that
  would otherwise pass the AGS `ltiToolId === tool` isolation check.
