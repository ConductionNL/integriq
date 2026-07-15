# Proposal: nextcloud-event-hub

## Summary
OpenConnector already ships a complete CloudEvents pipeline — `event`/`event_subscription`/`event_message`
schemas, filter-and-deliver logic (`EventService::processEvent`/`deliverMessage`), a scheduled retry sweep
(`EventRetryJob`), HMAC request signing (`WebhookSignatureService`, already wired into delivery), and a
dead-letter + replay UI (`EventDeliveriesPage.vue`) — but today it only reacts to **OpenRegister object**
lifecycle events (`com.nextcloud.openregister.object.*`). Nextcloud core's own event surface (files,
calendar, Tables, Forms) is exposed only through the admin-only, no-UI `webhook_listeners` app (NC 30+),
whose delivery is background-job-polled (up to 5 minutes of latency) and ships with no documented retries,
no signing, and no dead-lettering. This change adds in-process PHP listeners for Nextcloud core events,
normalizes them into the existing CloudEvents `event` envelope, and lets admins — and, for allow-listed
event types, non-admin users — subscribe those events to a synchronization, a job, or an outbound signed
webhook, entirely through machinery OpenConnector already operates and already tests. It is a pure
differentiator: no other App Store app offers guaranteed, self-service, in-process Nextcloud event routing.

## Motivation
Specter deep-research insight #1250 identified this as a market gap: Nextcloud's own answer to
"do something when a file changes" is either `workflow_engine` (no outbound HTTP, no retries) or
`webhook_listeners` + a second product (Windmill) for anything resembling delivery guarantees. Every
OpenConnector building block needed to close this gap already exists and is already spec'd
(`events-cloudevents`, `dead-letter-replay`, `webhook-signing`, `consumer-management`) — the only missing
piece is the NC-core-event → `event` entity producer and a subscription trigger that isn't "another
CloudEvent". Building this now reuses machinery that is implemented and tested rather than forking it,
and turns four independently-shipped specs into one coherent, sellable capability.

## Affected Projects
- [x] Project: `openconnector` — new NC-core event listeners, `EventService` extensions, subscription
  schema fields, self-service authorization gate, delivery-status UI additions.

## Scope

### In Scope
1. In-process PHP `IEventListener` classes for: NC file create/update/delete/tag (`OCP\Files\Events\Node\*`,
   stable OCP since NC 20), calendar object create/update/delete (`OCA\DAV\Events\CachedCalendarObject*`,
   bundled app but OCA-namespaced, not OCP-guaranteed), Tables row create/update/delete
   (`OCA\Tables\Event\*`, optional app), Forms submission created (`OCA\Forms\Event\*`, optional app).
   Each normalizes its NC event into the existing CloudEvents `event` envelope shape (REQ-004 pattern)
   under a new `com.nextcloud.<domain>.<entity>.<action>` type namespace and calls `EventService::processEvent`.
2. Per-event-type availability gating: Tables/Forms listeners are registered only when the source app is
   enabled (`IAppManager::isEnabledForAnyUser`); calendar listeners are registered unconditionally (`dav`
   ships with every NC instance) but documented as OCA-stability, not OCP-stability.
3. A `jsonlogic` filter dialect added to `EventService::evaluateFilters` (event-subscription payload
   filtering), reusing the `jwadhams/json-logic-php` library already a composer dependency (currently used
   only by the rule engine's `EndpointService`).
4. A subscription-level `action` field (`event_subscription.action = {kind, sink|synchronizationId|jobId}`)
   so a matched NC event can drive a synchronization (`SynchronizationService::synchronize`), a job
   (`JobService::executeJob`), or an outbound signed webhook (existing `deliverMessage` path) — not only
   the last of the three, which is all `event_subscription` currently supports.
5. Per-subscription configurable retry policy (`event_subscription.retryPolicy = {baseSeconds, factor,
   capSeconds, maxRetries}`), read by `deliverMessage`/`recordFailure` with the existing class constants
   (60s / ×4 / 6h cap / 5 retries) as the default when absent — today these are hardcoded, non-configurable
   constants.
6. Non-admin self-service subscription creation for NC event types an admin has explicitly allow-listed,
   by reusing OpenConnector's existing ADR-023 implementation at HEAD (`ActionAuthService::requireAction`,
   the `IAppConfig`-backed action matrix, `lib/actions.seed.json` seeding via `InitializeActions`, and the
   existing `ActionAuthMatrix.vue` admin editor): four new per-event-family actions
   (`event.subscribe-nextcloud-{files,calendar,tables,forms}`) seeded `["admin"]` (default-deny), layered
   on the coarse `event.subscribe`/`event.update-subscription` actions `EventsController` already enforces.
   No new authorization mechanism is introduced.
7. Delivery-status additions to the existing dead-letter/Event deliveries UI: NC-native event types are
   filterable/visible alongside CloudEvents-sourced messages using the same components.
8. Tests: unit (filter dialects incl. `jsonlogic`, backoff schedule with custom `retryPolicy`), integration
   (fire a real NC event → assert `event` + `event_message` + signature header; force a failing sink →
   assert dead-letter entry and successful replay), Playwright (self-service subscription creation as a
   non-admin, admin grant of the per-family actions via the existing action-matrix editor).

### Out of Scope
- Kafka/MQTT sinks (existing `protocol` field already allows for it; no new work here — deferred).
- WorkflowEngine/Flow actions as a delivery target (possible follow-up change).
- Tables as a synchronization source/target (`tables-bridge` change owns that).
- Any change to the existing ADR-023 machinery itself (`ActionAuthService`, `ActionMatrixController`,
  `ActionAuthMatrix.vue`, `InitializeActions`) — this change only ADDS seed entries to
  `lib/actions.seed.json` and `requireAction` call sites; the framework is consumed as-is.
- Per-object ownership checks on subscription update/delete (the residual gap noted in the
  `events-cloudevents` spec's REQ-005 Notes: action RBAC gates WHO may call the endpoint, not WHICH
  subscription they may touch) — a candidate for a dedicated security-labelled follow-up change.

## Approach
Follow the existing `lib/EventListener/` + `Application.php::register()` idiom used for
`ObjectCreatedEvent`/`ObjectUpdatedEvent`/`ObjectDeletedEvent` (`IEventDispatcher::addServiceListener`)
for the new NC-core listeners, so no new bootstrap pattern is introduced. Each listener maps its NC event
into the same `event` OR-object shape `EventService::handleObjectCreated` already writes, then calls the
same `processEvent`/`deliverMessage`/retry/dead-letter/signing pipeline unchanged. `EventService` gains a
small number of new methods (`handleNextcloudEvent`, an `action`-dispatch branch inside `processEvent`,
`jsonlogic` in `evaluateFilters`, reading `retryPolicy` off the subscription) rather than new services —
this is additive to one class plus new listener classes, not a parallel pipeline.

## New Dependencies
None. `jwadhams/json-logic-php` is already a composer dependency (used by `EndpointService`); Tables/Forms
event classes are referenced only via `::class` (compile-time string, no autoload requirement), so no
Tables/Forms/dav packages are added as composer/npm dependencies.

## Impact
- `lib/AppInfo/Application.php` — new `addServiceListener` registrations, feature-detected for Tables/Forms.
- `lib/EventListener/` — 4 new listener classes.
- `lib/Service/EventService.php` — new methods + `action`/`retryPolicy`/`jsonlogic` handling in existing methods.
- `lib/Settings/openconnector_register.json` — `event_subscription` gains `action`, `retryPolicy`,
  `allowedForGroups`-style authorization fields; `event` type vocabulary extended (documentation only, no
  schema field change — `type` is already a free string).
- `lib/Controller/EventsController.php` — `subscribe()`/`updateSubscription()` gain per-family
  `ActionAuthService::requireAction` calls for NC-native event types (layered on the coarse
  `event.subscribe`/`event.update-subscription` actions those methods already enforce).
- `lib/actions.seed.json` — four new `event.subscribe-nextcloud-*` action entries (seeded `["admin"]`);
  they surface automatically in the existing action-matrix admin UI (`ActionMatrixController::getMatrix`
  unions seed keys), so no new settings endpoint or view is needed.
- `src/views/EventDelivery/`, `src/modals/EventDelivery/` — filter/display additions for NC-native events.

## Cross-Project Dependencies
None outside openconnector. Tables and Forms are Nextcloud App Store apps, not Conduction apps — treated
as optional runtime dependencies, feature-detected, never a hard `composer`/`info.xml` `<dependencies>` entry.

## Risks

### Risk 1: OCA (non-OCP) event classes are not covered by Nextcloud's API stability guarantee
**Severity:** Medium — **Mitigation:** Calendar (`OCA\DAV\Events\*`), Tables (`OCA\Tables\Event\*`), and
Forms (`OCA\Forms\Event\*`) events are app-specific, not `OCP\*` stable API. Each listener is isolated
per-event-type, wrapped so a signature change in one app's events cannot break the others or the file
listeners, and covered by a NC-version compatibility note in `design.md`. A failing listener logs and
continues rather than throwing into the dispatcher.

### Risk 2: Broadening self-service via the action matrix could over-authorize if granularity is misjudged
**Severity:** Medium — **Mitigation:** Default-deny by construction — the four new actions are seeded
`["admin"]` and `ActionAuthService::getAllowedGroups` falls back to `["admin"]` for any action absent from
the matrix (covers upgraded installs whose matrix predates the new seed entries). The gate is layered: a
group needs BOTH the coarse `event.subscribe` grant AND the per-family grant. Scope stays narrow — existing
CloudEvents `subscribe()` behaviour for OR object events is unchanged, and the residual per-object ownership
gap (action RBAC does not check which subscription a caller may touch), flagged in the `events-cloudevents`
spec Notes, is out of scope for this change and not silently "fixed" by this gate.

### Risk 3: JsonLogic filter expressions on subscriber-supplied payloads (same class of risk already flagged for the `expression` dialect)
**Severity:** Low — **Mitigation:** `jwadhams/json-logic-php` evaluates a restricted operation set (no
arbitrary code execution, unlike Symfony ExpressionLanguage's existing `expression` dialect) against the
event payload only; no filesystem/network primitives are exposed by the library.

## Rollback Strategy
Each NC-core listener registration is an independent `addServiceListener` call — remove or comment out the
line(s) in `Application.php` to disable a single event family without affecting the others or the existing
OR-object pipeline. The `action`/`retryPolicy` subscription fields are additive and optional (absent ⇒
existing push-webhook-only behaviour with existing default backoff constants), so no data migration is
required to roll back; subscriptions created with an `action` block simply stop being able to select
synchronization/job actions in the UI if the change is reverted; the underlying `event_subscription`
objects are untouched.

## Open Questions
- Is per-event-FAMILY action granularity (4 actions: files/calendar/tables/forms) sufficient, or will
  operators want per-event-TYPE granularity (11 actions)? `design.md` Decision 5 chooses per-family for
  matrix legibility; finer granularity remains a pure seed-file addition later (no mechanism change), so
  this is cheap to revisit.
- Exact Forms/Tables event class names and payload shapes could not be verified against a live install in
  this repo (neither app is present in the checked server checkout) — `design.md` and `tasks.md` gate this
  work behind a runtime `class_exists()` check and mark the specific event class names TENTATIVE pending
  verification against an instance with both apps installed.
