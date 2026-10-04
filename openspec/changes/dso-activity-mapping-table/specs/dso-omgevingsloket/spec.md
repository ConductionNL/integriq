## MODIFIED Requirements

### Requirement: Activiteiten-to-Zaaktype Mapping (REQ-DSO-010)

The adapter MUST map DSO activiteiten to zaaktypen through a mapping table stored as `dso_activity_mapping` OpenRegister objects. A row MUST identify a DSO activity by the identifiers a STAM verzoekbericht carries: its `imowId` and, as a fallback, its `activityId` (the functionele structuurreferentie). The mapper MUST match the onderliggende activiteit before its parent, then the `imowId`, then the `activityId`, and MUST ignore inactive rows. One row MUST be able to map to one or more zaaktypen, each with the afdeling that handles it. The adapter MUST NOT ship real DSO activity codes, because no public static list exists; the production table MUST start empty.

@e2e exclude backend DSO/Omgevingsloket STAM integration: covered by PHPUnit, not browser UI

#### Scenario: One-to-one activiteit mapping creates zaak
- **WHEN** a row maps imowId "nl.imow-gm0000.activiteit.DemoBouwen" to zaaktype "Omgevingsvergunning Bouwen" and a verzoek contains that activiteit
- **THEN** the verzoek's `mappedCaseTypes` holds "Omgevingsvergunning Bouwen" and its `mappedActivities` entry is `mapped: true`

#### Scenario: One-to-many mapping creates multiple deelzaken
- **WHEN** a row maps one activity to both "Omgevingsvergunning Milieu" and "Omgevingsvergunning Bouwen", each with its own afdeling, and a verzoek contains this activiteit
- **THEN** `mappedCaseTypes` holds both zaaktypen, and the `mappedActivities` entry records each with its afdeling

#### Scenario: The fallback identifier matches
- **WHEN** a verzoek's activiteit has no imowId, and a row has the same `activityId`
- **THEN** the activiteit is mapped by that row

#### Scenario: The onderliggende activiteit wins
- **WHEN** a verzoek's activiteit has an onderliggende activiteit, and rows exist for both
- **THEN** the row of the onderliggende activiteit decides the zaaktypen

#### Scenario: Empty mapping table seeds defaults
- **WHEN** the mapping table is empty (fresh install) and an administrator opens the "DSO activities" section
- **THEN** no default rows are loaded, because no public list of DSO activity codes exists to load them from
- **AND** the section points to "Unmapped DSO activities", where the activities of real verzoeken appear

#### Scenario: A verzoek that maps nothing stores an empty case type list
- **WHEN** no active row maps any activiteit of a verzoek
- **THEN** the verzoek's `mappedCaseTypes` is stored as an empty list `[]`, not `null`
- **AND** `activityUnmapped` is true and no `samenloopStrategy` is set
- **AND** a read through OpenRegister's object API shows the empty list only with `_empty=true`, because OpenRegister leaves empty values out of a read by default

#### Scenario: A fresh install ships no activity codes
- **WHEN** Integriq is installed on a new instance
- **THEN** the `dso_activity_mapping` table holds no rows

#### Scenario: Modified mapping applied to next verzoek
- **WHEN** an administrator changes the zaaktype of a row and the next verzoek with that activity arrives
- **THEN** the updated zaaktype is used

### Requirement: Samenloop Handling (REQ-DSO-011)

The adapter MUST support samenloop: when one DSO-verzoek contains multiple activiteiten, the adapter creates either multiple deelzaken under one hoofdzaak or one combined zaak. The strategy MUST come from the mapping table: a `samenloopRules` entry for an activity combination decides for that pair, and without rules every mapped row's own `samenloopStrategy` applies, giving `gecombineerd` only when all of them say so.

@e2e exclude backend DSO/Omgevingsloket STAM integration: covered by PHPUnit, not browser UI

#### Scenario: Deelzaken strategy creates hoofdzaak plus deelzaken
- **WHEN** a verzoek contains two mapped activities whose rows say "deelzaken" and no rule covers the pair, and the adapter processes the verzoek
- **THEN** the verzoek's `samenloopStrategy` is "deelzaken", and the handoff creates one hoofdzaak plus two deelzaken sharing aanvrager and locatie data

#### Scenario: Gecombineerd strategy creates one combined zaak
- **WHEN** a verzoek contains two mapped activities whose rows both say "gecombineerd", and the adapter processes the verzoek
- **THEN** the verzoek's `samenloopStrategy` is "gecombineerd", and the handoff creates one combined zaak with both activiteiten as zaak-eigenschappen

#### Scenario: A samenloop rule combines a pair
- **WHEN** a verzoek contains two mapped activities whose rows say "deelzaken", and one row has a samenloop rule "gecombineerd" for the other
- **THEN** the verzoek's `samenloopStrategy` is "gecombineerd"

#### Scenario: Hoofdzaak stays open until all deelzaken besluit
- **WHEN** a verzoek has a samenloop where one deelzaak is afgerond but another is still in behandeling and the behandelaar marks the first deelzaak as "Besluit genomen"
- **THEN** the hoofdzaak status remains "In behandeling" until all deelzaken have a besluit

#### Scenario: Deelzaken routed to configured afdelingen
- **WHEN** samenloop results in deelzaken whose zaaktypen carry different afdelingen in the mapping table
- **THEN** each `mappedActivities` entry records the afdeling per zaaktype, so the handoff can route each deelzaak

### Requirement: Verzoek Payload Parsing (REQ-DSO-004)

The adapter MUST parse the DSO-verzoek XML/JSON payload into structured data including aanvrager (initiatiefnemer), locatie, activiteiten, bijlagen, and projectbeschrijving. Per activiteit the parser MUST keep the STAM identifiers `imowId`, `activityId`, `activityName` and `volgnr`, and the onderliggende activiteit when present. Parsing uses configurable mapping rules stored as OpenRegister mapping objects so municipalities can adapt field extraction to their internal data model.

@e2e exclude backend DSO/Omgevingsloket STAM integration: covered by PHPUnit, not browser UI

#### Scenario: Aanvrager block mapped via BRP mapping
- **WHEN** a DSO-verzoek payload contains an aanvrager with BSN, naam, adres, and contactgegevens and the parser extracts the aanvrager block
- **THEN** each field is mapped to the corresponding OpenRegister object property using the configured BRP-to-zaak mapping

#### Scenario: Locatie validated and geometry converted
- **WHEN** a verzoek payload contains a locatie with BAG-adresgegevens and GML-geometrie and the parser extracts locatie data
- **THEN** the BAG-adres is validated against the BAG register (via Integriq source), the GML geometry is converted to GeoJSON, and both are stored on the zaak

#### Scenario: Activiteiten array tagged with zaaktypen
- **WHEN** a verzoek payload contains multiple activiteiten and the parser processes the activiteiten array
- **THEN** each activiteit keeps its imowId, activityId, activityName and volgnr, is looked up in the `dso_activity_mapping` table, and is tagged with its zaaktypen

#### Scenario: The old integriq code field still parses
- **WHEN** a payload carries an activiteit with only a `code` field
- **THEN** the parser returns that value as `activityId`

#### Scenario: Projectbeschrijving stored with extracted references
- **WHEN** a verzoek contains a `projectbeschrijving` free-text field with embedded references and the parser processes this field
- **THEN** the text is stored verbatim as a zaak-eigenschap and references are extracted as linked metadata

#### Scenario: STAM version mismatch auto-detected
- **WHEN** the DSO payload format changes between STAM API versions and the adapter receives a payload with a version mismatch
- **THEN** it attempts parsing with the configured version, falls back to auto-detection, and logs a version warning if parsing succeeds on a different version

## ADDED Requirements

### Requirement: Administrators maintain the activity table on the admin settings page (REQ-DSO-012)

Integriq MUST offer a "DSO activities" section on its admin settings page (`/settings/admin/integriq`, ADR-079: the table is instance configuration) where an administrator lists, adds, edits and deactivates `dso_activity_mapping` rows, including several zaaktypen per row and samenloop rules. The same section MUST list "Unmapped DSO activities": the activities seen on recent verzoeken that no active row maps, with how often and when each was last seen, and a row action that opens the add form prefilled with the activity's identifiers and name. Only administrators MAY create, change or delete rows.

#### Scenario: Add a row with two zaaktypen
- **GIVEN** an administrator opens the "DSO activities" section
- **WHEN** they add a row with an imowId, an activity name and two zaaktypen, and save
- **THEN** the row appears in the list with both zaaktypen
- @e2e tests/e2e/dso-activity-mapping.spec.ts

#### Scenario: An unmapped activity can be mapped from the list
- **GIVEN** a verzoek arrived with an activity that no row maps
- **WHEN** an administrator opens the "Unmapped DSO activities" list and chooses that activity's action
- **THEN** the add form opens with its imowId, activityId and name filled in
- @e2e tests/e2e/dso-activity-mapping.spec.ts

#### Scenario: A second active row with the same imowId is refused
- **GIVEN** an active row with imowId "nl.imow-gm0000.activiteit.DemoBouwen"
- **WHEN** an administrator creates or updates another active row with that imowId
- **THEN** the save is refused with HTTP 422, and the body's `errors` carries code `dso_activity_imow_id_taken`, the message and status 409
- **AND** the 422 is an OpenRegister limitation: its object API answers every create or update refused by a save listener with 422, whatever status the listener names
- **AND** an inactive row with the same imowId saves
- @e2e exclude refusal on OpenRegister's save path: covered by PHPUnit (DsoActivityMappingGuardListenerTest) and the live proof in tasks.md 6.1

#### Scenario: A row without an identifier is refused
- **WHEN** an administrator saves a row with neither an imowId nor an activityId
- **THEN** the save is refused with HTTP 422, and the body's `errors` carries code `dso_activity_identifier_missing`, the message and status 400
- @e2e exclude refusal on OpenRegister's save path: covered by PHPUnit (DsoActivityMappingGuardListenerTest) and the live proof in tasks.md 6.1

#### Scenario: A non-administrator cannot change the table
- **GIVEN** a user outside the admin group
- **WHEN** they try to create or change a `dso_activity_mapping` row through any API
- **THEN** OpenRegister refuses the write
- @e2e exclude RBAC refusal is enforced by OpenRegister's schema authorization: covered by PHPUnit against the register fragment
