# Design: nextcloud-event-hub

## Architecture Overview
Today: NC core → nothing (Integriq has no listeners) or (separately) `webhook_listeners` core app →
polled background job → unsigned, unretried HTTP POST. OR object writes → `ObjectCreatedEvent` etc. →
`lib/EventListener/Object*EventListener.php` → `EventService::handleObjectCreated/Updated/Deleted` →
persists an `event` OR-object in the CloudEvents envelope → `EventService::processEvent` → matches
`event_subscription`s → persists `event_message` → `deliverMessage` (signs via `WebhookSignatureService`,
retries via `EventRetryJob` sweep, dead-letters via `EventsController` replay/discard endpoints).

This change adds a second producer path that lands in the exact same pipe:

```
NC core event (OCP\Files\Events\Node\*, OCA\DAV\Events\*, OCA\Tables\Event\*, OCA\Forms\Event\*)
  → new lib/EventListener/Nextcloud*EventListener.php (IEventListener, addServiceListener in Application.php)
  → EventService::handleNextcloudEvent(string $ncEventType, array $normalizedPayload)   [NEW]
      persists an `event` OR-object: type = com.nextcloud.<domain>.<entity>.<action>,
      source = /nextcloud/<domain>, subject = node/object id, data = normalized payload
  → EventService::processEvent(...)                                                     [UNCHANGED]
      doesEventMatchSubscription / evaluateFilters (+ new `jsonlogic` dialect)           [EXTENDED]
  → per matched subscription, NEW action dispatch inside processEvent:
      action.kind === 'webhook' (default, back-compat)   → deliverMessage (UNCHANGED, incl. signing)
      action.kind === 'synchronization'                  → SynchronizationService::synchronize()  [NEW call site]
      action.kind === 'job'                              → JobService::executeJob()               [NEW call site]
  → event_message persisted regardless of action.kind, so dead-letter/replay/audit-trail
    (attempts[], status machine) applies uniformly — a failed synchronization/job run is recorded
    the same way a failed webhook POST is.
```

No new services, no new controllers for the producer side. `EventsController` gains the self-service
authorization gate on the existing `subscribe()`/`updateSubscription()` methods (REQ-005), not a new
endpoint family.

## API Design

### `POST /api/events/subscriptions` (existing endpoint, extended request body)
**Request:**
```json
{
  "types": ["com.nextcloud.files.node.created"],
  "filters": [{"jsonlogic": {"in": [".pdf", {"var": "data.attributes.name"}]}}],
  "action": {"kind": "synchronization", "synchronizationId": "b6b1...-uuid"},
  "retryPolicy": {"baseSeconds": 30, "factor": 3, "capSeconds": 3600, "maxRetries": 8}
}
```
**Response (200):** unchanged shape (serialised `event_subscription`), now including `action`/`retryPolicy`.

**Errors (new):**
| Code | Condition |
|------|-----------|
| 403  | Caller is non-admin and the requested `types` include an NC event type whose per-family action (`event.subscribe-nextcloud-<domain>`) is not granted to any of their NC groups in the ADR-023 action matrix (`ActionAuthService::requireAction` throws `OCSForbiddenException`) |
| 400  | `action.kind` is `synchronization`/`job` but the referenced id does not resolve to an existing `synchronization`/`job` object |

No new settings endpoint. The self-service allow list IS the existing ADR-023 action matrix: admins grant
the four new `event.subscribe-nextcloud-*` actions to NC groups via the pre-existing
`GET`/`PUT /api/admin/action-matrix` endpoints (`ActionMatrixController`, `#[AuthorizedAdminSetting]`) and
the pre-existing `src/views/admin/ActionAuthMatrix.vue` editor — `ActionMatrixController::getMatrix()`
already unions `lib/actions.seed.json` keys into its response, so the new seeded actions appear in the
admin UI with zero UI changes.

## Database Changes
No new Nextcloud migration / SQL table. All new fields (`action`, `retryPolicy`) are additive properties
on the existing `event_subscription` OR-managed schema in `lib/Settings/integriq_register.json` — OR
schemas are schema-less at the storage layer (JSON column), so no `lib/Migration/VersionXXXXXXXXXX.php` is
required; see `migration.md` for the register-descriptor-only migration note. The self-service allow list
lives in the existing ADR-023 action matrix (`IAppConfig` key `actions`, managed by `ActionAuthService` —
see Decision 5), which needs no schema/migration either; the four new actions are seeded via the existing
`lib/Repair/InitializeActions.php` mechanism from `lib/actions.seed.json`.

## Nextcloud Integration
- **Controllers:** `EventsController` (existing; `subscribe`/`updateSubscription` extended with per-family
  `requireAction` calls, layered on the coarse `event.subscribe`/`event.update-subscription` actions those
  methods ALREADY enforce at HEAD). `ActionMatrixController` (existing, unchanged) remains the admin
  read/write surface for the matrix.
- **Services:** `EventService` (extended — see Decisions). `ActionAuthService` (existing, unchanged —
  ADR-023 action RBAC, `lib/Service/ActionAuthService.php`) is reused as-is; NO new authorization service
  is introduced.
- **Mappers/Entities:** none — OR `ObjectEntity` generic storage, unchanged pattern.
- **Events/Hooks:** 4 new `IEventListener` classes under `lib/EventListener/`, registered in
  `Application.php::register()` via `IEventDispatcher::addServiceListener`, mirroring the existing
  `ObjectCreatedEventListener` registration exactly (Decision 1).

## Security Considerations
- **Layered ADR-023 gating, per-object ownership still open:** at HEAD, every `EventsController` method
  already enforces a coarse ADR-023 action (`event.subscribe`, `event.update-subscription`, …) via
  `ActionAuthService::requireAction`. This change layers per-event-family actions on top for NC-native
  types only. What ADR-023 action RBAC deliberately does NOT provide is per-object ownership checks — a
  non-admin granted `event.update-subscription` can still update ANY subscription by UUID (the residual
  per-object gap noted in the `events-cloudevents` spec's REQ-005 Notes). Closing that ownership gap is out
  of this change's scope and would be a separate, security-labelled change.
- **Default-deny:** every new `event.subscribe-nextcloud-*` action is seeded `["admin"]` in
  `lib/actions.seed.json`, and `ActionAuthService::getAllowedGroups` falls back to `["admin"]` for any
  action missing from the matrix — so NC-native self-service is admin-only on fresh install AND on
  upgraded installs whose matrix predates the new seed entries, matching ADR-023's first-install-safe
  posture by construction.
- **JsonLogic filters:** unlike the existing `expression` dialect (Symfony ExpressionLanguage — Turing-complete,
  already flagged as a security concern in the events-cloudevents spec Notes), `jwadhams/json-logic-php`
  exposes a fixed, non-Turing-complete operator set with no code-execution primitives. Still evaluates
  subscriber-supplied logic against the full event payload, so `data.attributes` on file/calendar events
  MUST NOT be assumed access-controlled at filter-evaluation time — the filter only decides delivery, it is
  not a substitute for the action-matrix gate.
- **OCA event payload trust:** `OCA\DAV\Events\*`/`OCA\Tables\Event\*`/`OCA\Forms\Event\*` payloads are
  passed through into `event.data` verbatim (after normalization) exactly like OR object attributes today —
  no new trust boundary is crossed, but node/file paths now flow into a system (subscriptions/webhooks) that
  a non-admin (self-service) user may have configured, so path/name data reaching a subscriber's own webhook
  MUST be limited to nodes that user can already access. See Decision 6.
- **Signing / CSRF / rate-limit:** unchanged — this change delivers through the existing signed, retried,
  rate-limited (`consumer-management`) pipeline and inherits its posture, including its known gaps (flagged
  in `webhook-signing`/`consumer-management` specs, not re-litigated here).

## NL Design System
The self-service subscription modal additions reuse existing NcSelect/NcCheckboxRadioSwitch components
already used by `EventDeliveryDetailModal.vue` and the Consumers editor; the admin allow-list surface is the
pre-existing `ActionAuthMatrix.vue` editor, entirely unchanged. No new component patterns. WCAG AA unchanged
(existing app baseline).

## File Structure
```
lib/
  AppInfo/
    Application.php                         # + 4 addServiceListener calls, feature-detected
  EventListener/
    NextcloudFileEventListener.php          # NEW — OCP\Files\Events\Node\* (created/written/deleted)
    NextcloudCalendarEventListener.php      # NEW — OCA\DAV\Events\CachedCalendarObject*Event
    NextcloudTablesEventListener.php        # NEW — OCA\Tables\Event\Row*Event (feature-detected)
    NextcloudFormsEventListener.php         # NEW — OCA\Forms\Event\FormSubmitted (feature-detected)
  Service/
    EventService.php                        # + handleNextcloudEvent, action dispatch, jsonlogic dialect,
                                             #   retryPolicy-aware deliverMessage/recordFailure
    ActionAuthService.php                   # EXISTING, unchanged — reused for the self-service gate
  Controller/
    EventsController.php                    # subscribe()/updateSubscription(): + per-family requireAction
                                             #   calls for NC-native types (layered on existing coarse actions)
    ActionMatrixController.php              # EXISTING, unchanged — admin matrix read/write surface
  Repair/
    InitializeActions.php                   # EXISTING, unchanged — seeds new actions from actions.seed.json
  actions.seed.json                         # + 4 new event.subscribe-nextcloud-* action entries (["admin"])
  Settings/
    integriq_register.json             # event_subscription: + action, retryPolicy fields
src/
  views/EventDelivery/EventDeliveriesPage.vue        # + NC-native event type filter/badge
  modals/EventDelivery/EventDeliveryDetailModal.vue  # + action.kind display (webhook/sync/job)
  modals/EventSubscription/SubscriptionActionFields.vue  # NEW — action.kind picker (own modal-adjacent file)
  views/admin/ActionAuthMatrix.vue          # EXISTING, unchanged — new seeded actions appear automatically
tests/
  Unit/Service/EventServiceNextcloudEventTest.php    # NEW
  Unit/Service/JsonLogicFilterDialectTest.php         # NEW
  Integration/NextcloudEventDeliveryTest.php          # NEW
```

## Seed Data

### Schema: `event_subscription` (additive fields on existing seed objects — no new schema)
| Field | Existing seed 1 (unchanged) | New seed: file-triggered sync | New seed: calendar-triggered webhook |
|-------|------------------------------|--------------------------------|----------------------------------------|
| slug | (unchanged) | `seed-nc-file-tagged-sync` | `seed-nc-calendar-webhook` |
| types | (unchanged) | `["com.nextcloud.files.node.tagged"]` | `["com.nextcloud.calendar.object.created"]` |
| filters | (unchanged) | `[{"jsonlogic": {"in": ["invoice", {"var": "data.attributes.tags"}]}}]` | `[]` |
| action | (absent → webhook default) | `{"kind": "synchronization", "synchronizationId": "<seed sync uuid>"}` | `{"kind": "webhook"}` |
| sink | (unchanged) | — | `https://example.org/hooks/calendar` (placeholder) |
| retryPolicy | (absent → defaults) | (absent → defaults) | `{"baseSeconds": 30, "factor": 2, "capSeconds": 1800, "maxRetries": 3}` |

**Related items per object:** none (subscriptions are configuration objects, not linked to files/notes/tasks).

## Trade-offs
See Decisions below for alternatives considered per decision; the overarching trade-off is **extend one
mature pipeline** (chosen) vs. **build a parallel "NC event" pipeline with its own subscription/delivery
model** (rejected — would duplicate signing, retry, dead-letter, and UI work that already exists and is
already spec'd, directly contradicting the proposal's reuse constraint).

## Decisions

### Decision 1: Listener registration — eager `addServiceListener` in `Application.php::register()`, not lazy/deferred
**Chosen:** Follow the exact existing idiom (`$dispatcher->addServiceListener(eventName: X::class, className:
Y::class)`), called unconditionally for `OCP\Files\Events\Node\*` (stable OCP, always safe) and, for
Tables/Forms, wrapped in a feature-detection guard using `IAppManager::isEnabledForAnyUser('tables'|'forms')`
evaluated once at boot.
**Why:** `X::class` is a compile-time string constant — referencing `OCA\Tables\Event\RowAddedEvent::class`
does not trigger autoloading and is safe even when the `tables` app is absent (confirmed: no runtime error
from an unresolvable class-string used only as an event-name key). The listener itself is never invoked
because Tables never dispatches that event name when the app isn't installed. The feature-detection guard is
therefore not required for safety, but IS useful as an "is this event family available" signal (e.g. for
the subscription modal's event-type picker to grey out Tables/Forms types when the source app is absent),
so it is added for UX/observability, not correctness.
**Alternative considered — lazy registration on first subscription:** rejected. Registering listeners only
when a subscription referencing that event type exists would require re-registering on Application boot
after every subscription change (NC re-bootstraps the app container per request in some SAPIs), adds a
stateful dependency between subscription CRUD and listener wiring, and every other listener in this app
registers eagerly — deviating here for no correctness benefit adds cognitive cost for a marginal, unmeasured
performance saving (NC event listener registration is O(1) string-map inserts, not I/O).

### Decision 2: Event normalization shape — same CloudEvents envelope as OR object events, new type namespace
**Chosen:** NC-native events reuse the exact `event` OR-object shape `handleObjectCreated`/`Updated`/`Deleted`
already write (`source`, `type`, `time`, `subject`, `data.type`+`data.id`+`data.attributes`), under a new
`com.nextcloud.<domain>.<entity>.<action>` type namespace (e.g. `com.nextcloud.files.node.created`,
`com.nextcloud.calendar.object.updated`, `com.nextcloud.tables.row.deleted`,
`com.nextcloud.forms.submission.created`) and `source = /nextcloud/<domain>` (paralleling the existing
`/objects/<type>` convention for OR events).
**Why:** `processEvent`, `evaluateFilters`, `deliverMessage`, the retry sweep, the dead-letter UI, and the
signing service all operate on the `event`/`event_message` shape and do not care what produced it — reusing
the shape is what makes "reuse the machinery, don't fork it" (the proposal's explicit constraint) actually
true rather than aspirational. A distinct shape would require either a second `evaluateFilters`/`deliverMessage`
implementation or a translation layer, both strictly worse.
**Alternative considered — a dedicated "NC event" envelope distinct from CloudEvents:** rejected; this is
what the brief's framing ("CloudEvents envelope for internal NC events too") explicitly asks to avoid, and
verification confirmed OR object events already establish the precedent of using this same envelope for
non-webhook-sourced, in-process-originated events.

### Decision 3: Retry/backoff schedule storage — per-subscription `retryPolicy`, class constants as default
**Chosen:** Add `event_subscription.retryPolicy = {baseSeconds, factor, capSeconds, maxRetries}` (all
optional). `EventService::recordFailure`/`deliverMessage` read `subscription.retryPolicy.<field> ??
self::RETRY_BASE_SECONDS` etc., preserving today's exact behaviour (60s / ×4 / 6h cap / 5 retries) for every
subscription that doesn't set it — including all existing OR-object subscriptions, satisfying the
non-regression requirement.
**Why:** The brief calls for "per-consumer retry policy w/ exponential backoff" but `sink`/delivery
configuration already lives on `event_subscription`, not `consumer` (a subscription need not reference a
`consumer` at all — `protocolSettings.signingSecret` is already subscription-scoped per the `webhook-signing`
spec). Placing `retryPolicy` at the same grain as `signingSecret` keeps delivery-tuning config in one place.
**Alternative considered — global-only backoff (status quo, no per-subscription override):** rejected — the
brief explicitly asks for it, and a self-service subscriber to a high-value sync target may reasonably want
faster/more retries than a low-value webhook notification.
**Alternative considered — storing retry policy on `consumer`:** rejected — a `pull`-style subscription or a
subscription with no `consumer` reference would have nowhere to read it from; `event_subscription` is the
grain every delivery attempt is actually keyed on.

### Decision 4: Action dispatch — new `action` field on `event_subscription`, `webhook` as implicit default
**Chosen:** `event_subscription.action = {kind: 'webhook'|'synchronization'|'job', sink?, synchronizationId?,
jobId?}`. When `action` is absent, behaviour is `kind: 'webhook'` using the existing top-level `sink` field —
100% backward compatible with every existing subscription. `processEvent` branches on `action.kind` right
before the existing `deliverMessage` call: `synchronization` calls
`SynchronizationService::synchronize($synchronizationObject)`, `job` calls
`JobService::executeJob($jobObject, forceRun: true)`, both wrapped in the same try/log/persist-`event_message`
pattern `deliverMessage` already uses, so success/failure still lands in `attempts[]`/`status` and is subject
to the SAME retry sweep and dead-letter/replay UI — a failed synchronization run is replay-able exactly like
a failed webhook POST.
**Why:** Keeps exactly one `event_message` status machine and one dead-letter/replay surface for all three
action kinds, per the proposal's reuse constraint, rather than inventing a second "job run history"/"sync run
history" concept that duplicates what `job_log`/`synchronization_log` already track.
**Alternative considered — three separate subscription types (new schemas):** rejected — triples the schema
surface, triples the controller/UI surface, and loses the shared filter/retry/dead-letter machinery this
whole change exists to reuse.

### Decision 5: Self-service allow list — reuse the existing ADR-023 action matrix (`ActionAuthService`), no new mechanism
**Chosen:** The self-service gate is four new ADR-023 actions seeded in `lib/actions.seed.json` following
the existing `<entity>.<verb>` convention (cf. `event.subscribe`, `synchronization.run`):

- `event.subscribe-nextcloud-files`
- `event.subscribe-nextcloud-calendar`
- `event.subscribe-nextcloud-tables`
- `event.subscribe-nextcloud-forms`

all seeded `["admin"]` (default-deny for non-admins). Enforcement reuses `ActionAuthService::requireAction`
(`lib/Service/ActionAuthService.php` — admin always passes; throws `OCSForbiddenException` otherwise);
matrix storage stays in `IAppConfig` key `actions`; seeding on install/upgrade via the existing
`lib/Repair/InitializeActions.php` (which merges seed keys without overwriting admin customizations);
admin editing via the existing `ActionMatrixController` (`GET`/`PUT /api/admin/action-matrix`) and
`src/views/admin/ActionAuthMatrix.vue` — whose `getMatrix()` response already unions seed-file keys, so the
new actions surface in the UI with zero new endpoint/UI/service code.
**Why:** Integriq already ships a complete ADR-023 implementation at HEAD — `ActionAuthService` with
`requireAction()`/`can()`/`getMatrix()`/`setMatrix()`, used by eight controllers including
`EventsController` itself (every events endpoint already enforces a coarse action such as
`event.subscribe`). Introducing a parallel allow-list mechanism (a `NextcloudEventAuthorizationService` +
dedicated settings endpoint + dedicated editor view, as an earlier draft of this design proposed) would
duplicate authorization infrastructure that exists, is seeded, is admin-editable, and is already enforced on
the exact controller methods this change extends — a direct ADR-011 violation (reuse before reimplement).
**Granularity note:** the actions are per event FAMILY (files/calendar/tables/forms), not per individual
event type (11 types). Per-family keeps the matrix legible (4 rows vs 11 alongside the existing ~38) and
matches the trust boundary that actually matters (which NC domain's data a group may subscribe to);
finer per-type control remains available later by seeding additional actions without any mechanism change.
**Alternative considered — bespoke `IAppConfig` allow-list keyed by event type with its own service,
endpoint, and settings view:** rejected — duplicates the existing ADR-023 machinery.
**Alternative considered — OR-managed schema (`nextcloud_event_allowlist` register entry):** rejected —
over-engineering for admin-only, low-cardinality config, and equally duplicative of the action matrix.

### Decision 6: Non-admin subscribe() gate — per-family actions layered on the existing coarse actions, scoped strictly to NC-native `types`
**Chosen:** `EventsController::subscribe()`/`updateSubscription()` ALREADY call
`ActionAuthService::requireAction` with the coarse `event.subscribe`/`event.update-subscription` actions at
HEAD — that stays untouched and continues to gate the endpoint as a whole. This change ADDS, after the
coarse check, one `requireAction($user, 'event.subscribe-nextcloud-<domain>')` call per distinct NC-native
domain present in the request's `types[]` — where a type maps to a domain via
`com.nextcloud.<domain>.*` for `<domain>` ∈ {files, calendar, tables, forms}, explicitly EXCLUDING
`com.nextcloud.openregister.*` (the pre-existing OR-object namespace, which happens to share the same
`com.nextcloud.` top-level reverse-DNS root). Requests whose `types` are exclusively
`com.nextcloud.openregister.object.*` (or any other non-NC-native type) trigger no per-family check —
unchanged from today. This exclusion is load-bearing, not cosmetic: both namespaces share the
`com.nextcloud.` prefix, so a naive prefix→domain mapping would incorrectly gate every existing OR-object
subscription request too. Note `requireAction`'s admin bypass is built in, so admins are never gated.
**Why (layering):** two actions with different scopes compose naturally in the matrix — an admin grants a
group `event.subscribe` (may create subscriptions at all) plus `event.subscribe-nextcloud-files` (may
scope them to file events); either grant alone is insufficient for NC-native self-service, which is exactly
the least-privilege posture ADR-023 intends.
**Why (scoping to NC-native only):** changing the authorization posture of the pre-existing OR-event
subscribe path is a distinct, security-classified change of its own — in particular the residual PER-OBJECT
ownership gap (any holder of `event.update-subscription` can update ANY subscription by UUID; action RBAC
by design does not check object ownership) is out of scope per the proposal's Risk 2 mitigation.
**Alternative considered — also adding per-object ownership checks while in the code:** tempting, but
rejected for this change; tracked instead as a candidate for a dedicated security-labelled follow-up change
against `events-cloudevents` REQ-005, so it gets its own risk review and its own test plan rather than being
an implicit side effect here.

## Migration Plan
No schema migration. See `migration.md` for the register-descriptor-only change record and the app
install/upgrade sequencing note (new listeners register on next app boot; no backfill of historical NC
events is possible or attempted — this is a forward-only, event-driven feature).

## Open Questions
See `proposal.md` Open Questions — one carried forward (Forms/Tables event class name verification). The
earlier allow-list-storage question is resolved by Decision 5 (reuse the existing ADR-023 action matrix).
