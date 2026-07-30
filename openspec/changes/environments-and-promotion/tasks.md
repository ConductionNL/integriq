# Tasks: environments-and-promotion

## Implementation Tasks

### Task 1: Declare the environment and promotion_audit schemas via a register.d fragment
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001`
- **files**: `lib/Settings/register.d/environments-and-promotion.json`
- **acceptance_criteria**:
  - GIVEN a fresh `occ app:enable openconnector` WHEN `InitializeRegister` runs THEN the `environment` and `promotion_audit` schemas exist in the `openconnector` register
  - GIVEN the descriptor fragment WHEN inspected THEN `promotion_audit` declares `appendOnly: true` and `immutable: true`, matching `call_log`/`job_log`
- [ ] Implement
- [ ] Test

### Task 2: Seed local + acceptance environment objects and their connectivity Sources
- **spec_ref**: `openspec/changes/environments-and-promotion/design.md#seed-data`
- **files**: `lib/environments.seed.json` (new, following `lib/sources.seed.json` convention)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seed data loads THEN `local` and `acceptance` `environment` objects exist, each with a `sourceRef` pointing at a seeded `type: api` Source
- [ ] Implement
- [ ] Test

### Task 3: Environment CRUD service, controller, routes, and action keys
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001`
- **files**: `lib/Service/EnvironmentService.php`, `lib/Controller/EnvironmentController.php`, `appinfo/routes.php`, `lib/actions.seed.json`
- **acceptance_criteria**:
  - GIVEN an operator with `environment.manage` WHEN they `POST /api/environments` with a valid `sourceRef` THEN the object is created
  - GIVEN an `environment` whose `sourceRef` does not resolve WHEN it is used as a promotion target THEN the request is rejected with an actionable error naming the missing `sourceRef`
  - GIVEN a non-admin user without `environment.manage` WHEN they call any environment endpoint THEN `OCSForbiddenException` is returned
- [ ] Implement
- [ ] Test

### Task 4: PromotionService — local export + credentialRef placeholder scan
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-promotion-exports-locally-unchanged-and-dispatches-to-the-targets-existing-import-endpoints-req-002`
- **files**: `lib/Service/PromotionService.php`
- **acceptance_criteria**:
  - GIVEN a configuration id WHEN `PromotionService::export()` runs THEN it calls `ConfigurationService::exportConfiguration()` unchanged and returns its document verbatim
  - GIVEN an exported document containing a Source with `configuration.authentication.credentialRef` WHEN scanned THEN each placeholder is detected using the same shape `BrokeredCallService::isPlaceholder()` checks
- [ ] Implement
- [ ] Test

### Task 5: PromotionService — credential rebinding rewrite (reference-only, never plaintext)
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-credentialref-placeholders-are-re-bound-per-target-environment-never-resolved-to-a-secret-req-004`
- **files**: `lib/Service/PromotionService.php`
- **acceptance_criteria**:
  - GIVEN a `credentialBindings` entry for a flagged Source WHEN the document is rewritten THEN the target document's `credentialRef` is replaced with the supplied `credentialId`/`credentialName`, never resolved to plaintext
  - GIVEN no `credentialBindings` entry for a flagged Source WHEN the document is rewritten THEN the original `credentialRef` is sent verbatim, not dropped or defaulted
  - GIVEN this task's code WHEN reviewed THEN it never calls `CredentialBrokerService::resolveInjectable()` or any method that returns a plaintext secret
- [ ] Implement
- [ ] Test

### Task 6: PromotionService — remote dispatch via CallService against the target's sourceRef
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-promotion-exports-locally-unchanged-and-dispatches-to-the-targets-existing-import-endpoints-req-002`
- **files**: `lib/Service/PromotionService.php`
- **acceptance_criteria**:
  - GIVEN a target environment's `sourceRef` Source WHEN a preview or confirm call is dispatched THEN it goes through `CallService::call()` against that Source, unmodified
  - GIVEN the dispatch WHEN it completes THEN a `CallLog` is created exactly as for any other Source call
- [ ] Implement
- [ ] Test

### Task 7: PromotionService — merge target preview response with credentialRefsNeedingRebind
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003`
- **files**: `lib/Service/PromotionService.php`
- **acceptance_criteria**:
  - GIVEN a target's `/api/configurations/import/preview` response WHEN merged THEN `creates`/`updates`/`collisions`/`unresolvedReferences`/`credentialsNeedingReentry` are passed through unchanged from the target
  - GIVEN flagged `credentialRef` placeholders WHEN merged THEN they appear under a `credentialRefsNeedingRebind` array not present in the target's own response
- [ ] Implement
- [ ] Test

### Task 8: PromotionController — preview and confirm endpoints with confirmation + action gates
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005`
- **files**: `lib/Controller/PromotionController.php`, `appinfo/routes.php`, `lib/actions.seed.json`
- **acceptance_criteria**:
  - GIVEN `POST /api/promotions` without `confirmed: true` WHEN called THEN HTTP 400 is returned and nothing is dispatched
  - GIVEN a user without `environment.promote` WHEN they call preview or confirm THEN `OCSForbiddenException` is returned before any export or remote call
- [ ] Implement
- [ ] Test

### Task 9: promotion_audit — write append-only audit object after every promotion attempt
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-every-promotion-attempt-is-recorded-in-an-append-only-promotion-audit-log-req-006`
- **files**: `lib/Service/PromotionService.php`, `lib/Controller/PromotionController.php`
- **acceptance_criteria**:
  - GIVEN a successful promotion WHEN it completes THEN a `promotion_audit` object is written with `outcome: "success"`, counts-only `previewSummary`, and the dispatch `CallLog` id
  - GIVEN a failed promotion (e.g. target returns 404) WHEN the attempt completes THEN a `promotion_audit` object is written with `outcome: "failed"` and no fabricated `written` summary
  - GIVEN an existing `promotion_audit` object WHEN a PUT/DELETE is attempted via the OR object API THEN it is rejected by `appendOnly`/`immutable` enforcement
- [ ] Implement
- [ ] Test

### Task 10: Formalize the credentialRef pass-through contract on configuration-export-import
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/configuration-export-import/spec.md#requirement-credentialref-authentication-placeholders-pass-through-export-and-import-unresolved-and-untranslated-req-010`
- **files**: `tests/Unit/Service/ConfigurationHandlers/SourceHandlerTest.php` (extend), `tests/Unit/Service/ConfigurationServiceTest.php` (extend)
- **acceptance_criteria**:
  - GIVEN a Source with a `credentialRef` placeholder WHEN exported THEN the placeholder is byte-for-byte unchanged in the output (regression test pinning existing, previously-undocumented behaviour)
  - GIVEN an OAS document with a non-resolving `credentialRef` WHEN imported THEN the write succeeds and the reference is stored verbatim
- [ ] Implement
- [ ] Test

### Task 11: Environments & Promotion manifest-v2 UI page
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#non-functional-requirements`
- **files**: `src/views/EnvironmentsPromotion.vue`, manifest-v2 page config (per `openconnector-app-manifest`)
- **acceptance_criteria**:
  - GIVEN an operator opens the Environments & Promotion page THEN registered environments are listed with CRUD actions
  - GIVEN the environment/target select fields THEN each `NcSelect` carries an explicit `inputLabel`
- [ ] Implement
- [ ] Test

### Task 12: Promote flow — diff preview + credential rebind prompts + confirm, in its own modal
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003`
- **files**: `src/modals/PromotePreviewModal.vue`
- **acceptance_criteria**:
  - GIVEN an operator selects a configuration group and target environment WHEN they open the promote flow THEN the diff preview (creates/updates/collisions/credentialRefsNeedingRebind) renders before any confirm button is enabled
  - GIVEN the modal markup WHEN inspected THEN it lives entirely in `src/modals/PromotePreviewModal.vue`, never inlined in a parent component
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — `PromotionServiceTest`, `EnvironmentServiceTest`, credentialRef scan/rebind cases
- [ ] Newman/Postman tests for new/changed API endpoints — `/api/environments*`, `/api/promotions*`
- [ ] Browser tests (Playwright MCP) for UI changes — environment CRUD, promote flow diff preview + confirm
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — Environments & Promotion page, promotion workflow, credential rebinding
- [ ] Screenshot captured and committed to `docs/images/`

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added for the new UI page, promote flow, and error messages
