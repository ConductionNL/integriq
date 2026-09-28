# Tasks: connectors-service-desk-templates

Kind: config. Size S. Half for stackiq `sharing-itsm-exchange` (row stackiq `share-itsm-integration`).

## Implementation tasks

### Task 1: Templates
- **spec_ref**: `openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-topdesk-servicenow-and-glpi-are-source-templates-with-mapping-presets-req-sdc-001`
- **files**: `lib/Settings/register.d/topdesk-source.json`, `servicenow-source.json`, `glpi-source.json`, `lib/Service/CatalogRegistryService.php`
- **acceptance_criteria**:
  - GIVEN an upgrade WHEN the catalogue is materialised THEN the three templates show under `Service management`, dormant
- [ ] Implement
- [ ] Test (PHPUnit on `CatalogRegistryService::collect()`)

### Task 2: Mapping presets
- **spec_ref**: `openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-topdesk-servicenow-and-glpi-are-source-templates-with-mapping-presets-req-sdc-001`
- **files**: six mappings in `lib/Settings/integriq_seed_data.json`, recorded answers under `tests/fixtures/itsm/`
- **acceptance_criteria**:
  - GIVEN a recorded TOPdesk asset WHEN the inbound preset maps it THEN it yields `system` topdesk, the record id, the record's link, name and supplier
- [ ] Implement
- [ ] Test (PHPUnit per preset; one run of stackiq's inbound flow against a ServiceNow developer instance, recorded in the PR)

### Task 3: Docs
- **spec_ref**: `openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-topdesk-servicenow-and-glpi-are-source-templates-with-mapping-presets-req-sdc-001`
- **files**: `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the docs WHEN an administrator connects TOPdesk THEN the steps name the application password and the asset template to filter on
- [ ] Implement
- [ ] Test (docs build)

## Verification
- [ ] `openspec validate connectors-service-desk-templates --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
