# Tasks: case-system-operations-for-decidiq

## Implementation tasks

### Task 1: The case-system source type and its in-process dispatch
- **spec_ref**: `openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001`
- **files**: `lib/Service/CaseSystem/CaseSystemOperations.php`, `lib/Service/CallService.php` (dispatch beside soap), the source schema's type enum
- [ ] Implement
- [ ] Test (PHPUnit, mock fixture; the call log is written)

### Task 2: The ZGW mapping of the five operations
- **spec_ref**: `openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002`
- **files**: `lib/Service/CaseSystem/ZgwCaseSystem.php`
- [ ] Implement
- [ ] Test (recorded ZGW requests validated against the Zaken 1.5 and Documenten 1.4 request schemas)

### Task 3: The seeded zgw-zaken source and mock mode
- **spec_ref**: `openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003`
- **files**: `lib/Settings/register.d/case-system-operations.json`, `lib/Settings/case-system-mock.json`
- [ ] Implement
- [ ] Test (seed validated against the merged register; linkTemplate finds it)

### Task 4: Docs, strings and the e2e link test
- [ ] Implement (docs page, en and nl strings for the source configuration)
- [ ] Test (`tests/e2e/case-system-link.spec.ts`)
