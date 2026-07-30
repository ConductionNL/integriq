# flow-workflowengine-operations Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- `flow-workflowengine-integration` _(in progress)_

## Purpose
Registers OpenConnector's `SynchronizationService`, `EndpointService`, and `EventService` as NC core
`OCP\WorkflowEngine` operations ("Run synchronization", "Call endpoint", "Fire CloudEvent"), so an admin can
trigger them from NC's built-in Flow UI using the same file/tag conditions core Flow rules already use. Each
operation is a thin adapter over the existing service — no synchronization/endpoint/event business logic is
reimplemented. This capability is independent of, and complementary to, `nextcloud-event-triggers` (the
unconditional NC-event-to-CloudEvent normalization pipeline); this capability is the admin-configurable,
per-rule layer.

## ADDED Requirements

### Requirement: WorkflowEngine operation registration MUST be feature-detected on the `workflowengine` app (REQ-001)

`OCA\OpenConnector\AppInfo\Application::register()` MUST call a private `registerWorkflowEngineOperations()`
method that resolves `OCP\App\IAppManager` and registers
`OCA\OpenConnector\WorkflowEngine\RegisterOperationsListener` against
`OCP\WorkflowEngine\Events\RegisterOperationsEvent` via `IEventDispatcher::addServiceListener()` ONLY WHEN
`IAppManager::isEnabledForAnyUser('workflowengine')` returns `true`. The `IAppManager` resolution and
feature-detection check MUST be wrapped in `try/catch (\Throwable)`; on failure the method MUST degrade to
"WorkflowEngine operations unavailable this boot" (log a warning, register nothing) rather than throwing
into `Application::register()` and blocking app registration.

#### Scenario: WorkflowEngine operations are registered when the app is enabled

- **GIVEN** a Nextcloud instance with the `workflowengine` app enabled
- **WHEN** OpenConnector boots
- **THEN** `RegisterOperationsListener` SHALL be registered against `RegisterOperationsEvent`
- **AND** NC's Flow rule editor SHALL list "Run synchronization", "Call endpoint", and "Fire CloudEvent" as
  available operations for the File entity

#### Scenario: WorkflowEngine is unavailable — no registration, no crash

- **GIVEN** a Nextcloud instance where the `workflowengine` app is disabled (or `IAppManager` resolution
  throws)
- **WHEN** OpenConnector boots
- **THEN** no `RegisterOperationsListener` registration SHALL occur
- **AND** no exception SHALL propagate out of `Application::register()` — app registration SHALL complete
  normally
- **AND** no error-level log entry SHALL be written (a disabled `workflowengine` app is a normal, expected
  state, not a fault)

### Requirement: The "Run synchronization" operation's `onEvent()` MUST dispatch to `SynchronizationService` (REQ-002)

`OCA\OpenConnector\WorkflowEngine\RunSynchronizationOperation implements ISpecificOperation` MUST declare
`getEntityId(): string` returning `\OCA\WorkflowEngine\Entity\File::class`. Its `onEvent(string $eventName,
Event $event, IRuleMatcher $ruleMatcher)` MUST call `$ruleMatcher->getFlows(false)` and, for each returned
flow, `json_decode` the flow's `operation` string. When decoding succeeds and yields a non-empty
`synchronizationId`, it MUST call `SynchronizationService::getSynchronization($synchronizationId)` followed
by `SynchronizationService::synchronize($synchronization)` — no synchronization logic MUST be reimplemented
in the operation class. Each flow's dispatch MUST be wrapped in `try/catch (\Throwable)`; a failure (decode
error, missing/deleted synchronization, or a thrown exception from `synchronize()`) MUST be logged and MUST
NOT prevent the remaining matched flows in the same `onEvent()` call from being processed, and MUST NOT
propagate into the NC event dispatcher that invoked `onEvent()`.

#### Scenario: a matching file event runs the configured synchronization

- **GIVEN** an admin-configured Flow rule using "Run synchronization" with settings
  `{"synchronizationId": "abc-123"}`, scoped to files tagged `push-to-erp`
- **WHEN** a file matching the rule's checks is tagged `push-to-erp` and NC fires the corresponding
  `MapperEvent`
- **THEN** `RunSynchronizationOperation::onEvent()` SHALL call
  `SynchronizationService::getSynchronization('abc-123')`
- **AND** SHALL call `SynchronizationService::synchronize()` with the resolved synchronization

#### Scenario: multiple matching flows each run their own configured synchronization

- **GIVEN** two active Flow rules both using "Run synchronization", targeting `synchronizationId`
  `abc-123` and `def-456` respectively, both matching the same triggering file event
- **WHEN** the event fires
- **THEN** `onEvent()` SHALL call `synchronize()` once for `abc-123` and once for `def-456`
- **AND** a failure dispatching `abc-123` SHALL NOT prevent `def-456` from being dispatched

#### Scenario: a deleted target synchronization is logged and does not crash the triggering request

- **GIVEN** a Flow rule referencing a `synchronizationId` that no longer exists
- **WHEN** the rule's triggering file event fires
- **THEN** `SynchronizationService::getSynchronization()` SHALL raise `DoesNotExistException`
- **AND** `onEvent()` SHALL log the failure and return without throwing
- **AND** the NC request that triggered the underlying file event SHALL complete normally

### Requirement: The "Call endpoint" operation's `onEvent()` MUST dispatch to `EndpointService::triggerFromFlow()` (REQ-003)

`OCA\OpenConnector\WorkflowEngine\CallEndpointOperation implements ISpecificOperation` MUST resolve, for
each flow returned by `$ruleMatcher->getFlows(false)`, a non-empty `endpointId` from the decoded `operation`
settings (`{"endpointId": "<uuid>", "parameters": {...optional...}}`), fetch the target via
`EndpointService::getEndpointById($endpointId)`, and — when non-null — call
`EndpointService::triggerFromFlow($endpoint, $settings['parameters'] ?? [])`. `EndpointService` MUST expose
`triggerFromFlow(ObjectEntity $endpoint, array $parameters = []): Response`, which MUST synthesize an
`OCP\IRequest` (via the existing NC `Request` concrete implementation, constructed from `$parameters` plus a
`GET` method) and delegate to the existing `EndpointService::handleRequest()` — no endpoint routing, auth,
or proxying logic MUST be reimplemented outside `handleRequest()`. Synthetic-request construction MUST be
wrapped in `try/catch (\Throwable)`; a failure MUST be logged and MUST NOT propagate into the NC event
dispatcher.

#### Scenario: a matching file event calls the configured endpoint

- **GIVEN** an admin-configured Flow rule using "Call endpoint" with settings `{"endpointId": "ep-1"}`
- **WHEN** a file matching the rule's checks triggers the configured event
- **THEN** `CallEndpointOperation::onEvent()` SHALL call `EndpointService::getEndpointById('ep-1')`
- **AND** SHALL call `EndpointService::triggerFromFlow()` with the resolved endpoint
- **AND** `triggerFromFlow()` SHALL delegate to the existing `handleRequest()` without duplicating its
  routing/proxy logic

#### Scenario: a missing endpoint is logged and skipped, not thrown

- **GIVEN** a Flow rule referencing an `endpointId` that does not resolve to an object
- **WHEN** the rule's triggering event fires
- **THEN** `EndpointService::getEndpointById()` SHALL return `null`
- **AND** `onEvent()` SHALL log the failure and skip this flow without throwing

### Requirement: The "Fire CloudEvent" operation's `onEvent()` MUST dispatch to `EventService::emitCloudEvent()` (REQ-004)

`OCA\OpenConnector\WorkflowEngine\FireCloudEventOperation implements ISpecificOperation` MUST resolve, for
each flow returned by `$ruleMatcher->getFlows(false)`, non-empty `type` and `source` strings from the
decoded `operation` settings (`{"type": "...", "source": "...", "subject": null, "data": {...optional
static literal...}}`), and call `EventService::emitCloudEvent(type: $type, source: $source, subject:
$settings['subject'] ?? null, data: array_merge(['eventName' => $eventName], $settings['data'] ?? []))` — no
CloudEvent persistence, delivery, retry, or dead-letter logic MUST be reimplemented; `emitCloudEvent()`'s
existing behavior (persisting the `event` OR-object and invoking `EventService::processEvent()`) MUST be
used unchanged.

#### Scenario: a matching file event fires the configured CloudEvent

- **GIVEN** an admin-configured Flow rule using "Fire CloudEvent" with settings `{"type":
  "nl.conduction.flow.file-tagged", "source": "/openconnector/flow"}`, and an active `event_subscription`
  matching that `type`
- **WHEN** a file matching the rule's checks triggers the configured event
- **THEN** `FireCloudEventOperation::onEvent()` SHALL call `EventService::emitCloudEvent()` with `type =
  'nl.conduction.flow.file-tagged'` and `source = '/openconnector/flow'`
- **AND** a new `event` OR-object SHALL be persisted and `EventService::processEvent()` SHALL be invoked on
  it, producing a matching `event_message` for the subscription

#### Scenario: static configured data is merged into the emitted CloudEvent

- **GIVEN** a Flow rule with settings `{"type": "...", "source": "...", "data": {"reason": "tagged for
  export"}}`
- **WHEN** the rule's triggering event fires
- **THEN** the persisted `event.data` SHALL contain both `reason: "tagged for export"` and `eventName` (the
  NC event name that triggered dispatch)

### Requirement: All three operations MUST be admin-scoped and File-entity-scoped only (REQ-005)

`RunSynchronizationOperation`, `CallEndpointOperation`, and `FireCloudEventOperation` MUST each return
`$scope === IManager::SCOPE_ADMIN` from `isAvailableForScope(int $scope): bool` — none of the three MUST be
available in `IManager::SCOPE_USER`. Each MUST declare `getEntityId(): string` returning
`\OCA\WorkflowEngine\Entity\File::class` (NC core's only bundled `IEntity`); none MUST register a custom
`IEntity` or a custom `ICheck` — admin-configured scoping conditions MUST use NC's existing built-in File
checks unmodified.

#### Scenario: the operations are unavailable in user-scope Flow

- **GIVEN** an NC instance with per-user Flow enabled (`IManager::SCOPE_USER` active for a non-admin)
- **WHEN** a non-admin user opens their personal Files > Automation editor
- **THEN** "Run synchronization", "Call endpoint", and "Fire CloudEvent" SHALL NOT appear in the available
  operations list

#### Scenario: the operations are available in the admin Flow editor, scoped to File

- **GIVEN** an NC admin opens Settings > Flow
- **WHEN** they view the list of available operations
- **THEN** "Run synchronization", "Call endpoint", and "Fire CloudEvent" SHALL appear, each usable only
  against File-entity events and File-entity checks (mime type, name, size, system tags)

### Requirement: `validateOperation()` MUST reject unresolvable or malformed target settings before a rule can be saved (REQ-006)

Each operation's `validateOperation(string $name, array $checks, string $operation): void` MUST
`json_decode` the `$operation` string and throw `\UnexpectedValueException` (with a translated message) when:
decoding fails; the operation-specific required key(s) are missing or empty (`synchronizationId` for "Run
synchronization"; `endpointId` for "Call endpoint"; `type` and `source` for "Fire CloudEvent"); or — for
"Run synchronization"/"Call endpoint" only — the referenced OpenRegister object does not resolve via
`SynchronizationService::getSynchronization()`/`EndpointService::getEndpointById()` at validation time.

#### Scenario: saving a Flow rule with a non-existent synchronization is rejected

- **GIVEN** an admin configuring a "Run synchronization" operation with `synchronizationId` pointing at a
  non-existent object
- **WHEN** NC's Flow editor calls `validateOperation()` while saving the rule
- **THEN** `\UnexpectedValueException` SHALL be thrown
- **AND** the rule SHALL NOT be persisted

#### Scenario: saving a Flow rule with malformed settings JSON is rejected

- **GIVEN** an operation settings string that is not valid JSON
- **WHEN** `validateOperation()` runs
- **THEN** `\UnexpectedValueException` SHALL be thrown

#### Scenario: saving a valid Flow rule succeeds

- **GIVEN** a "Fire CloudEvent" operation with settings `{"type": "com.example.foo", "source":
  "/openconnector/flow"}`
- **WHEN** `validateOperation()` runs
- **THEN** no exception SHALL be thrown and the rule SHALL be persisted

## Non-Functional Requirements

- **Performance:** `onEvent()` dispatch runs synchronously inside the NC request that fired the triggering
  event; per-flow work is wrapped in `try/catch` so one slow/failing flow does not block sibling flows from
  being attempted, but the overall `onEvent()` call MAY add latency to that request proportional to the
  target synchronization/endpoint's own response time (documented limitation, see design.md Risk 1).
- **Accessibility:** No new frontend surface is introduced by this capability — operation display
  name/description/icon render inside NC core's own Flow editor UI, which owns its own accessibility
  posture.
- **Internationalization:** Operation display name and description strings MUST be written in English
  source text (`IL10N::t()`), consistent with this app's i18n convention; translation into other locales
  flows through NC's existing translation mechanism unchanged.

## Acceptance Criteria

- [ ] `RegisterOperationsListener` registers all three operations only when `workflowengine` is enabled
- [ ] `RunSynchronizationOperation::onEvent()` calls `SynchronizationService::getSynchronization()` +
      `synchronize()` for each matched flow, per-flow failure-isolated
- [ ] `CallEndpointOperation::onEvent()` calls `EndpointService::getEndpointById()` +
      `EndpointService::triggerFromFlow()` for each matched flow
- [ ] `EndpointService::triggerFromFlow()` delegates to the existing `handleRequest()` with a synthesized
      request, duplicating no routing/proxy logic
- [ ] `FireCloudEventOperation::onEvent()` calls `EventService::emitCloudEvent()` for each matched flow
- [ ] All three operations return `SCOPE_ADMIN`-only from `isAvailableForScope()` and
      `\OCA\WorkflowEngine\Entity\File::class` from `getEntityId()`
- [ ] `validateOperation()` on each operation rejects malformed/unresolvable settings with
      `\UnexpectedValueException`
- [ ] No registration occurs, and no error is logged, when `workflowengine` is disabled

## Notes

- This capability is independent of `nextcloud-event-triggers` (see design.md Context) — no requirement in
  that spec is modified by this change.
- No new `ICheck`/`IEntity` is registered (Decision 8 in design.md); admins reuse NC core's existing
  File-entity checks.
- Asynchronous/queued dispatch of `RunSynchronizationOperation` is explicitly out of scope (see proposal.md
  Out of Scope) — a deliberate v1 limitation, not an oversight.
