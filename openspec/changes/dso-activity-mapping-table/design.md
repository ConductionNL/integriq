## Context

`DsoIngestService::ingest()` calls `DsoActivityMapper::mapRequest()` since #2484. The mapper looks up each activiteit's `code` in `defaultMappingTable()`, built from the 25 hard-coded rows of `getDefaultMappings()`. It writes `mappedActivities`, `mappedCaseTypes`, `samenloopStrategy` and `activityUnmapped` on the `dso_verzoek`.

`DSOParserService::parseActiviteiten()` reads `code` (or `activiteitCode`) from the payload. That is integriq's own JSON shape. STAM itself carries other fields, see "What a verzoek carries" below.

REQ-DSO-010 asks for a table stored as OpenRegister objects, one-to-many mapping, admin editing, and a "Load default mappings" button seeding 25+ mappings. REQ-DSO-011 asks for a samenloop strategy "per activiteitcombinatie". REQ-DSO-013 asks for a summary of unmapped activities.

## Research: is there a list of DSO activity codes?

What a verzoek carries per activity, from the STAM specification v6.0.2 (definitief, https://iplo.nl/publish/pages/247429/20260922stam-v6-0-2-def.pdf):

- Section 3.7 (p. 27): a Project Activiteit has an `Activiteit-id` ("unieke identifier voor de betreffende activiteit, gebaseerd op de functionele structuurreferentie"), an `imow-id` ("gebaseerd op de generieke identifier van het Informatiemodel Omgevingswet"), an `Activiteitnaam` and a `Volgnr`.
- Footnote 18: the functional structure reference points at a regelbeheerobject of an activity in the Registratie Toepasbare Regels (RTR).
- Footnote 19: the imow-id is used across the DSO chain, in Ozon and RTR too.
- An optional Onderliggende Project Activiteit has the same three identifying fields, for example "Tankstation onder Milieubelastende activiteit gereguleerd bij AMvB".
- The attribute table (p. 46): `Activiteit-id` is CharacterString 1..6144 with no pattern. `Imow-id` is CharacterString 1..6144 with pattern `nl.imow-(gm|pv|ws|mn|mnre)[0-9]{1,6}.Objecttype.[A-Za-z0-9]{1,32}`.
- Section 3.7 also says: "De lijst van beschikbare activiteiten ligt vast, en is ook aan de locatie gebonden".

Where activities are published, from the DSO developer portal:

- RTR gegevens raadplegen, "activiteiten en werkzaamheden": https://developer.omgevingswet.overheid.nl/api-register/api/crud-rtr-gegevens-raadplegen/
- Toepasbare regels zoeken: https://developer.omgevingswet.overheid.nl/api-register/api/toepasbare-regels-zoeken/
- Omgevingsdocument toepasbaar opvragen (resources "Activiteiten" and "Locaties"): https://developer.omgevingswet.overheid.nl/api-register/api/omgevingsdocument-toepasbaar-opvragen/

Each of these pages says every request needs an API key ("Om API's te gebruiken moet bij elke request een API-key meegegeven worden").

Verdict: we found no static, public, machine-readable list of activity identifiers to ship. Activities are defined per bevoegd gezag (the `gm|pv|ws|mn|mnre` prefix) and are only queryable through the keyed APIs above. We did not verify what a national activity's identifier looks like, nor how stable identifiers are across versions of a regeling. **So this change seeds no real codes.**

Not verified, and needed before the parser task: the XML element names in the STAM verzoekbericht XSD, and the shape integriq's JSON intake receives them in. Task 1.1 asks for a real pre-production verzoekbericht.

## Decisions

### D1. The `dso_activity_mapping` schema

| Property | Type | Meaning |
|---|---|---|
| `imowId` | string, STAM pattern | primary match key |
| `activityId` | string, up to 6144 | fallback match key (functionele structuurreferentie) |
| `activityName` | string | the Activiteitnaam, for people |
| `caseTypes` | array, min 1 | `{reference, title, department}` per case type |
| `samenloopStrategy` | enum `deelzaken`, `gecombineerd` | default for this activity |
| `samenloopRules` | array | `{withImowId, strategy}` per activity combination |
| `isActive` | boolean, default true | inactive rows are ignored |
| `note` | string | free text for the beheerder |

`required`: `activityName`, `caseTypes`, `samenloopStrategy`, and at least one of `imowId` or `activityId` (`anyOf`).

`caseTypes[].reference` is a string: a ZGW zaaktype URL or a catalogue identificatie. The case system decides what it resolves. Integriq does not validate it against a catalogue, because the case system is a separate app and may not be installed.

`imowId` carries the STAM pattern. STAM writes `Objecttype` literally in the pattern; we read it as the IMOW object type segment and accept `[A-Za-z]+` there. Task 1.1 checks that reading against a real verzoekbericht before the pattern is enforced.

Authorization (`register.d/dso-activity-mapping.json`, ADR-037): `create`, `update`, `delete` for `admin`; `read` for `admin`. The mapper reads the table as an engine read of admin configuration (`_rbac: false`, read only), the same pattern the endpoint runtime uses for `rule`. A uniqueness check refuses two active rows with the same `imowId`.

### D2. Matching

Per parsed activiteit, in order:

1. the onderliggende activiteit's `imowId`, then its `activityId`, when the verzoek has one;
2. the activiteit's own `imowId`;
3. its `activityId`.

The first active row that matches wins. The most specific activity wins, because a gemeente may route "tankstation" differently from "milieubelastende activiteit" in general. No match leaves the activiteit `mapped: false`, exactly as today.

### D3. Samenloop

For a verzoek with two or more mapped activities:

1. When a `samenloopRules` entry exists for a pair, from either row, it decides for that pair.
2. All pairs decided `gecombineerd` gives `gecombineerd`. Any pair decided `deelzaken` gives `deelzaken`.
3. Without rules, today's `determineSamenloopStrategy()` applies: `gecombineerd` only when every mapped row says so.

`mappedCaseTypes` becomes the union over all case types of all mapped rows, so one activity with two case types yields two entries. Each `mappedActivities` entry records its own case types and departments, so the handoff can route each deelzaak (REQ-DSO-011).

### D4. The parser keeps the STAM identifiers

`parseActiviteiten()` returns per activiteit: `imowId`, `activityId`, `activityName`, `volgnr`, and `underlying` (the same fields) when present. It still reads `code` as a fallback for `activityId`, so existing integriq JSON pushes keep working. `dso_verzoek.mappedActivities` items gain the same fields.

### D5. The admin screen

A manifest fragment `src/manifest.d/dso-activity-mapping-table.json` (ADR-037) adds two pages to the Connections group:

- **DSO activities** (`type: index`, schema `dso_activity_mapping`). Columns: activity name, imow-id, case types, samenloop, active. Add and edit through `src/modals/DsoActivityMappingModal.vue` (ADR-004: one modal per file; every `NcSelect` has an `inputLabel`). The modal edits `caseTypes` and `samenloopRules` as lists.
- **Unmapped DSO activities** (`type: index`, schema `dso_verzoek`, filter `activityUnmapped = true`). It groups the unmapped activiteiten by `imowId` and shows how often each was seen and when last. A row action opens the same modal, prefilled with `imowId`, `activityId` and `activityName`.

The page is an app page, not a Nextcloud admin settings section. The rows are operational data a VTH functioneel beheerder maintains, like Sources, Mappings and Rules, which are app pages too. The schema's authorization keeps editing admin-only. ADR-079 places instance configuration in the admin settings section; whether this table counts as that is an open question for review.

Grouping the unmapped list needs a count per `imowId` over `mappedActivities` items. If OpenRegister's `x-openregister-aggregations` cannot group on a nested array item, the page falls back to listing verzoeken with `activityUnmapped` and the modal reads the identifiers from the chosen verzoek. Task 4.2 decides which, by trying the aggregation first (ADR-031).

### D6. Demo data, not seed data

The mock register gets three demo rows: one single case type, one with two case types, one with a samenloop rule. Their identifiers use the bevoegd gezag number `0000` (`nl.imow-gm0000.activiteit.DemoBouwen` and similar) and their names start with "Demo:". CBS does not use gemeentecode 0000, so a demo row cannot match a real verzoek by accident. The production register ships an empty table.

REQ-DSO-010's "Load default mappings" button is dropped. There is no list to load. The unmapped list of D5 replaces it: the first real verzoeken show which activities the gemeente needs.

## Rejected alternatives

### Rejected: replace the placeholders with real codes

There is no published static list (see Research). Any list we typed in would be invented, would cover one gemeente at best, and would go stale when a regeling changes.

### Rejected: sync the table from an RTR or Ozon API now

It is the right long-term source, but every API needs a DSO API key per organisation, and the mapping to a case type is still the gemeente's own decision. A sync would fill `activityName` and identifiers only. It can be added later as a source of type `dso` with a synchronization into `dso_activity_mapping`, leaving `caseTypes` to the beheerder.

### Rejected: let intake create unassigned rows

Intake could write a row without case types for every unknown activity. That makes the intake write admin configuration as the intake account, and fills the table with half rows. Reading the unknown activities from the verzoeken keeps the table clean and the intake's rights small.

### Rejected: key on `Activiteit-id` only

It is up to 6144 characters and has no pattern. The imow-id has a pattern and, per STAM footnote 19, is used across the whole DSO chain. `activityId` stays as a fallback for verzoeken without an imow-id.

### Rejected: an `IAppConfig` JSON blob

A blob has no history, no audit trail, no per-row validation and no list UI. REQ-DSO-010 asks for OpenRegister objects.

## Risks / trade-offs

- **The STAM XML field names are not verified here.** Task 1.1 needs a real pre-production verzoekbericht. Until then the parser change cannot be finished.
- **Identifier stability is unknown.** If a gemeente's activity gets a new imow-id when its omgevingsplan changes, its row stops matching. The verzoek is then flagged unmapped and shows up in the unmapped list, so the gap is visible, not silent.
- **Grouping by a nested field may need OpenRegister work.** D5 names the fallback.

## Migration

- `getDefaultMappings()` disappears. Its codes never matched a real verzoek, so no real mapping is lost.
- Existing `dso_verzoek` records keep their `mappedActivities` as written. New fields are optional, so old items stay valid.
- A fresh table is empty. The first verzoeken land in the unmapped list for the beheerder to map.
