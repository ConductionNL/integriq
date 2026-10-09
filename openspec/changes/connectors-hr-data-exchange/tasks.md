# Tasks: connectors-hr-data-exchange

Kind: config. Size M. Halves for humaniq `payroll-external-bureau-handoff` and `talent-training-and-lms` (rows humaniq `pay-outsourced`, `td-lms-sync`, `tal-training`).

## Implementation tasks

### Task 1: Payroll templates and mutation mappings
- **spec_ref**: `openspec/changes/connectors-hr-data-exchange/specs/hr-data-exchange/spec.md#requirement-a-ready-payroll-handoff-reaches-the-bureau-and-its-payslips-come-back-req-hrx-001`
- **files**: `lib/Settings/register.d/loket-payroll-source.json`, `nmbrs-payroll-source.json`, `payroll-sftp-drop-source.json`, mappings in `lib/Settings/integriq_seed_data.json`
- **acceptance_criteria**:
  - GIVEN a handoff with three mutations WHEN it is mapped for each bureau THEN the output matches the recorded example of that bureau
- [ ] Implement
- [ ] Test (PHPUnit per mapping against its recorded example)

### Task 2: Handoff push and payslip pull
- **spec_ref**: `openspec/changes/connectors-hr-data-exchange/specs/hr-data-exchange/spec.md#requirement-a-ready-payroll-handoff-reaches-the-bureau-and-its-payslips-come-back-req-hrx-001`
- **files**: synchronizations `payroll-handoff-push`, `payroll-payslip-pull` in the seed data
- **acceptance_criteria**:
  - GIVEN a handoff set to `klaargezet` WHEN the push succeeds THEN it reads `verzonden` with the delivery reference
  - GIVEN the bureau's return file with two payslips WHEN the pull runs THEN two `Payslip` objects carry the handoff id and the handoff reads `ontvangen`
- [ ] Implement
- [ ] Test (PHPUnit; one run against the SFTP container with humaniq installed on a dev instance)

### Task 3: Studytube people push and completions pull
- **spec_ref**: `openspec/changes/connectors-hr-data-exchange/specs/hr-data-exchange/spec.md#requirement-a-learning-platform-learns-who-works-where-and-finished-trainings-come-back-req-hrx-002`
- **files**: `lib/Settings/register.d/studytube-lms-source.json`, synchronizations `lms-people-push`, `lms-completions-pull`
- **acceptance_criteria**:
  - GIVEN a recorded completion WHEN the pull runs twice THEN one `TrainingRecord` with source `lms` exists
- [ ] Implement
- [ ] Test (PHPUnit against recorded Studytube answers)

### Task 4: Catalogue, docs and strings
- **spec_ref**: `openspec/changes/connectors-hr-data-exchange/specs/hr-data-exchange/spec.md#requirement-a-learning-platform-learns-who-works-where-and-finished-trainings-come-back-req-hrx-002`
- **files**: `lib/Service/CatalogRegistryService.php`, `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an upgrade WHEN the catalogue is materialised THEN the payroll templates show under `Payroll` and Studytube under `Education data`
- [ ] Implement
- [ ] Test (PHPUnit on the catalogue)

## Verification
- [ ] `openspec validate connectors-hr-data-exchange --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
