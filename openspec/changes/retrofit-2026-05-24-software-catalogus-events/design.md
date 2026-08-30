# Design — Retrofit software-catalogus-events

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

Two distinct concerns wired through a single service + event listener:

1. **ArchiMate model graph extension** (`SoftwareCatalogueService::extend*`,
   `find*`). Reads an OR-stored ArchiMate-style model + views + nodes +
   connections, walks the graph in parallel via ReactPHP promises, and
   writes back per-view "extended" objects under the `extendview` schema.
2. **Lifecycle event handling** (`SoftwareCatalogEventListener` + the
   `handle*` orchestrators in `SoftwareCatalogueService`). The listener
   subscribes to OR `ObjectCreated` / `ObjectUpdated` / `ObjectDeleted`
   events, filters by schema id (`ORGANIZATION_SCHEMA_ID` /
   `CONTACT_SCHEMA_ID` constants on the listener), and dispatches to the
   matching `handleNew*` / `handleContactUpdate` / `handleContactDeletion`
   orchestrators on the service. Each orchestrator then calls 2–3
   provisioning helpers in fixed order.

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `sendWelcomeEmail` / `sendVngNotification` / `createSecurityGroup` / `createOrEnableUser` / `updateUser` / `disableUser` / `sendContactWelcomeEmail` / `sendContactUpdateEmail` / `sendContactDeletionEmail` | every one of these 9 helpers is a stub. Body is `// TODO: Implement <X> logic.` + a single `$this->logger->info('<message>', ['organization' => $organization])` (or `['contact' => $contact]`). No email is sent, no group is created, no user is provisioned. The methods are wired up — the event listener calls the orchestrators, the orchestrators call these — so the pipeline LOOKS green at every layer. Operators reading info-level logs would see "Sending welcome email to organization" without realising NO email actually goes out. Triggers `hydra-gate-stub-scan`. | **HIGH — silent no-op provisioning** |
| `extendModel` / `extendView` `$deferred->promise()->catch(fn ($error) {})` | each method registers an empty catch handler on its own deferred promise, so any rejection upstream is silently swallowed at the boundary. The `then`'s `onRejected` callback DOES reject the deferred, but the empty `catch` then no-ops on the resulting rejected promise. Net: model-extension errors are invisible to the caller. | medium — silent failure |
| `extendModel` `array_map(function ($view) ... { $this->extendView(...); })` (line 130) | the inner callback NEVER `return`s the call to `extendView`. So `$promises` is a list of `null`s. `all($promises)` resolves immediately on `null`s — the actual extendView promises are leaked and run as side effects with no aggregation. Observable bug. | **HIGH — extendModel never actually awaits views** |
| `extendNode` SUFFIX mutation | unconditionally appends `self::SUFFIX` to `node.identifier` if not already present. The SUFFIX is set on the class constant; no defensive check that the identifier is a string (could be `null` for malformed nodes — `str_ends_with(null, ...)` would TypeError pre-PHP 8.1 / be cast to `""` in 8.1+). | low — input-trust |
| `extendNode` recursive promises | recurses via `extendNode` for every `$node['nodes']` subtree. No depth limit — adversarial input could OOM via deeply nested nodes (though OR objects are typically schema-bounded). | low — soft-DoS theoretical |
| `extendView` `array_filter(... === 'Relationship')` | partitions results by string equality on the `type` field. Case-sensitive, unbounded type vocabulary. Drift in OR's schema (e.g. switching to `relationship` lowercase) silently empties one of the two arrays. | low — drift surface |
| `extendView` `saveObject(..., uuid: $id)` | when `$id` is `null` (first-time extension) the save creates a new object; otherwise it overwrites. The override path has NO conflict / versioning check — a parallel extension run on the same view will clobber a peer's write. | medium — last-write-wins |
| `EventListener::handleObjectUpdated` | only acts on `CONTACT_SCHEMA_ID`; organisations have NO update handler (only create — `handleNewOrganization`). So renaming or otherwise editing an organisation triggers no NC group rename, no notification. | medium — gap |
| `EventListener::handleObjectDeleted` | same: organisations have NO delete handler — only contacts get `handleContactDeletion`. An organisation can be removed in SC while its provisioned (placeholder) NC group remains forever. | medium — gap (becomes worse once stubs are filled) |
| `EventListener::handle` exception wrapping | catches `\Exception` per dispatch but NOT `\Throwable` — fatal errors (TypeError, OutOfMemoryError) propagate up. The OR event dispatcher's tolerance for that depends on its own catch shape. | low — wrong-class catch |
| `handleNewOrganization` / `handleNewContact` etc. orchestrator order | the orchestrators call helpers in a fixed serial sequence with no transaction. If `createSecurityGroup` ever shipped and threw between `sendWelcomeEmail` (sent) and `sendVngNotification` (not yet sent), the org would be partially provisioned with no rollback. Documented as observed even though all helpers are stubs today. | low — pre-implementation hazard |

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `extendModel` + `extendView` |
| REQ-002 | `extendNode` + `extendConnection` (paired — both apply SUFFIX-normalisation and look up via REQ-003 helpers) |
| REQ-003 | `findElementForNode` + `findRelationForConnection` + `findRelationsForElement` |
| REQ-004 | `EventListener::handleObjectCreated` + `::handleObjectUpdated` + `::handleObjectDeleted` + `handleNewOrganization` + `handleNewContact` + `handleContactUpdate` + `handleContactDeletion` (all 7 dispatch / orchestrator methods folded — they share the same dispatch-by-schema-id + try/catch shape) |
| REQ-005 | All 9 stub provisioning helpers (`sendWelcomeEmail`, `sendVngNotification`, `createSecurityGroup`, `createOrEnableUser`, `updateUser`, `disableUser`, `sendContactWelcomeEmail`, `sendContactUpdateEmail`, `sendContactDeletionEmail`) |

REQ-005 is a single REQ deliberately — these 9 methods share the same
observable behaviour (a log-only no-op) and their contracts collapse
into "this method emits a log line and does nothing else". Splitting
them would mean 9 near-identical REQs.

## What the spec deliberately does NOT cover

- The OR `extendview` / `model` / `view` / `element` / `relationship`
  schemas — that's data-model territory.
- The OR event-dispatcher wiring (`addServiceListener`) — covered by
  the events-cloudevents cluster (PR #943).
- The ArchiMate semantics (what "extending" means in the modelling
  sense) — that's domain documentation.

## Validation

After archive, `openspec validate software-catalogus-events --strict`
MUST pass.
