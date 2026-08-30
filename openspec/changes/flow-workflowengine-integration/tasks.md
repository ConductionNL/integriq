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
- [ ] Implement
- [ ] Test

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
- [ ] Implement
- [ ] Test

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
- [ ] Implement
- [ ] Test

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
- [ ] Implement
- [ ] Test

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
  - GIVEN `workflowengine` disabled or `IAppManager` resolution throwing WHEN Integriq boots THEN no
    registration occurs, no exception propagates, and no error-level log is written
- [ ] Implement
- [ ] Test

### Task 6: Reuse NC's built-in File checks — confirm no new `ICheck`/`IEntity` is required
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-all-three-operations-must-be-admin-scoped-and-file-entity-scoped-only-req-005`
- **files**: none (verification-only task; no new files)
- **acceptance_criteria**:
  - GIVEN an NC admin opens Settings > Flow WHEN they configure a rule using one of the three new operations
    THEN NC's existing built-in `FileMimeType`/`FileName`/`FileSize`/`FileSystemTags` checks are usable
    unmodified to scope the rule
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/WorkflowEngine/`):
      `RunSynchronizationOperationTest`, `CallEndpointOperationTest`, `FireCloudEventOperationTest`
      (mock the target service, assert dispatch + per-flow failure isolation), `RegisterOperationsListenerTest`
      (assert `registerOperation()` called 3x), plus an `ApplicationTest`/boot-level test asserting
      feature-detection gating (registered when enabled, skipped when disabled, no throw when
      `IAppManager` resolution fails)
- [ ] Newman/Postman tests for new/changed API endpoints — N/A, no new REST endpoints are introduced
- [ ] Browser tests (Playwright MCP) for UI changes — N/A, no new Integriq frontend surface; the three
      operations render inside NC core's own Flow editor, which owns its own test coverage
- [ ] All tests pass (`composer test`)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — add a short "Trigger integrations from Flow" section
      explaining the three operations and linking to NC's Settings > Flow
- [ ] Screenshot captured and committed to `docs/images/` — the "Run synchronization" operation as it
      appears in NC's Flow rule editor

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added for each operation's
      `getDisplayName()`/`getDescription()` (English source strings per `IL10N::t()`, Dutch translation
      added to the existing `l10n/` catalog)
