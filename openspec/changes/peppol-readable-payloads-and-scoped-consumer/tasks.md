# Tasks: peppol-readable-payloads-and-scoped-consumer

Kind: code. Size S to M. Halves for shillinq `sales-einvoice-exchange` (rows shillinq `sal-peppol-send`, `sal-einvoice-rejection`, `pur-ubl-import`); closes integriq#1222's Peppol finding.

## Implementation tasks

### Task 1: Scope the consumer on resolved ids
- **spec_ref**: `openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-outbound-consumer-reacts-only-to-integriqs-own-event-schema-req-007`
- **files**: `lib/Service/PeppolOutboundConsumer.php`, `lib/Service/EventService.php` (reuse `getSelfSchemaIds()` or add a register id resolver beside it)
- **acceptance_criteria**:
  - GIVEN an object in register `integriq`, schema `event`, with type `nl.conduction.peppol.outbound.requested` WHEN it is created THEN a `peppol_transmission` is queued
  - GIVEN the same payload in another register WHEN it is created THEN nothing is queued
- [ ] Implement
- [ ] Test (PHPUnit with real `ObjectEntity` instances carrying numeric ids; the live check of #1222 on a dev instance, and close #1222's Peppol finding with its result)

### Task 2: Submit the UBL, not its reference
- **spec_ref**: `openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-access-point-receives-the-ubl-document-itself-req-008`
- **files**: `lib/Service/PeppolTransmissionService.php`, `lib/Service/Peppol/PeppolAccessPointProviderInterface.php`, `lib/Service/Peppol/RestPeppolAccessPointProvider.php`
- **acceptance_criteria**:
  - GIVEN a UBL stored in Nextcloud Files WHEN a request names it by id or path THEN the access point receives its XML
  - GIVEN a reference that does not resolve WHEN the request is consumed THEN the transmission is `failed` with the reason
- [ ] Implement
- [ ] Test (PHPUnit with a Files fixture and a recorded access point)

### Task 3: Fetch and store inbound documents
- **spec_ref**: `openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-an-inbound-document-is-stored-where-the-receiving-app-can-read-it-req-009`
- **files**: `lib/Service/PeppolTransmissionService.php`, both providers, `lib/Controller/PeppolController.php`, `lib/Settings/integriq_register.json` (`peppol_inbound_document`)
- **acceptance_criteria**:
  - GIVEN a signed inbound notification WHEN it is received THEN a `peppol_inbound_document` with the UBL attached exists and the emitted event names its path and file id
  - GIVEN an access point that answers 500 on the fetch WHEN the notification arrives THEN the webhook answers 200, the object is `fetch-failed` and no event is emitted
- [ ] Implement
- [ ] Test (PHPUnit; Newman for the signed webhook with the `log` provider)

### Task 4: Docs, seed and strings
- **spec_ref**: `openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-an-inbound-document-is-stored-where-the-receiving-app-can-read-it-req-009`
- **files**: `docs/features/`, `lib/Settings/integriq_seed_data.json`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the docs page for Peppol WHEN a developer reads it THEN it names the accepted `payloadFileUri` forms and the inbound event fields
- [ ] Implement
- [ ] Test (docs build; seed import on a dev instance)

## Verification
- [ ] `openspec validate peppol-readable-payloads-and-scoped-consumer --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit and Newman exit codes read
- [ ] shillinq `sales-einvoice-exchange` task 1.3 run against this branch on a dev instance
