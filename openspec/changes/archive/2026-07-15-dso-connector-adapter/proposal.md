---
kind: code
depends_on: []
---

# Proposal: dso-connector-adapter

## Summary

Complete the DSO (Digitaal Stelsel Omgevingswet) connector: OpenConnector
already had a STAM koppelvlak inbound webhook (`DSOController::receiveVerzoek()`,
`DSOParserService`, `DSOSignatureVerifierService` — PKIoverheid/HMAC signature
verification, payload validation, BSN 11-proef, GML→GeoJSON) but it only
logged and dropped every verzoek — no persistence, no case-intake handoff, no
outbound leg existed. This change adds the `dso_verzoek`/`dso_message` OR
schemas, a `DsoVerzoekTranslator` (fixed field mapping, literal-leak guard),
persists every received Verzoek (`received → mapped → handed_off | failed`),
declares OpenRegister's `x-openregister-handoff` targeting
`https://openregister.app/ns#Case`, an authenticated handoff-trigger endpoint
(HandoffService v1 has no system-user privilege lane — mirrors
`open-formulieren-intake` exactly), and the outbound leg: a
`DsoConnectorProviderInterface` seam (`log`/sandbox default,
`DsoClient`/REST binding) that posts a `status` (voortgangsinformatie) or
`besluit` update back to DSO-LV, audited per attempt in `dso_message`.

## Motivation

DSO is mandatory since January 2024 — Dutch municipalities MUST exchange
permit/Omgevingswet data through it; a research audit
(`docs/research/market-feature-workup-2026-07.md` in procest) flags DSO/SWR
as "mandatory; own connector = strategic (avoid 3rd-party dependency)" and
explicitly defers the certification track as "own wave — large, certification
track." procest's own VTH module docs (`docs/Features/vth-module.md`) list
"DSO Omgevingsloket -- Receive permit applications from the national digital
system" as planned-but-not-implemented integration.

Investigating the existing (uninvoked-past-logging) `DSOAdapterService` found
a second, more severe pre-existing defect matching the fleet's documented
"orphaned capability" bug class: `DSOAdapterService::createZaak()` fabricates
an in-memory `uniqid()`-keyed "zaak" array — it writes nothing to
OpenRegister — and `DSOAdapterService::processVerzoek()`/`handleSamenloop()`/
`createHoofdzaakWithDeelzaken()`/`createGecombineerdZaak()` all build on that
same fake persistence; none of them has ANY caller outside `DSOAdapterService`
itself (verified via `grep -rn "DSOAdapterService"` / `"processVerzoek("`
across `lib/`). `DSOStatusService::pushStatusToDSO()` (outbound status push,
real `IClientService`-based HTTP with retry/backoff) is fully implemented but
likewise has zero callers anywhere. Every real DSO Verzoek received in
production was therefore logged and discarded — a complete, silent data-loss
gap, not a partially-working feature.

## Capabilities

- `dso-connector-adapter` — new capability (this spec), completing the
  pre-existing `dso-omgevingsloket` capability's inbound leg and adding the
  outbound leg that never existed.

## Affected Projects

- [ ] Project: `openconnector` — `dso_verzoek`/`dso_message` OR schemas, the
  `Dso\*` translator/provider-seam classes, `DsoIngestService`, extended
  `DSOController` (list/status/handoff/outbound endpoints), routes.
- [ ] Project: `procest` — no code change here; the intended production
  `ns#Case` provider for a future VTH-module change (see "How procest/VTH
  consumes this" in design.md).

## Scope

### In Scope

- `DsoVerzoekTranslator` — translates a parsed DSO Verzoek
  (`DSOParserService::parseVerzoek()`'s output — verzoekId, type, aanvrager,
  locatie, activiteiten, projectbeschrijving) into the normalised handoff
  fields `mappedTitle`/`mappedSummary`/`mappedChannel`/`mappedPriority`.
  Literal-leak guard: a Verzoek with no `verzoekId` MUST throw, never
  fabricate a correlation reference. This is a FIXED translator (the STAM
  schema is nationally standardised), unlike `open-formulieren-intake`'s
  per-form admin-configured mapping.
- `dso_verzoek` OR schema — inbound Verzoek lifecycle record
  (`received → mapped → handed_off | failed`) with per-verzoek error
  isolation, declaring `x-openregister-handoff` targeting
  `https://openregister.app/ns#Case` (`trigger: manual`,
  `whenUnavailable: queue`).
- `dso_message` OR schema — outbound status/besluit per-attempt audit log
  (`ref`, `type`, `status`, `error`), correlated to its `dso_verzoek` by
  `verzoekUuid`.
- `DsoConnectorProviderInterface` + `LogDsoConnectorProvider` (sandbox
  default, `MOCK-DSO-<n>`) + `DsoClient` (generic REST binding, Bearer-token
  auth as a documented deviation — the real DSO-LV outbound transport uses
  PKIoverheid client-certificate mTLS; see design.md "Open Questions").
- `DsoIngestService` — wires `DSOController::receiveVerzoek()` to actually
  persist (fixing the silent-drop gap), the `listVerzoeken()`/`status()`
  read surface, the authenticated `handoff()` trigger
  (`POST /api/dso/verzoeken/{id}/handoff`), and `postOutbound()`
  (`POST /api/dso/verzoeken/{id}/status`, `type: status|besluit`).
- `RegisterDescriptorTest` updated (34 schemas).

### Out of Scope (certification / production-endpoint track — see Open Questions)

- **DSO-LV certification and the real production/pre-productie base URL,
  credentials, and PKIoverheid client-certificate provisioning.** This is
  explicitly flagged by procest's own research as "own wave — large,
  certification track" — a multi-month process (DSO-LV onboarding,
  conformance testing against VNG/Kadaster's DSO test environment,
  PKIoverheid certificate issuance) entirely outside what a single code
  change can deliver. This change builds the connector/translation layer
  ONLY, exactly as `fsc-connectivity`/`iwmo-ijw-adapter`/
  `zgw-version-translation` did for their respective external-standard
  connectors.
- **Samenwerkingsfunctie/Samenwerkingsruimte (SWR)** — multi-authority case
  collaboration. The pre-existing, still-uncalled `DSOSamenwerkingService`
  (adviesverzoeken) is a separate DSO subsystem; untouched here per the
  task's explicit "focus on the case-relevant inbound/outbound" instruction.
- **STAM/STTR (toepasbare regels)** evaluation — DSO's rules-engine surface;
  not case-relevant inbound/outbound.
- **LVBB/DROP publication of besluiten** (STOP-TPOD) — a DIFFERENT DSO
  subsystem (bekendmaken officiële publicaties) from the Omgevingsloket
  Verzoeken flow this connector targets. procest already has its own
  `PublicationService`/`BesluitvormingPublishHandler` dispatching to
  DROP/LVBB directly for that concern — not duplicated or touched here.
- **Bijlagen (attachment) download** — `DSOAdapterService::downloadBijlagen()`
  already exists (HTTPS-only, retry, mTLS-ready) but is unwired, same as the
  rest of that service; wiring it is a natural follow-up, not required by
  this change's four numbered scope items.
- **Activiteiten→zaaktype mapping table / samenloop (deelzaken/gecombineerd)
  routing** — `DSOAdapterService::mapActiviteitenToZaaktypen()`/
  `determineSamenloopStrategy()`/`handleSamenloop()` are legitimate,
  self-contained pure-function logic, but their `createZaak()` return value
  is fake (see Motivation). OpenRegister's handoff engine v1 has no
  first-class "one Verzoek → N Cases" concept, so reconciling
  samenloop-driven multi-zaak creation with the real handoff engine is a
  separate, larger design question — explicitly deferred, not silently
  ignored (see design.md "Open Questions").
- Inbound webhook push vs. poll was already decided by the pre-existing STAM
  koppelvlak (signed HTTP push, `DSOController::receiveVerzoek()`) — this
  change does not introduce a cursor/poll job.

## Approach

`DSOController::receiveVerzoek()` keeps its existing signature-verification
and payload-parsing exactly as-is, then calls
`DsoIngestService::ingest($verzoek)` (new) which: (1) persists the parsed
Verzoek as a `dso_verzoek` record (`status=received`); (2) runs
`DsoVerzoekTranslator` — success sets `mappedTitle`/`mappedSummary`/
`mappedChannel`/`mappedPriority` and `status=mapped`, a literal-leak-guard
failure sets `status=failed` + `errorDetail`, isolated to that verzoek. This
is fire-and-forget from the webhook's point of view (a persistence/mapping
failure is logged, never turns the STAM koppelvlak's 202 Accepted into an
error — mirrors `IwmoIjwSyncService::receiveRetour()`'s isolation). A
separate, authenticated `DSOController::handoff()` calls OpenRegister's
`HandoffService::execute()` against the `verzoek-to-case` handoff entry,
under the calling user's own RBAC — no system account (HandoffService v1
constraint, verified identically for `open-formulieren-intake`).
`DSOController::postOutbound()` resolves the active `type=dso` Source +
configured provider (`log` default, `rest` = `DsoClient`), dispatches a
`status`/`besluit` payload, and persists a `dso_message` audit row
regardless of outcome (mirrors `IwmoIjwSyncService::sendBericht()`).

## New Dependencies

None. Reuses `guzzlehttp/guzzle` (already an app dependency, for `DsoClient`),
`ActionAuthService`, `OCP\Security\ICrypto`, and OpenRegister's shipped
`ObjectService`/`Handoff\HandoffService` (cross-app DI, same pattern as every
other leaf connector).

## Impact

- New: `lib/Service/Dso/{DsoConnectorProviderInterface,LogDsoConnectorProvider,
  DsoClient,DsoVerzoekTranslator}.php`, `lib/Service/DsoIngestService.php`,
  `lib/Exception/{DsoProviderException,DsoTranslationException}.php`,
  `dso_verzoek`/`dso_message` schemas in
  `lib/Settings/openconnector_register.json`, `appinfo/routes.php` entries.
- Modified: `lib/Controller/DSOController.php` (constructor gains
  `DsoIngestService`/`ActionAuthService`/`IUserSession`/`IL10N`;
  `receiveVerzoek()` now persists via `DsoIngestService::ingest()`; four new
  authenticated methods: `listVerzoeken()`, `status()`, `handoff()`,
  `postOutbound()`), `tests/Unit/Controller/DSOControllerTest.php` (updated
  for the new constructor + new endpoint tests),
  `tests/Unit/Settings/RegisterDescriptorTest.php` (32 → 34 schemas).
- Explicitly NOT modified: `DSOAdapterService`, `DSOStatusService`,
  `DSOSamenwerkingService` — all three remain as they were (see Motivation
  and Out of Scope); this change does not wire the fake `createZaak()` path
  into anything (doing so would propagate the defect, not fix it) and does
  not consolidate `DSOStatusService`'s orphaned outbound push into the new
  `DsoClient` (kept self-contained/Guzzle-mockable, consistent with every
  sibling adapter's provider-seam convention — see design.md).

## Cross-Project Dependencies

- procest's `case` schema (prior `semantic-case-intake` change, same as
  `open-formulieren-intake`'s dependency) is the intended production
  `ns#Case` provider; no procest code change in this PR — see design.md "How
  procest/VTH consumes this."

## Risks

### Risk 1: DSO-LV's exact outbound wire shape (status/besluit endpoints, payload fields, response envelope) cannot be verified against a live or preprod connection

**Severity:** Medium — **Mitigation:** every assumed endpoint/field/header is
documented explicitly in design.md "Outbound message-shape assumptions,"
grounded in the already-shipped `DSOStatusService::buildStatusPayload()`
shape (`{verzoekId, status, timestamp}`) and the sibling adapters'
established REST-provider pattern; the `log`/sandbox provider makes the whole
inbound→mapped→handoff→outbound path demonstrable end-to-end without a real
credential; the provider seam isolates any future correction to `DsoClient`
alone.

### Risk 2: PKIoverheid client-certificate (mTLS) auth is not implemented on the outbound leg

**Severity:** Medium — **Mitigation:** flagged explicitly in design.md "Open
Questions," identical treatment to `iwmo-ijw-adapter`'s documented mTLS gap;
the inbound leg already HAS real PKIoverheid chain validation
(`DSOSignatureVerifierService`, pre-existing) — only the outbound leg is
affected, and it defaults to the zero-config sandbox provider.

### Risk 3: DSO-LV certification is out of scope but the connector must not overclaim readiness

**Severity:** Low — **Mitigation:** proposal, design, and code docblocks all
explicitly label the outbound transport shape and auth as assumptions, not
verified facts; the certification/production-endpoint gap is called out as
an Open Question exactly as `fsc-connectivity`/`iwmo-ijw-adapter`/
`zgw-version-translation` did for their respective external-standard
integrations.

### Risk 4: Register file concurrency with another in-flight openconnector change

**Severity:** Low — **Mitigation:** per the task brief, `origin/development`
is merged and the full suite rerun before the PR; `components.schemas` /
`components.registers.openconnector.schemas` are keyed structures so a
textual conflict (if any) is a mechanical union, not a logical one.

## Rollback Strategy

The connector is additive to `DSOController` (new constructor params + new
methods; the existing `receiveVerzoek()` signature/behaviour for
signature-verification and payload-validation is unchanged) and additive to
the register (two new schemas). Revert by removing the new
`Dso\*`/`DsoIngestService`/exception classes, the new `DSOController` methods
and constructor params (restoring the prior 5-arg constructor), the new
routes, and the two new schema entries; the pre-existing STAM koppelvlak
signature-verification/validation behaviour is untouched and cannot regress.

## Open Questions

- **DSO-LV certification and production/pre-productie base URLs, credentials,
  and PKIoverheid client-certificate provisioning are explicitly deferred** —
  a multi-month track per procest's own research classification ("own wave —
  large, certification track"), entirely outside this change's scope. This
  change ships the connector/translation layer with a `log`/sandbox default
  so it is demonstrable and testable without any live DSO-LV access.
- **PKIoverheid client-certificate (mTLS) auth on the OUTBOUND leg** —
  `DsoClient` uses Bearer-token auth as a documented deviation (mirrors
  `IStandaardenClient`'s identical, already-accepted deviation); a future
  revision adds `configuration.certPath`/mTLS support without touching
  `DsoConnectorProviderInterface`.
- **The exact DSO-LV outbound status/besluit endpoint paths, payload field
  names, and response envelope** could not be verified against a live or
  preprod DSO-LV connection from this environment — documented as binding
  assumptions in design.md, isolated to `DsoClient` alone should a
  correction be needed.
- **Whether DSO also supports a push/notification (webhook) delivery mode for
  status/besluit acknowledgement** (mirroring the generic VNG Notificaties
  API pattern several Common Ground APIs use) is unverified; this change
  only implements the outbound POST leg per the task's explicit scope.
- **Reconciling `DSOAdapterService`'s activiteiten→zaaktype/samenloop
  (deelzaken vs. gecombineerd) routing with OpenRegister's handoff engine**
  (which has no first-class "one Verzoek → N Cases" concept in v1) is a
  separate, larger design question — not solved here; a single Verzoek
  currently hands off to exactly one Case.
- **The canonical `openspec/specs/dso-omgevingsloket/spec.md`'s
  REQ-DSO-020/REQ-DSO-040 describe a different, more ambitious architecture**
  (direct Procest coupling, `EventService` dispatch, beschikking-PDF upload)
  than this change's generic `ns#Case` handoff, and were marked as
  requirements against the orphaned/fake `DSOAdapterService::createZaak()`/
  `DSOStatusService` code (see design.md §6.1 and Motivation) — a second
  instance of "spec-says-done ≠ feature runs," this time at the spec layer.
  A future spec-maintenance change should reconcile those requirements with
  what this change actually ships; not solved here per the task's explicit
  instruction to mirror `open-formulieren-intake`'s generic handoff
  mechanism.
