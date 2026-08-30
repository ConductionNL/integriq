# Tasks: nextcloud-forms-connector

## Implementation Tasks

### Task 1: Forms exception types and `FormsClientInterface`
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-feature-detection--forms-app-absence-hides-the-type-entirely-req-001`
- **files**: `lib/Exception/FormsFeatureDisabledException.php`, `lib/Exception/FormsNotFoundException.php`, `lib/Exception/FormsPermissionDeniedException.php`, `lib/Exception/FormsUpstreamException.php`, `lib/Exception/FormsConfigException.php`, `lib/Service/Forms/FormsClientInterface.php`
- **acceptance_criteria**:
  - GIVEN the interface WHEN reviewed THEN it declares `getForm(ObjectEntity $source, int $formId): array`, `getSubmission(ObjectEntity $source, int $formId, int $submissionId): array`, and `listSubmissions(ObjectEntity $source, int $formId, int $page, int $pageSize): array`, with no `OCA\Forms\*` import anywhere in the file
- [ ] Implement
- [ ] Test

### Task 2: `FormsOcsClient` — concrete Forms REST client over `CallService`
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002`
- **files**: `lib/Service/Forms/FormsOcsClient.php`
- **acceptance_criteria**:
  - GIVEN a `Source` and a `formId` WHEN `getForm()` runs THEN it calls `CallService::call()` against the Forms `index.php/apps/forms/api/v3/forms/{formId}` endpoint (TENTATIVE base path, design.md Decision 1/discovery.md Finding 5) and returns `{id, title, questions: [{id, text, name, type}]}`
  - GIVEN a `Source`, `formId`, and `submissionId` WHEN `getSubmission()` runs THEN it returns `{id, formId, userId, timestamp, answers: [{id, questionId, questionName, text}]}`
  - GIVEN a `Source` and `formId` WHEN `listSubmissions()` runs with `page`/`pageSize` THEN it forwards `limit`/`offset` query params and returns one page of submissions (each including `answers`)
  - GIVEN a non-2xx/4xx upstream response WHEN any method runs THEN a `FormsUpstreamException`/`FormsNotFoundException`/`FormsPermissionDeniedException` is thrown per the same mapping shape as `TablesOcsClient`
- [ ] Implement
- [ ] Test

### Task 3: `FormsSyncAdapter` — feature detection + source pagination
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-feature-detection--forms-app-absence-hides-the-type-entirely-req-001`
- **files**: `lib/Service/Forms/FormsSyncAdapter.php`
- **acceptance_criteria**:
  - GIVEN the `forms` app is disabled WHEN `isEnabled()`/`assertEnabled()` are called THEN `isEnabled()` returns `false` and `assertEnabled()` throws `FormsFeatureDisabledException`, mirroring `TablesSyncAdapter`'s method names and `IAppManager::isEnabledForUser('forms', ...)`-only detection
  - GIVEN an enabled Forms app and a `Source`+`formId` WHEN `fetchAllSubmissions()` runs THEN it pages via `FormsClientInterface::listSubmissions()` until a short page is returned (safety-capped, mirroring `TablesSyncAdapter::MAX_PAGES`) and returns every submission with `id` at the top level (so the existing default `idPosition: 'id'` resolves it with no override)
- [ ] Implement
- [ ] Test

### Task 4: `nextcloud-form` source dispatch in `SynchronizationService`
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/synchronization-engine/spec.md#requirement-nextcloud-form-source-dispatch-req-016`
- **files**: `lib/Service/SynchronizationService.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a synchronization with `sourceType: nextcloud-form` WHEN `getAllObjectsFromSource()` runs THEN a new `case 'nextcloud-form':` dispatches to a new `getAllObjectsFromForm()` method delegating to `FormsSyncAdapter::fetchAllSubmissions()`, following the exact shape of the existing `nextcloud-table` case/`getAllObjectsFromTable()`
  - GIVEN a synchronization with `targetType: nextcloud-form` WHEN `updateTarget()` runs THEN it still throws `Unsupported target type: nextcloud-form` (no new case added to that switch)
  - GIVEN `Application.php::register()` WHEN reviewed THEN `FormsClientInterface` is bound to `FormsOcsClient` unconditionally (no feature-detection at DI-binding time — mirrors the existing `TablesClientInterface` binding), and `FormsSyncAdapter` is injected into `SynchronizationService` as an optional constructor parameter (nullable default, mirroring `?TablesSyncAdapter $tablesSyncAdapter=null`)
- [ ] Implement
- [ ] Test

### Task 5: `FormsAnswerResolver` — answer-by-question resolution, coercion, ambiguity guard
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003`
- **files**: `lib/Service/Forms/FormsAnswerResolver.php`
- **acceptance_criteria**:
  - GIVEN a form's `questions` and a submission's `answers`, and a numeric question reference WHEN `resolve()` runs THEN it matches `answers[].questionId` directly
  - GIVEN a question-text reference matching exactly one question WHEN `resolve()` runs THEN it resolves via the matched question's id
  - GIVEN a question-text reference matching two or more questions WHEN `resolve()` runs THEN it throws `FormsConfigException` naming the ambiguous text and every matching question id — never picks the first match
  - GIVEN a `multiple`/`multiple_unique`-type question with N matching answer rows WHEN `resolve()` runs THEN it returns an array of N `text` values (0, 1, or many); every other type returns a single scalar or `null`
- [ ] Implement
- [ ] Test

### Task 6: `action.kind: 'mapping'` dispatch in `EventService`
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-may-additionally-support-a-mapping-kind-req-010`
- **files**: `lib/Service/EventService.php`
- **acceptance_criteria**:
  - GIVEN `attemptDelivery()`'s `switch ($kind)` WHEN reviewed THEN a new `case 'mapping':` dispatches to a new `dispatchMappingAction()`, following the exact resolve-then-invoke-then-record shape of `dispatchSynchronizationAction()`/`dispatchJobAction()`
  - GIVEN `dispatchMappingAction()` WHEN it runs successfully THEN it: resolves `Mapping`+`Source` by id (not-found → `recordFailure`, retryable); fetches the full submission via `FormsClientInterface::getSubmission()` using `event.data.formId`/`event.data.submission.id`; fetches the form via `getForm()`; resolves every mapping-referenced answer via `FormsAnswerResolver` (Task 5); runs `MappingService::executeMapping()`; calls `CallService::call($source, $action['endpoint'], $action['method'] ?? 'POST', ['json' => $mapped])`; and calls `recordDeliverySuccess()` on a 2xx result
  - GIVEN any step throws WHEN `dispatchMappingAction()` runs THEN `recordFailure()` is called with the exception message and the existing retry/backoff bookkeeping applies unchanged
  - GIVEN a subscription with `action.kind: 'mapping'` matching a non-Forms event type WHEN dispatched THEN it fails with a config error (missing `formId`/`submission.id`) rather than a crash — no uncaught `\Throwable` escapes `attemptDelivery()`
- [ ] Implement
- [ ] Test

### Task 7: `FormsBridgeController` discovery endpoints + routes
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005`
- **files**: `lib/Controller/FormsBridgeController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `GET .../synchronizations/forms-bridge/status` WHEN called by an authenticated user THEN it returns `{"enabled": bool}` per `FormsSyncAdapter::isEnabled()`, mirroring `TablesBridgeController::status()` exactly (no admin-only gate on this one endpoint, matching the Tables precedent)
  - GIVEN `GET .../synchronizations/forms-bridge/forms?sourceId=` and `GET .../synchronizations/forms-bridge/forms/{formId}/questions?sourceId=` WHEN called THEN both call `ActionAuthService::requireAction($user, 'synchronization.formsBridge.discover')` (unseeded — falls back to admin-only per the existing action-matrix default, matching `tablesBridge.discover`'s posture) before resolving the Source and delegating to `FormsSyncAdapter`
  - GIVEN a thrown `FormsFeatureDisabledException`/`FormsNotFoundException`/`FormsPermissionDeniedException`/`FormsConfigException`/`FormsUpstreamException` WHEN any endpoint's try/catch maps it THEN the HTTP status matches `TablesBridgeController::mapException()`'s table exactly (409/404/exception-code/exception-code/502)
- [ ] Implement
- [ ] Test

### Task 8: `sync-editor-ui` — Forms source kind, form picker, field-mapping helper
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008`
- **files**: `src/components/synchronizations/SyncConfigWidget.vue` (or equivalent per current file layout — verify against HEAD before editing), a new field-mapping-helper component mirroring the existing `nextcloud-table` column-mapping helper's file
- **acceptance_criteria**:
  - GIVEN `GET .../synchronizations/forms-bridge/status` reports `enabled: false` WHEN the source-kind selector renders THEN `nextcloud-form` is not offered
  - GIVEN `nextcloud-form` is available WHEN it is selected as a source kind and a `Source` is picked THEN the widget fetches and presents that Source's forms, storing the chosen `formId` into `sourceConfig.formId`
  - GIVEN a form is selected WHEN the field-mapping helper renders THEN it lists each question's `text`/`id`/`type`, visually flags `multiple`/`multiple_unique` types as array-valued, and visually flags any question text that is not unique within the form as ambiguous
  - GIVEN the target-kind selector WHEN it renders THEN `nextcloud-form` is never offered there, regardless of the source-types response
- [ ] Implement
- [ ] Test

### Task 9: Unit tests — Forms services
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003`
- **files**: `tests/Unit/Service/Forms/FormsAnswerResolverTest.php`, `tests/Unit/Service/Forms/FormsSyncAdapterTest.php`, `tests/Unit/Service/Forms/FormsOcsClientTest.php`, `tests/stubs/OCA/Forms/` (event/entity stubs mirroring `tests/stubs/OCA/Tables`)
- **acceptance_criteria**:
  - GIVEN the spec's answer-resolution scenarios (id match, unambiguous text match, ambiguous text → exception, `multiple`-type array, unanswered → null) WHEN run as PHPUnit tests THEN all pass
  - GIVEN a mocked `IAppManager` reporting `forms` disabled WHEN `FormsSyncAdapter::assertEnabled()` is tested THEN it throws `FormsFeatureDisabledException`
- [ ] Implement
- [ ] Test

### Task 10: Integration tests — mocked `FormsClientInterface`, end-to-end dispatch
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004`
- **files**: `tests/Integration/Forms/FormsSyncIntegrationTest.php`, `tests/Integration/Forms/FormsOutboundMappingIntegrationTest.php`, `tests/vitest/formsBridge.spec.js`
- **acceptance_criteria**:
  - GIVEN a mocked `FormsClientInterface` returning a form with a `multiple`-type question and a submission with 2 selected-option answer rows WHEN a `nextcloud-form` synchronization runs THEN the submission is fetched, mapped, and written to an OR target with the array-valued field intact
  - GIVEN a mocked `FormsClientInterface` and a `Mapping` referencing an ambiguous question text WHEN an `action.kind: 'mapping'` event dispatch runs THEN the `event_message` is persisted `status='failed'` with the ambiguity error, and `retryCount` is incremented (retryable, per REQ-004)
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — Tasks 9
- [ ] Newman/Postman tests for new/changed API endpoints — `FormsBridgeController`'s 3 new routes (Task 7)
- [ ] Browser tests (Playwright MCP) for UI changes — Task 8 (form picker + field-mapping helper)
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — new `nextcloud-form` sync source and Forms outbound mapping, mirroring the existing Tables-bridge docs page
- [ ] Screenshot captured and committed to `docs/images/` — form picker + field-mapping helper

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added — new UI strings in Task 8 (form picker labels, field-mapping helper labels, ambiguous-question warning text); all i18n keys MUST be authored in English per project convention
