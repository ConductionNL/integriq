# Proposal: dso-activity-mapping-table

kind: code. Cites **ADR-022** (apps consume OpenRegister abstractions), **ADR-031** (declare behaviour in the schema), **ADR-004** (frontend rules) and **ADR-037** (manifest and register fragments).

## Why

REQ-DSO-010 says the activiteit-to-zaaktype table is "stored as OpenRegister objects" and edited by an administrator. Today it is a PHP array: `DsoActivityMapper::getDefaultMappings()`. Its 25 codes (`bouwen-01`, `kappen-01`, …) are placeholders. No real DSO verzoek carries them, so on a live instance every activiteit maps to nothing and every verzoek is flagged `activityUnmapped`.

The table cannot be fixed by replacing the placeholders with real codes, because there is no fixed list to ship:

- STAM v6.0.2 (section 3.7, p. 27) defines a Project Activiteit by an `Activiteit-id` ("gebaseerd op de functionele structuurreferentie"), an `imow-id`, an `Activiteitnaam` and a `Volgnr`. The `Imow-id` pattern (p. 46) is `nl.imow-(gm|pv|ws|mn|mnre)[0-9]{1,6}.Objecttype.[A-Za-z0-9]{1,32}`. The prefix names who defines the activity: a gemeente, provincie, waterschap or ministerie.
- The same section says the list of available activities is fixed per location ("De lijst van beschikbare activiteiten ligt vast, en is ook aan de locatie gebonden"). A gemeente's own omgevingsplan adds its own activities.
- The DSO publishes activities only through APIs that need an API key: RTR gegevens raadplegen, Toepasbare regels zoeken, and Omgevingsdocument toepasbaar opvragen (resource "Activiteiten"). We found no static, public, downloadable list. Sources are in design.md.

So the table must be data an administrator fills, keyed on the identifiers STAM really carries. And the activities the gemeente actually receives must show up for that administrator to map.

## What changes

- **A `dso_activity_mapping` schema.** One row per DSO activity: its `imowId`, `activityId` and `activityName`, one or more case types, a default samenloop strategy, optional samenloop rules per activity combination, and `isActive`.
- **One activity can map to several case types.** Each case type entry has a reference, a title and the afdeling that handles it. That covers the one-to-many case of REQ-DSO-010 and the routing of REQ-DSO-011.
- **The mapper reads the table.** `DsoActivityMapper` loads the active rows once per verzoek and matches each activiteit on `imowId`, then on `activityId`. `getDefaultMappings()` and its placeholder codes are deleted.
- **The parser keeps the STAM identifiers.** `DSOParserService` returns `imowId`, `activityId`, `activityName` and `volgnr` per activiteit, plus the onderliggende activiteit when present. `dso_verzoek.mappedActivities` records them.
- **A section on the admin settings page** (Ruben, 2026-10-04, ADR-079). "DSO activities" lists, adds, edits and deactivates rows. "Unmapped DSO activities" lists the activities seen on verzoeken that no row maps, with how often each was seen, and opens the add form prefilled.
- **No real codes are seeded.** Demo data in the mock register shows the shape with identifiers that are visibly not real.

## Capabilities

### Modified Capabilities

- `dso-omgevingsloket`: REQ-DSO-010 (the table, keyed on STAM identifiers, no shipped codes), REQ-DSO-011 (samenloop rules come from the table), REQ-DSO-004 (the parser keeps the STAM activity identifiers). New REQ-DSO-012 (the admin screen and the unmapped list).

## Impact

- New: `lib/Settings/register.d/dso-activity-mapping.json` (schema, authorization), `DsoActivityTable`, `DsoActivityMappingGuardListener`, `DsoUnmappedActivities` and `DsoActivityMappingController` (`GET /api/admin/dso-activities/unmapped`), `src/views/admin/DsoActivityMappingSettings.vue`, `src/dialogs/DsoActivityMappingDialog.vue`, demo rows in `lib/Settings/integriq_mock_register.json`.
- Changed: `DsoActivityMapper` (reads OpenRegister, no built-in table), `DSOParserService::parseActiviteiten()`, `DsoRequestTranslator` (reads the new activity names), `dso_verzoek.mappedActivities` item shape (both registers), `AdminSettings.vue`, `l10n/*.json`.
- Removed: `DsoActivityMapper::getDefaultMappings()` and `defaultMappingTable()`, and the tests that pin the placeholder codes.
- Works with or without `dso-intake-through-an-integriq-connection`. With it, the table is read while the intake acts as the connection's account.
