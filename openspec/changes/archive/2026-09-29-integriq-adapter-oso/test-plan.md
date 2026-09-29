# Test Plan: integriq-adapter-oso

## Test Cases

### TC-1: log provider returns a synthetic ref with no network call
- **spec_ref**: `openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings`
- **type**: functional
- **preconditions**: source configured with `configuration.provider: log`
- **steps**: call `OsoService::sendExport()` with a complete payload
- **expected result**: a `MOCK-OSO-<n>` ref is returned, no HTTP call
- **test command**: PHPUnit

### TC-2: Kennisnet provider refuses closed without a certificate reference
- **spec_ref**: `.../spec.md#req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings`
- **type**: security
- **preconditions**: `configuration.provider: kennisnet`, no `certificateRef`
- **steps**: call `sendExport()`
- **expected result**: `OsoProviderException` naming the missing certificate reference
- **test command**: PHPUnit

### TC-3: a complete export payload translates to a valid envelope
- **spec_ref**: `.../spec.md#req-002-export-envelope-translation-with-a-literal-leak-guard`
- **type**: functional
- **preconditions**: fixture payload with learnerEckId, targetSchoolBrin, categories
- **steps**: `OsoExportEnvelopeTranslator::translate()`
- **expected result**: envelope carries all fields
- **test command**: PHPUnit contract test against recorded fixture

### TC-4: a missing required field never reaches the envelope
- **spec_ref**: `.../spec.md#req-002-export-envelope-translation-with-a-literal-leak-guard`
- **type**: functional
- **preconditions**: fixture payload missing targetSchoolBrin
- **steps**: `translate()`
- **expected result**: `OsoTranslationException` naming `targetSchoolBrin`
- **test command**: PHPUnit

### TC-5: a complete inbound dossier dispatches OsoDossierReceivedEvent
- **spec_ref**: `.../spec.md#req-003-import-parsing-into-learniqs-osoimportdossier-field-shape`
- **type**: functional
- **preconditions**: fixture OSO import XML
- **steps**: `OsoImportTranslator::translate()` then dispatch
- **expected result**: event carries `sourceSchoolBrin` and `learnerEckId`
- **test command**: PHPUnit

### TC-6: a malformed inbound dossier is logged and never dispatched
- **spec_ref**: `.../spec.md#req-003-import-parsing-into-learniqs-osoimportdossier-field-shape`
- **type**: functional
- **preconditions**: malformed XML fixture
- **steps**: `receiveImport()`
- **expected result**: no event dispatched, failure logged, never a 500
- **test command**: PHPUnit

### TC-7: export push endpoint happy path
- **spec_ref**: `.../spec.md#req-004-push-export-signed-inbound-import-and-signed-export-retour`
- **type**: api
- **preconditions**: authenticated session, `log` source active
- **steps**: `POST /api/oso/export` with complete payload
- **expected result**: HTTP 200, `{ref, direction: "export", status: "sent"}`
- **test command**: PHPUnit controller test

### TC-8: unsigned import rejected before processing
- **spec_ref**: `.../spec.md#req-004-push-export-signed-inbound-import-and-signed-export-retour`
- **type**: security
- **preconditions**: missing/invalid HMAC header
- **steps**: `POST /api/oso/import`
- **expected result**: HTTP 401, no `oso_message` record created
- **test command**: PHPUnit controller test

### TC-9: failed export persists and is retried in isolation
- **spec_ref**: `.../spec.md#req-005-per-message-audit-persistence-and-isolated-retry`
- **type**: functional
- **preconditions**: two failed export rows, one raises again on retry
- **steps**: run `OsoRetryJob::run()`
- **expected result**: failing row logged and skipped, other row retried
- **test command**: PHPUnit

### TC-10: an excluded category is transmitted as excluded, not omitted
- **spec_ref**: `.../spec.md#req-006-data-minimisation-is-a-pass-through-not-a-integriq-decision`
- **type**: functional
- **preconditions**: export payload with a category marked `included: false`
- **steps**: build the envelope
- **expected result**: the category is present in the envelope, marked excluded
- **test command**: PHPUnit

## Coverage Summary

| Requirement | Covered by |
|---|---|
| REQ-001 | TC-1, TC-2 |
| REQ-002 | TC-3, TC-4 |
| REQ-003 | TC-5, TC-6 |
| REQ-004 | TC-7, TC-8 |
| REQ-005 | TC-9 |
| REQ-006 | TC-10 |

## Out of Scope

- Live Kennisnet traffic — blocked on the OSO aansluiting approval (M3(c)).
- Playwright/e2e coverage — every scenario carries `@e2e exclude`.
- `OsoImportDossier`'s own lifecycle/guards — `oso-inbound-contract`'s scope, not this change's.
