# Tasks: connectors-catalogue-expansion

Kind: code. Size M. Rows `integriq:con-backoffice`,
`integriq:con-library-size` and `buildiq:int-saas-connectors`.

### Task 1: The template library in the registry
- **spec_ref**: openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
- **files**: `lib/Service/CatalogRegistryService.php` (`collectFromTemplates()`, `findSeedSourcePayload()`), `lib/Settings/connector-templates/README.md`, `lib/Settings/register.d/catalog-item-schema.json` (1.1.0)
- **acceptance_criteria**:
  - GIVEN one template in the library WHEN `collect()` runs THEN it returns a card for it
  - GIVEN a fresh import WHEN sources are listed THEN no template became a source
  - GIVEN Instantiate on a template WHEN it runs THEN one source is created from the template payload
- [x] Implement
- [x] Test (PHPUnit on `CatalogRegistryService` against a fixture library)

### Task 2: Template validation
- **spec_ref**: openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-a-back-office-template-names-its-standard-and-where-it-was-checked-req-ccx-002
- **files**: `tests/validate-connector-templates.js`, `package.json` (script), `.github/workflows` caller if the lint job lists validators
- **acceptance_criteria**:
  - GIVEN a curated template without `verifiedAgainst` WHEN validation runs THEN it fails naming the file
  - GIVEN a generated template with a secret-shaped field WHEN validation runs THEN it fails
- [x] Implement
- [x] Test (the validator against good and bad fixtures)

### Task 3: The back-office set and its README
- **spec_ref**: openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-a-back-office-template-names-its-standard-and-where-it-was-checked-req-ccx-002
- **files**: `lib/Settings/connector-templates/backoffice/*.json`, `lib/Settings/connector-templates/backoffice/README.md`
- **acceptance_criteria**:
  - GIVEN the fourteen systems in Stein requirements 166859 and 186343 WHEN the README is read THEN each has a template file or a stated reason
  - GIVEN each curated template WHEN validated THEN it names a standard integriq speaks and cites where it was checked
- [x] Implement
- [x] Test (`tests/validate-connector-templates.js`; `tests/e2e/connector-catalogue.spec.ts`)

### Task 4: The generator and the first allow-list
- **spec_ref**: openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-generated-saas-templates-come-from-a-pinned-directory-and-a-reviewed-allow-list-req-ccx-003
- **files**: `scripts/generate-connector-templates.php`, `lib/Settings/connector-templates/saas/allow-list.json`, `lib/Settings/connector-templates/saas/snapshot/`, `lib/Settings/connector-templates/saas/*.json`
- **acceptance_criteria**:
  - GIVEN the snapshot and an allow-list with Google Sheets, Salesforce and Slack WHEN the generator runs THEN three templates are written with base URL, auth scheme and snapshot date
  - GIVEN an entry not on the allow-list WHEN the generator runs THEN nothing is written for it
- [x] Implement
- [x] Test (PHPUnit on the generator against a small snapshot fixture)

### Task 5: The honest count and the tier badge
- **spec_ref**: openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-the-store-counts-only-real-connectors-once-each-req-ccx-004
- **files**: `lib/Service/CatalogRegistryService.php`, `src/components/CatalogItemCard.vue`, `src/manifest.json` (Store page), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the seeds at this sha WHEN `collect()` runs THEN no `environment-` source is returned and SmartDocuments and Xential appear once
  - GIVEN a generated card WHEN it renders THEN it shows the generated badge and the snapshot date
- [x] Implement
- [x] Test (PHPUnit on `collect()`; `tests/e2e/connector-catalogue.spec.ts`)

## Verification

- `openspec validate connectors-catalogue-expansion --type change --strict`
- Instantiate one curated and one generated template on a local instance and
  run the source test action on each.
- `composer check:strict` and `npm run lint` once before push.
