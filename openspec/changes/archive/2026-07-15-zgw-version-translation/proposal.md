---
kind: code
depends_on: []
---

# Proposal: zgw-version-translation

## Summary

Add a ZGW version-translation shim to OpenConnector — the fleet's
integration hub — so municipalities running a different ZGW API version
than the one procest/OpenRegister emit today can still integrate. Per the
user-mandated architecture, ALL integrations live in OpenConnector: it
translates the default OpenRegister/ZGW object API into other standards'
shapes, never re-implemented per leaf app (per ADR-022). Two VNG tracks
land in 2026: an incremental ZGW v1.6 (stability/practical-improvement
fixes on the 1.x line, published 2026-03-20 per VNG Realisatie) and an
early-stage next-generation ZGW standard (no stable OAS yet). This change
ships pure, testable translators for the fleet's 7 core ZGW resources
(zaak, zaaktype, informatieobjecttype→enkelvoudiginformatieobject, besluit,
rol, status, resultaat) between the fleet's current shape (canonical
version `1.0`) and `1.6`, a version-negotiation layer with passthrough
default, a `POST /api/zgw-translate` REST surface, and a light
`zgw_version_translation_log` for observability. The next-generation line
is stubbed behind the same seam with an explicit Open Question, exactly as
`fsc-connectivity` flagged its Outway/mTLS gap and `iwmo-ijw-adapter`
flagged its own.

## Motivation

procest/OpenRegister expose exactly one ZGW shape (the fleet's current
Twig-mapped `1.0` dialect — see design.md "v1.x fleet shape, verified").
Consumers on a different ZGW version — a municipality already upgraded to
1.6, or (later) the next-generation standard — cannot integrate without
either the fleet changing its own emitted shape (breaking every existing
1.0 consumer) or each consumer hand-rolling its own adapter. Per the
user-mandated architecture, that translation belongs in OpenConnector, once,
as a reusable shim — not duplicated per leaf app.

## Capabilities

- `zgw-version-translation` — new capability (this spec).

## Affected Projects

- [ ] Project: `openconnector` — new `ZgwResourceTranslatorInterface` with
  one implementation per resource (zaak, zaaktype, enkelvoudiginformatieobject,
  besluit, rol, status, resultaat), `ZgwVersionNegotiationService`,
  `ZgwVersionTranslationService`, `ZgwVersionTranslateController`, and a
  `zgw_version_translation_log` OR schema.
- [ ] Project: any sibling app / external municipality integration needing
  a non-`1.0` ZGW shape — no code change here; a consumer targets
  `POST /api/zgw-translate` (documented cross-app/cross-organisation
  contract only, see design.md "Consumer path").

## Scope

### In Scope

- `ZgwResourceTranslatorInterface` (`getResource()`, `translateToV16(array):array`,
  `translateToV1x(array):array`) implemented by one class per resource:
  `ZaakTranslator`, `ZaakTypeTranslator`, `InformatieObjectTranslator`,
  `BesluitTranslator`, `RolTranslator`, `StatusTranslator`,
  `ResultaatTranslator` — each direction independently unit-tested (the
  matrix), each enforcing a literal-leak guard (required-field presence,
  enum-value conformance, array-vs-scalar structural checks) so a
  malformed/unresolved mapping value never passes through untranslated.
- `ZgwVersionNegotiationService`: resolves the caller's declared version
  from an explicit body field, else `X-ZGW-Version` header, else `Accept`
  media-type `version=` parameter, else defaults to `1.0` (passthrough);
  recognises `1.0`/`1.6` as implemented and `2.0` (next-gen placeholder) as
  known-but-unimplemented; rejects anything else as unknown.
- `ZgwVersionTranslationService`: orchestrates resource+version resolution,
  invokes the correct translator direction, passes through unchanged when
  `fromVersion === toVersion`, persists a light `zgw_version_translation_log`
  record per attempt (success/passthrough/failed).
- `ZgwVersionTranslateController::translate()` — `POST /api/zgw-translate`
  `{resource, fromVersion?, toVersion?, payload}` → `{resource, fromVersion,
  toVersion, payload}`, gated by `ActionAuthService` (`zgw-version.translate`),
  clean typed errors for missing fields / unknown resource / unknown version
  / not-yet-implemented version / literal-leak failure — never a 500.
- `zgw_version_translation_log` OR schema (`resource`, `fromVersion`,
  `toVersion`, `status`, `error`, `translatedAt`) plus the register's
  `schemas` list entry (double-checked against `components.schemas`, per
  the kiss-kcc-bridge/iwmo-ijw-adapter/fsc-connectivity lesson that this
  list can silently drift).
- The documented, verified field/structure delta table between `1.0` and
  `1.6` (design.md) — grounded in procest's own current ZGW mapping
  (`LoadDefaultZgwMappings`) and VNG's published `zaken-api` CHANGELOG.

### Out of Scope

- **A transparent proxy mode fronting procest's own ZGW endpoints** — does
  not compose cleanly with the existing `Source`/proxy machinery inside one
  session (procest's ZGW controllers are resource-path-versioned, not
  header-negotiated, and a transparent proxy needs response-side
  content-length/streaming handling this change's time-box does not cover)
  — documented as a follow-up in design.md "Open Questions", not silently
  dropped.
- **Next-generation ZGW standard translation** — no stable OAS exists yet
  (VNG's own public communication: an active working group, "first
  contours," no publication date). The seam recognises version `2.0` as a
  known placeholder and raises a typed `ZgwVersionNotImplementedException`
  — never silently mistranslates. See design.md's central Open Question.
- **Object-graph `?expand=` resolution** — a real, verified `1.5.x` ZGW
  addition (VNG `zaken-api` CHANGELOG), but resolving an expansion means
  querying additional related resources, not translating one payload's
  field shape; the negotiation layer strips an unresolvable `expand` hint
  rather than pretending to honour it (documented lossy behaviour).
- **`bestandsdelen` (chunked binary upload)** for `enkelvoudiginformatieobject`
  — the fleet's document service does not implement chunked upload today
  (`inhoud` is always a `_downloadUrl`); nothing to translate.
- Any sibling app's or municipality's own consuming module — a cross-app
  contract this change defines and documents, not implements elsewhere.

## Approach

Pure, stateless per-resource translators (no I/O, no Twig — direct PHP
field mapping, mirroring `FscConnectivityProviderInterface`'s
one-seam-multiple-bindings shape but for data transformation rather than
transport) are selected by resource slug and invoked by
`ZgwVersionTranslationService`, which also owns version negotiation
delegation and light persistence. `ZgwVersionTranslateController` stays a
thin HTTP/auth shell (mirrors `KissController`/`IwmoIjwController`/
`FscController`). Details, the full delta table, and the next-gen Open
Question are in design.md.

## New Dependencies

None. Reuses `ActionAuthService` and the existing OR `ObjectService`
persistence pattern already used by `fsc_call`/`iwmo_ijw_message`/
`kiss_klantcontact`.

## Impact

- New: `lib/Service/ZgwVersion/{ZgwResourceTranslatorInterface,ZaakTranslator,
  ZaakTypeTranslator,InformatieObjectTranslator,BesluitTranslator,
  RolTranslator,StatusTranslator,ResultaatTranslator}.php`,
  `lib/Service/ZgwVersionNegotiationService.php`,
  `lib/Service/ZgwVersionTranslationService.php`,
  `lib/Controller/ZgwVersionTranslateController.php`,
  `lib/Exception/{ZgwUnknownVersionException,ZgwUnknownResourceException,
  ZgwVersionNotImplementedException,ZgwLiteralLeakException,
  ZgwVersionTranslationException}.php`, `appinfo/routes.php` entry,
  `zgw_version_translation_log` schema in
  `lib/Settings/openconnector_register.json`.
- Reused: `ActionAuthService`, OR `ObjectService`.

## Cross-Project Dependencies

- procest's own `ZgwService`/`LoadDefaultZgwMappings` is the read-only
  source-of-truth this change's `1.0` shape is verified against (no
  procest code change in this PR).
- Any sibling app or external municipality integration is the intended
  production consumer of `POST /api/zgw-translate` (contract owned here).

## Risks

### Risk 1: No authoritative published OAS diff for ZGW v1.6 exists in this environment

**Severity:** Medium — **Mitigation:** every `1.6` field/structure delta is
documented explicitly in design.md's delta table as either VERIFIED
(grounded in VNG's own published `zaken-api` CHANGELOG.rst) or an explicit,
labelled ASSUMPTION extrapolated from VNG's own "stability and practical
improvements, no breaking resource-model changes" characterisation of the
1.6 line — mirrors `fsc-connectivity`'s "ASSUMED WIRE SHAPE" precedent.

### Risk 2: The next-generation ZGW standard has no stable spec to translate against

**Severity:** Low (explicitly out of scope) — **Mitigation:** the version
seam recognises `2.0` as a known-but-unimplemented placeholder and raises a
typed, clean error rather than a silent no-op or crash; adding real
next-gen translators later requires no change to the negotiation service,
orchestration service, or controller — only new translator classes.

### Risk 3: A translator's literal-leak guard could reject a legitimate payload it doesn't yet understand

**Severity:** Low — **Mitigation:** guards check only the fields verified
present in procest's own current mapping (required-field presence, the 8
known `vertrouwelijkheidaanduiding` enum values, array-vs-scalar structural
shape) — never an invented/unverified constraint; every guard is unit
tested against both the accept and reject case.

## Rollback Strategy

The shim is additive. Revert by removing the new controller/services/
translators and the `zgw_version_translation_log` schema entry; no
existing source, sync, rule, event, or procest ZGW behaviour changes, so
removal cannot regress current integrations (default passthrough already
means "nothing translated" is the safe, existing state).

## Open Questions

The next-generation ZGW standard's exact shape (no stable OAS published as
of this change — VNG working group, "first contours" stage only) and a
transparent procest-fronting proxy mode are both explicitly deferred (see
"Out of Scope" / design.md "Open Questions") — not blocking, since
passthrough-by-default and the `1.0↔1.6` translator matrix make the change
self-contained and demonstrable without either.
