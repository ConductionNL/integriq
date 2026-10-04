## 1. What a verzoek really carries

- [ ] 1.1 Obtain one real STAM verzoekbericht from the DSO pre-production environment (or the STAM verzoekbericht XSD from the developer portal) and record, in this file, the element names that carry `Activiteit-id`, `imow-id`, `Activiteitnaam`, `Volgnr` and the onderliggende activiteit, plus one real `imow-id` value. Blocks 1.2 and the `imowId` pattern. Do not guess the names
- [ ] 1.2 Make `DSOParserService::parseActiviteiten()` return `imowId`, `activityId`, `activityName`, `volgnr` and `underlying`, keeping `code` as the `activityId` fallback. Verify in `DSOParserServiceTest` with the recorded real shape and with the old `code` shape

## 2. The schema

- [ ] 2.1 Add `dso_activity_mapping` (D1) in `lib/Settings/register.d/dso-activity-mapping.json` with its admin-only authorization, and verify through `RegisterSchemaValidator`: a full row saves; a row without `caseTypes`, or with neither `imowId` nor `activityId`, is refused; an `imowId` off the STAM pattern is refused
- [ ] 2.2 Refuse a second active row with the same `imowId`, and verify it in PHPUnit
- [ ] 2.3 Extend the `dso_verzoek.mappedActivities` item with `imowId`, `activityId`, `activityName`, `volgnr`, `caseTypes` in both registers, optional, and verify an old item without them still validates

## 3. The mapper reads the table

- [ ] 3.1 Load the active `dso_activity_mapping` rows once per `mapRequest()` call (engine read, read only) and match per D2. Verify in `DsoActivityMapperTest`: match on `imowId`; fallback on `activityId`; the onderliggende activiteit wins over its parent; an inactive row is ignored; no row means `mapped: false`
- [ ] 3.2 Map one activity to several case types: `mappedCaseTypes` holds all of them once, and the entry records each with its department. Verify in PHPUnit
- [ ] 3.3 Apply `samenloopRules` per D3, and verify: a `gecombineerd` rule for the pair gives `gecombineerd`; one `deelzaken` rule gives `deelzaken`; no rules falls back to today's rule
- [ ] 3.4 Delete `getDefaultMappings()` and `defaultMappingTable()` with the tests that pin placeholder codes, and verify `git grep -n "bouwen-01" -- lib tests` returns nothing
- [ ] 3.5 Verify through `DsoIngestServiceTest` that ingest writes the table's result onto the `dso_verzoek`, red before 3.1

## 4. The admin screen

- [ ] 4.1 Add `src/manifest.d/dso-activity-mapping-table.json` with the "DSO activities" index page and menu entry in the Connections group, and `src/modals/DsoActivityMappingModal.vue` for add and edit, with en and nl strings through `l10n/*.json` and `npm run l10n:build`. Verify with `tests/e2e/dso-activity-mapping.spec.ts`: add a row with two case types, edit it, deactivate it
- [ ] 4.2 Add the "Unmapped DSO activities" view (D5). Try `x-openregister-aggregations` grouped by `imowId` first; record in this file whether it works or the fallback was used. Verify with the same e2e file: an unmapped activity on a verzoek appears, and its row action opens the modal prefilled
- [ ] 4.3 Run the hydra gates `--scope-to-diff` and verify gates 26, 53, 62, 63, 101 and 102 pass for the new page and schema

## 5. Demo data

- [ ] 5.1 Add three demo rows (D6) to `lib/Settings/integriq_mock_register.json`, with gemeentecode `0000` and names starting with "Demo:", and verify the production register ships no `dso_activity_mapping` rows

## 6. Proof

- [ ] 6.1 On a throwaway instance with an identity for the intake (change `dso-intake-through-an-integriq-connection`, or an admin session if that has not landed): push a signed verzoek with two activiteiten whose identifiers no row maps. Verify both appear in "Unmapped DSO activities". Map one with two case types in the screen, push again, and verify the new verzoek's `mappedCaseTypes` holds both and `activityUnmapped` is still true for the other
- [ ] 6.2 Run `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint` once before push, and record the exit codes in the PR body
