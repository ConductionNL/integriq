# Tasks: connectors-case-system-document-delivery

Kind: code. Size M. Half for filinq `generate-store-in-case-system` and `zgw-document-bridge` (rows humaniq `td-dms-link`, filinq `con-zgw`).

## Implementation tasks

### Task 1: Outcome write-back on push synchronizations
- **spec_ref**: `openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001`
- **files**: `lib/Service/SynchronizationService.php`, `lib/Settings/integriq_register.json` (`synchronization.writeBack`), the synchronization editor
- **acceptance_criteria**:
  - GIVEN a push with write-back WHEN the target answers 201 THEN the source object carries the success fields and the push is not triggered again
  - GIVEN a failure after the last retry WHEN the budget is spent THEN the failure fields are written once
- [ ] Implement
- [ ] Test (PHPUnit with real `ObjectEntity` instances)

### Task 2: ZGW create, parts upload and case relation
- **spec_ref**: `openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002`
- **files**: `lib/Service/ZgwVersion/InformatieObjectTranslator.php`, a ZGW document push handler, `lib/Settings/configurations/zgw-documenten.json`
- **acceptance_criteria**:
  - GIVEN a recorded Documenten API that answers with two `bestandsdelen` WHEN a delivery is pushed THEN both parts are uploaded, the object is unlocked, and with `zaakUrl` a ZaakInformatieObject is created
- [ ] Implement
- [ ] Test (PHPUnit against recorded exchanges; one run against Open Zaak in the dev compose)

### Task 3: StUF-ZDS document message
- **spec_ref**: `openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002`
- **files**: `lib/Service/StufZkn/` (outbound document translator)
- **acceptance_criteria**:
  - GIVEN a StUF-ZDS destination WHEN a delivery is pushed THEN a `voegZaakdocumentToe` message with the file is sent and the returned identificatie is written back
- [ ] Implement
- [ ] Test (PHPUnit against a recorded StUF answer)

### Task 4: Seeded synchronizations, docs and strings
- **spec_ref**: `openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002`
- **files**: `lib/Settings/integriq_seed_data.json`, `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the seed is imported THEN `zgw-documenten-push` and `object-to-zgw-document` exist and the two synchronizations are disabled
- [ ] Implement
- [ ] Test (PHPUnit on the seed; an end-to-end run with filinq and Open Zaak on a dev instance, recorded in the PR)

## Verification
- [ ] `openspec validate connectors-case-system-document-delivery --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
