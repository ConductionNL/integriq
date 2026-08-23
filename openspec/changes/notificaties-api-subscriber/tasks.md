# Tasks: notificaties-api-subscriber

## Implementation Tasks

### Task 1: Register descriptor — notificaties_abonnement schema + action.kind enum
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/migration.md`
- **files**: `lib/Settings/integriq_register.json`
- **acceptance_criteria**:
  - GIVEN the app is upgraded WHEN the register-sync repair step runs THEN `notificaties_abonnement`
    appears in the `openconnector` register's schemas
  - GIVEN an existing `event_subscription` record WHEN the app is upgraded THEN its stored `action` value
    is unchanged (additive-only migration)
- [ ] Implement
- [ ] Test

### Task 2: NotificatiesSubscriberService — abonnement create/update/delete against the remote API
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001`
- **files**: `lib/Service/NotificatiesSubscriberService.php`
- **acceptance_criteria**:
  - GIVEN a valid abonnement config WHEN `createAbonnement` runs against a mocked 201 response THEN the
    local record is persisted `status='active'` with the remote-assigned `url`
  - GIVEN a remote 503 WHEN `createAbonnement` runs THEN the local record is persisted `status='error'`
    with a non-empty `lastError` and no exception propagates
  - GIVEN a remote DELETE failure WHEN `deleteAbonnement` runs THEN the local record does NOT transition
    to `deleted`
- [ ] Implement
- [ ] Test

### Task 3: Per-abonnement consumer provisioning and cascade delete
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnement-deletion-cascades-its-companion-consumer-req-004`
- **files**: `lib/Service/NotificatiesSubscriberService.php`
- **acceptance_criteria**:
  - GIVEN `createAbonnement` runs THEN a companion `consumer` OR-object is created with
    `authorizationType='apiKey'` and a server-generated secret, and the same secret is sent as the
    abonnement's `auth` field
  - GIVEN a successful `deleteAbonnement` WHEN it completes THEN the companion `consumer` is also deleted
  - GIVEN the companion-consumer delete call throws WHEN `deleteAbonnement` runs THEN the abonnement still
    transitions to `status='deleted'` and the failure is logged
- [ ] Implement
- [ ] Test

### Task 4: NotificatiesSubscriberController — callback endpoint with consumer-apiKey auth
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-callback-authentication-reuses-consumer-management-apikey-verification-req-002`
- **files**: `lib/Controller/NotificatiesSubscriberController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a request to `POST /api/notificaties/callback/{abonnementId}` with a matching `Authorization`
    header WHEN `callback()` runs THEN `AuthorizationService::authorizeApiKey()` authenticates it and
    processing proceeds
  - GIVEN a missing or mismatched `Authorization` header WHEN `callback()` runs THEN the response is
    HTTP 401 with no `event` OR-object persisted and `processEvent` NOT invoked
  - route entry exists in `appinfo/routes.php` with `#[NoAdminRequired]` + `#[NoCSRFRequired]` on
    `callback()` (hydra-gate-route-auth, hydra-gate-route-reachability)
- [ ] Implement
- [ ] Test

### Task 5: Inbound notification → internal CloudEvent normalization
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003`
- **files**: `lib/Service/NotificatiesSubscriberService.php`
- **acceptance_criteria**:
  - GIVEN an authenticated, well-formed notification body WHEN `handleInboundNotification` runs THEN
    `EventService::emitCloudEvent()` is called with `type='nl.conduction.zgw.notificatie.<resource>'`,
    `source='/notificaties-api/<kanaal>'`, `subject=resourceUrl`
  - GIVEN a matching `event_subscription` with `action.kind='synchronization'` WHEN the event fans out
    THEN `SynchronizationService::synchronize` runs
  - GIVEN a notification body missing `kanaal` WHEN validated THEN the response is HTTP 400 and no
    `event` is persisted
- [ ] Implement
- [ ] Test

### Task 6: EventService — `action.kind='notificaties'` dispatch branch
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-a-notificaties-kind-for-zgw-notificaties-api-publishing-req-010`
- **files**: `lib/Service/EventService.php`
- **acceptance_criteria**:
  - GIVEN a subscription with `action.kind='notificaties'` matching an event WHEN `attemptDelivery` runs
    THEN `CallService::call` is invoked against the resolved Source, NOT `deliverMessage`
  - GIVEN a 2xx response WHEN dispatch completes THEN the message is `status='delivered'`
  - GIVEN a non-2xx response or thrown exception WHEN dispatch completes THEN REQ-002's standard
    failure-path bookkeeping applies (retryCount, backoff, eventual abandon)
  - GIVEN an unresolvable `sourceId` WHEN dispatch runs THEN the message is `status='failed'` with
    `retryCount` incremented (retryable, not a config error)
- [ ] Implement
- [ ] Test

### Task 7: buildNotificationBody — ZGW wire-shape mapping + missing-kanaal config-error path
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-zgw-notification-publish-body-shape-req-005`
- **files**: `lib/Service/NotificatiesSubscriberService.php`
- **acceptance_criteria**:
  - GIVEN an `event` and a `notificaties` action WHEN `buildNotificationBody` runs THEN the result has
    `kanaal`, `hoofdObject`, `resource`, `resourceUrl`, `actie`, `aanmaakdatum`, `kenmerken` populated per
    the field-derivation rules in REQ-005
  - GIVEN both static `action.kenmerken` and `event.data.kenmerken` set the same key WHEN merged THEN the
    event-supplied value wins
  - GIVEN `action.kanaal` is absent or empty WHEN dispatch runs THEN the message fails once with
    `retryCount` NOT incremented (REQ-006)
- [ ] Implement
- [ ] Test

### Task 8: Abonnement lifecycle status surfacing
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnement-lifecycle-status-is-observable-req-007`
- **files**: `lib/Service/NotificatiesSubscriberService.php`, `lib/Controller/NotificatiesSubscriberController.php`
- **acceptance_criteria**:
  - GIVEN `createAbonnement` is called WHEN the OR object is first persisted, before the remote call
    returns THEN `status='pending'`
  - GIVEN the remote call completes WHEN it settles THEN `status` is `active` or `error` (never stuck
    `pending`)
- [ ] Implement
- [ ] Test

### Task 9: Lock in AuthorizationService::authorizeApiKey() as a public, reusable contract
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/consumer-management/spec.md#requirement-apikey-consumer-authentication-must-remain-callable-outside-the-endpoint-runtime-dispatch-path-req-con-002`
- **files**: `lib/Service/AuthorizationService.php` (verify only — no behaviour change expected), `tests/Unit/Service/AuthorizationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a controller other than `EndpointsController` WHEN it calls `authorizeApiKey($presentedKey, [])`
    against a configured consumer THEN authentication succeeds identically to the endpoint-runtime call
    site
  - GIVEN an unmatched key WHEN called from a non-endpoint-runtime controller THEN
    `AuthenticationException` is thrown and `getResolvedConsumer()` returns null
- [ ] Implement
- [ ] Test

### Task 10: Abonnementen Config UI
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008`
- **files**: `src/views/NotificatiesAbonnement/NotificatiesAbonnementenPage.vue`, `src/modals/NotificatiesAbonnement/NotificatiesAbonnementForm.vue`, manifest/route registration
- **acceptance_criteria**:
  - GIVEN an authenticated admin WHEN they navigate to `/apps/integriq/notificaties/abonnementen`
    THEN the Abonnementen index page renders with content visible
  - GIVEN the index page is loaded WHEN the user clicks "Add Item" THEN the creation modal opens with a
    kanalen `NcSelect` carrying a non-empty `inputLabel`
- [ ] Implement
- [ ] Test

### Task 11: Integration test — incoming notification triggers a synchronization end-to-end
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003`
- **files**: `tests/Integration/NotificatiesCallbackTest.php`
- **acceptance_criteria**:
  - GIVEN an active abonnement, its companion consumer, and a matching `event_subscription` with
    `action.kind='synchronization'` WHEN a correctly authenticated notification arrives at the callback
    THEN the synchronization runs, against a mocked remote Notificaties API (no live network call)
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — Tasks 2, 3, 5, 6, 7, 8, 9
- [ ] Newman/Postman tests for new/changed API endpoints — callback + abonnement CRUD endpoints (Task 4)
- [ ] Browser tests (Playwright MCP) for UI changes — Task 10 (Abonnementen page mount + modal)
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — Notificaties API subscriber/publisher usage guide
- [ ] Screenshot captured and committed to `docs/images/` — Abonnementen page + create modal

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added — Abonnementen UI labels, status
      values, error messages (i18n keys themselves stay ENGLISH per project convention)
