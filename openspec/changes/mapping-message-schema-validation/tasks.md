# Tasks: mapping-message-schema-validation

Kind: code. Matrix row `integriq:map-message-validation`.

### Task 1: The message schema object and page
- **spec_ref**: openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-message-schema-is-stored-once-and-referenced-req-msv-001
- **files**: `lib/Settings/register.d/mapping-message-schema-validation.json`, `src/manifest.d/mapping-message-schema-validation.json`
- **acceptance_criteria**:
  - GIVEN the register is imported WHEN an administrator opens the message schemas page THEN the two seeded schemas are listed
  - GIVEN a malformed XSD WHEN it is saved THEN the save is refused with the parser message
- [x] Implement
  - The fragment declares `message_schema` (slug, `name`, `description`, `kind` of four, `document`, `registerSchema`, `version`), joins it to the integriq register, closes every verb to administrators (in `SchemaAuthorizationRatchetTest::CLOSED`), and seeds `example-person-json` and `example-person-xsd`, the same person twice. The manifest fragment adds the `MessageSchemas` index page (admin) under Automation.
  - A document that does not parse for its kind is refused on OpenRegister's own save path: `MessageSchemaDocumentListener` on `ObjectCreatingEvent` and `ObjectUpdatingEvent` asks `MessageValidationService::documentProblem()` and stops the save with the parser's message (400, nothing stored). An XSD must be well-formed with an `xs:schema` root; a JSON Schema must be a JSON object or boolean; an OpenAPI description must parse as JSON or YAML and carry a `paths` object. `register-schema` carries no document.
- [ ] Test (`node tests/validate-register.js`, `node tests/validate-manifest.js`, Playwright `tests/e2e/message-schema-validation.spec.ts`)
  - Done: `tests/Unit/Settings/MessageSchemaRegisterFragmentTest.php` (fragment shape, admin-only, both seeds pass the real merged schema with Opis and check the same person with the real checkers, the page and menu) and `tests/Unit/EventListener/MessageSchemaDocumentListenerTest.php` (real OpenRegister events and real checkers: broken XSD refused with the parser's message, XML that is not an XSD, broken JSON Schema on update, OpenAPI without paths, good documents saved, the Application registration); `npm run check:specs` passes.
  - Owed: the Playwright spec against a live instance (the page lists both seeds; a broken XSD save shows the parser's message).

### Task 2: The validator service and its three checkers
- **spec_ref**: openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-xml-is-validated-without-network-access-req-msv-004
- **files**: `lib/Service/MessageValidationService.php`, `lib/Service/MessageValidation/JsonSchemaChecker.php`, `lib/Service/MessageValidation/XsdChecker.php`, `lib/Service/MessageValidation/OpenApiChecker.php`
- **acceptance_criteria**:
  - GIVEN a JSON Schema requiring `bsn` WHEN a payload lacks it THEN the outcome lists `/bsn`
  - GIVEN an XSD with a remote import WHEN it validates THEN no HTTP request is made and the import is reported
  - GIVEN an OpenAPI 3.0 operation with a `nullable` field WHEN null is sent THEN it passes
- [x] Implement
  - `MessageValidationService::validate(messageSchema, payload, context)` picks the checker by `kind` and answers a `ValidationOutcome` (errors with a JSON pointer path, `firstErrors(20)` for a refusal body). `JsonSchemaChecker` (Opis) reports a missing required property under its own path. `XsdChecker` loads both documents through `SafeXmlParser::loadDom()`, reports a remote `xs:import`/`include`/`redefine`/`override` by location before validating, and validates with libxml's entity loader pinned to one that loads nothing, so a relative location is not read from disk either. `OpenApiChecker` finds the operation by `operationId` or method and path, takes the request body or the answer for its status (`2XX`, `default`), inlines local `$ref`s (`OpenApiReferenceResolver`; a remote one is reported) and rewrites `nullable` to a type union.
  - Kind `register-schema` is not checked here yet: it is refused by name, never passed unchecked. It goes through OpenRegister's validate handler when Task 3 or 4 wires the first caller.
- [x] Test (PHPUnit per checker with fixtures under `tests/fixtures/message-schemas/`)
  - `tests/Unit/Service/MessageValidation/MessageValidationServiceTest.php` runs all three real checkers through the service: `/bsn` listed, a wrong value, a broken document, XSD valid and invalid, the remote import reported with libxml's loader never asked for it, a relative include not read from disk, OpenAPI `nullable`, an operation found by method and path on the answer, an unknown operation and an unknown kind.

### Task 3: Endpoint request and answer validation
- **spec_ref**: openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
- **files**: `lib/Service/EndpointService.php`, `src/modals/v2/EndpointFormFields.vue`
- **acceptance_criteria**:
  - GIVEN mode `refuse` WHEN a request fails THEN 400 problem+json and no dispatch
  - GIVEN mode `refuse` WHEN a proxied answer fails THEN 502
  - GIVEN mode `record` WHEN a request fails THEN it is dispatched and the call log carries the errors
- [x] Implement
  - The fragment merges `validation` onto `endpoint` (`mode` record or refuse, default record; `request` and `response`, each `messageSchema` + optional `operationId`) and a `validation` list onto `call_log`.
  - `EndpointService::doHandleRequest()` checks the raw request body right after the conditions, before any rule or dispatch; `handleSourceRequest()` checks the proxied answer's body with its status. Both go through `EndpointMessageGate` (`lib/Service/MessageValidation/`), which reads the `message_schema` in system context and runs `MessageValidationService`. Refuse: 400 for the request, 502 for the answer, `application/problem+json` with the first twenty errors. Record: the message passes, the findings go to the server log and onto the proxied call's `call_log.validation`. An endpoint on a register schema has no call log, so record mode's findings go to the server log only. A declared validation is never skipped: without the gate, mode refuse answers 500.
  - `src/modals/v2/EndpointFormFields.vue` has a message validation section: mode, request schema, answer schema (picked from the `message_schema` objects). The `operationId` is kept when the schema changes; it is not edited on the form yet.
- [ ] Test (PHPUnit on EndpointService; Newman requests in `tests/postman/`)
  - Done: `tests/Unit/Service/EndpointServiceMessageValidationTest.php` drives `handleRequest()` of a proxying endpoint with the real gate and the real checkers (refused 400 naming `/bsn` and not dispatched, record dispatched with the finding on the call log, answer refused 502, valid passes, no gate refuses 500, no validation unchanged); `MessageSchemaRegisterFragmentTest` checks the merged `endpoint` and `call_log` properties; `tests/vitest/endpointMessageValidation.spec.js` checks the form writes the block.
  - Owed: the Newman requests in `tests/postman/` against a live instance.

### Task 4: Synchronization source and target validation
- **spec_ref**: openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
- **files**: `lib/Service/SynchronizationService.php`, `src/views/Synchronization/SyncConfigWidget.vue`
- **acceptance_criteria**:
  - GIVEN ten objects with one invalid in mode `refuse` WHEN the run completes THEN nine are written and one is dead-lettered
  - GIVEN an invalid target body in mode `refuse` WHEN the item is written THEN CallService is not called
- [x] Implement
  - A synchronization declares `sourceConfig.validation` and `targetConfig.validation`, each `{mode, messageSchema, operationId?}` (mode record or refuse, default record). `SynchronizationMessageGate` (`lib/Service/MessageValidation/`) checks one message through `EndpointMessageGate::checkDeclared()`, the same message schema read and checkers the endpoint uses.
  - `SynchronizationService::processSynchronizationObject()` checks each source object before it is mapped; `writeObjectToTarget()` checks the body of an `api` target right before `callSourceObject()`, on create and on update. Refuse throws `MessageValidationRefusedException`, which the existing per-item isolation dead-letters (`SyncItemDeadLetterService`), so nothing is mapped or sent and the run goes on. Record lets the message through, writes the finding to the server log and puts it under `result.validation` on the run's synchronization log. A declared validation is never skipped: without the gate, mode refuse dead-letters and mode record records that the message could not be checked.
  - `src/views/Synchronization/SyncConfigWidget.vue` has a message validation section for an `api` source or target: message schema (picked from the `message_schema` objects) and mode. Clearing the schema removes the block; the `operationId` is kept when the schema changes and is not edited on the form yet.
- [ ] Test (PHPUnit with a mocked CallService; Playwright for the dead-letter entry)
  - Done: `tests/Unit/Service/SynchronizationMessageValidationTest.php` runs `synchronize()` with the real gate, the real checkers, the seeded message schemas and the real `SyncItemDeadLetterService`, CallService mocked: refuse dead-letters 1 of 10 and writes 9, record writes 10 with one finding on the run log, an invalid target body is not sent and is dead-lettered, an XSD target body that does not match sends nothing, record sends every body with the finding, no gate refuses, no validation unchanged. `tests/vitest/syncMessageValidation.spec.js` checks the form writes the block.
  - Owed: the Playwright dead-letter entry in `tests/e2e/message-schema-validation.spec.ts` against a live instance.

## Verification

- `openspec validate mapping-message-schema-validation --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/message-schema-validation.spec.ts` green against a local instance
