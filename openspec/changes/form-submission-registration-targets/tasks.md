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
- [x] Implement
- [x] Test
- note: Built: the event, listener, RegistrationService with every D2 refusal answered (never thrown) and the hand-over check (file ids only). `RegistrationFiles` reads each file from the files of the calling app's service account: the `userId` of the one `submission-registration` consumer whose `name` is the app (`RegistrationFilesTest`). No such consumer, two of them, or a missing or disabled account is `source-unavailable`, not retryable; a file the account cannot see is `invalid-handover`. The leg always gets the calling app, whatever app the hand-over names.

## Task 3: The ZGW leg
- **spec_ref**: `specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002`
- **files**: `lib/Service/Registration/ZgwRegistrationLeg.php`, `tests/Unit/Service/Registration/ZgwRegistrationLegTest.php`, `tests/postman/registration-zgw.json`
- **acceptance_criteria**:
  - GIVEN a ZGW mock WHEN a submission with two attachments is registered THEN one case, one initiator role, three informatieobjecten and three links are created in that order
  - GIVEN a KvK applicant WHEN the role is created THEN it is a `niet_natuurlijk_persoon` with the KvK number
- [x] Implement
- [ ] Test
- note: Built: `ZgwRegistrationLeg` over the case-system transport: `POST /zaken` with the mapped case, the target's zaaktype, bronorganisatie and verantwoordelijkeOrganisatie, the dates and the submission reference as a kenmerk; `POST /rollen` with the applicant as initiator (BSN as `natuurlijk_persoon`, KvK as `niet_natuurlijk_persoon`); the target's `zaakeigenschappen`; each call a step, so a repeated hand-over skips what is done; a 4xx as outside-refused with the API's detail, a 5xx, 429 or no answer as source-unavailable retryable (`ZgwRegistrationLegTest`). The PDF and each attachment become an EnkelvoudigInformatieObject (the target's `informatieobjecttypePdf` / `informatieobjecttypeAttachment`, falling back to `informatieobjecttype`), uploaded in parts through `ZgwDocumentDelivery` and linked to the case, each its own `document:<fileId>` step; a target without its Documenten source or types is refused before any call. Without a recorded case, `ZgwCaseLookup` first lists the cases of the target's zaaktype and bronorganisatie started on or after the submission day (the Zaken API has no kenmerk filter) and reuses one whose kenmerken carry the submission reference from the handing-over app, at most ten pages; a found case gets an initiator only when it has none (`ZgwCaseLookupTest`, `ZgwRegistrationLegTest`). Left: `tests/postman/registration-zgw.json`, a live check against Open Zaak.

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
- note: Built: StufZdsRegistrationLeg over StufZdsCaseClient: creeerZaak with the mapped case, a BSN applicant as heeftAlsInitiator and the target's zaaktype (a source with `caseIdentificationStrategy: vooraf` asks genereerZaakIdentificatie first), the zaak identificatie as externalReference, a repeated hand-over answered from its zaak step, a Fo03 as outside-refused with the fault text, an open circuit or retrying message as source-unavailable retryable (StufZdsRegistrationLegTest). After the zaak, the PDF and each attachment are read as the calling app and sent through `StufZdsDocumentDelivery` (genereerDocumentidentificatie_Di02, then voegZaakdocumentToe_Lk01 with the target's `documenttypeCode`), each its own `document:<fileId>` step, so a resumed hand-over sends only the missing documents; files on a target without a documenttype code are refused before the zaak. Left: a KvK applicant, which the shared StUF-ZDS message builder names only as a natuurlijk persoon by BSN; adding the niet-natuurlijk-persoon element changes that builder's XSD-checked golden output and needs a check against a real ZDS case system, so it is still refused as source-unavailable, not retryable. The XSD check of the messages is the builder's own (StufZdsMessageBuilderGoldenTest).

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
- [x] Implement
- [x] Test
- note: Built: a done record answers without an outside call (RegistrationResumeTest), and an unfinished one hands its steps to the leg, which skips what is done. The ZGW leg resumes after its last step (case, role, each document, each zaakeigenschap): a resumed hand-over creates only the missing documents (`ZgwRegistrationLegTest::testAResumedRegistrationCreatesOnlyTheMissingDocuments`). A leg refused part-way now hands the steps it completed back on the refusal (`RegistrationRefusal::withSteps`), and the failed record keeps them, so the retry after a refused document reuses the case and the documents already sent instead of creating them again (ZGW and StUF-ZDS; `RegistrationServiceTest`).

## Task 8: The list endpoint and the admin page
- **spec_ref**: `specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001`
- **files**: `lib/Controller/RegistrationTargetController.php`, `appinfo/routes.php`, `src/views/admin/RegistrationTargetsPage.vue`, `src/modals/RegistrationTargetModal.vue`, `l10n/nl.json`, `l10n/en.json`, `tests/e2e/registration-targets.spec.ts`
- **acceptance_criteria**:
  - GIVEN the list endpoint WHEN gate 5 runs THEN its method carries `#[NoAdminRequired]` and returns slug, title and kind only
  - GIVEN the modal WHEN kind `stuf-zds` is chosen THEN only the StUF-ZDS settings show
- [x] Implement
- [ ] Test
- note: Built: GET /api/registration-targets (NoAdminRequired, slug, title and kind only) with its OpenAPI entry and RegistrationTargetControllerTest. The admin page is manifest configuration, not a hand-built page: `src/manifest.d/submission-registration-targets.json` adds the *Registration targets* index page (admin-only menu entry under Connections) over `registrationTarget`, whose dialog shows each kind's settings object only when `kind` holds that kind (`visibleWhen`), and keeps sources and settings out of the columns (`registrationTargetsManifest.spec.js`). The "Test" dry run of design D6: `RegistrationService::dryRun` maps the pasted or sample hand-over, runs the JSON leg's schema check (`RegistrationBodyCheck`) and stops before any record, file read or outside call; `POST /api/registration-targets/{slug}/test` is admin-only (`RegistrationDryRunTest`, `RegistrationTargetControllerTest`); the page's *Test* row action opens `RegistrationTargetTestModal`, which shows the mapped body or the refusal (`registrationTargetTestModal.spec.js`). Left: `tests/e2e/registration-targets.spec.ts`, a browser test.

