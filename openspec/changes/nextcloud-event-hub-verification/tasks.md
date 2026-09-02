# Tasks: nextcloud-event-hub-verification

## 1. The spike (first — it can invalidate shipped behavior)

### Task 1: Confirm Tables/Forms event class names on a live instance
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md`
- **files**: none (evidence recorded here), or `lib/EventListener/NextcloudTablesEventListener.php`, `lib/EventListener/NextcloudFormsEventListener.php`, `lib/AppInfo/Application.php` if wrong
- **acceptance_criteria**:
  - GIVEN a live instance with Tables and Forms enabled WHEN a row is created/updated/deleted and a form is submitted THEN the dispatched event classes are recorded here verbatim
  - GIVEN a mismatch with what the listeners register for THEN the registrations are corrected and a unit test pins each corrected class name
- [ ] Implement
- [ ] Test

## 2. Playwright

### Task 2: Family grant + self-service subscribe flow
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md`
- **files**: `tests/e2e/spec-coverage/nextcloud-event-triggers.spec.ts`
- **acceptance_criteria**:
  - GIVEN an admin grants `event.subscribe-nextcloud-files` to a group via the ActionAuthMatrix editor WHEN a member self-service-subscribes to a file event THEN it succeeds
  - GIVEN an ungranted family WHEN the same user tries THEN the subscription is refused
- [ ] Implement
- [ ] Test

### Task 3: Action-type picker in the subscription modal
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md`
- **files**: `tests/e2e/spec-coverage/nextcloud-event-triggers.spec.ts`
- **acceptance_criteria**:
  - GIVEN the subscription modal WHEN synchronization, job or webhook is chosen THEN the matching target picker renders and the saved subscription carries the chosen `action`
- [ ] Implement
- [ ] Test

## 3. Newman

### Task 4: Subscription API scenarios
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md`
- **files**: `tests/postman/` (existing collection)
- **acceptance_criteria**:
  - GIVEN the collection runs THEN subscribe with `action` and `retryPolicy` round-trips, an ungranted non-admin gets `403` per family, and delivery status is readable
- [ ] Implement
- [ ] Test

## 4. Docs

### Task 5: "Nextcloud event triggers" documentation page
- **spec_ref**: `openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md`
- **files**: `docs/`, `docs/images/`
- **acceptance_criteria**:
  - GIVEN `docs/` THEN a page covers the four event families, filtering, the three action kinds, retry policy and self-service gating, with a screenshot of the matrix editor's event-family rows
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] All tests pass (`composer test`, `newman run`, Playwright suite)
