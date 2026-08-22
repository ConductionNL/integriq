# Design — Retrofit organisation-bridge

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`lib/Service/OrganisationBridgeService.php` is integriq's adapter to
OpenRegister's `OrganisationService`. It avoids a hard dependency on
openregister by reflecting on `IAppManager` + the DI container and returning
`null` / empty shapes when OR is not installed or fails to resolve.

The service is consumed by the user-management code path (cluster
`user-management-and-login`) and the frontend organisation switcher (cluster
`frontend-vue-spa`). It is **not** a security perimeter on its own — the
calling controller is supposed to gate access. The shapes it returns, however,
have an authorization-adjacent property worth flagging (see below).

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `getOrganisationService` | classic unsafe-auth-resolver: `catch (ContainerExceptionInterface\|NotFoundExceptionInterface) { logger->warning; return null; }`. Every consumer guards the call site with `if ($org !== null)` and silently degrades — the call "I cannot tell who you are" looks identical to "you have no org affiliation". Triggers the `hydra-gate-unsafe-auth-resolver` gate. | **HIGH — OWASP A01:2021 / CWE-863 silent-fail-open authorization shape** |
| `setActiveOrganisation` no membership re-check | the bridge forwards `$organisationUuid` verbatim to `OrganisationService::setActiveOrganisation` and returns the result. Authz that the current user actually belongs to the target org is delegated to OR's implementation — bridge has no defence in depth. | medium — relies on OR contract |
| `getUserOrganisationStats` shape divergence | unavailable path returns `{ total: 0, active: null, results: [], available: false }`, error path returns the same shape plus `error: 'Failed to retrieve organization data'`. Callers that only switch on `available` will treat an OR-side error identical to "OR not installed" — silently downgrades to empty stats. | medium — silent data-quality regression |
| `setActiveOrganisation` error message leak | `catch (\Exception $e) { return [..., 'message' => $e->getMessage()] }` echoes the OR exception text back to the API consumer (which is ultimately the browser). Could surface internal DB error fragments. | low — info-disclosure surface |
| `getUserOrganisations` empty-on-error | both unavailable AND OR-side error return `[]` indistinguishably. The frontend cannot tell "you have zero orgs" from "OR is broken". | low — UX ambiguity |
| `getActiveOrganisation` empty-on-anything | unavailable, no active org, OR exception — all three return `null`. Same indistinguishability problem. | low — UX ambiguity |

The repeated "swallow exception, return falsy" pattern is the spine of this
class. Whether to harden it (raise on OR-side exceptions; distinguish
"unavailable" from "errored"; reduce the surface to a single
`OrganisationContext` value object) is a design call worth its own change —
this retrofit pins the existing shape so any future hardening has a baseline.

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `getOrganisationService` |
| REQ-002 | `isOrganisationServiceAvailable` |
| REQ-003 | `getUserOrganisationStats` |
| REQ-004 | `setActiveOrganisation` |
| REQ-005 | `getActiveOrganisation` + `getUserOrganisations` (folded — same shape, same fail-paths) |

`isOrganisationServiceAvailable` gets its own REQ-002 because consumers
explicitly switch on it; it is not just sugar over `getOrganisationService()
!== null`.

## What the spec deliberately does NOT cover

- Constructor wiring (`__construct(IAppManager, ContainerInterface, LoggerInterface)`)
  — DI plumbing.
- OR's `OrganisationService` contract — that lives in openregister's spec; this
  retrofit pins only the integriq-side adapter shape.
- The HTTP controllers that consume these methods — covered by
  `user-management-and-login`.

## Validation

After archive, `openspec validate organisation-bridge --strict` MUST pass.
