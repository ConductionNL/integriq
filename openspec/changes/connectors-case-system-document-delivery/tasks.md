# Tasks: connectors-case-system-document-delivery

Kind: code. Size M. Half for filinq `generate-store-in-case-system` and `zgw-document-bridge` (rows humaniq `td-dms-link`, filinq `con-zgw`).

## Implementation tasks

### Task 1: Outcome write-back on push synchronizations
- **spec_ref**: `openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001`
- **files**: `lib/Service/SynchronizationService.php`, `lib/Settings/integriq_register.json` (`synchronization.writeBack`), the synchronization editor
- **acceptance_criteria**:
  - GIVEN a push with write-back WHEN the target answers 201 THEN the source object carries the success fields and the push is not triggered again
  - GIVEN a failure after the last retry WHEN the budget is spent THEN the failure fields are written once
- [x] Implement. Engine and schema done: `synchronization.writeBack` (1.2.0, both registers;
      `OutcomeWriteBack` fills `{{ response.* }}`, `{{ status }}`, `{{ targetId }}`,
      `{{ error.message }}`); `writeObjectToTarget()` writes `onSuccess` after an accepted create
      or update and `onFailure` after a 4xx/5xx answer or a transport failure, silently onto the
      `register/schema` source object. CallService spends the retry budget before it returns, so
      each attempt writes once. Editor: `SyncWriteBackFields.vue` in the synchronization editor's
      target column, shown for a `register/schema` source (field and value rows for On success and
      On failure, the placeholders named); an emptied write-back saves `{}`
      (`tests/vitest/syncWriteBackEditor.spec.js`).
- [x] Test (PHPUnit with real `ObjectEntity` instances)
      `tests/Unit/Service/SynchronizationOutcomeWriteBackTest.php`, 8 tests through the real
      `updateTarget()`: accepted create (silent save, url as external id, own fields kept),
      refusal with the ZGW `detail` written once, a message-less 503, a transport failure
      rethrown, an update with `{{ targetId }}`, no write-back declared, an api source, both
      registers.

### Task 2: ZGW create, parts upload and case relation
- **spec_ref**: `openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002`
- **files**: `lib/Service/ZgwVersion/InformatieObjectTranslator.php`, a ZGW document push handler, `lib/Settings/configurations/zgw-documenten.json`
- **acceptance_criteria**:
  - GIVEN a recorded Documenten API that answers with two `bestandsdelen` WHEN a delivery is pushed THEN both parts are uploaded, the object is unlocked, and with `zaakUrl` a ZaakInformatieObject is created
- [x] Implement. `lib/Service/CaseSystem/ZgwDocumentDelivery.php`: creates the
      EnkelvoudigInformatieObject with `bestandsomvang` (inline base64 when `inline`, for a
      Documenten API 1.0), PUTs each `bestandsdeel` as multipart with the lock in volgnummer
      order, refuses parts that do not add up to the file, unlocks, and with `zaakUrl` creates the
      ZaakInformatieObject; a refusal after the create deletes the new document. The push handler
      is `SynchronizationService::pushZgwDocument()`, chosen by `targetConfig.zgwDocument`
      (`zakenSource`, `zaakUrlField`, `inline`, and `fileName`/`fileId`/`objectId` read like
      `fileUpload`); a contract that holds a document url sends nothing (never updates); the
      outcome is written back as in Task 1. The translator header points at the new class.
- [ ] Test (PHPUnit against recorded exchanges; one run against Open Zaak in the dev compose).
      PHPUnit done: `tests/Unit/Service/CaseSystem/ZgwDocumentDeliveryTest.php` (7, bodies
      validated against Documenten 1.4.2 and Zaken 1.5.1) and
      `tests/Unit/Service/SynchronizationZgwDocumentPushTest.php` (3, through the real
      `updateTarget()`). Owed: the run against Open Zaak in the dev compose.

### Task 3: StUF-ZDS document message
- **spec_ref**: `openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002`
- **files**: `lib/Service/StufZkn/` (outbound document translator)
- **acceptance_criteria**:
  - GIVEN a StUF-ZDS destination WHEN a delivery is pushed THEN a `voegZaakdocumentToe` message with the file is sent and the returned identificatie is written back
- [x] Implement. `lib/Service/StufZkn/OutboundDocumentTranslator.php` (Di02, Du02 read, `edcLk01`),
      `lib/Service/StufZkn/StufZdsDocumentDelivery.php` (genereerDocumentIdentificatie, then
      voegZaakdocumentToe; design D6), `StufZknClient::exchange()` (answer back, Fo03 refusal text),
      and `SynchronizationService::pushStufDocument()`, chosen by `targetConfig.stufDocument`
      (`documenttype`, `zaakIdentificatieField`, file settings as `zgwDocument`); the identificatie
      becomes the contract's target id and `{{ response.identificatie }}`. Docs: the StUF-ZDS
      section of `docs/features/case-system-document-delivery.md`.
- [x] Test (PHPUnit against a recorded StUF answer)
      `tests/Unit/Service/StufZkn/OutboundDocumentTranslatorTest.php` (5) and
      `tests/Unit/Service/SynchronizationStufDocumentPushTest.php` (5, through the real
      `updateTarget()` with the real delivery, translator and client over Guzzle answering the
      Du02, Bv03 and Fo03 fixtures in `tests/fixtures/stuf-zds/`): identificatie written back,
      a held identificatie sends nothing, a Fo03 written back as failed, a log-mode source refused,
      a delivery without a case refused before sending.

### Task 4: Seeded synchronizations, docs and strings
- **spec_ref**: `openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002`
- **files**: `lib/Settings/integriq_seed_data.json`, `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the seed is imported THEN `zgw-documenten-push` and `object-to-zgw-document` exist and the two synchronizations are disabled
- [x] Implement. `lib/Settings/register.d/case-system-delivery.json` (design D5): the
      synchronizations `filinq-case-system-delivery` and `filinq-redacted-writeback` and the
      mappings `case-system-delivery-to-zgw-document` and `redacted-document-to-zgw-document`.
      `zgw-documenten-push` and `object-to-zgw-document` already exist (zgw-connectors-for-dossiq,
      `zgw-consumer-sets.json`), as the pass-through write-back of edits; this task seeds its own.
      Both synchronizations ship unbound (`sourceId` empty) on the dormant `zgw-set-documenten` and
      `zgw-set-zaken` sources, push only an object in `ready_for_writeback` (list-shaped
      `conditions`, which needed the engine fix in `fix/sync-conditions-list-shape`), and write
      back onto `deliveryStatus` / `processingStatus`. `zgwDocument.fileIdField` (new) reads the
      redacted file from `resultFileRef`. Docs `docs/features/case-system-document-delivery.md`.
      Strings: none; seed objects are not translated (check:schema-l10n scans schemas only).
- [ ] Test (PHPUnit on the seed; an end-to-end run with filinq and Open Zaak on a dev instance, recorded in the PR)
      PHPUnit done: `tests/Unit/Settings/CaseSystemDeliverySeedTest.php` (6: seeds unbound on
      dormant sources, accepted by the register, both mappings through the real MappingService
      and validated against Documenten 1.4.2, conditions through the engine's own check) and
      `SynchronizationZgwDocumentPushTest` (5, two for `fileIdField`). Owed: the end-to-end run;
      filinq has not built `caseSystemDelivery` or `externalDocument` yet (filinq development
      f0fa284c), and the delivery's file field is unnamed (for-ruben note).

## Verification
- [ ] `openspec validate connectors-case-system-document-delivery --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
