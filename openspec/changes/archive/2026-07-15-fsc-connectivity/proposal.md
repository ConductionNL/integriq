---
kind: code
depends_on: []
---

# Proposal: fsc-connectivity

## Summary

Add FSC (Federatieve Service Connectiviteit) connectivity to OpenConnector —
the fleet's integration hub — so OpenRegister/app API calls can reach other
organisations' services over the standard Dutch inter-municipality
connectivity rails. FSC (VNG/Common Ground) replaced NLX in 2025 as the
directory + service-contract + mTLS-tunnel model for secure cross-
organisation API calls. A narrow `FscConnectivityProviderInterface`
(`resolveService`, `call`) is bound by `LogFscConnectivityProvider`
(sandbox/dev default) and `FscDirectoryClient` (generic REST binding — the
one class housing every directory-lookup and downstream-call HTTP/credential
operation this change performs). `FscCallService` resolves a target
organisation+service via the directory (caching the result), then routes the
call through the resolved provider. `GET /api/fsc/services` lets sibling
apps discover what is resolvable; `POST /api/fsc/call` lets them invoke a
service. `fsc_service` (directory cache) and `fsc_call` (call log) OR
schemas provide observability.

## Motivation

FSC is the current (2025-onward) VNG/Common Ground standard for secure
inter-organisation API connectivity between Dutch municipalities and other
government bodies — no fleet connector bridges OpenRegister/app calls to
this standard today. Per the user-mandated architecture, ALL integrations
live in OpenConnector — it translates the default OpenRegister/ZGW object
APIs into other standards' APIs, never re-implemented per leaf app (per
ADR-022). This is the connectivity/transport layer sibling apps need so an
OpenRegister object's own logic can reach another organisation's service
without embedding a directory/mTLS client of its own.

## Capabilities

- `fsc-connectivity` — new capability (this spec).

## Affected Projects

- [ ] Project: `openconnector` — new `FscConnectivityProviderInterface`
  abstraction with `Log` + `FscDirectoryClient` (REST) bindings,
  `FscCallService`, `FscController`, and `fsc_service`/`fsc_call` OR schemas.
- [ ] Project: any sibling app needing to reach another organisation's
  service — no code change here; a consuming module targets
  `POST /api/fsc/call` / `GET /api/fsc/services` (documented cross-app
  contract only, see design.md "How sibling apps consume this").

## Scope

### In Scope

- `FscConnectivityProviderInterface` (`getProviderId`, `getConfigSchema`,
  `resolveService`, `call`), `LogFscConnectivityProvider` (sandbox, resolves
  against a static `knownServices` config list, no network/secret),
  `FscDirectoryClient` (generic REST binding, token auth,
  `ICrypto`-encrypted secret — the ONE class performing every HTTP/cert
  operation this change makes, both directory resolution and the downstream
  call).
- `FscCallService`: `callService()` (resolve via directory, cache the
  resolution as `fsc_service`, dispatch via the provider, persist `fsc_call`
  either way), `listResolvableServices()` (read the `fsc_service` cache).
- `FscController`: `listServices()` (NC-session read), `call()` (NC-session
  invocation) — never a 500, clean `not_configured`/`unknown_service`/
  `fsc_call_failed` error envelopes.
- `fsc_service` OR schema (directory entries cache: `organisation`,
  `service`, `endpoint`, `grantRequired`, `resolvedVia`, `resolvedAt`) and
  `fsc_call` OR schema (call log: `organisation`, `service`, `method`,
  `status`, `ref`, `error`, `syncedAt`), plus the register's `schemas` list
  entry (double-checked against `components.schemas`, per the
  kiss-kcc-bridge/iwmo-ijw-adapter lesson that this list can silently
  drift).
- Feature gating: `configuration.provider` (`log`|`rest`), default `log`.
  An unconfigured source reports `not_configured` cleanly on `call()`, an
  empty list on `listServices()`, no HTTP either way.
- Action-level authorization gating (`fsc.list`, `fsc.call`) via
  `ActionAuthService`, mirrors every other leaf connector's controller.

### Out of Scope

- **A live-verified FSC network integration** — no live FSC Directory,
  Outway, or Inway was available in this environment (stated explicitly).
  Every endpoint/field/response shape is a documented assumption; see
  design.md "FSC concept mapping" and "Directory API shape".
- **Client-certificate (mTLS) authentication and Outway/Inway provisioning**
  — the real FSC transport is mutual-TLS between organisation-provisioned
  Outway/Inway gateway processes, not a bearer token an application builds
  itself; `FscDirectoryClient` implements token auth only (mirrors every
  other REST binding already in this app) and documents this gap explicitly
  as design.md's central "Open Question", not silently — exactly as
  `iwmo-ijw-adapter` flagged its own mTLS gap.
- **Publishing this instance's own services INTO an FSC federation**
  (acting as Inway-side) — this change is caller-only (Outway-side).
- **Grant provisioning/verification** — `resolveService()` surfaces a
  read-only `grantRequired` flag; arranging or checking an actual grant is
  not implemented.
- **`fsc_service` cache invalidation/expiry** — a cached resolution never
  expires automatically.
- **A settings UI for entering/rotating the FSC API token** — same
  convention as `notifynl-sms-channel`/`kiss-kcc-bridge`/`iwmo-ijw-adapter`;
  set via the existing config-write surface.
- Any sibling app's own consuming module — a cross-app contract this change
  defines and documents, not implements elsewhere.

## Approach

Model the FSC connection as an openconnector `Source` (`type=fsc`) whose
`configuration` selects a provider (`log`|`rest`) and carries
`directory.directoryUrl`/`directory.knownServices`,
`authentication.encryptedToken`, `ownOrganisation`. A narrow
`FscConnectivityProviderInterface` (`resolveService`, `call`) is implemented
by `LogFscConnectivityProvider` and `FscDirectoryClient`. `FscCallService`
resolves the active source + provider, drives `callService()` (resolve →
cache → dispatch → persist) and `listResolvableServices()` (cache read).
`FscController` stays a thin HTTP/auth shell (mirrors
`KissController`/`IwmoIjwController`). Details in design.md.

## New Dependencies

None. Reuses `guzzlehttp/guzzle` (already a dependency), `ActionAuthService`,
and `OCP\Security\ICrypto` (all already used by existing leaf connectors in
this app).

## Impact

- New: `lib/Service/Fsc/{FscConnectivityProviderInterface,
  LogFscConnectivityProvider,FscDirectoryClient}.php`,
  `lib/Service/FscCallService.php`, `lib/Controller/FscController.php`,
  `lib/Exception/{FscConnectivityException,FscDirectoryException}.php`,
  `appinfo/routes.php` entries, `fsc_service`/`fsc_call` schemas in
  `lib/Settings/openconnector_register.json`.
- Reused: `ActionAuthService`, `OCP\Security\ICrypto`.

## Cross-Project Dependencies

- Any sibling app is the intended production consumer of `POST /api/fsc/call`
  / `GET /api/fsc/services` (contract owned here; no sibling-app code change
  in this PR).

## Risks

### Risk 1: The real FSC Directory/Outway wire shape may differ from the documented assumption

**Severity:** Medium — **Mitigation:** every assumed endpoint/field/response
shape is documented explicitly in design.md, grounded in the publicly
published FSC/Common Ground concept model and this app's own
`kiss-kcc-bridge`/`iwmo-ijw-adapter`/`peppol-access-point-connector`
precedent; the `log`/sandbox provider makes the whole resolve/call path
demonstrable end-to-end without a live credential; the provider seam
isolates any future correction to `FscDirectoryClient` alone.

### Risk 2: mTLS (Outway/Inway, client-certificate) transport is not implemented

**Severity:** Medium — **Mitigation:** documented explicitly as design.md's
central Open Question, not silently assumed away; `FscDirectoryClient`'s
token-auth shape mirrors every other REST binding in this app and is
provider-seam-isolated, so adding a real Outway-backed binding later
requires no change to `FscConnectivityProviderInterface`, `FscCallService`,
or `FscController`.

### Risk 3: A stale `fsc_service` cache entry could route a call to a decommissioned endpoint

**Severity:** Low — **Mitigation:** cache staleness is documented explicitly
in design.md's Open Questions; every `callService()` call still re-resolves
via the configured provider (the cache is a read-side convenience for
`listResolvableServices()`, not a bypass of resolution on the write/call
path) — a decommissioned endpoint surfaces as a normal transport failure
(502), not a silent misroute.

## Rollback Strategy

The connector is additive. Revert by removing the new
controller/services/routes and the `fsc_service`/`fsc_call` schema entries;
no existing source, sync, rule, or event behaviour changes, so removal
cannot regress current integrations.

## Open Questions

Outway/Inway (mTLS) provisioning, the exact FSC Directory API shape, and
grant verification are explicitly deferred (see "Out of Scope" / design.md
"Open Questions") — not blocking, since the sandbox provider makes the
change self-contained and demonstrable without either.
