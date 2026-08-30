# notificaties-api-connector Specification

**Status**: planned
**Scope**: integriq
**OpenSpec changes**:
- `notificaties-api-subscriber` _(in progress)_

## Purpose
Integriq acts as a ZGW **Notificaties API** (Logius/VNG "API Notificatiestandaard voor ZGW APIs")
client — both a SUBSCRIBER (registers an `abonnement` on a remote Notificaties API for one or more
`kanalen`, receives notifications at a callback endpoint, and routes them into the existing CloudEvents
fan-out) and a PUBLISHER (sends an internal CloudEvent onward to a configured `kanaal` in the standard
notification body shape). This capability owns the ZGW-domain-specific wire mapping and abonnement
lifecycle; the generic delivery mechanics (retry/backoff/dead-letter, action dispatch) are owned by the
`events-cloudevents` capability and reused unchanged (see that spec's REQ-010/REQ-011).

## ADDED Requirements

### Requirement: Abonnement registration, update, and deletion against the remote API (REQ-001)
`NotificatiesSubscriberService::createAbonnement(array $config)` MUST resolve the configured `sourceId` to
a `Source` OR-object and call `CallService::call($source, '/abonnement', 'POST', ['json' => $body])` where
`$body` carries `{callbackUrl, auth, kanalen: [{naam, filters}]}`. `callbackUrl` MUST be this app's own
absolute callback URL for the created abonnement (`/api/notificaties/callback/{abonnementId}`, resolved
after the OR object is first persisted with a generated UUID). On a 2xx response the method MUST persist
the `notificaties_abonnement` record with `status = 'active'` and the remote `url` (the abonnement's own
resource URL, distinct from `callbackUrl`) returned by the API. On any non-2xx response or thrown
exception the method MUST persist `status = 'error'` with `lastError` set to a descriptive message, and
MUST NOT leave the record `pending` indefinitely.
`updateAbonnement(string $id, array $config)` MUST `PUT`/`PATCH` the remote abonnement (kanalen/filters
changes) with the same success/error bookkeeping. `deleteAbonnement(string $id)` MUST `DELETE` the remote
abonnement; on a 2xx (or 404 — already gone remotely) response the local record transitions to `status =
'deleted'` (soft-deleted, not hard-removed, for audit — see REQ-007); on any other failure the record
remains `active`/`error` with `lastError` updated and the local delete does NOT proceed (so an operator
does not lose track of an abonnement that still exists remotely).

#### Scenario: registering an abonnement persists it active with the remote-assigned url

- **GIVEN** a `notificaties_abonnement` config with `sourceId` pointing at a reachable Notificaties API
  and `kanalen = [{naam: 'zaken', filters: {}}]`
- **WHEN** `createAbonnement($config)` runs and the remote API returns HTTP 201 with `{url:
  'https://notificaties.example/abonnement/<uuid>'}`
- **THEN** the local `notificaties_abonnement` record SHALL be persisted with `status = 'active'` and
  `url` equal to the remote-assigned value

#### Scenario: a failed registration is recorded as an error, not silently dropped

- **GIVEN** the same config, but the remote API returns HTTP 503
- **WHEN** `createAbonnement($config)` runs
- **THEN** the local record SHALL be persisted with `status = 'error'` and a non-empty `lastError`
- **AND** no exception SHALL propagate to the caller (the error is recorded on the OR object, matching
  the existing `bankfeed_connection` lifecycle-status convention)

#### Scenario: deleting an abonnement that still exists remotely fails safely

- **GIVEN** an `active` abonnement AND a remote `DELETE` call that returns HTTP 500
- **WHEN** `deleteAbonnement($id)` runs
- **THEN** the local record SHALL remain in its prior status (NOT transition to `deleted`)
- **AND** `lastError` SHALL be updated with the failure reason

### Requirement: Callback authentication reuses consumer-management apiKey verification (REQ-002)
`NotificatiesSubscriberController::callback(string $abonnementId)` MUST be `#[NoAdminRequired]` +
`#[NoCSRFRequired]` (inbound calls carry no Nextcloud session) and MUST call
`AuthorizationService::authorizeApiKey($presentedHeader, [])` with the request's `Authorization` header
value BEFORE any other processing. A header that fails to match any `consumer` (per that method's existing
constant-time `resolveConsumerByApiKey` fail-closed behaviour — `consumer-management` REQ-CON-001) MUST
result in HTTP 401 with no side effect: no `event` OR-object persisted, no fan-out triggered. This is the
callback-verification mechanism for the ZGW Notificaties API delivery: per the standard, the Notificaties
Routeer Component (NRC) is expected to echo the `abonnement.auth` value (set at registration time, REQ-001)
back as the `Authorization` header on every delivered notification — there is no separate challenge/
response handshake step (see design.md Decision 4; flagged as an assumption pending verification against
a specific target NRC implementation).

@e2e exclude backend callback authentication — covered by PHPUnit/Newman, not browser UI

#### Scenario: a notification carrying the matching auth header is accepted

- **GIVEN** an `active` abonnement whose companion `consumer` has `authorizationConfiguration.apiKey =
  '<secret>'`
- **WHEN** `POST /api/notificaties/callback/{abonnementId}` arrives with `Authorization: <secret>`
- **THEN** the request SHALL be authenticated (HTTP 200 on successful processing)

#### Scenario: a notification with a missing or mismatched auth header is rejected before any side effect

- **GIVEN** the same abonnement
- **WHEN** the callback receives a request with a missing `Authorization` header, or one that does not
  match the abonnement's consumer
- **THEN** the response SHALL be HTTP 401
- **AND** no `event` OR-object SHALL be persisted
- **AND** `processEvent` SHALL NOT be invoked

### Requirement: Inbound notifications are normalized into the existing CloudEvents pipe (REQ-003)
`NotificatiesSubscriberService::handleInboundNotification()` MUST call `EventService::emitCloudEvent()`
on successful authentication (REQ-002), where the method signature is `handleInboundNotification(string
$abonnementId, array $notification)` and the emitted event has `type =
'nl.conduction.zgw.notificatie.'.$notification['resource']` (e.g.
`nl.conduction.zgw.notificatie.zaak`), `source = '/notificaties-api/'.$notification['kanaal']`, `subject =
$notification['resourceUrl']`, and `data` equal to the notification body verbatim (`kanaal`, `hoofdObject`,
`resource`, `resourceUrl`, `actie`, `aanmaakdatum`, `kenmerken`) plus `abonnementId` for correlation. This
reuses the generic producer entry point `events-cloudevents` REQ-011 already specifies — no new fan-out
logic is written by this capability. A malformed notification body (missing `kanaal`/`resource`/`actie`)
MUST be rejected with HTTP 400 before `emitCloudEvent` is called, and MUST NOT persist a partial `event`.

#### Scenario: a well-formed zaak-created notification produces a matching internal event

- **GIVEN** an authenticated callback request with body `{kanaal: 'zaken', hoofdObject:
  'https://zaken.example/api/v1/zaken/<uuid>', resource: 'zaak', resourceUrl:
  'https://zaken.example/api/v1/zaken/<uuid>', actie: 'create', aanmaakdatum: '2026-07-15T10:00:00Z',
  kenmerken: {bronorganisatie: '123443210'}}`
- **WHEN** `handleInboundNotification` runs
- **THEN** an `event` OR-object SHALL be persisted with `type = 'nl.conduction.zgw.notificatie.zaak'`,
  `source = '/notificaties-api/zaken'`, `subject` equal to the `resourceUrl`
- **AND** `processEvent` SHALL be invoked, fanning out to any matching `event_subscription` exactly as any
  other CloudEvent does (`events-cloudevents` REQ-001)

#### Scenario: a matching event_subscription with a synchronization action runs the sync

- **GIVEN** an `event_subscription` with `types = ['nl.conduction.zgw.notificatie.zaak']` and `action =
  {kind: 'synchronization', synchronizationId: '<uuid>'}`
- **WHEN** the Scenario above's notification is processed
- **THEN** `SynchronizationService::synchronize` SHALL be invoked for the matched subscription
  (`events-cloudevents` REQ-008, unchanged) — the ZGW notification has triggered a synchronization run
  with zero notification-specific trigger code

#### Scenario: a malformed notification body is rejected before any event is created

- **GIVEN** an authenticated callback request whose body is missing `kanaal`
- **WHEN** the controller validates the body
- **THEN** the response SHALL be HTTP 400
- **AND** no `event` OR-object SHALL be persisted

### Requirement: Abonnement deletion cascades its companion consumer (REQ-004)
The service MUST also delete the companion `consumer` OR-object referenced by an abonnement's
`consumerId` when `deleteAbonnement` (REQ-001) successfully transitions a `notificaties_abonnement` to
`status = 'deleted'`, so authentication material for a no-longer-registered abonnement does not persist
indefinitely. A consumer deletion failure MUST be logged but MUST NOT block the abonnement's own
`deleted` transition (the abonnement's remote-side deletion already succeeded; the orphaned consumer is a
lesser, recoverable issue surfaced via logging, not a reason to leave the abonnement mis-reported as
active).

#### Scenario: deleting an abonnement removes its companion consumer

- **GIVEN** an `active` abonnement with `consumerId = 'c-1'` AND a successful remote `DELETE`
- **WHEN** `deleteAbonnement($id)` runs
- **THEN** the abonnement SHALL transition to `status = 'deleted'`
- **AND** the `consumer` OR-object with uuid `c-1` SHALL be deleted

#### Scenario: a consumer-deletion failure does not block the abonnement's deleted status

- **GIVEN** the same abonnement AND the consumer delete call throws
- **WHEN** `deleteAbonnement($id)` runs
- **THEN** the abonnement SHALL still transition to `status = 'deleted'`
- **AND** the failure SHALL be logged

### Requirement: ZGW notification publish body shape (REQ-005)
`NotificatiesSubscriberService::buildNotificationBody(ObjectEntity $event, array $action): array` MUST
derive the ZGW notification body when an `event_subscription.action.kind = 'notificaties'` dispatch runs
(`events-cloudevents` REQ-010), as follows: `kanaal = action['kanaal']` (required — see REQ-006 for the
missing-kanaal configuration-error path); `hoofdObject = action['hoofdObjectField'] !== null ?
data_get($event.data, action['hoofdObjectField']) : $event.subject`; `resource = action['resourceField']
!== null ? data_get($event.data, action['resourceField']) : the trailing dot-segment of $event.type`;
`resourceUrl = $event.data.attributes.url ?? $event.subject`; `actie` derived from `$event.type`'s suffix
via `action['actieMap']` when present, defaulting to `{created: 'create', updated: 'update', deleted:
'destroy'}` matching the `com.nextcloud.openregister.object.*` type suffix convention `events-cloudevents`
REQ-004 already establishes; `aanmaakdatum = $event.time`; `kenmerken` = `action['kenmerken']` (a static
map) shallow-merged with `$event.data.kenmerken` when present (event-supplied values win).

#### Scenario: an OR object update event is mapped to an update notification

- **GIVEN** an `event` with `type = 'com.nextcloud.openregister.object.updated'`, `subject = 'uuid-1'`,
  `time = '2026-07-15T09:00:00Z'`, and `action = {kind: 'notificaties', kanaal: 'zaken', resourceField:
  null}`
- **WHEN** `buildNotificationBody` runs
- **THEN** the resulting body SHALL have `actie = 'update'`, `hoofdObject = 'uuid-1'`, `aanmaakdatum =
  '2026-07-15T09:00:00Z'`, and `resource` equal to the event type's trailing segment (`object`, unless
  `resourceField` maps to a more specific value)

#### Scenario: static kenmerken merge with event-supplied kenmerken, event wins on key collision

- **GIVEN** `action.kenmerken = {bronorganisatie: '000000000'}` AND `event.data.kenmerken =
  {bronorganisatie: '123443210', zaaktype: 'https://zaken.example/zaaktypen/1'}`
- **WHEN** `buildNotificationBody` runs
- **THEN** the resulting `kenmerken` SHALL be `{bronorganisatie: '123443210', zaaktype:
  'https://zaken.example/zaaktypen/1'}` — the event-supplied value overrides the static config

### Requirement: A publish action missing `kanaal` is a configuration error, not a transient failure (REQ-006)
`dispatchNotificatiesAction` MUST treat an `action.kanaal` that is absent or empty as a configuration
error (the `events-cloudevents` REQ-010 handler backed by this capability's `buildNotificationBody`),
following the exact same non-retryable bookkeeping `events-cloudevents` REQ-008 already specifies for an
unrecognised `action.kind`: `event_message.status = 'failed'` with a descriptive `error`, `retryCount` NOT
incremented.

#### Scenario: a notificaties action with no kanaal fails once, does not enter the retry loop

- **GIVEN** a subscription with `action = {kind: 'notificaties', sourceId: '<uuid>'}` (no `kanaal`)
  matching an incoming event
- **WHEN** the dispatch runs
- **THEN** the `event_message` SHALL be persisted `status = 'failed'` with a descriptive error
- **AND** `retryCount` SHALL remain `0`

### Requirement: Abonnement lifecycle status is observable (REQ-007)
`notificaties_abonnement.status` MUST be one of `pending` (persisted before the remote registration call
completes — see REQ-001's create flow), `active`, `error`, or `deleted`, and the Abonnementen UI (REQ-008)
MUST surface the current status and `lastError` (when `status = 'error'`) so an operator can see a failed
registration without inspecting logs.

#### Scenario: a newly-created abonnement is briefly pending before settling

- **GIVEN** `createAbonnement` is called
- **WHEN** the OR object is first persisted, before the remote call returns
- **THEN** its `status` SHALL be `pending`
- **AND** it SHALL settle to `active` or `error` once the remote call completes (REQ-001)

### Requirement: Abonnementen Config UI (REQ-008)
Integriq MUST provide an Abonnementen section in its SPA where administrators can browse, create,
edit, and delete `notificaties_abonnement` configurations, following the existing typed-manifest-page
convention used by Consumers/Webhooks/Endpoints. The kanalen multi-select MUST use `NcSelect` with an
explicit `inputLabel` (ADR-004 / `hydra-gate-nc-input-labels` — a bare `<label>` paired with `NcSelect`
breaks the component's internal accessibility wiring).

#### Scenario: abonnementen list page mounts and shows content

- GIVEN an authenticated admin visits the integriq app
- WHEN they navigate to the Abonnementen section via the sidebar nav or direct URL
  `/apps/integriq/notificaties/abonnementen`
- THEN the Abonnementen index page renders inside the main content area with content visible

#### Scenario: add abonnement button opens the creation modal with a labeled kanalen select

- GIVEN the Abonnementen index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal opens containing the abonnement creation form
- AND the kanalen `NcSelect` has a non-empty `inputLabel`

## Non-Functional Requirements

- **Performance:** the callback endpoint's auth check (REQ-002) MUST complete in the same order of
  magnitude as the existing `consumer-management` apiKey path (a linear scan of `consumer` OR-objects,
  unchanged) — no new performance-sensitive code is introduced.
- **Accessibility:** the Abonnementen UI meets WCAG 2.1 AA, matching the existing app baseline (REQ-008).
- **Internationalization:** Dutch and English MUST be supported (hydra ADR-007); i18n keys MUST be
  written in English per project convention.

## Acceptance Criteria

- [ ] An abonnement can be registered against a mocked Notificaties API and settles to `active`
- [ ] A signed inbound notification produces a matching internal CloudEvent and triggers a subscribed
      synchronization
- [ ] An unsigned/mismatched inbound notification is rejected with HTTP 401 and no side effect
- [ ] A subscription with `action.kind = 'notificaties'` publishes the correct ZGW notification body
      shape and inherits retry/backoff/dead-letter bookkeeping
- [ ] Deleting an abonnement removes its companion consumer
- [ ] The Abonnementen UI renders, and its kanalen `NcSelect` carries `inputLabel`

## Notes

- **Autorisaties API is explicitly out of scope** (proposal.md) — this capability does not enforce
  scope-based authorization of which kanalen/resources may be subscribed to or published on; any such
  enforcement today is only whatever the remote Notificaties API itself applies.
- **Decision 4's callback-verification mechanism is an assumption** (design.md) — grounded in the
  VNG-Realisatie `notificaties-api` OAS as understood at the time of writing, not verified against a
  specific live NRC implementation. Flagged for verification before General Availability against the
  actual target deployment (e.g. Open Notificaties).
- The per-object IDOR gap already flagged in `events-cloudevents` REQ-005's Notes (no ownership check on
  `EventsController` subscription endpoints) is inherited by `notificaties_abonnement` CRUD endpoints,
  which follow the same controller pattern — observed, not introduced, not fixed here (see design.md
  Security Considerations).
