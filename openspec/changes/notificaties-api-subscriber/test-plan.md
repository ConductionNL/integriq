# Test Plan: notificaties-api-subscriber

## Test Cases

### TC-1: Registering an abonnement persists it active with the remote-assigned url
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001`
- **type**: api
- **persona**: n/a (backend service test)
- **preconditions**: a `Source` configured pointing at a mocked Notificaties API returning 201 with a `url`
- **steps**: call `createAbonnement` with `kanalen=[{naam:'zaken', filters:{}}]`
- **expected result**: local record persisted `status='active'` with the mocked `url`
- **test command**: `/test-api` (PHPUnit — mocked HTTP, no live network)

### TC-2: A failed registration is recorded as an error, not silently dropped
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001`
- **type**: api
- **preconditions**: mocked Notificaties API returns HTTP 503
- **steps**: call `createAbonnement`
- **expected result**: local record `status='error'` with non-empty `lastError`; no exception propagates
- **test command**: `/test-api`

### TC-3: Deleting an abonnement that still exists remotely fails safely
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001`
- **type**: api
- **preconditions**: an `active` abonnement; mocked remote DELETE returns HTTP 500
- **steps**: call `deleteAbonnement`
- **expected result**: local record status unchanged (NOT `deleted`); `lastError` updated
- **test command**: `/test-api`

### TC-4: A notification carrying the matching auth header is accepted
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-callback-authentication-reuses-consumer-management-apikey-verification-req-002`
- **type**: api
- **preconditions**: active abonnement with companion consumer holding a known apiKey secret
- **steps**: `POST /api/notificaties/callback/{abonnementId}` with `Authorization: <secret>` and a
  well-formed notification body
- **expected result**: HTTP 200; request authenticated via `AuthorizationService::authorizeApiKey`
- **test command**: `/test-api` (Newman collection)

### TC-5: A notification with a missing or mismatched auth header is rejected before any side effect
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-callback-authentication-reuses-consumer-management-apikey-verification-req-002`
- **type**: security
- **preconditions**: same abonnement as TC-4
- **steps**: send the callback with no `Authorization` header, then with a wrong value
- **expected result**: HTTP 401 both times; no `event` OR-object persisted; `processEvent` not invoked
- **test command**: `/test-security`

### TC-6: A well-formed zaak-created notification produces a matching internal event
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003`
- **type**: api
- **preconditions**: authenticated callback request
- **steps**: POST a `{kanaal:'zaken', resource:'zaak', actie:'create', ...}` body
- **expected result**: `event` persisted with `type='nl.conduction.zgw.notificatie.zaak'`,
  `source='/notificaties-api/zaken'`; `processEvent` invoked
- **test command**: `/test-api`

### TC-7: A matching event_subscription with a synchronization action runs the sync end-to-end
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003`
- **type**: api
- **preconditions**: as TC-6, plus an `event_subscription` with `types=['nl.conduction.zgw.notificatie.zaak']`
  and `action={kind:'synchronization', synchronizationId:'<uuid>'}`
- **steps**: send the same notification as TC-6
- **expected result**: `SynchronizationService::synchronize` invoked for the matched subscription
- **test command**: `/test-api` (integration test, Task 11)

### TC-8: A malformed notification body is rejected before any event is created
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003`
- **type**: api
- **preconditions**: authenticated callback request missing `kanaal`
- **steps**: POST the malformed body
- **expected result**: HTTP 400; no `event` persisted
- **test command**: `/test-api`

### TC-9: Deleting an abonnement removes its companion consumer
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnement-deletion-cascades-its-companion-consumer-req-004`
- **type**: api
- **preconditions**: active abonnement with `consumerId`
- **steps**: `deleteAbonnement`
- **expected result**: abonnement `status='deleted'`; companion `consumer` OR-object deleted
- **test command**: `/test-api`

### TC-10: A consumer-deletion failure does not block the abonnement's deleted status
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnement-deletion-cascades-its-companion-consumer-req-004`
- **type**: regression
- **preconditions**: as TC-9, but consumer delete throws
- **steps**: `deleteAbonnement`
- **expected result**: abonnement still transitions to `deleted`; failure logged
- **test command**: `/test-regression`

### TC-11: action.kind=notificaties publishes the ZGW notification body instead of an HTTP webhook
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010`
- **type**: api
- **preconditions**: subscription with `action={kind:'notificaties', sourceId, kanaal:'zaken'}` matching
  an event
- **steps**: trigger `processEvent`
- **expected result**: `CallService::call` invoked against the resolved Source; `deliverMessage` NOT
  invoked; `status='delivered'` on 2xx
- **test command**: `/test-api`

### TC-12: action.kind=notificaties failure follows the standard retry/backoff/abandon machine
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010`
- **type**: regression
- **preconditions**: as TC-11, mocked remote returns HTTP 500 repeatedly
- **steps**: trigger dispatch across retry sweeps
- **expected result**: `retryCount` increments, backoff scheduled, eventual `status='abandoned'` matching
  webhook/synchronization behaviour
- **test command**: `/test-regression`

### TC-13: An unresolvable sourceId is a retryable failure, not a hard error
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010`
- **type**: api
- **preconditions**: subscription action references a missing `sourceId`
- **steps**: trigger dispatch
- **expected result**: `status='failed'`, `error='source not found'`, `retryCount` incremented
- **test command**: `/test-api`

### TC-14: An OR object update event is mapped to an update notification
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-zgw-notification-publish-body-shape-req-005`
- **type**: api
- **preconditions**: an `object.updated` event and a `notificaties` action
- **steps**: call `buildNotificationBody`
- **expected result**: `actie='update'`, `hoofdObject`/`aanmaakdatum` correctly derived
- **test command**: `/test-api`

### TC-15: Static kenmerken merge with event-supplied kenmerken, event wins on key collision
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-zgw-notification-publish-body-shape-req-005`
- **type**: api
- **preconditions**: overlapping static and event-supplied `kenmerken` keys
- **steps**: call `buildNotificationBody`
- **expected result**: event-supplied value wins on collision
- **test command**: `/test-api`

### TC-16: A notificaties action with no kanaal fails once, does not enter the retry loop
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-a-publish-action-missing-kanaal-is-a-configuration-error-not-a-transient-failure-req-006`
- **type**: api
- **preconditions**: `action={kind:'notificaties', sourceId}` with no `kanaal`
- **steps**: trigger dispatch
- **expected result**: `status='failed'` with descriptive error; `retryCount` remains `0`
- **test command**: `/test-api`

### TC-17: A newly-created abonnement is briefly pending before settling
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnement-lifecycle-status-is-observable-req-007`
- **type**: api
- **preconditions**: n/a
- **steps**: call `createAbonnement`, inspect the OR object immediately after first persist and after
  settlement
- **expected result**: `pending` immediately, then `active`/`error`
- **test command**: `/test-api`

### TC-18: Abonnementen list page mounts and shows content
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin) — configures gov-facing integrations
- **preconditions**: authenticated admin session
- **steps**: navigate to `/apps/integriq/notificaties/abonnementen`
- **expected result**: index page renders inside the main content area with content visible
- **test command**: `/test-functional`

### TC-19: Add abonnement button opens the creation modal with a labeled kanalen select
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008`
- **type**: accessibility
- **persona**: Noor
- **preconditions**: Abonnementen index page loaded
- **steps**: click "Add Item"; inspect the kanalen `NcSelect`
- **expected result**: modal opens; `NcSelect` carries a non-empty `inputLabel` (WCAG 2.1 AA 1.3.1/4.1.2)
- **test command**: `/test-accessibility`

### TC-20: A non-endpoint-runtime controller authenticates via the same consumer apiKey path
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/consumer-management/spec.md#requirement-apikey-consumer-authentication-must-remain-callable-outside-the-endpoint-runtime-dispatch-path-req-con-002`
- **type**: api
- **preconditions**: a configured `consumer` with `authorizationType='apiKey'`
- **steps**: call `authorizeApiKey()` directly from `NotificatiesSubscriberController`
- **expected result**: identical success/fail-closed behaviour to the endpoint-runtime call site
- **test command**: `/test-api`

## Coverage Summary
| Requirement | Covered by |
|---|---|
| notificaties-api-connector REQ-001 | TC-1, TC-2, TC-3 |
| notificaties-api-connector REQ-002 | TC-4, TC-5 |
| notificaties-api-connector REQ-003 | TC-6, TC-7, TC-8 |
| notificaties-api-connector REQ-004 | TC-9, TC-10 |
| notificaties-api-connector REQ-005 | TC-14, TC-15 |
| notificaties-api-connector REQ-006 | TC-16 |
| notificaties-api-connector REQ-007 | TC-17 |
| notificaties-api-connector REQ-008 | TC-18, TC-19 |
| events-cloudevents REQ-010 | TC-11, TC-12, TC-13 |
| events-cloudevents REQ-011 | TC-6 (shared with connector REQ-003 — same normalization call) |
| consumer-management REQ-CON-002 | TC-20 |

All 20 requirement-scenario groups across the three delta/new spec files have at least one mapped test
case; every ADDED requirement in this change has coverage.

## Out of Scope
- Live-network testing against a real Notificaties API (e.g. Open Notificaties) is deliberately excluded
  from this test plan — all test cases above mock the remote API. A manual smoke test against a real
  target instance is recommended before General Availability, specifically to validate Design Decision
  4's callback-verification assumption (see design.md), but is not automated here.
- Autorisaties API scope enforcement — out of scope per proposal.md, therefore untested here.
