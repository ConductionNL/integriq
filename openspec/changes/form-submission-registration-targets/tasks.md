# Tasks: form-submission-registration-targets

Kind: code. Size L. Row: portaliq `int-registration-per-form`. Builds on `zgw-connectors-for-dossiq` (ZGW sources) and `connectors-case-system-document-delivery` (document upload, StUF document message).

## Task 1: The schemas
- **spec_ref**: `specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001`
- **files**: `lib/Settings/register.d/registration-target.json`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN the register imports THEN `registrationTarget` and `registrationRecord` carry the properties of design D1, each with a `title`, and the log has no `PARTIAL IMPORT` line
  - GIVEN a non-admin WHEN they list either schema THEN nothing is returned
- [x] Implement
- [x] Test

## Task 2: The command, the hand-over check and the record
- **spec_ref**: `specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002`
- **files**: `lib/Event/SubmissionRegistrationRequestedEvent.php`, `lib/Listener/SubmissionRegistrationListener.php`, `lib/Service/Registration/RegistrationService.php`, `lib/AppInfo/Application.php`, `tests/Unit/Service/Registration/RegistrationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN each refusal of design D2 WHEN its condition holds THEN the answer carries the code and the right `retryable` flag, and nothing is thrown
  - GIVEN a hand-over with file ids WHEN files are read THEN they are read as the calling app's service account
- [ ] Implement
- [ ] Test
- note: Built: the event, listener, RegistrationService with every D2 refusal answered (never thrown) and the hand-over check (file ids only). Left: reading the files as the calling app's service account, which no leg needs yet (json and objects-api send no files).

## Task 3: The ZGW leg
- **spec_ref**: `specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002`
- **files**: `lib/Service/Registration/ZgwRegistrationLeg.php`, `tests/Unit/Service/Registration/ZgwRegistrationLegTest.php`, `tests/postman/registration-zgw.json`
- **acceptance_criteria**:
  - GIVEN a ZGW mock WHEN a submission with two attachments is registered THEN one case, one initiator role, three informatieobjecten and three links are created in that order
  - GIVEN a KvK applicant WHEN the role is created THEN it is a `niet_natuurlijk_persoon` with the KvK number
- [ ] Implement
- [ ] Test
- note: Built: `ZgwRegistrationLeg` over the case-system transport: `POST /zaken` with the mapped case, the target's zaaktype, bronorganisatie and verantwoordelijkeOrganisatie, the dates and the submission reference as a kenmerk; `POST /rollen` with the applicant as initiator (BSN as `natuurlijk_persoon`, KvK as `niet_natuurlijk_persoon`); the target's `zaakeigenschappen`; each call a step, so a repeated hand-over skips what is done; a 4xx as outside-refused with the API's detail, a 5xx, 429 or no answer as source-unavailable retryable (`ZgwRegistrationLegTest`, 13). Left: the informatieobjecten for the PDF and attachments, which wait for reading the hand-over's files (Task 2), so a hand-over with files is refused as source-unavailable, not retryable; the outside lookup of a case by kenmerk when no record exists; `tests/postman/registration-zgw.json`, a live check.

## Task 4: The Objects API leg
- **spec_ref**: `specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002`
- **files**: `lib/Service/Registration/ObjectsApiRegistrationLeg.php`, `tests/Unit/Service/Registration/ObjectsApiRegistrationLegTest.php`
- **acceptance_criteria**:
  - GIVEN a target with object type version 3 WHEN a submission is registered THEN one object is posted with `record.typeVersion` 3 and the mapped data
- [x] Implement
- [x] Test
- note: Files to the Documenten API (informatieobjecttype) are refused until the document upload lands.

## Task 5: The StUF-ZDS leg
- **spec_ref**: `specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002`
- **files**: `lib/Service/StufZkn/StufZknClient.php`, `lib/Service/StufZkn/` (message builders for `genereerZaakidentificatie_Di02`, `creeerZaak_ZakLk01`, `genereerDocumentidentificatie_Di02`), `lib/Service/Registration/StufZdsRegistrationLeg.php`, `tests/Unit/Service/Registration/StufZdsRegistrationLegTest.php`
- **acceptance_criteria**:
  - GIVEN recorded StUF answers WHEN a submission with one attachment is registered THEN the messages go out in the order of design D4 and each passes the bundled ZDS XSD
  - GIVEN a Fo03 answer WHEN `creeerZaak` is refused THEN the refusal is `outside-refused` with the fault text
- [ ] Implement
- [ ] Test
- note: Built: StufZdsRegistrationLeg over StufZdsCaseClient: creeerZaak with the mapped case, a BSN applicant as heeftAlsInitiator and the target's zaaktype (a source with `caseIdentificationStrategy: vooraf` asks genereerZaakIdentificatie first), the zaak identificatie as externalReference, a repeated hand-over answered from its zaak step, a Fo03 as outside-refused with the fault text, an open circuit or retrying message as source-unavailable retryable (StufZdsRegistrationLegTest). Left: the files (genereerDocumentidentificatie_Di02 and voegZaakdocumentToe_Lk01), which wait for reading the hand-over's files (Task 2), and a KvK applicant, which the StUF-ZDS message builder cannot name yet; both are refused as source-unavailable, not retryable. The XSD check of the messages is the builder's own (StufZdsMessageBuilderGoldenTest).

## Task 6: The JSON leg
- **spec_ref**: `specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002`
- **files**: `lib/Service/Registration/JsonRegistrationLeg.php`, `tests/Unit/Service/Registration/JsonRegistrationLegTest.php`
- **acceptance_criteria**:
  - GIVEN a target with a JSON Schema WHEN the mapped body does not match THEN the refusal is `mapping-failed`, `retryable` false, and no POST is made
- [x] Implement
- [x] Test

## Task 7: Idempotency and resume
- **spec_ref**: `specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003`
- **files**: `lib/Service/Registration/RegistrationService.php`, `tests/Unit/Service/Registration/RegistrationResumeTest.php`
- **acceptance_criteria**:
  - GIVEN a record in `started` with the case created WHEN the hand-over repeats THEN only the missing documents are created
  - GIVEN a record in `done` WHEN the hand-over repeats THEN no outside call is made
- [ ] Implement
- [ ] Test
- note: Built: a done record answers without an outside call (RegistrationResumeTest), and an unfinished one hands its steps to the leg, which skips what is done. The ZGW leg resumes after its last step (case, role, each zaakeigenschap). Left: only the missing documents, which come with the ZGW files (Task 3).

## Task 8: The list endpoint and the admin page
- **spec_ref**: `specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001`
- **files**: `lib/Controller/RegistrationTargetController.php`, `appinfo/routes.php`, `src/views/admin/RegistrationTargetsPage.vue`, `src/modals/RegistrationTargetModal.vue`, `l10n/nl.json`, `l10n/en.json`, `tests/e2e/registration-targets.spec.ts`
- **acceptance_criteria**:
  - GIVEN the list endpoint WHEN gate 5 runs THEN its method carries `#[NoAdminRequired]` and returns slug, title and kind only
  - GIVEN the modal WHEN kind `stuf-zds` is chosen THEN only the StUF-ZDS settings show
- [ ] Implement
- [ ] Test
- note: Built: GET /api/registration-targets (NoAdminRequired, slug, title and kind only) with its OpenAPI entry and RegistrationTargetControllerTest. Left: RegistrationTargetsPage.vue, the modal and the e2e test.

