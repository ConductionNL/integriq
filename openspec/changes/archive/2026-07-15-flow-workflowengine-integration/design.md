# Design: flow-workflowengine-integration

## Context
NC's Flow (`workflowengine` app, bundled with core, disable-able like `tables`/`forms`) lets admins build
`WHEN <entity event> AND <checks> THEN <operation>` rules through a settings UI. OpenConnector already owns
three services that do useful work in response to events: `SynchronizationService::synchronize()`,
`EndpointService::handleRequest()` (endpoint runtime), and `EventService::emitCloudEvent()` (CloudEvents
producer). None of them are reachable from NC's Flow UI today. This change adds three thin
`OCP\WorkflowEngine\IOperation` adapters so an admin can wire "file tagged → run synchronization" (or → call
endpoint / → fire CloudEvent) using the automation surface they already know, without touching
`SynchronizationService`/`EndpointService`/`EventService` internals.

This is a *different* mechanism from the existing `nextcloud-event-triggers` capability
(`NextcloudFileEventListener` et al.), which unconditionally normalizes every NC file/calendar/tables/forms
event into a CloudEvent, fanned out to whatever `event_subscription`s exist — no admin-configurable
per-rule scoping. This change is the admin-UI-configurable layer: a specific Flow rule, with specific
checks (mime type, tag, filename pattern...), targeting one specific synchronization/endpoint/CloudEvent
type. The two capabilities are complementary and independently useful; this change does not modify
`nextcloud-event-triggers`.

Naming note to avoid confusion during implementation: this app already has an unrelated internal
"flow"/`FlowToken` concept (`lib/Service/Helper/FlowToken.php`, spec `flow-token-helper`) — a mutable
container that threads request/response/sync-input/sync-output payloads through the mapping/rule pipeline
inside `EndpointService`/`SynchronizationService`. That is NOT NC's WorkflowEngine "Flow" feature and is
unrelated to this change; the two must not be conflated in code comments or variable names. This change's
operation classes do not construct or touch `FlowToken`.

## Goals / Non-Goals

**Goals**
- Let an admin configure "file event + checks → run one specific synchronization/endpoint/CloudEvent" from
  NC's Settings > Flow UI.
- Zero duplicated business logic: every operation's `onEvent()` is a decode-then-delegate adapter.
- Degrade cleanly (no crash, no registration) when `workflowengine` is disabled.

**Non-Goals**
- A visual flow builder inside OpenConnector (`visual-flow-orchestration`).
- Windmill integration.
- Generic (non-file) entity support.
- Asynchronous/queued dispatch (see Risks).
- Any change to `nextcloud-event-triggers`.

## Decisions

### Decision 1: `ISpecificOperation` scoped to NC core's `File` entity, not a custom `IEntity`
All three operations implement `ISpecificOperation` (extends `IOperation`) with
`getEntityId(): string { return \OCA\WorkflowEngine\Entity\File::class; }`. This is the only `IEntity` NC
core ships (verified in discovery.md finding 6), and it directly matches every in-scope example. Registering
a custom `IEntity` for, say, "OpenConnector domain events" was considered and rejected for v1: it would
require also implementing `IEntity::prepareRuleMatcher()`/`getEvents()`/`isLegitimatedForUserId()` against
OpenConnector's own event vocabulary, which is new engine surface the brief explicitly scopes out ("no new
engine logic"). File-triggered automation covers the brief's stated use case fully.

### Decision 2: Registration via `RegisterOperationsEvent` listener, not direct `IManager::registerOperation()`
`Application.php::register()` gains a new private method `registerWorkflowEngineOperations(IRegistrationContext
$context, IEventDispatcher $dispatcher)`, called alongside the existing `registerNextcloudEventTriggers()`
call. It feature-detects with the same try/catch pattern as the Tables/Forms gate:

```php
try {
    $appManager = $this->getContainer()->get(\OCP\App\IAppManager::class);
    if ($appManager->isEnabledForAnyUser('workflowengine') === true) {
        $dispatcher->addServiceListener(
            eventName: \OCP\WorkflowEngine\Events\RegisterOperationsEvent::class,
            className: \OCA\OpenConnector\WorkflowEngine\RegisterOperationsListener::class
        );
    }
} catch (\Throwable $e) {
    // Degrade to "WorkflowEngine operations unavailable this boot" — see registerNextcloudEventTriggers()
    // for the identical precedent.
}
```

`RegisterOperationsListener implements IEventListener` resolves the three operation classes from the
container and calls `$event->registerOperation($operation)` for each — this is the ONLY documented
registration path (discovery.md finding 2); calling `IManager::registerOperation()` directly from `boot()`
would silently do nothing durable, since `Manager` re-dispatches `RegisterOperationsEvent` on every
operator-list read rather than caching a boot-time registration.

Alternative considered: registering unconditionally like the Files/Calendar triggers (no feature-detection).
Rejected because, unlike `OCP\Files\Events\Node\*`, the `workflowengine` *app* itself (not just the
interface) can be disabled by an admin — registering a listener for an event a disabled app's `Manager`
never dispatches is harmless, but skipping it is more correct and matches the brief's explicit "gate
cleanly" instruction.

### Decision 3: `onEvent()` calls `getFlows(false)` and loops — never trusts pre-filtering
Per discovery.md finding 3, `onEvent()` is invoked for every matching entity+event combination regardless of
per-rule checks. Each operation's `onEvent()`:

```php
public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void
{
    foreach ($ruleMatcher->getFlows(false) as $flow) {
        try {
            $settings = json_decode($flow['operation'], true, flags: JSON_THROW_ON_ERROR);
            // ... decode + delegate, see Decision 4/5/6 ...
        } catch (\Throwable $e) {
            $this->logger->warning('WorkflowEngine operation dispatch failed', ['exception' => $e]);
            // Deliberately swallowed — see Risk 1. One bad flow must not stop the others
            // or propagate into the NC event that triggered this onEvent() call.
        }
    }
}
```

### Decision 4: `RunSynchronizationOperation` settings shape and dispatch
Settings JSON: `{"synchronizationId": "<OpenRegister uuid>"}`. `onEvent()` calls
`$this->synchronizationService->getSynchronization($settings['synchronizationId'])` then
`->synchronize($synchronization)` — the exact two-call sequence `SynchronizationService`'s own callers (cron,
controller) already use. `validateOperation()` performs the same `getSynchronization()` lookup inside a
try/catch and throws `\UnexpectedValueException` (translated message) when it raises
`DoesNotExistException`, satisfying `IOperation::validateOperation()`'s contract.

### Decision 5: `CallEndpointOperation` settings shape, and the new `EndpointService::triggerFromFlow()`
Settings JSON: `{"endpointId": "<uuid>", "parameters": {...optional static key/values...}}`.
`EndpointService::handleRequest()` cannot be called without a live `OCP\IRequest` (discovery.md finding 4).
Rather than reimplement any part of endpoint routing/proxying inside the operation class (which would
violate "no new engine logic"), `EndpointService` gains one new thin public method:

```php
public function triggerFromFlow(ObjectEntity $endpoint, array $parameters = []): Response
{
    $request = $this->buildSyntheticRequest($parameters);
    return $this->handleRequest(endpoint: $endpoint, request: $request, path: '');
}

private function buildSyntheticRequest(array $parameters): IRequest
{
    return new \OC\AppFramework\Http\Request(
        vars: ['method' => 'GET', 'get' => $parameters, 'params' => $parameters],
        requestId: $this->requestId,
        config: $this->config,
    );
}
```

(`$this->requestId`/`$this->config` are `IRequestId`/`IConfig`, added to `EndpointService`'s constructor —
both are ordinary, already-common NC services with no side effects to inject.) `CallEndpointOperation::onEvent()`
resolves the endpoint via `EndpointService::getEndpointById()` (returns `?ObjectEntity`, already null-safe)
and, when found, calls `triggerFromFlow()`; when not found, logs and skips (same as `validateOperation()`'s
`\UnexpectedValueException` path at rule-save time — a rule referencing a deleted endpoint fails validation
before it can even be saved, so a missing endpoint at `onEvent()` time means it was deleted after the rule
was configured).

Alternative considered and rejected: writing a minimal hand-rolled `IRequest` implementation inside
OpenConnector implementing only the handful of methods `doHandleRequest()` currently calls. Rejected because
`IRequest` is a large interface (dozens of methods covering headers, cookies, server vars, CSRF token,
overloaded array access); a partial implementation would throw `\Error` the moment `doHandleRequest()`'s
internals touch any unimplemented method in a future NC or OpenConnector version, whereas NC's own concrete
`Request` class is guaranteed complete and is exercised by NC core on every real request.

### Decision 6: `FireCloudEventOperation` settings shape and dispatch
Settings JSON: `{"type": "com.example.foo", "source": "/openconnector/flow", "subject": null, "data":
{...optional static literal...}}`. `onEvent()` calls
`$this->eventService->emitCloudEvent(type: $settings['type'], source: $settings['source'], subject:
$settings['subject'] ?? null, data: array_merge(['eventName' => $eventName], $settings['data'] ?? []))` — a
direct pass-through to the existing producer, identical in shape to how `NextcloudFileEventListener`
already calls the sibling `handleNextcloudEvent()` method for the always-on pipeline. `type` and `source`
are required non-empty strings in `validateOperation()`; no DB lookup is needed since CloudEvent
type/source are admin-chosen free text, not references to a stored object.

Rejected alternative: automatically enriching `data` with the triggering file's metadata (path, fileid,
mimetype) by inspecting `$ruleMatcher->getEntity()`. `IEntity` does not expose a generic "return contextual
data" method (only `IContextPortation`'s narrower URL/display-text hooks, implemented by `File` but not part
of the operation-facing contract) — reaching into `File`'s internals here would be new engine logic reading
another app's private state. Deferred; the operation's static `data` literal plus `eventName` is sufficient
for v1 and keeps the operation a pure adapter.

### Decision 7: `isAvailableForScope()` restricts to `IManager::SCOPE_ADMIN`
All three operations return `$scope === IManager::SCOPE_ADMIN` from `isAvailableForScope()`. These
operations can trigger arbitrary external HTTP calls (endpoints), pull/push data across systems
(synchronizations), and emit events fanned out to webhook subscribers — system-wide effects disproportionate
to a single NC user's own data. Restricting to admin scope (Settings > Flow, not the personal Files >
Automation surface NC exposes to regular users when user-scope Flow is enabled) matches this codebase's
existing default-deny/admin-gated posture (e.g. `ActionAuthService`'s matrix seeds every new action
`["admin"]`). See Security Considerations.

### Decision 8: no new `ICheck`, no new `IEntity`
Per discovery.md findings 5-6: admins scope rules using NC's existing built-in `File` checks unmodified.
Nothing new is registered for `RegisterChecksEvent`/`RegisterEntitiesEvent`.

## Risks / Trade-offs
- **[Risk] Synchronous dispatch inside the triggering NC request** → Mitigation: per-flow `try/catch
  (\Throwable)` in `onEvent()` (Decision 3); documented as a known v1 limitation; a background-job variant
  (`IJobList::add()`) is a deliberately deferred fast-follow, out of scope here because it is new engine
  logic (queueing semantics), not adapter logic.
- **[Risk] `triggerFromFlow()` depends on `\OC\AppFramework\Http\Request`, a non-`OCP` class** →
  Mitigation: isolated in one private helper, wrapped in `try/catch (\Throwable)`, logs-and-skips on
  failure rather than throwing into `onEvent()`. Precedent: `nextcloud-event-triggers` REQ-002 already
  applies the same defensive pattern to another non-`OCP` NC internal (`OCA\DAV\Events\*`).
- **[Risk] `workflowengine` app can be disabled** → Mitigation: feature-detected registration (Decision 2).
- **[Trade-off] File-entity-only scope** → Simpler, verified-stable v1; generic entity support deferred
  (Decision 1).

## Migration Plan
No schema changes (see migration.md skip rationale — Flow rule settings persist in NC's own
`flow_operations.operation` column, which OpenConnector does not own or migrate). Deploy: ship the new
`lib/WorkflowEngine/` classes and the `Application.php` registration change; no data backfill, no feature
flag needed beyond the existing `IAppManager::isEnabledForAnyUser('workflowengine')` runtime check. Rollback:
see proposal.md Rollback Strategy.

## Open Questions
None outstanding.

## Nextcloud Integration
- Controllers: none (no new endpoints).
- Services: `EndpointService` (new `triggerFromFlow()` + `buildSyntheticRequest()`, new `IRequestId`/`IConfig`
  constructor deps), `SynchronizationService` (unchanged, called as-is), `EventService` (unchanged, called
  as-is).
- New classes: `lib/WorkflowEngine/RunSynchronizationOperation.php`,
  `lib/WorkflowEngine/CallEndpointOperation.php`, `lib/WorkflowEngine/FireCloudEventOperation.php`,
  `lib/WorkflowEngine/RegisterOperationsListener.php`.
- Events/Hooks: listens to `OCP\WorkflowEngine\Events\RegisterOperationsEvent`; each operation is invoked by
  NC core's `workflowengine` app via `IOperation::onEvent()` against NC core's `\OCP\Files::*` /
  `MapperEvent` family (the same events `\OCA\WorkflowEngine\Entity\File` already supports — no new event
  subscription by this app).

## Security Considerations
- `isAvailableForScope()` restricts all three operations to `IManager::SCOPE_ADMIN` (Decision 7) — only NC
  admins can create Flow rules using these operations; NC's own Flow settings UI already enforces that only
  admins reach the admin-scope rule editor.
- `validateOperation()` on `RunSynchronizationOperation`/`CallEndpointOperation` resolves the target object
  via the existing services' own lookups (`getSynchronization()`/`getEndpointById()`), which already apply
  whatever access rules those services apply elsewhere — no new authorization surface is introduced, and no
  bypass of OpenRegister's existing object resolution is added.
- `triggerFromFlow()`'s synthetic request carries no cookies/session/CSRF token (`csrfTokenManager` left
  `null`) — it must never be used for an endpoint action that assumes an authenticated NC user context
  beyond what the endpoint's own configured auth (source credentials, etc.) already provides. This mirrors
  how `EndpointService` already treats system/cron-triggered calls elsewhere in this codebase.
- No new user-supplied input surface: operation settings are configured by an admin through NC's own Flow UI
  (a trusted actor per Decision 7), not through any request OpenConnector parses from an untrusted caller.

## File Structure
```
lib/
  AppInfo/
    Application.php                       # + registerWorkflowEngineOperations()
  WorkflowEngine/
    RegisterOperationsListener.php        # IEventListener<RegisterOperationsEvent>
    RunSynchronizationOperation.php       # ISpecificOperation
    CallEndpointOperation.php             # ISpecificOperation
    FireCloudEventOperation.php           # ISpecificOperation
  Service/
    EndpointService.php                   # + triggerFromFlow(), buildSyntheticRequest()
tests/
  Unit/
    WorkflowEngine/
      RunSynchronizationOperationTest.php
      CallEndpointOperationTest.php
      FireCloudEventOperationTest.php
      RegisterOperationsListenerTest.php
```

## Trade-offs
See Decisions 1, 5, 6 above for the specific alternatives considered and rejected at each point (custom
`IEntity` vs. reusing core `File`; hand-rolled `IRequest` vs. NC's concrete `Request`; automatic vs. static
CloudEvent data enrichment).
