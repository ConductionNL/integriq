# Tasks: automation-integration-regression-tests

Kind: code. Matrix row `integriq:auto-regression-tests`.

### Task 1: Test case and test run schemas
- **spec_ref**: openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-stores-an-input-an-expected-output-and-its-subject-req-irt-001
- **files**: `lib/Settings/register.d/integration-regression-tests.json`, `src/manifest.d/integration-regression-tests.json`
- **acceptance_criteria**:
  - GIVEN the app is installed WHEN the register is imported THEN `integration_test_case` and `integration_test_run` exist in the `integriq` register
  - GIVEN the seed runs WHEN an administrator opens the test cases page THEN two cases for `lowercase-keys` are listed
- [ ] Implement
- [ ] Test (`node tests/validate-register.js`, `node tests/validate-manifest.js`, and a Playwright check that the page lists the seeded cases)

### Task 2: Suite runner for mapping and synchronization cases
- **spec_ref**: openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
- **files**: `lib/Service/RegressionSuiteService.php`, `tests/Unit/Service/RegressionSuiteServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a mapping with a passing and a failing case WHEN the suite runs THEN one run record holds total 2, passed 1, failed 1 and a diff for the failure
  - GIVEN a synchronization case WHEN the suite runs THEN CallService is never called and no target object is saved
  - GIVEN an ignored path WHEN only that path differs THEN the case passes
- [ ] Implement
- [ ] Test (PHPUnit with a mocked CallService and ObjectService asserting zero calls and zero saves)

### Task 3: Controller, routes and actions
- **spec_ref**: openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-running-and-recording-suites-are-named-actions-req-irt-004
- **files**: `lib/Controller/RegressionSuiteController.php`, `appinfo/routes.php`, `lib/actions.seed.json`
- **acceptance_criteria**:
  - GIVEN a user without `regression-suite.run` WHEN they post to the run route THEN the answer is 403 and no run record exists
  - GIVEN an administrator WHEN they post to the run route THEN the answer is 200 with the run record
- [ ] Implement
- [ ] Test (PHPUnit on the controller for the 403 and the 200)

### Task 4: Save a trace step as a test case
- **spec_ref**: openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
- **files**: `lib/Service/RegressionSuiteService.php`, `src/views/ExecutionTrace/TraceDetailPage.vue`, `src/modals/SaveTestCaseModal.vue`
- **acceptance_criteria**:
  - GIVEN a trace with a mapping step WHEN the administrator saves it as a case THEN the case input and expected output equal the step snapshot
  - GIVEN a redacted field in the snapshot WHEN the dialog opens THEN the field is named and editable before save
- [ ] Implement
- [ ] Test (Playwright in `tests/e2e/integration-regression-tests.spec.ts`)

### Task 5: Run test cases from the detail pages
- **spec_ref**: openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
- **files**: `src/views/wrappers/MappingDetailPage.vue`, `src/views/Synchronization/SynchronizationDetailPage.vue`
- **acceptance_criteria**:
  - GIVEN a mapping with cases WHEN the engineer chooses "Run test cases" THEN the result shows the pass and fail counts and the diff of each failure
- [ ] Implement
- [ ] Test (Playwright in `tests/e2e/integration-regression-tests.spec.ts`)

### Task 6: Regression check on the promotion preview
- **spec_ref**: openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-the-promotion-preview-shows-failing-cases-before-a-change-goes-live-req-irt-005
- **files**: `lib/Service/PromotionService.php`, `lib/Controller/PromotionController.php`, the promotion preview dialog
- **acceptance_criteria**:
  - GIVEN a configuration with a failing case WHEN the preview runs THEN the failing case is listed
  - GIVEN a failing case and no override WHEN the promotion is confirmed THEN it is refused and CallService is not called
  - GIVEN a failing case and the override WHEN the promotion is confirmed THEN the audit record stores the failed count and the override
- [ ] Implement
- [ ] Test (PHPUnit on PromotionService for refusal and audit; Playwright for the preview list)

## Verification

- `openspec validate automation-integration-regression-tests --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/integration-regression-tests.spec.ts` green against a local instance
