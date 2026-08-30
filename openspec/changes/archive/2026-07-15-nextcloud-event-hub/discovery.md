# Discovery: nextcloud-event-hub

## Question
Which Nextcloud core/bundled-app event classes for files, calendar, Tables, and Forms actually exist and
are stable enough to build in-process `IEventListener`s against, across the NC 28–34 range this app
targets — and does OpenConnector already have any of the "reuse" machinery (JsonLogic filters, dead-letter
UI, HMAC signing) the context brief assumes, or would those need to be built fresh?

## Approach Taken
- Read `lib/Settings/openconnector_register.json`, `lib/Service/EventService.php`,
  `lib/Cron/EventRetryJob.php`, `lib/Controller/EventsController.php`,
  `lib/Service/WebhookSignatureService.php`, `lib/EventListener/*.php`, `lib/AppInfo/Application.php`,
  `appinfo/routes.php`, `appinfo/info.xml` in this checkout at HEAD.
- Read the four target specs in full: `openspec/specs/events-cloudevents/spec.md`,
  `openspec/specs/dead-letter-replay/spec.md`, `openspec/specs/webhook-signing/spec.md`,
  `openspec/specs/consumer-management/spec.md`.
- Searched `/home/rubenlinde/nextcloud-docker-dev/workspace/server` (a live NC 33.0.0-dev master checkout)
  for `OCP\Files\Events\Node\*`, `OCA\DAV\Events\*`, `apps/tables`, `apps/forms`.
- Searched this repo and sibling app repos for existing JsonLogic usage and existing OR-object-lifecycle
  listener registration idiom.
- Verified openconnector's existing ADR-023 (action authorization matrix) implementation by reading
  `lib/Service/ActionAuthService.php`, `lib/Controller/ActionMatrixController.php`,
  `lib/Repair/InitializeActions.php`, `lib/actions.seed.json`, the `EventsController` call sites, and the
  `/api/admin/action-matrix` route registrations in `appinfo/routes.php`.

## Findings
- **Files events — stable, verified.** `OCP\Files\Events\Node\{NodeCreatedEvent, NodeWrittenEvent,
  NodeDeletedEvent, NodeRenamedEvent, NodeCopiedEvent, NodeTouchedEvent}` are genuine `OCP\*` public API,
  `NodeCreatedEvent` present since NC 20.0.0. Safe to build against for the full NC 28–34 range. File
  *tagging* has no dedicated `OCP` event in this class family — tag changes surface via
  `OCP\SystemTag\MapperEvent` (a different, older stable OCP interface) rather than a `Node*Event`; the
  file-tagged listener targets that event class instead of a `Node*Event` variant.
- **Calendar events — exist, but OCA not OCP.** `apps/dav/lib/Events/{CalendarCreatedEvent,
  CalendarUpdatedEvent, CalendarDeletedEvent, CachedCalendarObjectCreatedEvent,
  CachedCalendarObjectUpdatedEvent, CachedCalendarObjectDeletedEvent, ...}` are real, present in the NC 33
  checkout, and `dav` ships bundled with every NC instance (no feature-detection needed for "is calendar
  present" — it always is), but the namespace is `OCA\DAV\Events`, i.e. app-internal API with no NC public-API
  stability guarantee across major versions. Treat class names/constructor signatures as subject to change
  between NC majors; isolate each listener so a signature break in one calendar event class cannot break the
  file listeners.
- **Tables / Forms events — plausible but unverified in this environment.** Neither `apps/tables` nor
  `apps/forms` exists in the checked server checkout, so `OCA\Tables\Event\*`/`OCA\Forms\Event\*` could not
  be directly confirmed. Both are known, real, separately-installed Nextcloud apps that do expose their own
  event classes in current versions per general Nextcloud ecosystem knowledge, but exact class names/payload
  shapes are NOT verified here and MUST be feature-detected AND spot-checked against a real instance with
  both apps installed before the listener implementation is trusted. `class_exists()` referencing a bare
  `::class` string is safe even when the target app is absent (compile-time string, no autoload trigger) —
  confirmed by reasoning about PHP's `::class` resolution, not by executing code in this environment.
- **The "reuse" machinery is real, not aspirational.** `WebhookSignatureService::sign()`/`verify()` IS
  already called from inside `EventService::deliverMessage()` (constructor-injected, header emission at
  lines ~311–328) — the context brief's phrasing ("wire existing WebhookSignatureService into subscription
  delivery") reads as if this were still to be done; it is already done. Similarly `EventRetryJob` is
  registered and running, and the dead-letter UI (`EventDeliveriesPage.vue`,
  `EventDeliveryDetailModal.vue`) exists and is spec'd/implemented. This substantially de-risks the change:
  no rebuild of delivery/retry/signing/dead-letter is needed, only a new producer path and a new
  action-dispatch branch.
- **JsonLogic is present but NOT currently used for event filters.** `jwadhams/json-logic-php` is a real
  composer dependency, used today only by `EndpointService`'s rule-condition engine
  (`JsonLogic::apply($conditions, $data)`). `EventService::evaluateFilters()` has its own, separate
  `exact`/`prefix`/`suffix`/`expression` dialect switch. Adding `jsonlogic` as a new case in that switch is
  a small, additive change reusing the already-present library — not a new dependency, but also not
  something that "just works today" as the brief's phrasing might suggest.
- **ADR-023 is FULLY implemented in openconnector at HEAD** (correcting this discovery's own first research
  pass, which wrongly reported it absent): `lib/Service/ActionAuthService.php` (`requireAction()`, `can()`,
  `getMatrix()`/`setMatrix()`; admin always passes; matrix stored in `IAppConfig` key `actions`; any action
  missing from the matrix defaults to `["admin"]` — default-deny by construction),
  `lib/Controller/ActionMatrixController.php` (`GET`/`PUT /api/admin/action-matrix`,
  `#[AuthorizedAdminSetting]`, and its `getMatrix()` response unions `lib/actions.seed.json` keys so newly
  seeded actions surface in the admin UI automatically), `lib/Repair/InitializeActions.php` (seeds the
  matrix from `lib/actions.seed.json` on install/upgrade without clobbering admin edits), and
  `src/views/admin/ActionAuthMatrix.vue` (existing admin editor). Multiple controllers already enforce it —
  including `EventsController` itself: `subscribe()`, `updateSubscription()`, `unsubscribe()`,
  `subscriptions()`, `subscriptionMessages()`, `messages()`, and `pull()` each call `requireAction` with
  seeded actions (`event.subscribe`, `event.update-subscription`, … — `lib/actions.seed.json` lines 27–33).
  Self-service for NC-native event types is therefore a pure REUSE play: seed four new per-family actions
  following the existing `<entity>.<verb>` naming convention and add per-family `requireAction` call sites —
  no new authorization service, endpoint, or UI. This also means the `events-cloudevents` spec's REQ-005
  Note ("no auth on subscribe") is stale at the ACTION level at HEAD; the still-open residue is per-OBJECT
  ownership (action RBAC gates who may call the endpoint, not which subscription they may touch).

## Recommendation
**Proceed to specs and design as scoped** — extend the existing `events-cloudevents`/`dead-letter-replay`/
`webhook-signing`/`consumer-management` machinery rather than building anything new for delivery, retry,
signing, or dead-lettering. For the producer side: build file listeners against `OCP\Files\Events\Node\*`
+ `OCP\SystemTag\MapperEvent` with full confidence (verified stable API); build the calendar listener
against `OCA\DAV\Events\*` with an explicit "OCA stability, not OCP" caveat documented at the listener
class level; build Tables/Forms listeners behind `IAppManager::isEnabledForAnyUser()` feature-detection AND
mark their exact event class names/payloads as TENTATIVE pending a live-instance check with both apps
installed (tracked as a task-list item, not blocking spec-writing — the subscription/delivery/filter
machinery around them is identical regardless of the exact upstream class name).

## Risks Uncovered
- Tables/Forms event class names used in `design.md`/`tasks.md` (`OCA\Tables\Event\Row*Event`,
  `OCA\Forms\Event\FormSubmitted`) are best-effort/plausible names, NOT confirmed against source. The
  implementation task for these two listeners MUST start with a `find`/`grep` against a live checkout (or
  the apps' published source) to confirm exact class names and constructor/payload shape before writing
  listener code — flagged explicitly in `tasks.md`.
- `OCA\DAV\Events\*` class names/shapes could change across NC majors (28→34 spans several years of DAV
  app changes) in ways this discovery did not diff version-by-version (only NC 33 master was checked). The
  calendar listener needs a defensive `instanceof`/property-existence check rather than assuming a fixed
  shape, and a compatibility note per supported NC version in the task's acceptance criteria.

## Next Steps
Proceed to `specs/` (delta specs against `events-cloudevents`, plus one new `nextcloud-event-triggers`
capability spec for the producer side — see `design.md` for why the producer is a new capability while the
delivery-side changes are deltas to existing specs), then `tasks.md` with the Tables/Forms class-name
verification as an explicit early task.
