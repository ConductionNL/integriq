# Tasks: mapping-message-schema-validation

Kind: code. Matrix row `integriq:map-message-validation`.

### Task 1: The message schema object and page
- **spec_ref**: openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-message-schema-is-stored-once-and-referenced-req-msv-001
- **files**: `lib/Settings/register.d/mapping-message-schema-validation.json`, `src/manifest.d/mapping-message-schema-validation.json`
- **acceptance_criteria**:
  - GIVEN the register is imported WHEN an administrator opens the message schemas page THEN the two seeded schemas are listed
  - GIVEN a malformed XSD WHEN it is saved THEN the save is refused with the parser message
- [ ] Implement
- [ ] Test (`node tests/validate-register.js`, `node tests/validate-manifest.js`, Playwright `tests/e2e/message-schema-validation.spec.ts`)

### Task 2: The validator service and its three checkers
- **spec_ref**: openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-xml-is-validated-without-network-access-req-msv-004
- **files**: `lib/Service/MessageValidationService.php`, `lib/Service/MessageValidation/JsonSchemaChecker.php`, `lib/Service/MessageValidation/XsdChecker.php`, `lib/Service/MessageValidation/OpenApiChecker.php`
- **acceptance_criteria**:
  - GIVEN a JSON Schema requiring `bsn` WHEN a payload lacks it THEN the outcome lists `/bsn`
  - GIVEN an XSD with a remote import WHEN it validates THEN no HTTP request is made and the import is reported
  - GIVEN an OpenAPI 3.0 operation with a `nullable` field WHEN null is sent THEN it passes
- [ ] Implement
- [ ] Test (PHPUnit per checker with fixtures under `tests/fixtures/message-schemas/`)

### Task 3: Endpoint request and answer validation
- **spec_ref**: openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
- **files**: `lib/Service/EndpointService.php`, `src/modals/v2/EndpointFormFields.vue`
- **acceptance_criteria**:
  - GIVEN mode `refuse` WHEN a request fails THEN 400 problem+json and no dispatch
  - GIVEN mode `refuse` WHEN a proxied answer fails THEN 502
  - GIVEN mode `record` WHEN a request fails THEN it is dispatched and the call log carries the errors
- [ ] Implement
- [ ] Test (PHPUnit on EndpointService; Newman requests in `tests/postman/`)

### Task 4: Synchronization source and target validation
- **spec_ref**: openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
- **files**: `lib/Service/SynchronizationService.php`, `src/views/Synchronization/SyncConfigWidget.vue`
- **acceptance_criteria**:
  - GIVEN ten objects with one invalid in mode `refuse` WHEN the run completes THEN nine are written and one is dead-lettered
  - GIVEN an invalid target body in mode `refuse` WHEN the item is written THEN CallService is not called
- [ ] Implement
- [ ] Test (PHPUnit with a mocked CallService; Playwright for the dead-letter entry)

## Verification

- `openspec validate mapping-message-schema-validation --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/message-schema-validation.spec.ts` green against a local instance
