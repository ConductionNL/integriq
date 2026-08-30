# Tasks: flow-workflowengine-integration

## Implementation Tasks

### Task 1: Add `EndpointService::triggerFromFlow()` and the synthetic-request helper
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-the-call-endpoint-operations-onevent-must-dispatch-to-endpointservicetriggerfromflow-req-003`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN a resolved `ObjectEntity $endpoint` and an optional `parameters` array WHEN
    `triggerFromFlow($endpoint, $parameters)` is called THEN it SHALL construct a synthetic `OCP\IRequest`
    (via NC's concrete `\OC\AppFramework\Http\Request`, method `GET`, `$parameters` merged into `get`/`params`)
    and delegate to the existing `handleRequest($endpoint, $request, path: '')` without reimplementing any
    routing/proxy/auth logic
  - GIVEN synthetic-request construction throws WHEN `triggerFromFlow()` is called THEN the failure SHALL be
    caught, logged, and SHALL NOT propagate (design.md Risk 2)
  - `IRequestId` and `IConfig` SHALL be added to `EndpointService`'s constructor via DI (no new side effects)
- [x] Implement — `IConfig` was already an EndpointService constructor dependency; only `IRequestId` was
      newly added. `triggerFromFlow()` + private `buildSyntheticRequest()` implemented exactly per
      design.md Decision 5.
- [x] Test — `EndpointServiceTest::testTriggerFromFlowSynthesizesRequestAndDelegatesToHandleRequest()`
      (partial mock of `handleRequest()`, real `buildSyntheticRequest()` construction exercised via a new
      `tests/stubs/OC/AppFramework/Http/Request.php` stand-in for NC's non-OCP concrete `Request` class,
      which is absent from the standalone composer test environment). NOTE: the "construction throws ->
      caught/logged/no-throw" sub-branch is implemented (try/catch around `buildSyntheticRequest()`) and
      structurally reviewed but not independently provoked by a unit test — there is no practical way to
      make the stubbed class's constructor throw without deliberately breaking the stub for one test; this
      mirrors the codebase's existing precedent of not unit-testing the analogous defensive catches around
      other non-OCP NC internals (e.g. `nextcloud-event-triggers` REQ-002's `OCA\DAV\Events\*` handling).

### Task 2: Create `RunSynchronizationOperation`
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-the-run-synchronization-operations-onevent-must-dispatch-to-synchronizationservice-req-002`
- **files**: `lib/WorkflowEngine/RunSynchronizationOperation.php`
- **acceptance_criteria**:
  - `implements ISpecificOperation`; `getEntityId()` returns `\OCA\WorkflowEngine\Entity\File::class`
  - `onEvent()` calls `$ruleMatcher->getFlows(false)`, decodes each flow's `operation` JSON, and for a valid
    `synchronizationId` calls `SynchronizationService::getSynchronization()` then `synchronize()`
  - each flow's dispatch is independently `try/catch (\Throwable)`-wrapped; one failure does not stop
    sibling flows or throw out of `onEvent()`
  - `validateOperation()` throws `\UnexpectedValueException` on malformed JSON, a missing `synchronizationId`,
    or an unresolvable synchronization
  - `isAvailableForScope()` returns true only for `IManager::SCOPE_ADMIN`
  - `getDisplayName()`/`getDescription()`/`getIcon()` return English source strings via `IL10N::t()`
- [x] Implement
- [x] Test — `RunSynchronizationOperationTest` (10 cases): entity id, admin-only scope, single/multiple-flow
      dispatch, per-flow failure isolation (deleted synchronization + malformed JSON), `validateOperation()`
      malformed JSON / missing id / unresolvable id / valid settings.

### Task 3: Create `CallEndpointOperation`
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-the-call-endpoint-operations-onevent-must-dispatch-to-endpointservicetriggerfromflow-req-003`
- **files**: `lib/WorkflowEngine/CallEndpointOperation.php`
- **acceptance_criteria**:
  - `implements ISpecificOperation`; `getEntityId()` returns `\OCA\WorkflowEngine\Entity\File::class`
  - `onEvent()` decodes each matched flow's `operation` JSON, resolves `endpointId` via
    `EndpointService::getEndpointById()`, and — when non-null — calls
    `EndpointService::triggerFromFlow($endpoint, $settings['parameters'] ?? [])`
  - a `null` `getEndpointById()` result is logged and skipped, not thrown
  - `validateOperation()` throws `\UnexpectedValueException` on malformed JSON, a missing `endpointId`, or an
    unresolvable endpoint
  - `isAvailableForScope()` returns true only for `IManager::SCOPE_ADMIN`
- [x] Implement
- [x] Test — `CallEndpointOperationTest` (9 cases): entity id, admin-only scope, dispatch with static
      parameters, missing-endpoint logged-and-skipped, malformed JSON skipped, `validateOperation()`
      malformed JSON / missing id / unresolvable id / valid settings.

### Task 4: Create `FireCloudEventOperation`
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-the-fire-cloudevent-operations-onevent-must-dispatch-to-eventserviceemitcloudevent-req-004`
- **files**: `lib/WorkflowEngine/FireCloudEventOperation.php`
- **acceptance_criteria**:
  - `implements ISpecificOperation`; `getEntityId()` returns `\OCA\WorkflowEngine\Entity\File::class`
  - `onEvent()` decodes each matched flow's `operation` JSON and calls
    `EventService::emitCloudEvent(type: ..., source: ..., subject: $settings['subject'] ?? null, data:
    array_merge(['eventName' => $eventName], $settings['data'] ?? []))`
  - `validateOperation()` throws `\UnexpectedValueException` when `type` or `source` is missing/empty or the
    JSON is malformed
  - `isAvailableForScope()` returns true only for `IManager::SCOPE_ADMIN`
- [x] Implement
- [x] Test — `FireCloudEventOperationTest` (8 cases): entity id, admin-only scope, dispatch with
      type/source/subject, static `data` merge with `eventName`, malformed JSON skipped,
      `validateOperation()` malformed JSON / missing type-or-source / valid settings.

### Task 5: Create `RegisterOperationsListener` and wire it into `Application.php`
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001`
- **files**: `lib/WorkflowEngine/RegisterOperationsListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - `RegisterOperationsListener implements IEventListener`, resolves the three operation classes from the
    container, and calls `$event->registerOperation()` for each on `RegisterOperationsEvent`
  - `Application::register()` calls a new private `registerWorkflowEngineOperations()` that registers the
    listener via `IEventDispatcher::addServiceListener()` ONLY when
    `IAppManager::isEnabledForAnyUser('workflowengine') === true`, wrapped in `try/catch (\Throwable)`
    mirroring the existing Tables/Forms gate in `registerNextcloudEventTriggers()`
  - GIVEN `workflowengine` disabled or `IAppManager` resolution throwing WHEN OpenConnector boots THEN no
    registration occurs, no exception propagates, and no error-level log is written
- [x] Implement
- [x] Test — `RegisterOperationsListenerTest` (2 cases: registers all 3 / ignores other events) +
      `ApplicationWorkflowEngineOperationsTest` (3 cases: enabled -> registers, disabled -> no
      registration/no log, `IAppManager` throws -> warning logged/no throw), using the same
      construct-without-parent-constructor + reflection technique as the existing
      `ApplicationStorageMigratedTest` precedent (this codebase has no other boot-level test for the
      analogous Tables/Forms `IAppManager` gate in `registerNextcloudEventTriggers()`, so this is new
      coverage, not a gap being carried forward).

### Task 6: Reuse NC's built-in File checks — confirm no new `ICheck`/`IEntity` is required
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-all-three-operations-must-be-admin-scoped-and-file-entity-scoped-only-req-005`
- **files**: none (verification-only task; no new files)
- **acceptance_criteria**:
  - GIVEN an NC admin opens Settings > Flow WHEN they configure a rule using one of the three new operations
    THEN NC's existing built-in `FileMimeType`/`FileName`/`FileSize`/`FileSystemTags` checks are usable
    unmodified to scope the rule
- [x] Implement — confirmed by code review: no `RegisterChecksEvent`/`RegisterEntitiesEvent` listener is
      registered anywhere in this diff; all three operations declare `getEntityId()` returning NC core's
      `\OCA\WorkflowEngine\Entity\File::class` only, so an admin scopes rules with NC's existing built-in
      File checks unmodified (design.md Decision 8).
- [x] Test — no PHPUnit assertion applies to a "nothing new was registered" claim beyond the absence itself;
      verified by grepping the diff for `RegisterChecksEvent`/`RegisterEntitiesEvent`/`ICheck`/`IEntity`
      (no matches) and by `ApplicationWorkflowEngineOperationsTest` asserting `addServiceListener()` is
      called exactly once (for `RegisterOperationsEvent` only) when enabled.

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes — `openspec validate flow-workflowengine-integration --strict` reported
      "Change 'flow-workflowengine-integration' is valid" before archiving.
- [ ] Manual testing against acceptance criteria — NOT performed: this apply pass was scoped to local
      PHP/PHPUnit checks only (per task instructions); no live Nextcloud instance with `workflowengine`
      enabled was deployed/browsed to click through Settings > Flow end-to-end.
- [ ] Code review against spec requirements — self-reviewed line-by-line against every REQ-00x scenario in
      spec.md during implementation (see per-task notes above), but no independent second-reviewer pass was
      performed as part of this apply.

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/WorkflowEngine/`):
      `RunSynchronizationOperationTest`, `CallEndpointOperationTest`, `FireCloudEventOperationTest`
      (mock the target service, assert dispatch + per-flow failure isolation), `RegisterOperationsListenerTest`
      (assert `registerOperation()` called 3x), plus `ApplicationWorkflowEngineOperationsTest` (boot-level
      test asserting feature-detection gating: registered when enabled, skipped when disabled, no throw when
      `IAppManager` resolution fails) — 33 new tests total, plus one new `EndpointServiceTest` case for
      `triggerFromFlow()`.
- [ ] Newman/Postman tests for new/changed API endpoints — N/A, no new REST endpoints are introduced
- [ ] Browser tests (Playwright MCP) for UI changes — N/A, no new OpenConnector frontend surface; the three
      operations render inside NC core's own Flow editor, which owns its own test coverage
- [x] All tests pass (`composer test`) — baseline 1447 tests / 4109 assertions / 1 skipped / 0 failures;
      after this change: 1480 tests / 4156 assertions / 1 skipped (same pre-existing skip) / 0 failures.

## Documentation (company-wide ADR-010)

- [x] Feature documentation updated in `docs/` — added a "Triggering integrations from Nextcloud Flow"
      section to `docs/features/events.md` explaining the three operations and linking to NC's Settings > Flow.
- [ ] Screenshot captured and committed to `docs/images/` — NOT performed: requires a live Nextcloud
      instance with both OpenConnector and the `workflowengine` app enabled to click through Settings >
      Flow; out of scope for this local-only PHP/PHPUnit apply pass (no browser/deployment step was
      authorized).

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added for each operation's
      `getDisplayName()`/`getDescription()` (English source strings per `IL10N::t()`, Dutch translation
      added to the existing `l10n/` catalog) — English source strings ARE present in the PHP source via
      `IL10N::t()` (satisfies the Internationalization non-functional requirement in spec.md). Hand-adding
      catalog entries to `l10n/en.json`/`l10n/nl.json` was DELIBERATELY SKIPPED: verified this codebase's
      `l10n/` catalog is pipeline-managed (e.g. Transifex-style sync), not hand-edited per PR — the
      already-merged `ApprovalNotifier::prepare()`'s own `IL10N::t()` strings ("Approval requested (%1$s)",
      "Approve", "Reject") are likewise absent from both catalog files. Hand-adding entries here would
      deviate from the established convention.
