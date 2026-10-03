# Tasks: connectors-service-desk-templates

Kind: config, with two node options. Half for stackiq `sharing-itsm-exchange` (row stackiq `share-itsm-integration`).

## Implementation tasks

### Task 1: Sources and catalogue
- **spec_ref**: `openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-topdesk-servicenow-and-glpi-are-source-templates-with-two-way-mapping-presets-req-sdc-001`
- **files**: `lib/Settings/register.d/service-desk-connectors.json`, `lib/Service/CatalogRegistryService.php`
- **acceptance_criteria**:
  - GIVEN an upgrade WHEN the catalogue is materialised THEN the three templates show under `Service management`, dormant, with broker references only
- [x] Implement
- [x] Test (`CatalogRegistryServiceTest::testServiceDeskTemplatesAreServiceManagement`, `ServiceDeskConnectorsTest::testSourcesAreDormantAndHoldNoSecret`)

### Task 2: Presets with ownership, and synchronizations
- **spec_ref**: `openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-topdesk-servicenow-and-glpi-are-source-templates-with-two-way-mapping-presets-req-sdc-001`
- **files**: `lib/Settings/register.d/service-desk-connectors.json`, `tests/fixtures/itsm/`, `tests/Unit/Settings/ServiceDeskConnectorsTest.php`
- **acceptance_criteria**:
  - GIVEN a recorded TOPdesk or ServiceNow page WHEN each preset maps it THEN the result has exactly stackiq's keys and values in stackiq's enums and types
- [x] Implement (13 presets, 10 synchronizations, `ownership` on the mapping schema 1.3.0)
- [x] Test (PHPUnit with the real MappingService on recorded answers)

### Task 3: Ownership in apply-mapping
- **spec_ref**: `openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-every-mapped-field-has-an-owner-and-an-update-never-overwrites-the-other-sides-fields-req-sdc-002`
- **files**: `lib/Flow/MappingOwnership.php`, `lib/Flow/ApplyMappingNode.php`
- **acceptance_criteria**:
  - GIVEN an existing record WHEN an inbound update is mapped THEN only desk-owned fields remain; GIVEN a new record THEN every field remains
- [x] Implement
- [x] Test (`ApplyMappingNodeOwnershipTest`, real MappingService and seeded preset)

### Task 4: bodyFrom in source-call
- **spec_ref**: `openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-a-source-call-sends-a-mapped-object-whole-req-sdc-003`
- **files**: `lib/Flow/SourceCallNode.php`, `lib/Flow/SourceCallConfigGuard.php`
- [x] Implement
- [x] Test (`SourceCallNodeTest`)

### Task 5: Mocks
- **files**: `tests/mocks/topdesk/`, `tests/mocks/servicenow/`
- [x] TOPdesk Assets API mock (paging with 200/206, create, update with POST, asset links, 401)
- [x] ServiceNow Table API mock (offset paging, `X-Total-Count`, `Link`, display modes, create, patch, 401)

### Task 6: Docs
- **files**: `docs/features/service-desk-connectors.md`, `l10n/en.json`, `l10n/nl.json`
- [ ] Implement

## Verification
- [ ] `openspec validate connectors-service-desk-templates --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
- [ ] Live on :8096: inbound reads from both mocks land mapped records; an outbound write sends the expected payload; in a conflict the desk-owned field wins and a stackiq-owned field survives
- [ ] Run against a real ServiceNow developer instance (Ruben provides one)
- [ ] Confirm the TOPdesk card link pattern on a real tenant
