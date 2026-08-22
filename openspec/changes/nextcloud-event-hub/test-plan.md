# Test Plan: nextcloud-event-hub

## Test Cases

### TC-1: File create/update/delete produces a correctly-shaped event
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001`
- **type**: api
- **preconditions**: an active `event_subscription` with `types = ["com.nextcloud.files.node.created"]`
- **steps**: upload a file via the WebDAV/Files API
- **expected result**: an `event` record is persisted with `type = 'com.nextcloud.files.node.created'`,
  `source = '/nextcloud/files'`, `data.fileid`/`data.path` populated; `processEvent` produces a matching
  `event_message`
- **test command**: `/test-api`

### TC-2: File tag change is distinctly typed from create/update/delete
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001`
- **type**: api
- **preconditions**: subscription with `types = ["com.nextcloud.files.node.tagged"]`
- **steps**: apply a system tag to a file
- **expected result**: `event.type = 'com.nextcloud.files.node.tagged'`
- **test command**: `/test-api`

### TC-3: Calendar object creation is captured
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002`
- **type**: api
- **preconditions**: subscription with `types = ["com.nextcloud.calendar.object.created"]`
- **steps**: create a calendar event via CalDAV
- **expected result**: `event.type = 'com.nextcloud.calendar.object.created'`, `data.calendarId`/`data.objectUri` populated
- **test command**: `/test-api`

### TC-4: Unexpected DAV event shape is logged and skipped, not thrown
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002`
- **type**: regression
- **preconditions**: a mocked `CachedCalendarObjectCreatedEvent` missing an expected accessor
- **steps**: dispatch the malformed event in a unit test
- **expected result**: `NextcloudCalendarEventListener::handle()` logs a warning, returns without persisting
  or throwing; other listeners unaffected
- **test command**: `/test-regression`

### TC-5: Tables listener is absent when Tables app is not installed
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003`
- **type**: functional
- **preconditions**: an NC instance without `tables` installed
- **steps**: boot Integriq
- **expected result**: no `NextcloudTablesEventListener` registration occurs, no error logged
- **test command**: `/test-functional`

### TC-6: Tables row update produces an event when Tables is installed
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003`
- **type**: api
- **preconditions**: `tables` installed; subscription with `types = ["com.nextcloud.tables.row.updated"]`
- **steps**: edit a row via the Tables API
- **expected result**: `event.type = 'com.nextcloud.tables.row.updated'`, `data.tableId`/`data.rowId` populated
- **test command**: `/test-api`

### TC-7: Forms submission produces an event when Forms is installed
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004`
- **type**: api
- **preconditions**: `forms` installed; subscription with `types = ["com.nextcloud.forms.submission.created"]`
- **steps**: submit a form
- **expected result**: `event.type = 'com.nextcloud.forms.submission.created'`, `data.formId` populated
- **test command**: `/test-api`

### TC-8: Non-admin lacking the per-family action grant is rejected for an NC-native type
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-non-admin-subscription-requests-for-nc-native-types-must-be-gated-via-the-existing-adr-023-action-matrix-req-005`
- **type**: security
- **persona**: Priya (ZZP Developer / Integrator) — a technically capable non-admin user attempting
  self-service
- **preconditions**: action matrix has `event.subscribe-nextcloud-files = ["admin"]` (seeded default);
  caller's group holds the coarse `event.subscribe` grant
- **steps**: non-admin calls `POST /api/events/subscriptions` with `types = ["com.nextcloud.files.node.created"]`
- **expected result**: HTTP 403 (`ActionAuthService::requireAction` throws for the family action)
- **test command**: `/test-security`

### TC-9: Non-admin succeeds once their group is granted the per-family action
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-non-admin-subscription-requests-for-nc-native-types-must-be-gated-via-the-existing-adr-023-action-matrix-req-005`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator)
- **preconditions**: matrix grants the caller's NC group both `event.subscribe` and
  `event.subscribe-nextcloud-files`
- **steps**: subscribe via the UI with a `com.nextcloud.files.*` type
- **expected result**: subscription created, HTTP 200
- **test command**: `/test-functional`

### TC-10: Admin is never gated regardless of matrix state
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-non-admin-subscription-requests-for-nc-native-types-must-be-gated-via-the-existing-adr-023-action-matrix-req-005`
- **type**: regression
- **preconditions**: seeded admin-only matrix defaults for all four `event.subscribe-nextcloud-*` actions
- **steps**: admin subscribes to any NC-native type
- **expected result**: succeeds regardless of matrix state (`requireAction` admin bypass)
- **test command**: `/test-regression`

### TC-11: Subscribing to a pre-existing (non-NC-native) type triggers no per-family check
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-non-admin-subscription-requests-for-nc-native-types-must-be-gated-via-the-existing-adr-023-action-matrix-req-005`
- **type**: regression
- **preconditions**: non-admin whose group holds the coarse `event.subscribe` grant; no
  `event.subscribe-nextcloud-*` grants
- **steps**: subscribe with `types = ["com.nextcloud.openregister.object.created"]` only
- **expected result**: no per-family action is checked; pre-existing coarse-action-only `subscribe()`
  behaviour applies unchanged
- **test command**: `/test-regression`

### TC-12: Admin grants the Tables family to a group via the existing action-matrix editor
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-the-four-per-family-actions-must-be-seeded-into-the-existing-action-matrix-req-006`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: `event.subscribe-nextcloud-tables = ["admin"]` (seeded default); the four new actions
  are visible in the existing ActionAuthMatrix editor (seed-key union, no UI change)
- **steps**: admin opens the existing Action authorization matrix editor, adds `openconnector-power-users`
  to `event.subscribe-nextcloud-tables`, saves (`PUT /api/admin/action-matrix`)
- **expected result**: mapping persists; reload shows it; group members holding `event.subscribe` can then
  self-service-subscribe to `com.nextcloud.tables.*` types
- **test command**: `/test-functional`

### TC-13: Non-admin cannot read or write the action matrix
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-the-four-per-family-actions-must-be-seeded-into-the-existing-action-matrix-req-006`
- **type**: security
- **preconditions**: authenticated non-admin
- **steps**: call `GET`/`PUT /api/admin/action-matrix`
- **expected result**: rejected by `AuthorizedAdminSetting` (pre-existing behaviour, regression-confirmed)
- **test command**: `/test-security`

### TC-13b: Upgraded install whose matrix predates the seed entries stays fail-closed
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-the-four-per-family-actions-must-be-seeded-into-the-existing-action-matrix-req-006`
- **type**: security
- **preconditions**: stored matrix without any `event.subscribe-nextcloud-*` keys (simulating an upgrade
  before the repair step runs)
- **steps**: non-admin (holding coarse `event.subscribe`) attempts an NC-native subscribe
- **expected result**: HTTP 403 — `getAllowedGroups` falls back to `["admin"]` for the missing action
- **test command**: `/test-security`

### TC-14: jsonlogic filter evaluates correctly and short-circuits like other dialects
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-cloudevent-fan-out-to-matching-subscriptions-req-001`
- **type**: api
- **preconditions**: subscription filter `[{jsonlogic: {"in": ["invoice", {"var": "data.attributes.tags"}]}}]`
- **steps**: fire a matching and a non-matching event
- **expected result**: `evaluateFilters` returns `true`/`false` correctly via `JsonLogic::apply`
- **test command**: `/test-api`

### TC-15: Default action (webhook) delivery is unchanged
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008`
- **type**: regression
- **preconditions**: subscription with no `action` field
- **steps**: fire a matching event
- **expected result**: `deliverMessage` invoked exactly as pre-change; signing/retry/dead-letter behaviour
  identical
- **test command**: `/test-regression`

### TC-16: action.kind=synchronization runs the synchronization, not an HTTP call
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008`
- **type**: api
- **preconditions**: subscription with `action = {kind: 'synchronization', synchronizationId}`
- **steps**: fire a matching event
- **expected result**: `SynchronizationService::synchronize` invoked; no HTTP request made; success →
  `status='delivered'`
- **test command**: `/test-api`

### TC-17: action.kind=synchronization failure enters the standard retry/backoff/abandon machine
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008`
- **type**: api
- **preconditions**: same as TC-16, synchronization configured to fail
- **steps**: fire the event repeatedly across sweep cycles
- **expected result**: retryCount increments, backoff schedule applies, eventually `abandoned`
- **test command**: `/test-api`

### TC-18: action.kind=job runs the job, not an HTTP call
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008`
- **type**: api
- **preconditions**: subscription with `action = {kind: 'job', jobId}`
- **steps**: fire a matching event
- **expected result**: `JobService::executeJob(forceRun: true)` invoked
- **test command**: `/test-api`

### TC-19: Unrecognised action.kind fails once without entering the retry loop
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008`
- **type**: api
- **preconditions**: subscription with `action = {kind: 'carrier-pigeon'}`
- **steps**: fire a matching event
- **expected result**: message `status='failed'` with descriptive error, `retryCount` stays 0
- **test command**: `/test-api`

### TC-20: Custom retryPolicy overrides the default backoff schedule
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-push-delivery-with-status-tracking-and-retry-sweep-req-002`
- **type**: api
- **preconditions**: subscription `retryPolicy = {baseSeconds: 30, factor: 2, capSeconds: 1800, maxRetries: 3}`
- **steps**: fail delivery repeatedly
- **expected result**: backoff follows the custom schedule; abandons after 3 failures
- **test command**: `/test-api`

### TC-21: Partial retryPolicy only overrides the keys it sets
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009`
- **type**: api
- **preconditions**: subscription `retryPolicy = {maxRetries: 8}` only
- **steps**: fail delivery repeatedly
- **expected result**: baseSeconds/factor/capSeconds use defaults; abandons after 8 failures
- **test command**: `/test-api`

### TC-22: Subscription without retryPolicy is byte-for-byte unchanged (regression)
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009`
- **type**: regression
- **preconditions**: subscription with no `retryPolicy`
- **steps**: fail delivery repeatedly
- **expected result**: 60s / ×4 / 6h cap / 5 retries — identical to pre-change behaviour
- **test command**: `/test-regression`

### TC-23: Replaying an abandoned synchronization-action message re-runs the synchronization
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-audited-replay-returning-the-message-to-the-delivery-machine-req-dlr-003`
- **type**: api
- **preconditions**: abandoned message with `action.kind = 'synchronization'`
- **steps**: admin calls replay
- **expected result**: `SynchronizationService::synchronize` invoked, not an HTTP call; success → delivered
- **test command**: `/test-api`

### TC-24: Replaying an abandoned webhook message is unchanged (regression)
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-audited-replay-returning-the-message-to-the-delivery-machine-req-dlr-003`
- **type**: regression
- **preconditions**: abandoned message, `action` absent, sink now healthy
- **steps**: admin calls replay
- **expected result**: `deliverMessage` invoked; message delivered; prior `attempts[]` preserved
- **test command**: `/test-regression`

### TC-25: Dead-letter list shows action-kind badges per row
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-dead-letter-listing-and-detail-must-surface-action-kind-and-nextcloud-event-provenance-req-dlr-007`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: dead-lettered messages with mixed action kinds
- **steps**: admin opens Event deliveries view
- **expected result**: each row displays its own action-kind badge
- **test command**: `/test-functional`

### TC-26: Nextcloud-event provenance filter excludes OR-object events despite shared type prefix
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-dead-letter-listing-and-detail-must-surface-action-kind-and-nextcloud-event-provenance-req-dlr-007`
- **type**: functional
- **preconditions**: a mix of `/nextcloud/files`-sourced and `/objects/person`-sourced dead-lettered messages
  (the latter carrying a `com.nextcloud.openregister.object.created` type, sharing the `com.nextcloud.`
  prefix with the new producer namespace)
- **steps**: admin applies the "Nextcloud event" provenance filter
- **expected result**: only `/nextcloud/*`-sourced messages remain; OR-object messages are excluded despite
  the shared type prefix
- **test command**: `/test-functional`

### TC-27: HMAC signature present and verifiable on an NC-event-sourced webhook delivery
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001`
- **type**: api
- **preconditions**: subscription with a `signingSecret` and `types = ["com.nextcloud.files.node.created"]`
- **steps**: fire a file-created event; capture the outbound request
- **expected result**: `X-OpenConnector-Signature` header present; recomputed HMAC matches
- **test command**: `/test-api`

### TC-28: Full failure→dead-letter→replay loop for an NC-native event
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-audited-replay-returning-the-message-to-the-delivery-machine-req-dlr-003`
- **type**: api
- **preconditions**: subscription to an NC-native type with an unreachable sink
- **steps**: fire the event, let the retry sweep exhaust `maxRetries`, then fix the sink and replay
- **expected result**: message reaches `abandoned`, appears in dead-letter listing, and delivers successfully
  after replay
- **test command**: `/test-api`

## Coverage Summary
- `nextcloud-event-triggers` REQ-001–REQ-006: covered by TC-1, TC-2, TC-3, TC-4, TC-5, TC-6, TC-7, TC-8,
  TC-9, TC-10, TC-11, TC-12, TC-13, TC-13b.
- `events-cloudevents` REQ-001 (MODIFIED, jsonlogic dialect): covered by TC-14.
- `events-cloudevents` REQ-002 (MODIFIED, retryPolicy override): covered by TC-20, TC-22.
- `events-cloudevents` REQ-008 (ADDED, action dispatch): covered by TC-15, TC-16, TC-17, TC-18, TC-19.
- `events-cloudevents` REQ-009 (ADDED, configurable retry policy): covered by TC-21 (TC-20/TC-22 also apply).
- `dead-letter-replay` REQ-DLR-003 (MODIFIED, action-aware replay): covered by TC-23, TC-24, TC-28.
- `dead-letter-replay` REQ-DLR-007 (ADDED, provenance/badges): covered by TC-25, TC-26.
- Signing (unchanged `webhook-signing` REQ-WHS-001, exercised against the new producer path): covered by
  TC-27.

## Out of Scope
- `consumer-management` and `webhook-signing` receive no spec deltas in this change (existing mechanisms are
  reused unmodified) — no dedicated new test cases beyond TC-27's regression-style confirmation that signing
  still applies correctly when the event's producer is new.
- Kafka/MQTT sink delivery, WorkflowEngine/Flow actions, and Tables-as-sync-source/target are out of this
  change's scope per `proposal.md` and are not tested here.
