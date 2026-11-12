# Tasks: call-log-personal-data-masking

Kind: code. Matrix row `integriq:obs-redact`.

### Task 1: Mask declared personal data fields before a log write
- **spec_ref**: openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
- **files**: `lib/Service/Security/SensitiveFieldRegistry.php`, `lib/Outbound/BodyRedactor.php`, `lib/Service/CallService.php`, `tests/Unit/Service/Security/SensitiveFieldRegistryTest.php`
- **acceptance_criteria**:
  - GIVEN a JSON or XML body with a BSN and a name WHEN it is logged THEN both are masked and the sent body is unchanged
  - GIVEN an added field name WHEN a body with that field is logged THEN it is masked
- [ ] Implement
- [ ] Test (PHPUnit)
