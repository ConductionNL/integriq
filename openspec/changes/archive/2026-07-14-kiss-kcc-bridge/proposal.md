---
kind: code
depends_on: []
---

# Proposal: kiss-kcc-bridge

## Summary

Add a KISS (Klantinteractie Servicesysteem) bridge to OpenConnector — a narrow
`KlantinteractiesProviderInterface` (list/create/link) plus two bindings:
`LogKlantinteractiesProvider` (sandbox/dev) and `KlantinteractiesClient` (the
generic REST binding against the VNG "Klantinteracties API", the OpenKlant
2.x model that KISS — the open-source Common Ground KCC component built by
Utrecht + Dimpact, live with ~8 municipalities — implements). A scheduled
`KissPullJob` pulls new/changed klantcontacten into `kiss_klantcontact`
records, mapping each klantcontact's onderwerpobjecten to a case/zaak
reference. A `POST /api/kiss/klantcontacten` endpoint lets sibling apps (e.g.
procest's ContactMomentService) register a klantcontact in KISS and link it
to a case, mirroring how procest would consume `NotifyNlController::send()`.

## Motivation

Procest has a native KCC (Klant Contact Centrum) module. Municipalities
running KISS need their KISS klantcontacten to reach procest cases and vice
versa — otherwise a citizen's phone call logged in KISS has no visibility in
the case file, and a case update in procest never reaches the KCC operator's
klantcontact history. KISS is the reference KCC integration target for
zaaksystemen (Utrecht + Dimpact co-development, ~8 municipalities), making
this the natural next connector after notifynl-sms-channel and the
vng-klantinteracties-adapter (which serves openconnector's OWN data as a
Klantinteracties-shaped API — a different, complementary direction from this
change's outbound client to an external KISS deployment). Per ADR-022
integrations belong in openconnector, never as nc-vue leaves and never
re-implemented per app.

## Capabilities

- `kiss-kcc-bridge` — new capability (this spec).

## Affected Projects

- [ ] Project: `openconnector` — new `KlantinteractiesProviderInterface`
  abstraction with `Log` + `KlantinteractiesClient` (REST) bindings,
  `KissSyncService` (pull sweep + push orchestration), `KissController`,
  `KissPullJob`, and a `kiss_klantcontact` OR schema.
- [ ] Project: `procest` — no code change here; procest's own
  `ContactMomentService` would target the push endpoint this change
  introduces (documented cross-app contract only — out of scope for this
  change, see design.md "How procest should consume this").

## Scope

### In Scope

- Generic provider contract: `KlantinteractiesProviderInterface`, so a future
  alternative Klantinteracties-API-compatible backend can be added without
  touching `KissSyncService` or `KissController`.
- `LogKlantinteractiesProvider` (sandbox, no network/secret) and
  `KlantinteractiesClient` (generic REST binding, token auth, ICrypto-
  encrypted secret).
- PULL: `KissPullJob` (hourly `TimedJob`) sweeps every active KISS source,
  pulling klantcontacten changed since a persisted cursor
  (`source.configuration.cursor.lastRegistratiedatum`), mapping onderwerp-
  objecten to `caseReference`/`caseObjectType`, and upserting
  `kiss_klantcontact` records keyed by KISS `uuid` (new vs. changed).
  Per-record isolation: one malformed klantcontact is logged and skipped,
  never aborting the rest of the page.
- PUSH: `POST /api/kiss/klantcontacten` — an authenticated NC-session call
  that creates a klantcontact in KISS, optionally links it to a case as an
  onderwerpobject, mirrors the record locally, and returns `{id, localUuid}`.
- `kiss_klantcontact` OR schema tracking both pull- and push-originated
  records (`direction` field), plus `source.type=kiss` and the register's
  schemas list entry.
- Feature gating: `configuration.provider` (`log`|`rest`), mirroring the
  SMS/Peppol/PSD2 leaves — no `log|test|live` convention exists elsewhere in
  this app (checked). An unconfigured KISS bridge reports `not_configured`
  cleanly from the push endpoint and no-ops from the pull job.
- AVG/BSN hygiene: any `partijIdentificator` carrying a raw BSN
  (`codeSoortObjectId: bsn`) is SHA-256-hashed before storage, consistent
  with this app's own `AvgBsnPolicyRule` precedent.

### Out of Scope

- A live-verified KISS integration — no live KISS instance was available in
  this environment (stated explicitly, not pretended otherwise). Every
  endpoint/field/param is a documented assumption grounded in the published
  VNG Klantinteracties API and this app's own already-implemented
  server-side half of the same dialect (`vng-klantinteracties-adapter`). See
  design.md "API-shape assumptions".
- A settings UI for entering/rotating the KISS API token — same convention
  as notifynl-sms-channel; the encrypted value is set via the existing
  config-write surface.
- procest's own consuming adapter (`ContactMomentService`) — a cross-app
  contract this change defines and documents, not implements in procest.
- Read/list REST surface for sibling apps (`GET /api/kiss/klantcontacten`) —
  not required by the current cross-app contract (procest only needs to
  push); the pulled records are queryable via OpenRegister's generic object
  API like any other schema.

## Approach

Model the KISS connection as an openconnector `Source` (`type=kiss`) whose
`configuration` selects a provider (`log`|`rest`) and carries
`authentication.encryptedToken`, `baseUrl`, `pageSize`, and an
`onderwerpobject.codeRegister` override. A narrow
`KlantinteractiesProviderInterface` (`listKlantcontacten`,
`createKlantcontact`, `linkOnderwerpobject`) is implemented by
`LogKlantinteractiesProvider` and `KlantinteractiesClient`. `KissSyncService`
resolves the active source + provider and drives two flows: `pullAll()` /
`pullSource()` (cursor-based sweep, per-record isolation, onderwerpobject →
case mapping) and `pushKlantcontact()` (create + link + local mirror).
`KissController` stays a thin HTTP/auth shell (mirrors `NotifyNlController`).
Details in design.md, including the full "API-shape assumptions" list and
cursor semantics.

## New Dependencies

None. Reuses `guzzlehttp/guzzle` (already an app dependency, same pattern as
`RestNotifyNlProvider`/`RestPeppolAccessPointProvider`), `ActionAuthService`,
and `OCP\Security\ICrypto` (already used by `RestNotifyNlProvider`).

## Impact

- New: `lib/Service/Kiss/{KlantinteractiesProviderInterface,
  LogKlantinteractiesProvider,KlantinteractiesClient}.php`,
  `lib/Service/KissSyncService.php`, `lib/Controller/KissController.php`,
  `lib/Cron/KissPullJob.php`, `lib/Exception/KissProviderException.php`,
  `appinfo/routes.php` + `appinfo/info.xml` entries, a `kiss_klantcontact`
  schema in `lib/Settings/openconnector_register.json`.
- Reused: `ActionAuthService`, `OCP\Security\ICrypto`.

## Cross-Project Dependencies

- procest is the intended production consumer of
  `POST /api/kiss/klantcontacten` (contract owned here; no procest code
  change in this PR).

## Risks

### Risk 1: The real KISS API shape may differ from the documented VNG assumption

**Severity:** Medium — **Mitigation:** every assumed endpoint/field/param is
documented explicitly in design.md, grounded in the published VNG
Klantinteracties OpenAPI spec AND this app's own already-implemented
server-side half of the dialect (`vng-klantinteracties-adapter`); the
`log`/sandbox provider makes the whole pull/push path demonstrable
end-to-end without a real credential; the provider seam isolates any future
correction to `KlantinteractiesClient` alone.

### Risk 2: Credential storage deviates from the Peppol/PSD2 `credentialRef` precedent

**Severity:** Low — **Mitigation:** documented explicitly in design.md,
mirroring the notifynl-sms-channel leaf's identical, already-accepted
deviation (a static bearer-style secret decrypted in-process, no hard
dependency on the optional OpenRegister credential-broker class).

### Risk 3: A persistently malformed klantcontact could silently stall the cursor forever

**Severity:** Low — **Mitigation:** the cursor advances to the page's max
`registratiedatum` regardless of individual persist failures (see design.md
"Cursor semantics") — a malformed record is logged and skipped, never
blocking newer records from syncing.

## Rollback Strategy

The connector is additive. Revert by removing the new controller/services/
cron job/routes and the `kiss_klantcontact` schema entry; no existing source,
sync, rule, or event behaviour changes, so removal cannot regress current
integrations.

## Open Questions

None blocking — the sandbox provider makes the change self-contained. A
live-verified KISS integration and a settings UI for the KISS credential are
explicitly deferred (see Out of Scope).
