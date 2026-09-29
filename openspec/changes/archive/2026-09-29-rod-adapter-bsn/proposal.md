---
kind: code
depends_on: []
---

# Proposal: rod-adapter-bsn

## Summary

The DUO ROD adapter sends the persoonsgebonden nummer that learniq hands over, in the choice
element DUO asks for: `burgerservicenummer` or `onderwijsnummer` inside
`persoonsgebondenNummer`. It also gains the school advice message, DUO's
`AanleverenAdviesVO_Request` (contract `DUO_PO_AdviesVO_V1`), fed by a new mapping row
`learniq-bron-rod-export-schooladvies`. The adapter never writes the number to a log line, an
exception message or an error stored on a job or message record.

## Motivation

The learner mapping `learniq-bron-rod-export-learner` fills `bsn` with the ECK iD today
(`"bsn": "eckId"`), so every ROD message identifies the pupil with the wrong number. DUO
identifies a pupil by BSN or onderwijsnummer, a choice field (PvE ROD-PO 1.14.2, 15-4-2026,
section 7.9.1). Learniq now hands both the number and its type per record.

Schools must also report the school advice to DUO. The adapter's `schooladvies` kind carried
two invented fields (`schooladviesWaarde`, `schooladviesDatum`) instead of DUO's message.
Source: DUO "Programma van Eisen ROD-PO" versie 1.14.2, section 7.9.1 AanleverenAdviesVO_Request,
https://duo.nl/zakelijk/images/pve-po.pdf.

## Affected Projects

- [x] Project: `integriq`: ROD envelope translator, ROD service, exchange dispatch defaults, learniq mapping rows.

## Scope

### In Scope

- `RodEnvelopeTranslator`: the persoonsgebonden nummer as a choice element on every message
  kind; the legacy `bsn` key still reads as a burgerservicenummer for `POST /api/rod/send`.
- `schooladvies` becomes `AanleverenAdviesVO_Request`: persoonsgebonden nummer, adviesvolgnummer,
  onderwijsaanbieder, onderwijslocatie, vestigingscode, adviesjaar, Advies1 and Advies2 (each a
  combined Advies plus Adviesdatum, left out when null).
- Format checks on every field before any XML is built; failures name the field, never the value.
- `RodPersonalNumberRedactor`: scrubs the number from any provider or transport message before
  it is logged, thrown or stored.
- Mapping rows: learner row maps the number and its type (version 1.1.0); new schooladvies row.
- A learniq `bron-rod` job without an explicit mapping gets the schooladvies row when its scope
  says `berichtsoort: schooladvies`, and the learner row otherwise.

### Out of Scope

- DUO's value list for AdviesVO is not copied: the adapter checks the format, DUO checks the
  value (`046_waardenlijst_fout`). The list changes each school year.
- The live Edukoppeling provider stays gated on the DUO certificate (unchanged).
- The ECK iD stays in the learner mapping output for publisher chains; the ROD envelope never
  carried it and does not now.

## Approach

See design.md. Validation and rendering stay in the one translator class.

## New Dependencies

None.

## Impact

`RodEnvelopeTranslator`, `RodService`, `RodEdukoppelingClient`, `ExchangeJobService`, the learniq
exchange register fragment. The `rod_message` schema is unchanged; `bsnHash` now hashes the
persoonsgebonden nummer of either type.

## Cross-Project Dependencies

Learniq's gate hands the records described in design.md (contract fixed by the learniq half of
lane f-rod). Learniq names or omits the mapping slug; both work.

## Risks

### Risk 1: element names are derived, not taken from DUO's XSD
**Severity:** Medium. **Mitigation:** the PvE names fields but does not print the XSD. Names
are the PvE names in camelCase, isolated in the translator. The live provider stays gated; the
XSD check is a task for whoever enables it.

### Risk 2: the number reaches a log through an upstream message
**Severity:** Medium. **Mitigation:** every provider and transport message passes the redactor,
which removes the known number and any standalone run of nine digits. Tests capture the logger.

## Rollback Strategy

Revert the commit. The mapping row version returns to 1.0.0 on the next import.
