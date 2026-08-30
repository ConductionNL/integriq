# Proposal: flow-workflowengine-integration

## Summary
Register three OpenConnector actions — "Run synchronization", "Call endpoint", and "Fire CloudEvent" — as
Nextcloud `OCP\WorkflowEngine` operations, so admins can trigger them from NC's built-in Flow UI (Settings >
Flow) using the same file/tag conditions they already use for core Flow rules (e.g. "file tagged X → run
synchronization Y"). Each operation is a thin adapter: its `onEvent()` decodes the operation's stored
settings and calls the existing `SynchronizationService::synchronize()`, a new thin `EndpointService`
trigger entrypoint, or `EventService::emitCloudEvent()` — no synchronization/endpoint/event logic is
duplicated. This also replaces the "webhook URL as the only way to react to a file event" workaround some
admins currently use.

## Motivation
NC's Flow is file-centric and cannot call external services or run an OpenConnector integration. Today, an
admin who wants "when a file is tagged, push it to system X" must either build a webhook receiver and point
a synthetic upload at it, or write a cron-polling synchronization that has no relation to the triggering
file event. Registering OpenConnector's existing services as WorkflowEngine operations puts the integration
engine directly inside the automation UI file admins already know, without introducing a second, competing
automation surface (that is `visual-flow-orchestration`, explicitly out of scope here).

## Affected Projects
- [x] Project: `openconnector` — new `OCP\WorkflowEngine\IOperation`/`ISpecificOperation` implementations,
  one new thin `EndpointService` entrypoint, `Application.php` boot registration, admin-facing operation UI
  metadata (name/description/icon), unit tests.

## Scope

### In Scope
1. Three `ISpecificOperation` implementations, all scoped to NC core's `\OCA\WorkflowEngine\Entity\File`
   entity (the only `IEntity` guaranteed present across NC 28-34): `RunSynchronizationOperation`,
   `CallEndpointOperation`, `FireCloudEventOperation`.
2. Registration via `OCP\WorkflowEngine\Events\RegisterOperationsEvent`, listened to through
   `IEventDispatcher::addServiceListener()` in `Application.php::register()`, feature-detected on
   `IAppManager::isEnabledForAnyUser('workflowengine')` (same pattern already used for the optional
   Tables/Forms triggers in `nextcloud-event-triggers`).
3. Each operation's `onEvent()` calls `$ruleMatcher->getFlows(false)`, decodes each matching flow's
   `operation` JSON settings, and delegates to the corresponding existing service — `synchronizationId` →
   `SynchronizationService::getSynchronization()` + `synchronize()`; `endpointId` (+ optional static
   `parameters`) → a new thin `EndpointService::triggerFromFlow()` that synthesizes a minimal `IRequest` and
   calls the existing `handleRequest()`; `type`/`source`/`subject`/`data` → `EventService::emitCloudEvent()`.
4. `validateOperation()` on each class decodes and validates the settings shape and (for sync/endpoint)
   confirms the target object still exists, throwing `\UnexpectedValueException` per the `IOperation`
   contract when it does not.
5. Reuse of NC's existing built-in `File`-entity checks (`FileMimeType`, `FileName`, `FileSize`,
   `FileSystemTags`) for admin-configured scoping conditions — no new `ICheck` classes.
6. Unit tests: one per operation's `onEvent()` (mock the target service, assert dispatch), plus a boot test
   confirming the `RegisterOperationsEvent` listener is registered when `workflowengine` is enabled and is a
   no-op when it is not.

### Out of Scope
- A visual flow builder inside OpenConnector — that is `visual-flow-orchestration`.
- Windmill integration.
- A generic (non-`ISpecificOperation`) operation usable against arbitrary third-party `IEntity`
  implementations — deferred; every in-scope use case is file-triggered.
- Asynchronous/background-job dispatch of `RunSynchronizationOperation` — v1 runs synchronously inside
  `onEvent()` (see design.md Risk 1); deferring a queued variant avoids adding new engine logic to this
  change.

## Approach
Follow the exact pattern NC core's `workflowengine` app documents for third-party operations: listen for
`RegisterOperationsEvent` (fired by `OCP\WorkflowEngine\IManager` on every operator-list read) and call
`$event->registerOperation()` for each of the three operation instances, resolved from the DI container.
Each operation class is a pure adapter — no business logic beyond decoding its own settings string and
calling one existing service method. Full technical rationale, verified `OCP\WorkflowEngine` interface
surface, and the `EndpointService::triggerFromFlow()` design are in design.md.

## New Dependencies
None — `OCP\WorkflowEngine\*` ships as part of Nextcloud core (bundled `workflowengine` app), already present
on every supported NC version (28-34).

## Impact
- `lib/AppInfo/Application.php`: one new private `registerWorkflowEngineOperations()` method called from
  `register()`.
- New `lib/WorkflowEngine/` namespace: three operation classes + the `RegisterOperationsEvent` listener.
- `lib/Service/EndpointService.php`: one new thin public method (`triggerFromFlow()`) plus a private
  synthetic-`IRequest` builder.
- No database schema changes — operation settings persist inside NC's own `flow_operations` table
  (`operation` column), which OpenConnector does not own.
- No new REST endpoints, so no cross-project API contract.

## Cross-Project Dependencies
None — this is self-contained within OpenConnector. It consumes NC core's `OCP\WorkflowEngine` API and
OpenConnector's own `SynchronizationService`/`EndpointService`/`EventService`; no other `apps-extra` project
calls into or is called by this change.

## Risks

### Risk 1: Synchronous dispatch inside a live NC request
**Severity:** Medium — **Mitigation:** `onEvent()` fires inside the same request that triggered the NC
event (e.g. a file upload). A slow external synchronization or endpoint call would delay that request. Each
per-flow dispatch is wrapped in `try/catch (\Throwable)` + logged so a failure never propagates into NC's
event dispatcher and breaks the triggering request; a queued/background variant is flagged as a fast-follow
if this proves too slow in practice, deliberately deferred to avoid adding new engine logic here.

### Risk 2: `EndpointService::triggerFromFlow()` reaches into non-`OCP` internals
**Severity:** Medium — **Mitigation:** `EndpointService::handleRequest()` requires a live `OCP\IRequest`;
there is no OCP-blessed way to construct one outside an HTTP request. `triggerFromFlow()` constructs NC's
internal `\OC\AppFramework\Http\Request` (not `OCP`, but the same concrete class NC's own HTTP kernel uses,
constructible from a plain `$vars` array + `IRequestId` + `IConfig`). This call is isolated in one private
helper, wrapped in `try/catch (\Throwable)`, and logs-and-no-ops on failure rather than throwing into
`onEvent()`, so an NC version that changes this internal class's constructor degrades one operation instead
of crashing the triggering request.

### Risk 3: `workflowengine` app can be disabled by the admin
**Severity:** Low — **Mitigation:** Registration is feature-detected via
`IAppManager::isEnabledForAnyUser('workflowengine')`, mirroring the existing Tables/Forms gate in
`nextcloud-event-triggers`. When disabled, no registration occurs and no error is logged (absence is a
normal state).

## Rollback Strategy
Revert the `Application.php` registration call and delete the `lib/WorkflowEngine/` namespace and the
`EndpointService::triggerFromFlow()` addition. No migration to reverse (no schema changes). Any Flow rules
an admin already configured using these operations become inert (NC's workflowengine UI shows them as
referencing an unknown operator class) — no data loss, since the rule's own checks/conditions remain stored
in NC's own tables.

## Open Questions
None outstanding — the `OCP\WorkflowEngine` interface surface, the `EndpointService`/`SynchronizationService`/
`EventService` entrypoints, and the synthetic-`IRequest` approach were all verified against this repo's local
Nextcloud 33.0.0 checkout (see design.md).
