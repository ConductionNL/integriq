# Tasks: connections-declare-data-residency

Kind: code. Size M. Woo row 17.11. Wave 2.

Every test named here fails on `development` today: sources have no
`residency`, the validator refuses the key, and no destinations endpoint or
EU-only check exists.

## Implementation tasks

### Task 1: Residency on the source and in the declaration
- **spec_ref**: `openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001`
- **files**: `lib/Settings/integriq_register.json` (source `residency` object; register version bump), `lib/Settings/connections.schema.json` (optional `residency` with `country` and `evidence`), `lib/Service/ConnectionDeclarationValidator.php` (mirror the schema rule for rule, as its docblock promises), `lib/Service/ConnectionRegistryService.php` (`sync()` copies a declared residency onto the linked source unless one is `set-by-admin`), `lib/Service/Residency/ResidencyZones.php` (EU 27 and EEA 3 as constants, `zoneOf(?string $country): string`)
- **acceptance_criteria**:
  - GIVEN a declaration without `residency` WHEN validated THEN it passes, as today
  - GIVEN `residency.country` `XX` WHEN validated THEN the file is refused naming the path
  - GIVEN a linked source with an admin residency WHEN the sync runs with a declared one THEN the admin one stays
  - GIVEN `NO`, `NL`, `US` and null WHEN `zoneOf()` runs THEN `eea`, `eu`, `outside`, `unknown`
- [ ] Implement
- [ ] Test: PHPUnit `ConnectionDeclarationValidatorTest::testResidencyIsOptional`, `::testAnInvalidCountryIsRefused`; `ConnectionRegistryServiceTest::testADeclaredResidencyIsCopiedToTheLinkedSource`, `::testAnAdminResidencyWins`; `ResidencyZonesTest::testZones`
- [ ] A test that `connections.schema.json` and `ConnectionDeclarationValidator` agree on `residency` (feed the same valid and invalid fixture to both), so the two never drift.

### Task 2: The administrator sets residency on the source page
- **spec_ref**: `openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001`
- **files**: the source detail page (a residency section with country picker and evidence), `lib/Controller/SourceResidencyController.php` (`PUT /api/sources/{id}/residency`, admin checked in the method, the route's auth attribute matching), `appinfo/routes.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN she saves `NL` with evidence THEN `basis` is `set-by-admin`, `recordedBy` is her uid, and the audit trail has the change
  - GIVEN a non-administrator WHEN the route is called THEN 403
- [ ] Implement. The country picker is an `NcSelect` with `inputLabel`.
- [ ] Test: PHPUnit `SourceResidencyControllerTest::testAnAdminSetsResidency`, `::testANonAdminIsRefused`; the route-reachability gate resolves the new route

### Task 3: The destinations endpoint and page
- **spec_ref**: `openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002`
- **files**: `lib/Service/Residency/EgressDestinationService.php` (`list(): array` with keys `destinations`, `verdict`, `counts`), `lib/Controller/EgressDestinationController.php` (`GET /api/egress/destinations`), `appinfo/routes.php`, `src/manifest.json` (a page under the Connections menu group), `src/views/admin/EgressDestinations.vue` or a manifest `index` page over the endpoint, `docs/features/data-residency.md`
- **acceptance_criteria**:
  - GIVEN sources in `NL`, `DE`, `FR` and no other host WHEN listed THEN `verdict` is `eu-only`
  - GIVEN one source without residency WHEN listed THEN `verdict` is `not-proven` and `counts.unknown` is 1
  - GIVEN a call-log host matching no source WHEN listed THEN it is a row with `sourceId` null and zone `unknown`
  - GIVEN a source in `NO` WHEN listed THEN zone `eea` and `not-proven`
  - GIVEN the page WHEN rendered THEN it states that calls made by other apps without integriq are not covered
- [ ] Implement. Read call-log hosts with one grouped query over the last 30 days, not by loading every record.
- [ ] Test: PHPUnit `EgressDestinationServiceTest::testAnUnknownDestinationBlocksTheVerdict`, `::testACallLogHostWithoutASourceIsListed`, `::testAnEeaCountryIsNotCountedAsEu`, `::testAllEuGivesEuOnly`; `EgressDestinationControllerTest::testANonAdminIsRefused`. Double OpenRegister's `ObjectService` with `environmentAwareDouble` after reading its real `findAll()` signature on `openregister` `development`.
- [ ] Wiring: the controller test goes through the route name from `appinfo/routes.php`, and the Playwright spec opens the page from the Connections menu, so the page is reachable from the navigation and not only by URL.

### Task 4: EU-only mode
- **spec_ref**: `openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-an-administrator-can-enforce-eu-only-egress-req-res-003`
- **files**: `lib/Service/CallService.php` (check before `dispatchRequest()` sends; record a `refused-residency` call), `lib/Service/SettingsService.php` (`egress_eu_only`; refuse switching on while the verdict is `not-proven`), the destinations page (the switch)
- **acceptance_criteria**:
  - GIVEN the mode on and a source without residency WHEN called THEN no request is sent and a `refused-residency` record names `unknown`
  - GIVEN the mode on and a source in `NL` WHEN called THEN the request is sent
  - GIVEN the settings read throws and a source without residency WHEN called THEN the call is refused
  - GIVEN one unknown destination WHEN the mode is switched on THEN the save is refused naming it
  - GIVEN the mode off (the default) WHEN any source is called THEN behaviour is as today
- [ ] Implement
- [ ] Test: PHPUnit `CallServiceResidencyTest::testAnUnknownDestinationIsRefusedBeforeSending` (assert the mocked `IClientService` client receives no request), `::testAnEuDestinationIsCalled`, `::testABrokenSettingsReadRefuses`, `::testModeOffChangesNothing`; `SettingsServiceTest::testEuOnlyCannotBeSwitchedOnOverAGap`
- [ ] `testAnUnknownDestinationIsRefusedBeforeSending` must be shown failing on `development` before the change. Paste the failing line in the PR body.

### Task 5: The end-to-end page
- **spec_ref**: `openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002`
- **files**: `tests/e2e/data-residency.spec.ts`
- **acceptance_criteria**:
  - GIVEN the seeded sources WHEN an administrator sets residency on each THEN the destinations page reads "EU only"
  - GIVEN one source cleared WHEN she tries to switch EU-only mode on THEN the save is refused naming it
- [ ] Test: Playwright `tests/e2e/data-residency.spec.ts`

## Cross-app follow-up, not in this change

Record in the PR body: dossiq (the registry's first adopter) may then add
`residency` to its `connections.json` for BRP and the other national
endpoints, in a release that requires this integriq version, with a test on
the dossiq side that its file validates against the new schema.

## Verification

The building agent follows `openspec/woo-build-rules.md`:

- [ ] Own clone, `git checkout --no-track -b <branch> origin/development`, `TMPDIR` a sibling outside the clone.
- [ ] PHPUnit judged by the `Tests:` line, or with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`, `npm run check:register`.
- [ ] CI runs the gates on the full tree; the coverage guard needs a test for every added statement.
- [ ] `openspec validate connections-declare-data-residency --type change --strict` passes.
- [ ] One PR, `--base development`; merge `development` in, never rebase; no `Co-Authored-By` trailer.
- [ ] Done means merged on `development` with CI green. Row 17.11 is `production` only once a store release carries it.
