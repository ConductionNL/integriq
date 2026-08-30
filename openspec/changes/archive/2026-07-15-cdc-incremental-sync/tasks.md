# Tasks: cdc-incremental-sync

## Implementation Tasks

### Task 1: Add `syncMode` and `cursorWatermark` fields to the Synchronization schema
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN the `synchronization` schema WHEN it is inspected THEN it has a
    `syncMode` string property (documented values `full`|`incremental`,
    default `full`) and a `cursorWatermark` string property, following the
    existing `currentPage`/`targetLastSynced` documentation style
  - GIVEN the `sourceConfig` property's description THEN it documents the
    new recognised keys `cursorField` and `cursorComparator`, alongside the
    existing `deletionRatioThreshold`/`resultsPosition`/etc. documentation
  - GIVEN an existing Synchronization object with no `syncMode` set WHEN it
    is read THEN the application treats it as `full` (no migration/backfill
    needed)
- [x] Implement
- [x] Test

### Task 2: Extend Twig request-config templating with a `cursor` context key (REQ-016)
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016`
- **files**: `lib/Service/SynchronizationService.php` (`getAllObjectsFromApi()`)
- **acceptance_criteria**:
  - GIVEN a Synchronization with `syncMode: incremental` and a templated
    `sourceConfig.endpoint` referencing `{{ cursor }}` WHEN
    `getAllObjectsFromApi()` runs THEN the rendered endpoint contains the
    stored `cursorWatermark` value (or an empty string when unset)
  - GIVEN the same Synchronization with a templated `sourceConfig.query`
    value referencing `{{ cursor }}` WHEN the fetch runs THEN that query
    value is rendered the same way endpoint values already are
  - GIVEN a Synchronization with `syncMode` absent or `full` WHEN the fetch
    runs THEN the Twig context has no `cursor` key and `sourceConfig.query`
    values are passed through unrendered — byte-identical to current
    behavior (regression check)
- [x] Implement
- [x] Test

### Task 3: Compute and persist the cursor watermark, gated on fetch-completeness (REQ-017)
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-cursor-watermark-advances-only-after-a-complete-successful-fetch-req-017`
- **files**: `lib/Service/SynchronizationService.php` (new private
  `computeCursorWatermark()`; `synchronizeExternToIntern()` Stage 5/end-of-run)
- **acceptance_criteria**:
  - GIVEN an incremental run whose fetch completes (REQ-009
    `fetchInfo.complete === true`) WHEN the run finishes THEN
    `cursorWatermark` is persisted as the maximum `sourceConfig.cursorField`
    value seen across the fetched records
  - GIVEN an incremental run whose fetch is marked incomplete (page
    failure, rate-limit, or safety-cap per REQ-009) WHEN the run finishes
    THEN `cursorWatermark` is left unchanged
  - GIVEN an incremental `isTest: true` run whose fetch completes WHEN the
    run finishes THEN `cursorWatermark` is left unchanged (REQ-011 parity)
  - GIVEN a fetched record whose configured `cursorField` resolves to
    `null` WHEN the run processes it THEN an `Exception` is thrown naming
    the missing field, and no partial/incorrect watermark is persisted
- [x] Implement
- [x] Test

### Task 4: Hard-block `deleteInvalidObjects()` for incremental synchronizations (REQ-018)
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-deletion-garbage-collection-never-runs-for-an-incremental-sync-req-018`
- **files**: `lib/Service/SynchronizationService.php`
  (`synchronizeExternToIntern()` Stage 5 call site; `deleteInvalidObjects()`)
- **acceptance_criteria**:
  - GIVEN a Synchronization with `syncMode: incremental` WHEN
    `synchronizeExternToIntern()` reaches its cleanup stage THEN
    `deleteInvalidObjects()` is never invoked, and `result.objects.
    deletionGuard.reason` is `incremental_mode`
  - GIVEN the same Synchronization and `forceDeletion: true` WHEN the run
    executes THEN deletion is still blocked (unconditional — `forceDeletion`
    has no effect on this guard)
  - GIVEN `deleteInvalidObjects()` is invoked directly (bypassing
    `synchronizeExternToIntern()`) against a Synchronization with `syncMode:
    incremental` WHEN it runs THEN it returns `0`, logs a warning, and
    dispatches `SynchronizationDeletionGuardedEvent` with `reason:
    incremental_mode`
  - GIVEN the event-driven single-object `deleteRestriction` path (REQ-010)
    on an incremental Synchronization WHEN an `ObjectDeletedEvent` fires
    THEN the single-object delete still runs unaffected (regression check —
    this path never calls the bulk-diff branch this task guards)
- [x] Implement
- [x] Test

### Task 5: Reset-cursor controller action and route (REQ-019)
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019`
- **files**: `lib/Controller/SynchronizationsController.php`,
  `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a Synchronization with a stored `cursorWatermark` WHEN `POST
    /api/synchronizations/{id}/reset-cursor` is called THEN the watermark
    is persisted as cleared and `syncMode` is unchanged
  - GIVEN no Synchronization exists with the given id WHEN the action is
    called THEN it responds `404`, matching `run()`/`test()`'s existing
    not-found handling
  - GIVEN a successful reset WHEN the response is inspected THEN it
    reflects the cleared watermark (for SPA confirmation feedback)
- [x] Implement
- [x] Test

### Task 6: Synchronization SPA — sync mode field + reset-cursor action
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016`
- **files**: Synchronization edit form component (e.g.
  `src/modals/Synchronization/EditSynchronization.vue` or equivalent — match
  existing modal location), Synchronization detail/actions view
- **acceptance_criteria**:
  - GIVEN the Synchronization edit form WHEN an admin opens it THEN a "Sync
    mode" field (full / incremental) and, when incremental, a "Cursor
    field" + "Cursor comparator" configuration are shown
  - GIVEN an incremental Synchronization's detail/actions view WHEN an
    admin opens it THEN a "Reset cursor" action button is available,
    labelled/tooltipped to make clear it clears the watermark only and does
    **not** delete data or restore deletion detection (design.md Decision
    3 / Risks)
  - GIVEN the "Reset cursor" action WHEN clicked THEN it calls `POST
    .../reset-cursor` and shows a confirmation
- [x] Implement
- [ ] Test — SPA fields/action implemented and eslint-clean (0 errors); no
  live-instance Playwright run was performed as part of this apply pass

## Verification
- [ ] All tasks checked off — Task 6's browser test is the one open item
- [x] `openspec validate` passes
- [ ] Manual testing against acceptance criteria — not run against a live
  Nextcloud instance in this pass (PHPUnit + local static checks only)
- [ ] Code review against spec requirements — self-reviewed only; no
  independent reviewer pass in this apply session

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) —
  watermark advance/no-advance (Task 3), incremental deletion block
  (Task 4), cursor templating (Task 2); 20 new tests, all passing
- [ ] Newman/Postman tests for new/changed API endpoints — `reset-cursor`
  (Task 5) — not written; no live API server available in this apply pass
- [ ] Browser tests (Playwright MCP) for UI changes — sync mode field +
  reset-cursor action (Task 6) — not run in this apply pass
- [x] Integration test: two successive incremental runs against a
  synthetic paginated source fetch/write only the delta between them
  (proposal.md Scope item 5) —
  `testTwoSuccessiveIncrementalRunsFetchOnlyTheDelta()`
- [x] All tests pass (`composer test:unit` — 1467 tests, 4156 assertions,
  1 pre-existing skip, 0 failures); `newman run` not exercised (no new
  Newman collection written this pass)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — not done in this apply
  pass; deferred (see report deviations)
- [ ] Screenshot captured and committed to `docs/images/` — not done in
  this apply pass; deferred (see report deviations)

## i18n (company-wide hydra ADR-007)

- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added for:
  "Sync mode", "Cursor field", "Cursor comparator", "Reset cursor" action
  label + confirmation + tooltip text (`l10n/en.json`, `l10n/nl.json`)
