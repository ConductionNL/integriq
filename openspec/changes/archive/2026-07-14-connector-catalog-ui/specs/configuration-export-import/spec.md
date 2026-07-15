# configuration-export-import Specification (delta: connector-catalog-ui)

## ADDED Requirements

### Requirement: REQ-006 — Export a configuration from the UI

The system SHALL expose the existing `ConfigurationService::exportConfiguration()` (REQ-001–REQ-005, unchanged) through a routed `POST /api/configurations/{id}/export` endpoint and a Configuration UI page action, so an operator can download a redacted configuration document without using the API directly. The endpoint SHALL be gated by OpenConnector's existing `ActionAuthService::requireAction()` (ADR-023) with a new action key `configuration.export`, seeded `["admin"]` in the existing `lib/actions.seed.json`.

Notes: This requirement adds reachability only; it does not change REQ-001–REQ-005's export, slug-translation, or redaction behaviour, including the documented substring-match redaction gap (REQ-005 Notes) and the O(all entities) cost note (REQ-001 Notes).

#### Scenario: Exporting a configuration from the UI produces a redacted downloadable file
- GIVEN a configuration group containing a Source with `apikey = "live_xyz"`
- WHEN an operator with the `configuration.export` action permission clicks "Export" on that configuration in the UI
- THEN the browser downloads a JSON file
- AND the file does not contain `apikey`, `secret`, or any other REQ-005 redacted field

#### Scenario: A user without the configuration.export action permission cannot export
- GIVEN a non-admin user whose groups are not mapped to `configuration.export` in the admin-configured action matrix (admins always pass `requireAction()` — documented break-glass behaviour)
- WHEN that user calls the export endpoint
- THEN the request is rejected with `OCSForbiddenException` and no file is produced
- @e2e exclude API-level action-matrix denial (no UI surface for an unmapped user) — covered by PHPUnit `ConfigurationControllerTest::testExportDeniedForUnmappedNonAdmin`

### Requirement: REQ-007 — Preview an import before writing anything

The system SHALL expose a non-mutating `POST /api/configurations/import/preview` endpoint that, given an OAS document, computes and returns the same creates/updates/collisions classification that `importConfiguration()` (REQ-003) would perform, plus the set of unresolved slug references (REQ-004's "left verbatim" case) that would result, WITHOUT calling `saveObject()` on any entity. The preview SHALL mirror the existing import pipeline's slug-resolution semantics — the same per-schema slug maps `ConfigurationService::buildSchemaSlugMaps()` builds over the target environment, and the handlers' reference-field vocabulary (top-level `source_id`/`target_id`, endpoint `targetId`/`inputMapping`/`outputMapping`/`rules[]`, and the nested-configuration `<type>`/`<type>Id` key convention) — rather than inventing new resolution rules. (Adjusted at apply time: the handlers expose no dry-run mode and `resetMappings()` is private, so the preview lives in a dedicated read-only `ConfigurationImportPreviewService` that replicates the maps via the same OR `findAll` reads instead of calling the mutating `import()` path.)

#### Scenario: Preview classifies creates, updates and collisions
- GIVEN an OAS document containing one Source whose slug exists in the target environment and one Source whose slug does not
- WHEN `POST /api/configurations/import/preview` is called with that document
- THEN the response lists the existing-slug Source under `updates` and the new-slug Source under `creates`
- AND no Source object is created or modified by the preview call

#### Scenario: Preview surfaces an unresolvable slug reference as a blocking warning
- GIVEN an OAS document containing a Rule whose nested configuration references a Source slug that does not exist in the target environment (the REQ-004 "unresolvable slug is left verbatim" case)
- WHEN the import is previewed
- THEN the response's `unresolvedReferences` array contains that Rule's slug and the unresolved field
- AND the import UI marks this as a blocking warning requiring explicit operator acknowledgement before the import can be confirmed

### Requirement: REQ-008 — Import requires explicit confirmation after preview

The system SHALL require a `confirmed: true` flag on `POST /api/configurations/import` and SHALL reject the request with HTTP 400 if it is absent, so that no import write occurs without the caller having first retrieved and (per the UI) displayed a preview. Both the import and preview endpoints SHALL be gated by the existing `ActionAuthService::requireAction()` (ADR-023) with a new action key `configuration.import` seeded `["admin"]` in the existing `lib/actions.seed.json`, and the underlying entity writes SHALL continue to pass through each entity type's existing OpenRegister data-layer authorization unchanged (e.g. Source writes remain admin-only per the `source` schema lock).

#### Scenario: Import without confirmation is rejected
- GIVEN a valid OAS document
- WHEN `POST /api/configurations/import` is called with `confirmed` omitted or `false`
- THEN the response is HTTP 400
- AND no entity is created or updated
- @e2e exclude raw-HTTP 400 guard (the UI always previews first, so this path has no browser surface) — covered by PHPUnit `ConfigurationControllerTest::testImportWithoutConfirmationReturns400` / `testImportWithConfirmedFalseReturns400`

#### Scenario: Confirmed import proceeds and reuses the existing import pipeline unchanged
- GIVEN a valid OAS document and `confirmed: true`
- WHEN `POST /api/configurations/import` is called
- THEN the system delegates to the existing `ConfigurationService::importConfiguration()` (REQ-003) unmodified
- AND the response reflects what was actually created and updated

### Requirement: REQ-009 — Imported Sources with redacted credentials are flagged for re-entry

The system SHALL, in both the preview and post-import response, list every imported Source object whose credential fields were stripped by REQ-005's redaction (i.e. every Source in the import document, since export always redacts) under `credentialsNeedingReentry`, naming the fields that require operator re-entry, so the UI can direct the operator to the Source's edit form after import completes.

#### Scenario: A newly created Source from import is flagged for credential re-entry
- GIVEN an OAS document containing a Source with no `apikey`/`secret`/`username`/`password` fields (because REQ-005 stripped them on export)
- WHEN the import is confirmed and the Source is created
- THEN the response's `credentialsNeedingReentry` array contains that Source's slug and the list of credential field names it is missing
- AND the created Source object itself contains no credential values, matching the existing REQ-005 "imported source has no credentials and needs re-entry" scenario
