# Tasks: openconnector-register-schema-declaration

## Implementation Tasks

### Task 1: Author the register descriptor file
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-001`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh repo checkout WHEN inspecting `lib/Settings/` THEN
    `openconnector_register.json` exists and parses as valid JSON
  - GIVEN the file is parsed WHEN inspecting `components.registers` THEN
    exactly one register exists with slug `openconnector`
  - GIVEN the file is parsed WHEN inspecting `components.schemas` THEN all 15
    schema slugs are declared (the 11 mutable + 4 log slugs listed in REQ-002)
  - The OpenAPI `info`, `x-openregister`, `components.registers.openconnector`
    blocks match the file-format contract in `contract.md` line-for-line
- [ ] Implement
- [ ] Test

### Task 2: Transcribe each entity into its schema definition
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-002`
- **files**: `lib/Settings/openconnector_register.json` (sub-blocks for each of 15 schemas)
- **acceptance_criteria**:
  - GIVEN each `lib/Db/<EntityName>.php` (Source, Consumer, Endpoint, Event,
    EventMessage, EventSubscription, Job, Mapping, Rule, Synchronization,
    SynchronizationContract, CallLog, JobLog, SynchronizationLog,
    SynchronizationContractLog) WHEN compared against its matching schema
    THEN every protected property is present
  - GIVEN PHP-to-JSON type mapping rules (string → string, integer → integer,
    boolean → boolean, json → object/array, datetime → string+format:date-time)
    WHEN inspecting each property type THEN it matches
  - Each schema declares `slug`, `title`, `version: "1.0.0"`, `summary`,
    `description`, `properties`, `required` (where applicable), `icon`
- [ ] Implement
- [ ] Test

### Task 3: Mark log schemas append-only + immutable, with retention
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-003`
- **files**: `lib/Settings/openconnector_register.json` (4 log schemas)
- **acceptance_criteria**:
  - GIVEN the 4 log schemas (`call_log`, `job_log`, `synchronization_log`,
    `synchronization_contract_log`) WHEN inspecting top-level flags THEN both
    `appendOnly: true` and `immutable: true` are set
  - GIVEN the 11 mutable schemas WHEN inspecting top-level flags THEN
    `appendOnly` and `immutable` are either `false` or omitted
  - GIVEN each log schema WHEN inspecting `x-openregister-archival` THEN it
    encodes `PT1H` for success-class entries and `P30D` for error-class
    entries (per REQ-004)
- [ ] Implement
- [ ] Test

### Task 4: Add FK relation annotations to all 6 integer foreign keys
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-005`
- **files**: `lib/Settings/openconnector_register.json` (call_log, event_message, synchronization_contract_log)
- **acceptance_criteria**:
  - GIVEN `call_log` WHEN inspecting `properties.source`, `properties.synchronization`
    THEN both carry `type: "string"`, `format: "uuid"`, and `$ref` to the
    target schema
  - GIVEN `event_message` WHEN inspecting `properties.event`,
    `properties.consumer`, `properties.subscription` THEN all three carry the
    same shape
  - GIVEN every relation WHEN inspecting `onDelete` THEN it matches the table
    in REQ-005 (CASCADE / SET NULL per relation)
  - Legacy `*Id` fields are retained as sibling properties with their original
    types (REQ-008)
- [ ] Implement
- [ ] Test

### Task 5: Document Synchronization.sourceId/targetId overload
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-006`
- **files**: `lib/Settings/openconnector_register.json` (synchronization schema)
- **acceptance_criteria**:
  - GIVEN the `synchronization` schema WHEN inspecting `properties.sourceId`
    and `properties.targetId` THEN both carry `type: "string"`, NO `$ref`
  - The `description` field on each MUST enumerate the three valid formats
    (integer PK string, `register/schema` slug pair, uuid) and reference
    `lib/Service/SynchronizationService.php` for resolution logic
- [ ] Implement
- [ ] Test

### Task 6: Author the seed data file
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-007`
- **files**: `lib/Settings/openconnector_seed_data.json`
- **acceptance_criteria**:
  - GIVEN the file is parsed WHEN inspecting top-level keys THEN they are a
    subset of the 11 mutable schema slugs (no log slug appears)
  - GIVEN each mutable schema WHEN counting its seed objects THEN there are 3
    to 5 objects
  - GIVEN each seed object WHEN inspecting `@self` THEN `register` is
    `"openconnector"`, `schema` matches the parent key, `slug` is set
  - GIVEN every seed `source` object WHEN inspecting `apikey`, `password`,
    `secret`, `jwt`, `authenticationConfig` THEN values are safe placeholders
    (`"YOUR_API_KEY_HERE"`, `"<placeholder>"`, etc.)
  - Seed entries match the per-schema tables in `design.md` § Seed Data
- [ ] Implement
- [ ] Test

### Task 7: Validate descriptor against a dev OpenRegister instance
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-001`
- **files**: (no source code change — validation only)
- **acceptance_criteria**:
  - GIVEN a clean nextcloud-docker-dev environment with openregister enabled
    WHEN running
    `docker exec nextcloud php occ openregister:import /var/www/html/custom_apps/openconnector/lib/Settings/openconnector_register.json`
    (or the equivalent `ConfigurationService::importFromApp` invocation)
    THEN the command exits 0
  - GIVEN the post-import state WHEN running
    `SELECT count(*) FROM oc_openregister_schemas WHERE register IN (SELECT id FROM oc_openregister_registers WHERE slug='openconnector')`
    THEN the count is exactly 15
  - GIVEN the post-import state WHEN querying log schemas THEN their
    `appendOnly` and `immutable` columns are both `true`
  - Validation is documented in the PR description (no automated test wired
    up — this is a manual dev-env check)
- [ ] Implement
- [ ] Test

### Task 8: Add a CI guard that re-validates schema↔entity coverage
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-002`
- **files**: `.github/workflows/openspec-validate.yml` (extend existing) or
  `tests/Unit/Settings/RegisterDescriptorTest.php` (new)
- **acceptance_criteria**:
  - GIVEN a PHPUnit unit test WHEN running `composer test`
    THEN it iterates all 15 `lib/Db/*.php` entities, reflects their protected
    fields, and asserts each appears as a property on the matching schema in
    `openconnector_register.json`
  - GIVEN a missing/typo'd property WHEN the test runs THEN it fails with a
    diagnostic naming the entity, field, and schema slug
- [ ] Implement
- [ ] Test

## Verification

- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria (Task 7 dev-env smoke test)
- [ ] Code review against spec requirements
- [ ] CI green (lint, schema validation)

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) —
  Task 8 adds `RegisterDescriptorTest.php` covering entity↔schema coverage
- [ ] Newman/Postman tests for new/changed API endpoints — **N/A**: this
  change introduces no HTTP endpoints
- [ ] Browser tests (Playwright MCP) for UI changes — **N/A**: no UI changes
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — add
  `docs/architecture/register-schema.md` describing the descriptor layout, the
  declarative `appendOnly` + `immutable` flags, and the chain split with the
  storage change
- [ ] Screenshot captured and committed to `docs/images/` — **N/A**: no UI

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added —
  **N/A**: schema/property descriptions live inside the descriptor in English;
  no new user-facing strings reach `l10n/`
