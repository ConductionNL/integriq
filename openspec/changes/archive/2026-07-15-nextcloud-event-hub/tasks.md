# Tasks: nextcloud-event-hub

## Implementation Tasks

### Task 1: Add `action` and `retryPolicy` properties to the `event_subscription` register descriptor
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN the descriptor is parsed WHEN inspecting `components.schemas.event_subscription.properties` THEN
    `action` and `retryPolicy` object properties exist, both absent from `required`
  - GIVEN an existing seeded subscription with neither field set WHEN re-validated against the updated
    schema THEN it remains valid (no regression)
- [x] Implement
- [x] Test

### Task 2: Spike — confirm Tables and Forms event class names against a live instance
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003`
- **files**: none (research spike; update `discovery.md` findings and this task's own notes if names differ
  from the TENTATIVE ones used elsewhere in this change)
- **acceptance_criteria**:
  - GIVEN a Nextcloud instance with `tables` and `forms` installed WHEN inspecting their `Event`/`Events`
    namespaces THEN the exact class names, constructor signatures, and available payload accessors for
    row create/update/delete and form-submission-created are documented
- [ ] Implement — unverified: `discovery.md` states Tables/Forms class names are "plausible/unverified in
      this environment" (neither `apps/tables` nor `apps/forms` present in the checked checkout); requires a
      live instance with both apps installed to confirm.
- [ ] Test — same reason; this is a research spike, not a code deliverable.

### Task 3: Extend `EventService::evaluateFilters` with the `jsonlogic` dialect
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-cloudevent-fan-out-to-matching-subscriptions-req-001`
- **files**: `lib/Service/EventService.php`
- **acceptance_criteria**:
  - GIVEN a filter `{jsonlogic: {"in": ["invoice", {"var": "data.attributes.tags"}]}}` and a matching event
    WHEN `evaluateFilters` runs THEN it returns `true` via `JsonLogic::apply`
  - GIVEN the existing `exact`/`prefix`/`suffix`/`expression` dialects WHEN their existing tests run THEN
    they are unaffected (regression)
- [x] Implement
- [x] Test

### Task 4: Add subscription-level `retryPolicy` override to `deliverMessage`/`recordFailure`/`processRetries`
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-push-delivery-with-status-tracking-and-retry-sweep-req-002`
- **files**: `lib/Service/EventService.php`
- **acceptance_criteria**:
  - GIVEN a subscription with `retryPolicy = {baseSeconds: 30, factor: 2, capSeconds: 1800, maxRetries: 3}`
    WHEN a delivery fails THEN `nextAttempt` uses the custom schedule and the message abandons after 3
    failures, not 5
  - GIVEN a subscription with a partial `retryPolicy` (only `maxRetries`) WHEN a delivery fails THEN the
    unset keys fall back to the existing class constants
  - GIVEN a subscription with no `retryPolicy` WHEN deliveries fail repeatedly THEN behaviour is byte-for-byte
    identical to pre-change (60s / ×4 / 6h cap / 5 retries) — regression test required
- [x] Implement
- [x] Test

### Task 5: Add `event_subscription.action` dispatch to `EventService::processEvent`
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008`
- **files**: `lib/Service/EventService.php`
- **acceptance_criteria**:
  - GIVEN `action` absent WHEN a message is dispatched THEN `deliverMessage` runs exactly as before
    (regression)
  - GIVEN `action = {kind: 'synchronization', synchronizationId}` WHEN dispatched THEN
    `SynchronizationService::synchronize` runs and NOT `deliverMessage`; failure enters the standard
    retry/backoff/abandon machine
  - GIVEN `action = {kind: 'job', jobId}` WHEN dispatched THEN `JobService::executeJob(forceRun: true)` runs
  - GIVEN an unrecognised `action.kind` WHEN dispatched THEN the message fails once with `retryCount`
    unchanged (no retry loop)
- [x] Implement
- [x] Test

### Task 6: File event listener (`OCP\Files\Events\Node\*`, `OCP\SystemTag\MapperEvent`)
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001`
- **files**: `lib/EventListener/NextcloudFileEventListener.php`, `lib/EventListener/NextcloudFileTagEventListener.php`,
  `lib/Service/EventService.php` (new `handleNextcloudEvent` method), `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a file is created/updated/deleted WHEN NC dispatches the corresponding `Node*Event` THEN a
    correctly-typed, correctly-sourced `event` record is persisted and `processEvent` is invoked
  - GIVEN a file's system tag changes WHEN `MapperEvent` fires THEN `com.nextcloud.files.node.tagged` is
    persisted, distinct from create/update/delete
- [x] Implement
- [x] Test

### Task 7: Calendar event listener (`OCA\DAV\Events\CachedCalendarObject*Event`)
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002`
- **files**: `lib/EventListener/NextcloudCalendarEventListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a calendar object is created/updated/deleted WHEN NC dispatches the corresponding `Cached*Event`
    THEN a correctly-typed `event` record is persisted
  - GIVEN a fired event lacks an expected accessor (defensive `method_exists` check) WHEN `handle()` runs
    THEN it logs a warning and returns without throwing into the NC event dispatcher
- [x] Implement
- [x] Test

### Task 8: Tables row event listener (feature-detected)
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003`
- **files**: `lib/EventListener/NextcloudTablesEventListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `tables` is not installed WHEN OpenConnector boots THEN no listener is registered and no error is
    logged
  - GIVEN `tables` is installed and a row is created/updated/deleted WHEN the row event fires THEN a
    correctly-typed `event` record is persisted
- [x] Implement
- [x] Test

### Task 9: Forms submission event listener (feature-detected)
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004`
- **files**: `lib/EventListener/NextcloudFormsEventListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `forms` is not installed WHEN OpenConnector boots THEN no listener is registered
  - GIVEN `forms` is installed and a form is submitted WHEN the submission event fires THEN a correctly-typed
    `event` record is persisted with `data.formId`
- [x] Implement
- [x] Test

### Task 10: Per-family `requireAction` gate in `EventsController` (ADR-023 reuse)
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-non-admin-subscription-requests-for-nc-native-types-must-be-gated-via-the-existing-adr-023-action-matrix-req-005`
- **files**: `lib/Controller/EventsController.php` (no new service — reuses the already-injected
  `ActionAuthService`; add the `types[]` → `event.subscribe-nextcloud-<domain>` mapping and the per-family
  `requireAction` calls in `subscribe()` and `updateSubscription()`, after the existing coarse
  `event.subscribe`/`event.update-subscription` checks)
- **acceptance_criteria**:
  - GIVEN a non-admin whose groups hold `event.subscribe` but NOT the family action (seeded `["admin"]`)
    WHEN `subscribe()` is called with an NC-native type THEN 403 (`OCSForbiddenException` from
    `requireAction`)
  - GIVEN a non-admin whose NC group is granted BOTH `event.subscribe` and the relevant
    `event.subscribe-nextcloud-<domain>` action WHEN `subscribe()` is called THEN 200
  - GIVEN an admin caller WHEN `subscribe()` is called with any NC-native type THEN the gate never rejects
    (`requireAction` admin bypass)
  - GIVEN `types[]` contains only `com.nextcloud.openregister.*` (or other non-NC-native) entries WHEN
    `subscribe()`/`updateSubscription()` run THEN no per-family action is checked (pre-existing
    coarse-action-only behaviour unchanged) — regression test required
- [x] Implement
- [x] Test

### Task 11: Seed the four `event.subscribe-nextcloud-*` actions in `lib/actions.seed.json`
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-the-four-per-family-actions-must-be-seeded-into-the-existing-action-matrix-req-006`
- **files**: `lib/actions.seed.json` (four new entries, each `["admin"]`; no changes to
  `InitializeActions.php`, `ActionMatrixController.php`, or `ActionAuthMatrix.vue` — the existing repair
  step seeds them and the existing matrix endpoint/UI surfaces them automatically)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the `InitializeActions` repair step runs THEN the matrix contains all four
    `event.subscribe-nextcloud-*` actions mapped to `["admin"]`
  - GIVEN an admin WHEN they open `GET /api/admin/action-matrix` (or the ActionAuthMatrix editor) THEN the
    four new actions are listed (seed-key union) without any endpoint or UI change
  - GIVEN an upgraded install whose stored matrix predates the new keys WHEN a non-admin attempts an
    NC-native subscribe THEN `getAllowedGroups` falls back to `["admin"]` and the request is rejected
    (fail-closed) — regression test required
- [x] Implement
- [x] Test

### Task 12: Playwright regression — grant a family via the existing matrix editor, then self-service subscribe
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-the-four-per-family-actions-must-be-seeded-into-the-existing-action-matrix-req-006`
- **files**: `tests/e2e/spec-coverage/nextcloud-event-triggers.spec.ts` (no production UI files — the
  admin surface is the pre-existing `src/views/admin/ActionAuthMatrix.vue`, unchanged)
- **acceptance_criteria**:
  - GIVEN an admin in the existing Action authorization matrix editor WHEN they grant
    `event.subscribe` and `event.subscribe-nextcloud-files` to a test group and save THEN the grants
    persist and reloading the editor shows them
  - GIVEN a non-admin member of that group WHEN they create a file-event subscription through the UI THEN
    the subscription is created (and the same flow WITHOUT the family grant is rejected with a visible
    error)
- [ ] Implement — not verified: `tests/e2e/spec-coverage/nextcloud-event-triggers.spec.ts` does not exist in
      the repo; this acceptance criterion requires a live browser/Playwright run.
- [ ] Test — same reason.

### Task 13: Self-service subscription action-type picker in the subscription modal
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008`
- **files**: `src/modals/EventSubscription/SubscriptionActionFields.vue`
- **acceptance_criteria**:
  - GIVEN a user creating a subscription WHEN they choose "Synchronization" or "Job" as the action THEN the
    corresponding target picker (synchronization/job selector) appears and its selection is submitted as
    `action.synchronizationId`/`action.jobId`
- [x] Implement — `src/modals/EventSubscription/SubscriptionActionFields.vue` exists, is wired into
      `src/registry.js` and `src/manifest.json` (`form-fields: SubscriptionActionFields`), and implements
      the kind picker + synchronization/job selectors writing `action.{kind,synchronizationId,jobId}`.
- [ ] Test — not verified: acceptance criterion is UI interaction behaviour requiring Playwright/live
      browser; this app has zero pre-existing `src/**/*.spec.js` Vue unit tests and none were added here.

### Task 14: Action-kind badge and Nextcloud-event provenance filter in the dead-letter UI
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-dead-letter-listing-and-detail-must-surface-action-kind-and-nextcloud-event-provenance-req-dlr-007`
- **files**: `src/views/EventDelivery/EventDeliveriesPage.vue`, `src/modals/EventDelivery/EventDeliveryDetailModal.vue`,
  `lib/Controller/EventsController.php` (dead-letter list/detail response fields)
- **acceptance_criteria**:
  - GIVEN dead-lettered messages of mixed `action.kind` WHEN the Event deliveries view renders THEN each
    row shows its own action-kind badge
  - GIVEN a mix of `/nextcloud/*`-sourced and `/objects/*`-sourced messages WHEN the "Nextcloud event"
    filter is applied THEN only `/nextcloud/*`-sourced messages remain
- [x] Implement
- [x] Test

### Task 15: Action-aware replay
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-audited-replay-returning-the-message-to-the-delivery-machine-req-dlr-003`
- **files**: `lib/Controller/EventsController.php`, `lib/Service/EventService.php`
- **acceptance_criteria**:
  - GIVEN an abandoned message with `action.kind = 'synchronization'` WHEN an admin replays it THEN
    `SynchronizationService::synchronize` runs (not an HTTP call) and success sets `status='delivered'`
  - GIVEN an abandoned webhook-kind message WHEN replayed THEN behaviour is unchanged (regression)
- [x] Implement
- [x] Test

### Task 16: Integration test — end-to-end NC event → delivery → signature → dead-letter → replay
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001`
- **files**: `tests/Integration/NextcloudEventDeliveryTest.php`
- **acceptance_criteria**:
  - GIVEN a real `NodeCreatedEvent` fired against a subscription with a signing secret WHEN delivery runs
    THEN the outbound request carries a verifiable `X-OpenConnector-Signature` header
  - GIVEN the sink fails 3 times consecutively WHEN the retry sweep runs THEN the message reaches
    `abandoned` and appears in the dead-letter listing, and replaying it after the sink recovers delivers it
- [x] Implement
- [x] Test

## Verification
- [ ] All tasks checked off — not all: Task 2 (live-instance spike), Task 12 (Playwright e2e), and Task 13's
      Test box (Playwright/live browser) remain unticked; see reasons inline above.
- [x] `openspec validate` passes — `nextcloud-event-triggers`, `events-cloudevents`, and `dead-letter-replay`
      all pass `openspec validate <name> --type spec --strict` after archiving.
- [ ] Manual testing against acceptance criteria — requires a live instance/browser; not performed in this
      finalization pass.
- [ ] Code review against spec requirements — pending PR review.

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/Service/EventServiceTest.php` extended;
      `tests/Unit/Controller/EventsControllerTest.php` extended for the per-family `requireAction` gate;
      new `tests/Unit/EventListener/Nextcloud*EventListenerTest.php`)
- [ ] Newman/Postman tests for new/changed API endpoints (subscribe with `action`/`retryPolicy`, per-family
      403/200 gate outcomes against the existing `/api/admin/action-matrix` grants, action-aware replay) —
      not verified: `tests/postman/openconnector.postman_collection.json` /
      `tests/integration/openconnector.postman_collection.json` show no diff for this change.
- [ ] Browser tests (Playwright MCP) for UI changes (family grant via the existing ActionAuthMatrix editor
      followed by self-service subscribe, subscription action-type picker, dead-letter action-kind badge +
      provenance filter) — not performed; requires a live instance/browser.
- [ ] All tests pass (`composer test`, `newman run`) — PHPUnit suite is green (988 tests); Newman was not run
      because no Newman collection changes exist for this feature (see above).

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` (new "Nextcloud event triggers" page describing file/calendar/
      Tables/Forms subscriptions, self-service via the ADR-023 action matrix, and action types) — not
      verified: `docs/features/events.md` shows no diff and no new page was added.
- [ ] Screenshot captured and committed to `docs/images/` (action matrix editor showing the new
      `event.subscribe-nextcloud-*` rows, subscription action picker, dead-letter action-kind badges) — not
      performed; requires a live instance/browser.

## i18n (company-wide hydra ADR-007)

- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added for: self-service 403 error message,
      subscription action-type picker, dead-letter action-kind badges and provenance filter label (the
      action-matrix editor's existing strings are unchanged)
