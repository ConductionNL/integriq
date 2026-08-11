# English vocabulary for openconnector — DSO / KISS integration schemas

> Implements `hydra/openspec/changes/fleet-english-vocabulary`.

## Why

Scan found **2 Dutch-named schemas and 10 Dutch property names**:
`dso_verzoek`, `kiss_klantcontact`, and a `verzoek`/`onderwerp`/`besluit` family.

openconnector is an **integration app**, so §1 applies hardest: much of this
vocabulary mirrors the DSO (Digitaal Stelsel Omgevingswet) and KISS APIs it
connects to.

## What changes

### Classify first (§1)

`dso_verzoek` and `kiss_klantcontact` are named after external systems. Decide
per schema whether it models **our** representation (rename to English) or is a
**wire mirror** (keep the field names, rename only the schema to an English
adapter name like `DsoRequest` / `KissCustomerContact`).

Note `rawVerzoek` — a property literally holding the raw external payload. Its
*name* becomes `rawRequest`; its *contents* keep whatever the external system
sends.

### Internationalised (§2)

| Dutch | English |
|---|---|
| `dso_verzoek` | `DsoRequest` |
| `kiss_klantcontact` | `KissCustomerContact` |
| `verzoekId` / `verzoekUuid` / `rawVerzoek` | `requestId` / `requestUuid` / `rawRequest` |
| `besluitStatus` | `decisionStatus` |
| `indieningsdatum` | `submissionDate` |
| `kenmerk` | `reference` |
| `nummer` | `number` |
| `onderwerp` / `onderwerpobjecten` | `subject` / `subjectObjects` |
| `zaakId` | `caseId` (must match procest/zaakafhandelapp — see risks) |

### Naming convention

`dso_verzoek` and `kiss_klantcontact` are also **snake_case**, unlike the rest of
the fleet's PascalCase schema names. This change normalises that too.

## Tasks

- [ ] Inventory per schema and per lib/+src/ file — real counts.
- [ ] Classify each schema: our representation vs wire mirror.
- [ ] Rename schemas to PascalCase English; rename properties.
- [ ] Agree `zaakId` → `caseId` with procest and zaakafhandelapp first.
- [ ] Rename classes/methods/files; `l10n/nl.json` + `check-l10n`.
- [ ] Full suite + hydra gates.

## Risks

- ⚠️ `zaakId` is a **cross-app** key (procest, docudesk, zaakafhandelapp all use
  it). Renaming it in one app desynchronises the fleet — this must be a
  coordinated rename or deliberately deferred.
- DSO/KISS payload keys are external; rename the *property holding* them, never
  the keys *inside* the payload.
