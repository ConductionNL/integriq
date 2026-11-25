# Tasks: peppol-readable-payloads-and-scoped-consumer

Kind: code. Size S to M. Halves for shillinq `sales-einvoice-exchange` (rows shillinq `sal-peppol-send`, `sal-einvoice-rejection`, `pur-ubl-import`); closes integriq#1222's Peppol finding.

## Implementation tasks

### Task 1: Scope the consumer on resolved ids
- **spec_ref**: `openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-outbound-consumer-reacts-only-to-integriqs-own-event-schema-req-007`
- **files**: `lib/Service/PeppolOutboundConsumer.php`, `lib/Service/EventService.php` (reuse `getSelfSchemaIds()` or add a register id resolver beside it)
- **acceptance_criteria**:
  - GIVEN an object in register `integriq`, schema `event`, with type `nl.conduction.peppol.outbound.requested` WHEN it is created THEN a `peppol_transmission` is queued
  - GIVEN the same payload in another register WHEN it is created THEN nothing is queued
- [x] Implement
- [x] Test (PHPUnit with real `ObjectEntity` instances carrying numeric ids; the live check of #1222 on a dev instance, and close #1222's Peppol finding with its result)
  > 2026-11-25, live (sandbox Nextcloud 34.0.6 + MariaDB, OpenRegister main 292aa09d): Newman `tests/postman/integriq-peppol-outbound-scope.postman_collection.json` 14/14, two runs in a row. A `nl.conduction.peppol.outbound.requested` CloudEvent created over the OpenRegister objects API in register `integriq`, schema `event` queues one `peppol_transmission`. The same CloudEvent in another register, whose schema is also called `event`, queues nothing. OpenRegister hands the listener numeric ids (the other register was 27, its schema 135), so this is the case #1222 found. Result for #1222's Peppol finding: fixed, the consumer is scoped on resolved ids. PHPUnit `--filter Peppol` 70/70, exit 0. Shape note: the request fields are read from the CloudEvent's `data`; a variant with `objectUri` at the top level is ignored as "missing required fields", as it should be.

### Task 2: Submit the UBL, not its reference
- **spec_ref**: `openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-access-point-receives-the-ubl-document-itself-req-008`
- **files**: `lib/Service/PeppolTransmissionService.php`, `lib/Service/Peppol/PeppolAccessPointProviderInterface.php`, `lib/Service/Peppol/RestPeppolAccessPointProvider.php`
- **acceptance_criteria**:
  - GIVEN a UBL stored in Nextcloud Files WHEN a request names it by id or path THEN the access point receives its XML
  - GIVEN a reference that does not resolve WHEN the request is consumed THEN the transmission is `failed` with the reason
- [x] Implement
- [x] Test (PHPUnit with a Files fixture and a recorded access point)

### Task 3: Fetch and store inbound documents
- **spec_ref**: `openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-an-inbound-document-is-stored-where-the-receiving-app-can-read-it-req-009`
- **files**: `lib/Service/PeppolTransmissionService.php`, both providers, `lib/Controller/PeppolController.php`, `lib/Settings/integriq_register.json` (`peppol_inbound_document`)
- **acceptance_criteria**:
  - GIVEN a signed inbound notification WHEN it is received THEN a `peppol_inbound_document` with the UBL attached exists and the emitted event names its path and file id
  - GIVEN an access point that answers 500 on the fetch WHEN the notification arrives THEN the webhook answers 200, the object is `fetch-failed` and no event is emitted
- [x] Implement
- [x] Test (PHPUnit; Newman for the signed webhook with the `log` provider)
  > 2026-11-25, live: Newman `tests/postman/integriq-peppol-inbound.postman_collection.json` 25/25, two runs in a row. An unsigned notification and one signed with another secret answer 401 `invalid signature`. A notification signed with the Peppol connection's secret (`X-OpenConnector-Signature: t=..,v1=HMAC-SHA256('<t>.<body>')`) answers 200. The `log` provider's fixture invoice is stored as a `peppol_inbound_document` with status `stored`, a `fileId` and `peppol-inbound-<uuid>.xml`, listed on the object's files. Exactly one `nl.conduction.peppol.inbound.received` event names `payloadReference`, `payloadFileId` and `payloadFileName`. A redelivery changes nothing: still one document, still one event. The `fetch-failed` path (an access point answering 500) has no `log` equivalent and stays with PHPUnit (`--filter Peppol` 70/70).

### Task 4: Docs, seed and strings
- **spec_ref**: `openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-an-inbound-document-is-stored-where-the-receiving-app-can-read-it-req-009`
- **files**: `docs/features/`, `lib/Settings/integriq_seed_data.json`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the docs page for Peppol WHEN a developer reads it THEN it names the accepted `payloadFileUri` forms and the inbound event fields
- [x] Implement
  - `docs/features/peppol.md` names both accepted `payloadFileUri` forms and the inbound event fields (`payloadReference`, `payloadFileId`, `payloadFileName`); the schema strings are in `l10n/en.json` and `l10n/nl.json`. No seeded inbound document: a seed cannot carry its file, so the seed import stays with the test box.
- [ ] Test (docs build; seed import on a dev instance)

## Verification
- [x] `openspec validate peppol-readable-payloads-and-scoped-consumer --strict` passes
  - OpenSpec 2.1.1, 2026-11-23: valid under `--strict`.
- [ ] `composer check:strict` once before push; PHPUnit and Newman exit codes read
- [ ] shillinq `sales-einvoice-exchange` task 1.3 run against this branch on a dev instance

Built: Tasks 1 and 2 landed earlier (#2237 scopes the consumer on resolved ids through `ListenerSchemaResolver`; #2240 submits the UBL content, with the Files-fixture and recorded-access-point tests). Task 3 adds `fetchInboundDocument()` to both providers, stores the UBL as a file on a `peppol_inbound_document` (written in system context; read like `event`), and announces it with `payloadReference`, `payloadFileId` and `payloadFileName`; a failed fetch leaves the record `fetch-failed` and a redelivery retries it. Task 4: `docs/features/peppol.md` and the schema strings in `l10n/en.json` / `nl.json`. Left open: the live check of #1222 (Task 1 test), the Newman run for the signed webhook (Task 3 test), the seed of a stored inbound document (a seed cannot carry its file), and the verification section, which needs a dev instance.
