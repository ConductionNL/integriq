# Design: zgw-version-translation

## Architecture Overview

```
   Sibling app / external municipality integration
         │ POST /api/zgw-translate {resource, fromVersion?, toVersion?, payload}
         │   (fromVersion/toVersion also negotiable via X-ZGW-Version header
         │    or Accept "version=" param — see "Version negotiation")
         ▼
   ZgwVersionTranslateController::translate()
         │  ActionAuthService::requireAction("zgw-version.translate")
         ▼
   ZgwVersionTranslationService::translate(resource, fromVersion, toVersion, payload)
         │
         ├─ 1. ZgwVersionNegotiationService::assertKnownVersion(from), (to)
         │        └─ ZgwUnknownVersionException  when neither "1.0" nor "1.6" nor "2.0"
         │
         ├─ 2. ZgwVersionNegotiationService::assertImplementedVersion(from), (to)
         │        └─ ZgwVersionNotImplementedException  when "2.0" (next-gen placeholder)
         │
         ├─ 3. resolve translator by resource slug (zaak, zaaktype,
         │      enkelvoudiginformatieobject, besluit, rol, status, resultaat)
         │        └─ ZgwUnknownResourceException  when not one of the 7
         │
         ├─ 4. fromVersion === toVersion?  → PASSTHROUGH: return payload unchanged
         │      (existing 1.0-only integrations are never touched by this change)
         │
         ├─ 5. else invoke translator->translateToV16() or ->translateToV1x()
         │        └─ ZgwLiteralLeakException  when a required field is missing, an
         │           enum value is outside the documented set, or a field that MUST
         │           be an array structurally is a bare scalar (the literal-leak guard)
         │
         └─ 6. persist one `zgw_version_translation_log` OR record (resource,
              fromVersion, toVersion, status, error, translatedAt) — always,
              success, passthrough, or failure (mirrors fsc-connectivity's
              "persist either way" convention)
```

Unlike `fsc-connectivity` / `iwmo-ijw-adapter` / `kiss-kcc-bridge`, this
change has **no transport leg at all** — every translator is pure PHP
(struct in, struct out, no HTTP, no Twig, no OpenRegister read for the
translation itself). The only I/O is the light log write in step 6.

## v1.x fleet shape, verified (translate-FROM contract)

Per the task brief, this is **not invented** — it is read directly from
procest's own `origin/development` ZGW implementation:
`lib/Service/ZgwService.php::RESOURCE_MAP`, and the field-level shape from
`lib/Repair/LoadDefaultZgwMappings.php`'s default Twig `propertyMapping`
for each resource (the actual Dutch-language ZGW field names procest emits
today, called canonical version `"1.0"` throughout this change). Verified
2026-07-15 against `procest` `origin/development` commit range including
`retrofit-2026-05-24-zgw-api-mapping` and `zgw-openapi-publication`.

| Resource (this change) | procest `zgwResource` | Fields procest actually emits (verified) |
| --- | --- | --- |
| `zaak` | `zaak` | `url, uuid, identificatie, bronorganisatie, omschrijving, toelichting, zaaktype, registratiedatum, startdatum, einddatum, einddatumGepland, uiterlijkeEinddatumAfdoening, vertrouwelijkheidaanduiding, verantwoordelijkeOrganisatie, archiefnominatie, archiefactiedatum, archiefstatus, betalingsindicatie, laatsteBetaaldatum, hoofdzaak` |
| `zaaktype` | `zaaktype` | `url, uuid, identificatie, omschrijving, omschrijvingGeneriek, catalogus, doel, aanleiding, onderwerp, doorlooptijd, vertrouwelijkheidaanduiding, concept, beginGeldigheid, eindeGeldigheid, handelingInitiator, indicatieInternOfExtern, handelingBehandelaar, opschortingEnAanhoudingMogelijk, verlengingMogelijk, verlengingstermijn, publicatieIndicatie, productenOfDiensten, selectielijstProcestype, referentieproces, verantwoordelijke, gerelateerdeZaaktypen, besluittypen, informatieobjecttypen` |
| `enkelvoudiginformatieobject` | `enkelvoudiginformatieobject` | `url, uuid, identificatie, bronorganisatie, creatiedatum, titel, vertrouwelijkheidaanduiding, auteur, status, formaat, taal, bestandsnaam, bestandsomvang, inhoud, link, beschrijving, informatieobjecttype, locked, registratiedatum, indicatieGebruiksrecht` |
| `besluit` | `besluit` | `url, uuid, identificatie, toelichting, zaak, besluittype, verantwoordelijkeOrganisatie, bestuursorgaan, datum, ingangsdatum, vervaldatum, publicatiedatum, verzenddatum` |
| `rol` | `rol` | `url, uuid, zaak, roltype, omschrijving, omschrijvingGeneriek, betrokkeneIdentificatie` — **NOTE:** no `betrokkeneType` field. procest's own mapping stores `betrokkeneIdentificatie` as a single opaque value, not the real ZGW polymorphic-by-`betrokkeneType` object. This is the fleet's own pre-existing simplification, verified from the mapping, not invented — see "Rol: documented lossy translation" below. |
| `status` | `status` | `url, uuid, zaak, statustype, datumStatusGezet, statustoelichting` |
| `resultaat` | `resultaat` | `url, uuid, zaak, resultaattype, toelichting` — **NOTE:** no `resultaattoelichting` duplicate (procest never emitted the deprecated field). |

## ZGW v1.6 delta table (VERIFIED vs. ASSUMED, stated explicitly)

**No machine-readable OAS diff for ZGW v1.6 could be retrieved in this
environment.** `github.com/VNG-Realisatie/zaken-api`'s and
`catalogi-api`'s `CHANGELOG.rst` (fetched directly, 2026-07-15) list
entries only up to `1.5.1` (2023-09-25) and `1.3.1` respectively — no
`1.6.0` entry is present in either changelog as retrievable here. VNG
Realisatie's own public communication (openwebconcept.nl, "Werken aan de
volgende generatie ZGW-standaarden") characterises the 1.6 line as
**"gericht op stabiliteit en praktische verbeteringen"** (focused on
stability and practical improvements) with **no breaking changes to the
resource data model** — 1.6 is explicitly positioned as the conservative
line, in contrast to the separate next-generation effort. Given that
constraint, every row below is labelled:

- **VERIFIED** — taken directly from a real, fetched VNG `CHANGELOG.rst`
  entry (the 1.5.x line that feeds into the 1.6 stability track).
- **ASSUMED** — a documented, explicit extrapolation of "stability fix"
  class behaviour consistent with VNG's own characterisation, exactly as
  `fsc-connectivity`'s "ASSUMED WIRE SHAPE" section and
  `iwmo-ijw-adapter`'s documented gaps handle an ungrounded external spec:
  isolated behind the translator seam, never presented as verified fact.

| Resource | Field / concern | `1.0` (fleet) | `1.6` (target) | Class | Status |
| --- | --- | --- | --- | --- | --- |
| *(all)* | `?expand=` query hint | not supported; procest ignores unknown query params | `_expand` inclusion mechanism on list/detail GET (VNG `zaken-api` CHANGELOG 1.5.0: "extend list call to accept expand parameters") | negotiation-layer, not payload-shape | **VERIFIED** (real 1.5.0 addition) — this shim's `ZgwVersionNegotiationService::stripUnsupportedExpandHint()` removes an `expand` query hint before forwarding rather than pretending to resolve it (resolving an expansion means querying additional resources, out of scope for a pure field translator — see Open Questions) |
| `resultaat` | `resultaattoelichting` | never emitted (procest only ever had `toelichting`) | dropped as a duplicate field fleet-wide (VNG `zaken-api` CHANGELOG 1.5.1: "removed field resultaattoelichting", issue #2157) | field removal | **VERIFIED** — `ResultaatTranslator::translateToV1x()` (going DOWN to a **legacy pre-1.5.1** consumer that still expects the duplicate) mirrors `toelichting` into `resultaattoelichting` for back-compat; `translateToV16()` is a no-op here since procest never emitted it |
| `rol` | `betrokkeneType` | absent — `betrokkeneIdentificatie` stored as one opaque scalar, no discriminator | the real ZGW `Rol` resource (both `1.0`-canonical per the official OAS and `1.6`) REQUIRES `betrokkeneType` (enum: `natuurlijk_persoon`\|`niet_natuurlijk_persoon`\|`vestiging`\|`organisatorische_eenheid`\|`medewerker`) alongside `betrokkeneIdentificatie` | structural / lossy | **fleet gap, not a version delta** — procest's own simplification predates this change and is out of scope to fix at the source; `RolTranslator::translateToV16()` best-effort defaults `betrokkeneType` to `natuurlijk_persoon` when absent (documented, lossy, see "Rol: documented lossy translation"); `translateToV1x()` strips it again to match the fleet's own scalar shape |
| `zaaktype` | `besluittypen` / `informatieobjecttypen` structural shape | MUST be arrays of URLs per ZGW; procest's own `LoadDefaultZgwMappings` maps `besluittypen` to a literal `'decisionTypes'` propertyMapping value (a pre-existing fleet quirk, not Twig-templated) | unchanged (no breaking change) — but MUST validate as an array | structural validation | **ASSUMED enforcement, not an assumed field rename** — `ZaakTypeTranslator` guards both directions: a non-array value under either key raises `ZgwLiteralLeakException` rather than silently forwarding a leaked internal fieldname/scalar (this is precisely the class of bug procest's own mapping quirk could produce if ever fed through unmapped) |
| `zaak`, `zaaktype`, `enkelvoudiginformatieobject` | `vertrouwelijkheidaanduiding` enum | 8 values: `openbaar, beperkt_openbaar, intern, zaakvertrouwelijk, vertrouwelijk, confidentieel, geheim, zeer_geheim` (verified from procest's own `valueMapping`) | identical value set (VNG: no breaking resource-model change in 1.6) | enum conformance | **VERIFIED** value set (from procest's own mapping) + **ASSUMED** that 1.6 keeps it identical (per VNG's own no-breaking-change characterisation) — guarded by the literal-leak check in every translator that carries this field |
| `enkelvoudiginformatieobject` | `status` enum | 4 values: `in_bewerking, ter_vaststelling, definitief, gearchiveerd` (verified from procest's own `valueMapping`) | identical value set | enum conformance | **VERIFIED** value set + **ASSUMED** stability |
| `enkelvoudiginformatieobject` | `bestandsdelen` (chunked upload) | not supported — `inhoud` is always a `_downloadUrl` string | real ZGW supports chunked binary upload via a separate `bestandsdelen` sub-resource (unrelated to the 1.6 stability track specifically) | out of scope | procest's document service never implements chunked upload; there is nothing to translate either direction — documented as a fleet gap, not a shim gap |
| `zaak`, `zaaktype`, `besluit`, `rol`, `status` | all other fields | see "v1.x fleet shape, verified" table above | **structurally identical to `1.0`** | passthrough | **ASSUMED**, directly from VNG's own "no breaking changes to the resource model" 1.6 characterisation — these translators still run required-field presence + enum-conformance validation on both directions (a version-boundary conformance gate has real value even when no field renames exist), they are not literal no-ops |

## Version negotiation

Real ZGW does **not** standardise an HTTP content-negotiation media-type
registry for versioning — production ZGW APIs version via the URL path
(e.g. procest's own `/api/zgw/zaken/v1/{resource}`), and report the served
version via an `API-version` response header. Since `POST /api/zgw-translate`
is a single, resource-path-agnostic endpoint (not itself a versioned ZGW
resource URL), this change **documents its own explicit, ASSUMED negotiation
convention** for sibling/consumer use, mirroring `API-version`'s spirit:

1. An explicit `fromVersion`/`toVersion` field in the POST body — **highest
   precedence**, since it is the caller's unambiguous stated intent.
2. Else an `X-ZGW-Version` request header (this change's own convention,
   not a VNG standard — documented here, not silently assumed elsewhere).
3. Else the `Accept` header's `version=` media-type parameter (e.g.
   `Accept: application/json;version=1.6` — a convention observed in some
   ZGW-adjacent implementations, not a VNG-ratified standard either).
4. Else default to `1.0` for both `fromVersion` and `toVersion` —
   **passthrough**, so a caller who sends no version signal at all gets its
   payload back completely unchanged. This is the explicit safety net the
   task requires: *"Default = passthrough (no translation) so existing
   integrations are untouched."*

`fromVersion === toVersion` (whether both explicit `"1.6"` or both
defaulted `"1.0"`) always short-circuits to passthrough before any
translator runs — a same-version "translation" is definitionally a no-op,
and skipping the translator call also skips its required-field/enum
guards, so an already-conformant same-version payload is never rejected by
this shim.

## Rol: documented lossy translation

`RolTranslator::translateToV16()` cannot losslessly translate procest's
`betrokkeneIdentificatie` opaque scalar into the real ZGW polymorphic
`betrokkeneType` + `betrokkeneIdentificatie` object pair — the fleet's own
source data does not carry the discriminator. The translator adds
`betrokkeneType: 'natuurlijk_persoon'` as a **documented best-effort
default** (the most common real-world case, and the only one requiring no
structural change to `betrokkeneIdentificatie` itself) rather than
refusing to translate at all. `translateToV1x()` strips `betrokkeneType`
back out, matching the fleet's own scalar shape exactly (lossless in that
direction, since the fleet never reads it). This is called out explicitly,
per the task's "documented lossy cases" requirement, and is **not** a
`1.0↔1.6` version delta — it is a pre-existing fleet gap this translator
works around, isolated to one class.

## Consumer path

A sibling app, or an external municipality's own integration reaching this
instance, calls:

```
POST /api/zgw-translate
Content-Type: application/json
X-ZGW-Version: 1.6            (optional — see "Version negotiation")

{
  "resource": "zaak",
  "fromVersion": "1.0",
  "toVersion": "1.6",
  "payload": { "...": "a zaak object in procest's current shape" }
}

200 OK
{
  "resource": "zaak",
  "fromVersion": "1.0",
  "toVersion": "1.6",
  "payload": { "...": "the same zaak, translated" }
}
```

No procest code changes; procest keeps emitting its one `1.0` shape
unmodified. A consumer expecting `1.6` calls this endpoint once per
payload it sends to, or receives from, procest — this change does not
(yet) proxy procest's endpoints transparently, see "Open Questions" below.

## Open Questions

1. **Next-generation ZGW standard.** No stable OAS exists as of this
   change (VNG working group at "first contours" stage, no publication
   date given). The negotiation seam recognises `"2.0"` as a known
   placeholder version and `ZgwVersionTranslationService` raises a typed
   `ZgwVersionNotImplementedException` (HTTP 501) rather than silently
   mistranslating or crashing — exactly as `fsc-connectivity` flagged its
   Outway/mTLS gap as its central Open Question rather than a silent
   omission. Adding real next-gen translators later requires new
   translator classes only; the negotiation service, orchestration
   service, and controller are unaffected (same provider-seam argument
   `fsc-connectivity`/`iwmo-ijw-adapter` already established for this
   codebase).
2. **Transparent proxy mode fronting procest's ZGW endpoints.** Explicitly
   permitted as optional scope by the task brief, "implement if it
   composes cleanly... else document as follow-up." It does not compose
   cleanly within this change's time-box: procest's ZGW endpoints are
   resource-path-versioned (`/api/zgw/zaken/v1/{resource}`), not
   header-negotiated, so a transparent proxy needs its own request
   routing, response streaming/content-length handling, and error-envelope
   passthrough — a distinct unit of work from the pure translator matrix
   this change ships. Deferred as a follow-up; the `POST /api/zgw-translate`
   endpoint is fully usable standalone by any consumer able to call it
   directly (see "Consumer path").
3. **`?expand=` object-graph resolution.** A verified real ZGW 1.5.x
   addition (see delta table). Out of scope for a pure payload-shape
   translator — resolving an expansion means querying additional related
   resources. `ZgwVersionNegotiationService::stripUnsupportedExpandHint()`
   documents and implements the lossy strip explicitly rather than
   ignoring the concern.
4. **`zgw_version_translation_log` retention/expiry.** Not implemented —
   every attempt is persisted, no automatic pruning. Mirrors
   `fsc_call`'s identical, already-accepted deviation (documented, not
   silent).
