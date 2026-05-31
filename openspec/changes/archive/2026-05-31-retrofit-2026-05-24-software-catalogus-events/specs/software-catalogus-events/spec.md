---
retrofit: true
status: draft
---

# Software catalogus events

## Purpose

Two concerns wired into the openconnector software-catalogus integration:
ArchiMate-style model graph extension (read OR model + views, walk nodes
+ connections via ReactPHP promises, write per-view "extended" objects)
and lifecycle-event provisioning (subscribe to OR
ObjectCreated/Updated/Deleted, dispatch to per-schema orchestrators
that should provision NC groups + users + emails).

This spec retroactively documents the observed contract — including
the fact that 9 of the 23 methods are documented log-only stubs whose
"observable behaviour" is a single `LoggerInterface::info` call.

## ADDED Requirements

### Requirement: ReactPHP promise-based ArchiMate model graph extension (REQ-001)

`extendModel(int|string $modelId): PromiseInterface` MUST resolve the
OR `vng-gemma` model object via `ObjectService::getOpenRegisters()->find(...)`,
load all existing `extendview` objects into `$this->existingViews`,
then for each `view` in the model's `views` array call
`extendView($view, $model)` (REQ-001 cont) and aggregate via ReactPHP
`all(...)`. The returned `PromiseInterface` resolves when all view
extensions complete.

`extendView(array $viewPromise, array $modelPromise): PromiseInterface`
MUST stash the model's `elements` + `relationships` into instance
state (`$this->elements`, `$this->relations`), extend each `node` via
`extendNode` (REQ-002) and each `connection` via `extendConnection`
(REQ-002) in parallel, partition results by `type === 'Relationship'`
into the view's `nodes` and `connections` arrays, and save the
resulting view via `ObjectService::getOpenRegisters()->saveObject(...,
uuid: <existing-uuid-or-null>)`.

Both methods MUST reject the returned deferred on
`OpenRegister service is not available` if `getOpenRegisters()` returns
`null`.

#### Scenario: extendModel walks all views

- **GIVEN** a model with 3 views
- **WHEN** `extendModel($id)` is called
- **THEN** `extendView` is invoked 3 times (one per view, in parallel)
- **AND** the returned promise resolves after all 3 view extensions complete

#### Scenario: OR unavailable rejects the promise

- **GIVEN** `ObjectService::getOpenRegisters()` returns `null`
- **WHEN** `extendModel(...)` is called
- **THEN** the returned promise is REJECTED with `\Exception('OpenRegister service is not available')`

#### Notes

- **HIGH (observable bug — `extendModel` never awaits views):** the
  inner array_map callback in `extendModel`
  `function ($view) use ($model) { $this->extendView(viewPromise: $view, modelPromise: $model); }`
  has NO `return` statement — so `$promises` is a list of `null`s,
  `all($promises)` resolves immediately, and the actual `extendView`
  promises run as fire-and-forget side effects. The caller's
  `then()` fires before any view is actually extended. Documented as
  observed; fix is a `return $this->extendView(...)`.
- **MEDIUM (silent failure):** both methods register
  `$deferred->promise()->catch(fn ($error) {})` — any rejection at
  the deferred boundary is swallowed.
- **MEDIUM (last-write-wins):** `saveObject(..., uuid: $id)` has no
  conflict-detection; parallel extension runs on the same view race.
- `$this->elements` / `$this->relations` / `$this->existingViews`
  are mutable instance state set by extendView/extendModel —
  reusing the service instance across concurrent extends would race.

---

### Requirement: Node and connection extension with identifier-suffix normalisation (REQ-002)

`extendNode(array $node): PromiseInterface` MUST:

1. Append `self::SUFFIX` to `node.identifier` if not already present.
2. Call `findElementForNode($node)` (REQ-003). If `null`, log a warning
   and resolve with the unchanged node.
3. Call `findRelationsForElement($element)` (REQ-003).
4. Attach the matched element to the node as `$node['element']`.
5. If `$node['nodes']` is a non-empty array, recurse via `extendNode`
   on each child in parallel and replace `$node['nodes']` with the
   extended array.
6. Resolve with the extended node.

On `\Exception`, log via `LoggerInterface::error` and reject.

`extendConnection(array $connection): PromiseInterface` MUST:

1. Append `self::SUFFIX` to `connection.identifier` if not already present.
2. Call `findRelationForConnection($connection)` (REQ-003). If `null`,
   log a warning and resolve with the unchanged connection.
3. Attach the matched relationship to the connection as
   `$connection['relationship']`.
4. Append `self::SUFFIX` to `source` and `target` if not already
   present.
5. Resolve with the extended connection.

#### Scenario: extendNode attaches a matching element

- **GIVEN** a node `{ identifier: 'x', elementRef: 'el-1' }` and `$this->elements` contains an element with `identifier: 'el-1'`
- **WHEN** `extendNode($node)` is called
- **THEN** the resolved node carries `element` matching `el-1`
- **AND** the node's identifier is `'x' . self::SUFFIX`

#### Scenario: extendConnection appends SUFFIX to source/target

- **GIVEN** a connection `{ identifier: 'c1', source: 's1', target: 't1' }`
- **WHEN** `extendConnection($conn)` is called
- **THEN** the resolved connection carries `source: 's1' . SUFFIX`, `target: 't1' . SUFFIX`

#### Notes

- **LOW (input trust):** `str_ends_with($node['identifier'], ...)`
  TypeErrors on non-string identifier in PHP 8.0; casts to `""` in
  8.1+. The methods do not validate input types.
- **LOW (soft-DoS theoretical):** `extendNode` recurses on
  `$node['nodes']` with no depth limit. Schema-bounded in practice
  but worth noting.

---

### Requirement: Element and relation lookup helpers (REQ-003)

`findElementForNode(array $node): ?array` MUST return `null` if
`$node['elementRef']` is unset. Otherwise the method MUST search
`$this->elements` (set by `extendView`) for an element whose
`identifier` strictly equals `$node['elementRef']` and return the
matching element array, or `null` if none.

`findRelationForConnection(array $connection): ?array` MUST mirror
the same shape for `$connection['relationshipRef']` against
`$this->relations`, returning the matching relationship or `null`.

`findRelationsForElement(array $element): array` MUST return all
entries in `$this->relations` where `source` OR `target` equals
`$element['identifier']`, returning a (possibly empty) array of
matching relations.

#### Scenario: findElementForNode misses cleanly

- **GIVEN** `$this->elements` contains no element with `identifier === 'missing'`
- **WHEN** `findElementForNode(['elementRef' => 'missing'])` is called
- **THEN** the return is `null`

#### Scenario: findRelationsForElement returns matches

- **GIVEN** `$this->relations` contains 2 relations referencing element `e1` (one as source, one as target) and 1 relation referencing `e2`
- **WHEN** `findRelationsForElement(['identifier' => 'e1'])` is called
- **THEN** the return contains exactly the 2 e1-related entries

#### Notes

- These helpers depend on `$this->elements` / `$this->relations`
  being set — they MUST only be invoked from inside an `extendView`
  call (or its descendants). Calling directly with stale state
  silently returns wrong matches.

---

### Requirement: OR lifecycle event dispatch to provisioning orchestrators (REQ-004)

`SoftwareCatalogEventListener::handleObjectCreated(ObjectCreatedEvent
$event): void` MUST inspect the event's `getObject()` result. If the
object's `getSchema()` equals `self::ORGANIZATION_SCHEMA_ID`, the
method MUST call `SoftwareCatalogueService::handleNewOrganization($object)`.
If it equals `self::CONTACT_SCHEMA_ID`, the method MUST call
`handleNewContact($object)`.

`handleObjectUpdated(ObjectUpdatedEvent $event): void` MUST inspect
`$event->getNewObject()` and dispatch to
`handleContactUpdate($object)` if the schema id matches
`CONTACT_SCHEMA_ID`. Organisations have NO update handler.

`handleObjectDeleted(ObjectDeletedEvent $event): void` MUST inspect
`$event->getObject()` and dispatch to `handleContactDeletion($object)`
if schema id matches `CONTACT_SCHEMA_ID`. Organisations have NO
delete handler.

All three event-listener methods MUST catch `\Exception` per
dispatch, log via `LoggerInterface::error('Failed to handle ...: ',
['exception' => $e, 'object' => $object])`, and return — never
rethrow.

The four orchestrators on `SoftwareCatalogueService` MUST call their
helpers in fixed serial order:

- `handleNewOrganization($organization)` → `sendWelcomeEmail` +
  `sendVngNotification` + `createSecurityGroup` (REQ-005).
- `handleNewContact($contact)` → `createOrEnableUser` +
  `sendContactWelcomeEmail` (REQ-005).
- `handleContactUpdate($contact)` → `updateUser` +
  `sendContactUpdateEmail` (REQ-005).
- `handleContactDeletion($contact)` → `disableUser` +
  `sendContactDeletionEmail` (REQ-005).

The orchestrators do not catch exceptions themselves — exceptions
propagate to the listener's per-dispatch catch.

#### Scenario: new organisation routes to handleNewOrganization

- **GIVEN** an `ObjectCreatedEvent` whose object's schema id matches `ORGANIZATION_SCHEMA_ID`
- **WHEN** `SoftwareCatalogEventListener::handle($event)` runs (dispatches to `handleObjectCreated`)
- **THEN** `SoftwareCatalogueService::handleNewOrganization($object)` is called

#### Scenario: organisation update is ignored

- **GIVEN** an `ObjectUpdatedEvent` whose schema id matches `ORGANIZATION_SCHEMA_ID`
- **WHEN** the event is dispatched
- **THEN** NO orchestrator is invoked (there is no organisation-update handler)

#### Scenario: orchestrator exception is logged and swallowed at the listener

- **GIVEN** `handleNewOrganization` throws (e.g. an OR transient failure)
- **WHEN** the event listener dispatches the event
- **THEN** the `\Exception` is caught, an error is logged with the object payload, and the listener returns normally

#### Notes

- **MEDIUM (gap):** organisations have no update / delete handler.
  Renaming or removing an organisation in SC triggers no NC-side
  reaction.
- **LOW (wrong-class catch):** `handle*` methods catch `\Exception`
  but not `\Throwable` — fatal errors propagate up. OR's event
  dispatcher's tolerance varies.
- **LOW (no transaction):** the orchestrators run helpers serially
  with no rollback. Once the stubs are filled (REQ-005), a partial
  failure can leave the org / contact half-provisioned.

---

### Requirement: Stub provisioning helpers — log-only no-ops (REQ-005)

Nine helper methods MUST emit a single `LoggerInterface::info` line
documenting the call and otherwise do nothing:

| Method | Log message | Context |
|---|---|---|
| `sendWelcomeEmail($organization)` | `'Sending welcome email to organization'` | `['organization' => $organization]` |
| `sendVngNotification($organization)` | `'Sending VNG notification about new organization'` | `['organization' => $organization]` |
| `createSecurityGroup($organization)` | `'Creating security group for organization'` | `['organization' => $organization]` |
| `createOrEnableUser($contact)` | `'Creating or enabling user for contact'` | `['contact' => $contact]` |
| `updateUser($contact)` | `'Updating user for contact'` | `['contact' => $contact]` |
| `disableUser($contact)` | `'Disabling user for contact'` | `['contact' => $contact]` |
| `sendContactWelcomeEmail($contact)` | `'Sending welcome email to contact'` | `['contact' => $contact]` |
| `sendContactUpdateEmail($contact)` | `'Sending update email to contact'` | `['contact' => $contact]` |
| `sendContactDeletionEmail($contact)` | `'Sending deletion email to contact'` | `['contact' => $contact]` |

Each method MUST be `private` and return `void`. The body is `// TODO:
Implement <X> logic.` plus the log call. No external side effects —
no email is sent, no NC group is created, no NC user is created /
updated / disabled, no VNG endpoint is called.

#### Scenario: sendWelcomeEmail emits a log line and returns

- **WHEN** `sendWelcomeEmail($org)` is called
- **THEN** `LoggerInterface::info('Sending welcome email to organization', ['organization' => $org])` is emitted
- **AND** no SMTP / IMailer / IUserManager / IGroupManager interaction occurs
- **AND** the method returns `void`

#### Scenario: orchestrator handleNewContact calls 2 stubs in order

- **GIVEN** `$contact` is an ObjectEntity
- **WHEN** `handleNewContact($contact)` is called
- **THEN** `createOrEnableUser($contact)` is called
- **AND** then `sendContactWelcomeEmail($contact)` is called
- **AND** the two info log lines are emitted in that order
- **AND** no actual user is created, no actual email is sent

#### Notes

- **HIGH (silent no-op provisioning) — `hydra-gate-stub-scan`:**
  these 9 methods are TODOs. The orchestrators (REQ-004) call them
  in a clear path that looks production-grade — the EventListener
  catches errors, the orchestrators dispatch by schema id, the
  helpers log every call. To an operator looking at logs the
  pipeline LOOKS green. In reality every organisation / contact
  lifecycle event is a no-op. Multiple memory notes apply:
  - The fleet "mail being phased out for n8n" note suggests this is
    deliberate during the transition. If so the methods should be
    removed and the orchestrators should call n8n webhooks directly
    — silently logging without telling operators "this is a stub"
    is the worst-of-all-worlds shape.
- The retrofit pins the stub shape as the observed contract so any
  future implementation lands as a behavioural change with explicit
  REQ + scenario updates. Filling in the stubs without a REQ
  update would silently change the observable contract.
