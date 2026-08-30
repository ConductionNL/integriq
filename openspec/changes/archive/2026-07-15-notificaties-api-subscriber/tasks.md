# Tasks: notificaties-api-subscriber

## Implementation Tasks

### Task 1: Register descriptor — notificaties_abonnement schema + action.kind enum
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/migration.md`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN the app is upgraded WHEN the register-sync repair step runs THEN `notificaties_abonnement`
    appears in the `openconnector` register's schemas
  - GIVEN an existing `event_subscription` record WHEN the app is upgraded THEN its stored `action` value
    is unchanged (additive-only migration)
- [x] Implement
- [x] Test — `RegisterDescriptorTest::testRegisterDeclaresAllSchemaSlugs`/`testAllSchemasAreDefined` updated
      and pass; full PHPUnit suite (1466 tests) confirms no regression to existing `event_subscription`
      records or other schemas.

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
- [x] Implement
- [x] Test — `NotificatiesSubscriberServiceTest::testCreateAbonnementPersistsActiveWithRemoteUrl`/
      `testCreateAbonnementRecordsErrorOnRemote503`/`testDeleteAbonnementFailsSafelyOnRemote500`

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
- [x] Implement
- [x] Test — `NotificatiesSubscriberServiceTest::testCreateAbonnementProvisionsConsumerWithMatchingSecret`/
      `testDeleteAbonnementCascadesConsumer`/`testDeleteAbonnementConsumerDeleteFailureDoesNotBlock`

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
- [x] Implement — note: `callback()` uses `#[NoCSRFRequired]` + `#[PublicPage]` (not `#[NoAdminRequired]`),
      matching the `PeppolController::inbound()` precedent design.md Decision 1 cites — the caller carries
      no NC session at all, so `#[PublicPage]` is required for the route to be reachable (`#[NoAdminRequired]`
      alone still requires SOME authenticated NC user). Deviation from the acceptance-criteria wording,
      not from the design intent. Also added a defense-in-depth cross-check beyond REQ-002's literal text:
      the resolved consumer must be *this abonnement's own* companion consumer, not merely *a* valid
      consumer — see `NotificatiesSubscriberController::authenticateCallback()` docblock.
- [x] Test — `check:routes` passes (167 routes); `NotificatiesCallbackTest` covers both the accept and
      401-reject-with-no-side-effect paths end to end through the real controller/service/AuthorizationService.

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
- [x] Implement
- [x] Test — `NotificatiesSubscriberServiceTest::testHandleInboundNotificationEmitsCloudEvent`/
      `testHandleInboundNotificationRejectsMissingKanaal`; end-to-end sync trigger covered by
      `NotificatiesCallbackTest::testAuthenticatedNotificationTriggersSynchronization` (Task 11).

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
- [x] Implement — required adding `CallService` to `EventService`'s constructor (new dependency); all 4
      existing call sites (3 test files + 1 integration test) updated to pass a mocked `CallService`.
- [x] Test — `EventServiceNotificatiesActionTest` (4 tests: 2xx publish, failure retry-count increment,
      unresolvable-source retryable, REQ-006 missing-kanaal config-error). Backoff/abandon-schedule reuse
      is by construction (shared `recordFailure`/`recordConfigurationError` helpers, unit-tested already
      for webhook/synchronization/job kinds) — not re-proven with a dedicated multi-sweep abandon test here.

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
- [x] Implement — `buildNotificationBody` is `public static` (pure function, no service deps) so
      `EventService::dispatchNotificatiesAction()` can call it directly without a constructor dependency on
      `NotificatiesSubscriberService` — avoids a circular DI dependency (that class already depends on
      `EventService` for inbound normalization). Documented in both classes' docblocks.
- [x] Test — `NotificatiesSubscriberServiceTest::testBuildNotificationBodyMapsUpdateEvent`/
      `testBuildNotificationBodyKenmerkenMergeEventWins`;
      `EventServiceNotificatiesActionTest::testActionKindNotificatiesMissingKanaalIsConfigError` (REQ-006).

### Task 8: Abonnement lifecycle status surfacing
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnement-lifecycle-status-is-observable-req-007`
- **files**: `lib/Service/NotificatiesSubscriberService.php`, `lib/Controller/NotificatiesSubscriberController.php`
- **acceptance_criteria**:
  - GIVEN `createAbonnement` is called WHEN the OR object is first persisted, before the remote call
    returns THEN `status='pending'`
  - GIVEN the remote call completes WHEN it settles THEN `status` is `active` or `error` (never stuck
    `pending`)
- [x] Implement — status/lastError surfaced verbatim in the controller's JSON responses (`index`/`create`/
      `update`/`destroy` all return `$abonnement->getObject()`) and rendered in the Abonnementen UI
      (status badge + lastError column, Task 10).
- [x] Test — `NotificatiesSubscriberServiceTest::testCreateAbonnementIsPendingBeforeSettling`.

### Task 9: Lock in AuthorizationService::authorizeApiKey() as a public, reusable contract
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/consumer-management/spec.md#requirement-apikey-consumer-authentication-must-remain-callable-outside-the-endpoint-runtime-dispatch-path-req-con-002`
- **files**: `lib/Service/AuthorizationService.php` (verify only — no behaviour change expected), `tests/Unit/Service/AuthorizationServiceApiKeyTest.php`
- **acceptance_criteria**:
  - GIVEN a controller other than `EndpointsController` WHEN it calls `authorizeApiKey($presentedKey, [])`
    against a configured consumer THEN authentication succeeds identically to the endpoint-runtime call
    site
  - GIVEN an unmatched key WHEN called from a non-endpoint-runtime controller THEN
    `AuthenticationException` is thrown and `getResolvedConsumer()` returns null
- [x] Implement — verified only, zero behaviour change to `AuthorizationService.php`; confirmed
      `authorizeApiKey()`/`getResolvedConsumer()` are already public and used unmodified by
      `NotificatiesSubscriberController` (the second controller-outside-endpoint-runtime call site, after
      the pre-existing test suite itself).
- [x] Test — note: the task's `files` line names a not-yet-existing `AuthorizationServiceTest.php`; the
      real existing test file for this behaviour is `tests/Unit/Service/AuthorizationServiceApiKeyTest.php`
      — added `testApiKeyAuthWorksIdenticallyForANonEndpointRuntimeCaller` there instead of creating a
      duplicate file.

### Task 10: Abonnementen Config UI
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008`
- **files**: `src/views/NotificatiesAbonnement/NotificatiesAbonnementenPage.vue`, `src/modals/NotificatiesAbonnement/NotificatiesAbonnementForm.vue`, manifest/route registration
- **acceptance_criteria**:
  - GIVEN an authenticated admin WHEN they navigate to `/apps/openconnector/notificaties/abonnementen`
    THEN the Abonnementen index page renders with content visible
  - GIVEN the index page is loaded WHEN the user clicks "Add Item" THEN the creation modal opens with a
    kanalen `NcSelect` carrying a non-empty `inputLabel`
- [x] Implement — custom manifest page (`type: custom`, matches the Approvals/EventDeliveries precedent
      because create/update/delete must run server-side remote-registration logic, not generic OR CRUD),
      dedicated modal file (`src/modals/`), menu entry under Connections, registered in `registry.js`. The
      kanalen field is a taggable multi `NcSelect` with both `input-label` and `aria-label-combobox` set.
- [ ] Test — NOT live-verified against a running Nextcloud instance (none available in this session/
      environment). Verified instead via: `npm run build` (webpack, `USE_LOCAL_LIB=false NODE_ENV=production`)
      succeeds with zero compile errors; `eslint`/`stylelint` clean (pre-existing project-wide parser
      warnings only, matching every other custom `.vue` page in this app). No Playwright/Vitest component
      test was authored. Flagged as a genuine gap, not silently claimed done.

### Task 11: Integration test — incoming notification triggers a synchronization end-to-end
- **spec_ref**: `openspec/changes/notificaties-api-subscriber/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003`
- **files**: `tests/Integration/NotificatiesCallbackTest.php`
- **acceptance_criteria**:
  - GIVEN an active abonnement, its companion consumer, and a matching `event_subscription` with
    `action.kind='synchronization'` WHEN a correctly authenticated notification arrives at the callback
    THEN the synchronization runs, against a mocked remote Notificaties API (no live network call)
- [x] Implement
- [x] Test — `NotificatiesCallbackTest::testAuthenticatedNotificationTriggersSynchronization` (real
      Controller + real `NotificatiesSubscriberService` + real `EventService` + real `AuthorizationService`,
      only OR persistence/`CallService`/`SynchronizationService` mocked) plus
      `testMismatchedAuthHeaderRejectedWithNoSideEffect` (REQ-002 negative path, Task 4).

## Verification
- [ ] All tasks checked off — Task 10's UI is implemented but not live-browser-verified (see above); every
      other task's Implement + Test boxes are genuinely checked.
- [x] `openspec validate` passes — scoped validation of all three touched canonical specs passes after
      archive (see migration.md/design.md for the @spec-anchor repoint performed during archive).
- [ ] Manual testing against acceptance criteria — backend fully exercised via PHPUnit (1466 tests, 0
      failures); frontend build-verified only, not manually clicked through in a browser.
- [ ] Code review against spec requirements — not performed (no second reviewer in this session).

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — Tasks 2, 3, 5, 6, 7, 8, 9
- [ ] Newman/Postman tests for new/changed API endpoints — callback + abonnement CRUD endpoints (Task 4) —
      NOT authored in this session; PHPUnit unit (`NotificatiesSubscriberServiceTest`) + integration
      (`NotificatiesCallbackTest`, real controller call) coverage exists instead, but no Postman/Newman
      collection was added.
- [ ] Browser tests (Playwright MCP) for UI changes — Task 10 (Abonnementen page mount + modal) — NOT run;
      no live Nextcloud instance available in this session.
- [x] All tests pass (`composer test`) — `vendor/bin/phpunit -c phpunit-unit.xml`: 1466 tests, 4169
      assertions, 0 failures, 1 pre-existing skip (baseline was 1447/0/1 before this change).
      `newman run` — not applicable, no collection authored.

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — Notificaties API subscriber/publisher usage guide — NOT
      done in this session; flagged as follow-up work.
- [ ] Screenshot captured and committed to `docs/images/` — Abonnementen page + create modal — NOT done
      (no live browser session to capture from).

## i18n (company-wide hydra ADR-007)

- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added — every user-facing string in the new
      Vue components is wrapped in `t('openconnector', '...')` with an English literal key (project
      convention). Translated `.json` resource files were not hand-authored — this app's existing
      translation workflow generates those from extracted `t()` keys separately from feature PRs (matching
      every other recently-added connector's UI in this codebase), not a gap specific to this change.
