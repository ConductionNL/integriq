# Discovery: flow-workflowengine-integration

## Question
The context brief flags that `OCP\WorkflowEngine`'s interface surface "has shifted across NC versions" and
asks for per-version gating. Two concrete questions had to be answered before design/specs could be written:
1. Is the `IOperation`/`ISpecificOperation`/`IEntity`/`IManager` registration contract actually stable across
   NC 28-34, or does it require version-specific branching?
2. `SynchronizationService::synchronize()` and `EventService::emitCloudEvent()` are directly callable, but
   `EndpointService::handleRequest()` requires a live `OCP\IRequest` — is there a way to invoke an endpoint
   from a Flow trigger (no real inbound HTTP request) without reimplementing endpoint-runtime logic?

## Approach Taken
- Read the local Nextcloud checkout's `OCP\WorkflowEngine` stubs directly
  (`/home/rubenlinde/nextcloud-docker-dev/workspace/server/lib/public/WorkflowEngine/`), which is a
  `33.0.0 dev` checkout (`version.php`) — inside the NC 28-34 target range, at the top end.
- Read `apps/workflowengine/lib/Manager.php`, `apps/workflowengine/lib/AppInfo/Application.php`, and
  `apps/workflowengine/lib/Service/RuleMatcher.php` (NC core's own `workflowengine` app — the engine that
  implements `IManager` and drives `onEvent()`), to see the real registration and dispatch flow rather than
  inferring it from interface docblocks alone.
- Read `apps/workflowengine/lib/Entity/File.php` (NC core's only bundled `IEntity` implementation) to
  confirm which entity ID/event names a file-triggered operation would key against.
- Grepped `apps/` for any third-party `implements IOperation` example to copy a real pattern — none exists
  in this checkout (NC's built-in tag-based operations were removed; `getBuildInOperators()` in `Manager.php`
  returns `// None yet`), so the design is derived directly from the interface contracts + the engine's
  dispatch code, not copied from a working example.
- Read this repo's `lib/Service/SynchronizationService.php`, `lib/Service/EndpointService.php`, and
  `lib/Service/EventService.php` public method signatures, and `lib/AppInfo/Application.php`'s existing
  `registerNextcloudEventTriggers()` for the house feature-detection pattern.
- Checked `lib/private/AppFramework/Http/Request.php` (NC's concrete `IRequest` implementation) for a
  constructible shape usable outside a live HTTP request.

## Findings

**1. The registration contract is stable, not shifted, across NC 28-34.** Every relevant interface member —
`IManager::registerOperation()`/`registerEntity()`/`registerCheck()`, `IOperation::onEvent()` /
`validateOperation()` / `isAvailableForScope()`, `ISpecificOperation::getEntityId()`,
`IEntity::getEvents()`/`prepareRuleMatcher()`, and the `RegisterOperationsEvent` /
`RegisterEntitiesEvent` / `RegisterChecksEvent` classes — carries an `@since 18.0.0` (or earlier, `@since
9.1` for the oldest `ICheck` methods) annotation in the local stubs. NC 18 predates this app's NC 28 floor by
five major versions, so there is no version-specific branching to write for the *registration mechanism*
itself. What genuinely varies by NC version is unrelated to this change (e.g. calendar event class family,
already handled in `nextcloud-event-triggers`).

The one thing that IS confirmed version-sensitive and worth gating defensively: whether the `workflowengine`
app is *enabled* on a given instance (an admin can disable it, exactly like `tables`/`forms`) — that is a
runtime feature-detection concern, not an interface-shift concern, and is handled the same way REQ-003/004
already handle Tables/Forms.

**2. `RegisterOperationsEvent` is the correct — and only documented — registration path.**
`apps/workflowengine/lib/Manager.php::getOperatorList()` dispatches `RegisterOperationsEvent` (via
`IEventDispatcher::dispatchTyped()`) every time the operator list is read (not just once at boot), and the
`IOperation` interface docblock itself says: "Listen to `OCP\WorkflowEngine\Events\RegisterOperationsEvent`
at the IEventDispatcher for registering your operators." Calling `IManager::registerOperation()` directly
from `Application.php::boot()` would only populate a `Manager` instance's in-memory array for whichever
request happened to run boot — it would not survive across requests, since `Manager` is not a persisted
singleton. The event-listener pattern is therefore mandatory, not stylistic.

**3. Real dispatch requires `onEvent()` to call `$ruleMatcher->getFlows()` itself.**
`apps/workflowengine/lib/AppInfo/Application.php::registerRuleListeners()` calls `$operation->onEvent(...)`
for *every* fired event matching a *registered* operation class + entity + event name combination —
regardless of whether any admin-configured checks/conditions on a specific Flow rule actually match. The
`IOperation::onEvent()` docblock confirms this: "An evaluation whether the event qualifies for this
operation to run has still to be done by the implementor by calling the RuleMatchers getMatchingOperations
method." Concretely: `IRuleMatcher::getFlows(bool $returnFirstMatchingOperationOnly = true): array` returns
the matched, already-checks-filtered flow rows (each with a decoded `checks` array and a raw `operation`
string — the exact settings string this app's own operation classes serialize). `onEvent()` must call
`getFlows(false)` (to get *all* matches, not just the first) and loop.

**4. `EndpointService::handleRequest()` cannot be called with a synthetic in-memory array — it needs a real
`OCP\IRequest`.** `handleRequest(ObjectEntity $endpoint, IRequest $request, string $path)` →
`doHandleRequest()` reads method/params/headers directly off `$request`. The only current caller is
`EndpointsController` with the live injected `IRequest`. There is no lower-level method that accepts a plain
parameter array. However, NC's concrete `IRequest` implementation
(`\OC\AppFramework\Http\Request`, `lib/private/AppFramework/Http/Request.php`) has a simple constructor:
`__construct(array $vars, IRequestId $requestId, IConfig $config, ?CsrfTokenManager $csrfTokenManager =
null, string $stream = 'php://input')` — both `IRequestId` and `IConfig` are ordinary DI-resolvable
services, and `$vars` is a plain array (`method`, `get`, `post`, `params`, `urlParams`, `server`, ...). This
class is not `OCP`, but it is the same concrete class NC's own HTTP kernel constructs for every real request,
so building one synthetically is a well-established (if unofficial) pattern rather than a fragile guess.

**5. No bundled `ICheck` work is needed.** The brief's "operation admin UI check classes as needed" is
already satisfied by NC's existing built-in `File`-entity checks (`FileMimeType`, `FileName`, `FileSize`,
`FileSystemTags`, all in `apps/workflowengine/lib/Check/`) — an admin scopes a Flow rule using these
unmodified, exactly as they would for any core file Flow. OpenConnector does not need to register any new
`ICheck`.

**6. No bundled `IEntity` work is needed.** NC core's only shipped `IEntity` is
`\OCA\WorkflowEngine\Entity\File`. Every in-scope example ("file tagged → run synchronization") is a
file-triggered flow, so all three operations are `ISpecificOperation`s scoped to
`\OCA\WorkflowEngine\Entity\File::class`. A generic (entity-agnostic) `IOperation` would additionally need to
handle arbitrary third-party `IEntity` implementations with unknown event/data shapes — explicitly deferred
(see proposal.md Out of Scope).

## Recommendation
Proceed with the design as scoped: three `ISpecificOperation` implementations against NC core's `File`
entity, registered via a `RegisterOperationsEvent` listener gated on
`IAppManager::isEnabledForAnyUser('workflowengine')`, each `onEvent()` calling `getFlows(false)` and
delegating one existing service call per matched flow. For the endpoint case, add one thin
`EndpointService::triggerFromFlow()` method that synthesizes `\OC\AppFramework\Http\Request` and delegates
to the existing `handleRequest()` — this is plumbing (constructing the one missing input NC's own contract
requires), not new endpoint-runtime business logic, so it does not violate "no new engine logic."

## Risks Uncovered
- `\OC\AppFramework\Http\Request` is a private (`\OC\`) class, not `OCP`. It is exercised on every real HTTP
  request in NC core so it is extremely unlikely to disappear, but its constructor signature is not under an
  `OCP` stability guarantee. Mitigated with `try/catch (\Throwable)` + log-and-skip around its construction,
  matching the defensive `method_exists` pattern `nextcloud-event-triggers` REQ-002 already uses for another
  non-`OCP` NC internal (`OCA\DAV\Events\*`).
- `onEvent()` runs synchronously inside the request that fired the triggering NC event. See proposal.md Risk
  1 — deliberately deferred rather than solved here, to keep this change a thin adapter.

## Next Steps
Proceed to design.md and specs/ with the above findings as the authoritative, verified basis (no further
discovery needed).
