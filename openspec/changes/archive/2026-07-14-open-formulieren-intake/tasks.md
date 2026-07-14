# Tasks — open-formulieren-intake

## 1. Data model

### Task 1: Declare the `openformulieren_submission` and `openformulieren_form_mapping` schemas
- **spec_ref**: `openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN `openformulieren_submission` (status enum `received|mapped|handed_off|failed`) and `openformulieren_form_mapping` schemas both exist
  - GIVEN `openformulieren_submission` WHEN inspected THEN it declares `x-openregister-handoff` targeting `https://openregister.app/ns#Case`
  - GIVEN the register's schemas list WHEN compared to `components.schemas` THEN both new slugs are listed
- [x] Implement
- [x] Test

## 2. Mapping layer

### Task 2: Add `FormFieldMapper` + `MappingResolutionException`
- **spec_ref**: `openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002`
- **files**: `lib/Service/OpenFormulieren/FormFieldMapper.php`, `lib/Exception/MappingResolutionException.php`
- **acceptance_criteria**:
  - GIVEN a full, resolvable mapping WHEN mapped THEN all declared fields populate
  - GIVEN a mapping config missing a mandatory contract field key WHEN validated THEN it is rejected
  - GIVEN a declared `from`/`template` entry whose key is absent at runtime WHEN resolved THEN `MappingResolutionException` is thrown — never the literal path/template string
- [x] Implement
- [x] Test

## 3. Ingest orchestration

### Task 3: Add `OpenFormulierenIntakeService` (ingest, attachment fetch, handoff trigger)
- **spec_ref**: `openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#requirement-openformulieren_submission-lifecycle-with-per-submission-isolation-req-003`
- **files**: `lib/Service/OpenFormulierenIntakeService.php`, `lib/Exception/OpenFormulierenException.php`
- **acceptance_criteria**:
  - GIVEN a valid signed submission for a mapped form WHEN ingested THEN the submission reaches `status=mapped`
  - GIVEN a mandatory field resolution failure on one submission WHEN a second, valid submission is processed after it THEN only the first is `status=failed`; the second independently reaches `mapped`
  - GIVEN an unreachable attachment URL WHEN ingested THEN the submission still reaches `mapped`, with that attachment recorded `failed`
  - GIVEN an authenticated handoff trigger WHEN `HandoffService::execute()` succeeds THEN the submission becomes `handed_off` and successfully stored attachments are best-effort copied onto the created Case
- [x] Implement
- [x] Test

## 4. REST surface

### Task 4: Add `OpenFormulierenController` (inbound, status, handoff) + routes
- **spec_ref**: `openspec/changes/open-formulieren-intake/specs/open-formulieren-intake/spec.md#requirement-signed-inbound-submission-webhook-req-001`
- **files**: `lib/Controller/OpenFormulierenController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a valid HMAC signature WHEN `POST /api/open-formulieren/submissions` is called THEN the submission is accepted and processed
  - GIVEN an invalid or missing signature WHEN the endpoint is called THEN HTTP 401 is returned with no state change
  - GIVEN an authenticated session WHEN `POST /api/open-formulieren/submissions/{id}/handoff` is called THEN the handoff executes under the caller's own RBAC
- [x] Implement
- [x] Test

## 5. Pre-existing gap fix (encountered while wiring `FileService`)

### Task 5: Correct the stale `FileService` test stub
- **spec_ref**: N/A — pre-existing drift unrelated to this capability's requirements, fixed per house convention (same class of gap as `bankfeed_connection`/`bankfeed_batch`, fixed alongside `notifynl-sms-channel`)
- **files**: `tests/stubs/OCA/OpenRegister/Service/FileService.php`
- **acceptance_criteria**:
  - GIVEN the stub WHEN compared to `OCA\OpenRegister\Service\FileService` at HEAD THEN `addFile`/`getFiles`/`copyFile` signatures match
  - GIVEN the existing test suite WHEN rerun THEN no test breaks from the stub correction
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate open-formulieren-intake --strict` passes
- [x] Manual testing against acceptance criteria — exercised via the PHPUnit suite
- [x] Code review against spec requirements — self-reviewed; see Deviations below

## Deviations

- **Webhook HMAC secret is plaintext, not ICrypto-encrypted (REQ-001).**
  Verified against 3 existing inbound webhooks at HEAD (`PeppolController`,
  `NotifyNlController`, `PaymentsController`/`PaymentIntentService`) — all
  read `configuration.webhookSignature.secret` directly, none route it
  through `ICrypto`. `ICrypto` is reserved for asymmetric API
  keys/private keys elsewhere in this app. See design.md §1.4.
- **No automatic handoff execution at webhook-receipt time (REQ-004).**
  Verified `HandoffService` v1 has no system-user privilege lane
  (`lib/Listener/HandoffLifecycleListener.php` line 12, OpenRegister HEAD).
  A separate authenticated endpoint executes the handoff as the real acting
  user. See design.md §1.1.
- **`requester` contract field not mapped.** No OR-managed party register
  exists in this fleet to resolve a BSN/KvK auth context against; the
  "anonymous request omits the requester" scenario is the documented
  supported path (hydra `ns#Case` contract spec). See design.md §1.2.
