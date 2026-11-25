# Tasks: nextcloud-event-hub-verification

## 1. The spike (first — it can invalidate shipped behavior)

### Task 1: Confirm Tables/Forms event class names on a live instance
- **spec_ref**: `openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md`
- **files**: none (evidence recorded here), or `lib/EventListener/NextcloudTablesEventListener.php`, `lib/EventListener/NextcloudFormsEventListener.php`, `lib/AppInfo/Application.php` if wrong
- **acceptance_criteria**:
  - GIVEN a live instance with Tables and Forms enabled WHEN a row is created/updated/deleted and a form is submitted THEN the dispatched event classes are recorded here verbatim
  - GIVEN a mismatch with what the listeners register for THEN the registrations are corrected and a unit test pins each corrected class name
- [ ] Implement
- [ ] Test

## 2. Playwright

### Task 2: Family grant + self-service subscribe flow
- **spec_ref**: `openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md`
- **files**: `tests/e2e/spec-coverage/nextcloud-event-triggers.spec.ts`
- **acceptance_criteria**:
  - GIVEN an admin grants `event.subscribe-nextcloud-files` to a group via the ActionAuthMatrix editor WHEN a member self-service-subscribes to a file event THEN it succeeds
  - GIVEN an ungranted family WHEN the same user tries THEN the subscription is refused
- [x] Implement
  - `tests/e2e/spec-coverage/nextcloud-event-triggers.spec.ts`, "family grant and self-service subscribe": the administrator ticks `event.subscribe` and `event.subscribe-nextcloud-files` for the group in the ActionAuthMatrix editor on integriq's admin settings page and saves (the PUT answers 200, and the stored matrix grants both and not Calendar). A member then subscribes to a Files event (200), and is refused with 403 for Calendar alone and for Files plus Calendar. The matrix is put back afterwards. Direct calls to the matrix route now carry the session's request token, because the route has no `#[NoCSRFRequired]`.
- [x] Test (written, runs in nightly)
  - Written, not run here (it needs a live instance); runs in nightly.

### Task 3: Action-type picker in the subscription modal
- **spec_ref**: `openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md`
- **files**: `tests/e2e/spec-coverage/nextcloud-event-triggers.spec.ts`
- **acceptance_criteria**:
  - GIVEN the subscription modal WHEN synchronization, job or webhook is chosen THEN the matching target picker renders and the saved subscription carries the chosen `action`
  - GIVEN the subscription modal WHEN "Flow" is chosen and a flow is picked THEN the saved subscription carries `action: {kind: "flow", flowId}` (moved here from `nc-events-start-or-flows` task 4 when that change archived on 2026-09-28; the picker itself is covered by `tests/vitest/subscriptionFlowAction.spec.js`)
- [x] Implement
  - Same file, "the action-type picker": in the Webhooks page's Add Webhook modal, picking Synchronization, Job or Flow shows the target picker and Webhook shows none. A subscription saved with each kind carries it as stored (`synchronizationId`, `jobId`, `{kind: "webhook"}`, `flowId`); this half goes through the API so it holds without a synchronization, job or flow on the instance.
- [x] Test (written, runs in nightly)
  - Written, not run here (it needs a live instance); runs in nightly.

## 3. Newman

### Task 4: Subscription API scenarios
- **spec_ref**: `openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md`
- **files**: `tests/postman/` (existing collection)
- **acceptance_criteria**:
  - GIVEN the collection runs THEN subscribe with `action` and `retryPolicy` round-trips, an ungranted non-admin gets `403` per family, and delivery status is readable
- [x] Implement
  - `tests/postman/integriq-nextcloud-event-triggers.postman_collection.json` (beside the main collection, as the other per-change collections are): a subscription sent with `action` and `retryPolicy` is checked in the subscribe answer, the stored `event_subscription` object and the list; a member of a group granted `event.subscribe` but no `event.subscribe-nextcloud-<family>` action is refused with 403 for Files, Calendar, Tables and Forms, while a type outside the four passes; the subscription's messages are read and each carries a status of the delivery lifecycle, and an unknown subscription is 404. The action matrix is read first and put back, and the member, group and subscriptions are removed.
- [x] Test (written, runs in nightly)
  - Written, not run here (it needs a live instance); runs in nightly.

## 4. Docs

### Task 5: "Nextcloud event triggers" documentation page
- **spec_ref**: `openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md`
- **files**: `docs/`, `docs/images/`
- **acceptance_criteria**:
  - GIVEN `docs/` THEN a page covers the four event families, filtering, the three action kinds, retry policy and self-service gating, with a screenshot of the matrix editor's event-family rows
  - Page written: `docs/features/nextcloud-event-triggers.md` (the four families with their types, sources and data, filtering with the five dialects, the action kinds including flow, the retry defaults and `retryPolicy`, dead letters, and the two-layer per-family gate with a worked example), plus a row in `docs/features/README.md`. Facts checked against `EventService::doesEventMatchSubscription()`, `evaluateFilters()`, `resolveRetryPolicy()`, `lib/actions.seed.json` and `appinfo/routes.php`. Still owed: the screenshot of the matrix editor's event-family rows, which needs a running instance, so the task stays open.
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] All tests pass (`composer test`, `newman run`, Playwright suite)
