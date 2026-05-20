# Tasks: saas-productivity-connectors

> Sub-bullets describe the work per task. Each top-level checkbox is the unit Hydra tracks; flip when the whole task (implementation + tests) is done. ADR-032 cap respected (≤20).

## Task 1: Deduplication Check
- **spec_ref**: ADR-012-deduplication
- **files**: `openspec/changes/saas-productivity-connectors/design.md`
- Search `openspec/specs/` and `openconnector/lib/Service/` for any existing Monday.com connector, DXP connector, or list view implementation that overlaps with REQ-MON-001 through REQ-DXP-004
- Verify `CallService`, `SynchronizationService`, `AuthenticationService`, and `JobService` cover the required infrastructure — document findings in design.md Reuse Analysis
- Confirm `CnIndexPage` + `useListView` + `CnDataTable` + `selectionPlugin` cover the list view requirements without custom components
- [ ] Task complete

## Task 2: Monday.com API Connection (REQ-MON-001)
- **spec_ref**: `specs/saas-productivity-connectors/spec.md#req-mon-001`
- **files**: `lib/Service/MondayConnectorService.php`
- **acceptance_criteria**:
  - GIVEN Monday.com source configured with API token WHEN test connection clicked THEN GraphQL `{ me { name } }` succeeds and workspace name returned
  - GIVEN API call returns rate limit headers WHEN processed THEN Source rate limit fields updated via existing `CallService.sourceRateLimit()`
  - GIVEN OAuth2 token expires WHEN next call made THEN `AuthenticationService` auto-refreshes before retry
- Implement `MondayConnectorService` with Source entity wiring, `testConnection()` method, and rate limit handling; add `@spec` PHPDoc tags; write PHPUnit tests (≥3 methods)
- [ ] Task complete

## Task 3: Monday.com Inbound Sync — Board to Object (REQ-MON-002)
- **spec_ref**: `specs/saas-productivity-connectors/spec.md#req-mon-002`
- **files**: `lib/Service/MondayConnectorService.php`, `lib/Cron/MondayPollJob.php`
- **acceptance_criteria**:
  - GIVEN board item updated in Monday.com WHEN 5-minute poll runs THEN change reflected in OpenRegister object within 5 minutes
  - GIVEN multiple items updated between polls WHEN job runs THEN all retrieved in single batched `items_page` GraphQL query
  - GIVEN Monday.com API unavailable WHEN poll runs THEN CallLog entry created and retry deferred to next interval
- Implement `MondayPollJob` (extends `TimedJob`) registered in `appinfo/info.xml`; implement `pollBoardChanges()` using cursor-paginated GraphQL query; wire through `SynchronizationService`; PHPUnit tests
- [ ] Task complete

## Task 4: Monday.com Outbound Sync — Object to Board (REQ-MON-003)
- **spec_ref**: `specs/saas-productivity-connectors/spec.md#req-mon-003`
- **files**: `lib/Service/MondayConnectorService.php`
- **acceptance_criteria**:
  - GIVEN new object saved with Monday.com mapping active WHEN mutation event fires THEN `create_item` GraphQL executed and `mondayItemId` stored in `MondayBoardSync`
  - GIVEN existing mapped object updated WHEN event fires THEN `change_multiple_column_values` executed for changed fields only
  - GIVEN outbound conflict detected WHEN both sides changed THEN `MondayBoardSync` status set to `conflict` and admin notified via `NotificationService`
- Implement `pushItemToBoard()` and `updateItemColumns()` methods; hook into `EventService` mutation events; implement conflict detection logic; PHPUnit tests
- [ ] Task complete

## Task 5: Monday.com Token Expiry Notification (REQ-MON-004)
- **spec_ref**: `specs/saas-productivity-connectors/spec.md#req-mon-004`
- **files**: `lib/Service/MondayConnectorService.php`
- **acceptance_criteria**:
  - GIVEN API token expires or revoked WHEN call returns 401 THEN Source status set to `error` and Nextcloud notification sent to admin
  - GIVEN source status is `error` WHEN poll job runs THEN no further sync attempts made until source manually re-enabled
- Implement 401 handler in `MondayConnectorService`; dispatch Nextcloud notification via `NotificationService` with deep-link to Source config; PHPUnit tests
- [ ] Task complete

## Task 6: List View Data Display (REQ-LST-001)
- **spec_ref**: `specs/saas-productivity-connectors/spec.md#req-lst-001`
- **files**: `src/views/SynchronisationsIndex.vue` (or relevant OpenConnector index view)
- **acceptance_criteria**:
  - GIVEN synchronisation records loaded WHEN user views list THEN rows shown with column headers from `columnsFromSchema()`
  - GIVEN page loads THEN records fetched via `useListView` with default sort and pagination; `CnPagination` shows total count
- Replace or extend the existing synchronisations view to use `CnIndexPage` with `useListView` composable; wire `columnsFromSchema()` for column generation; register all imported components in `components: {}`; all user-visible strings via `t(appName, ...)`
- [ ] Task complete

## Task 7: Column Sorting (REQ-LST-002)
- **spec_ref**: `specs/saas-productivity-connectors/spec.md#req-lst-002`
- **files**: `src/views/SynchronisationsIndex.vue`
- **acceptance_criteria**:
  - GIVEN list view active WHEN column header clicked THEN `useListView` updates sort state and issues `findAll()` with `_order` param
  - GIVEN column already sorted ascending WHEN header clicked again THEN direction toggles to descending
  - GIVEN user sorts and navigates page THEN sort order preserved across pagination
- Verify `useListView` sort state is wired to `CnDataTable` column header click events; confirm `_order` parameter is passed correctly in `findAll()` calls; no custom sort logic
- [ ] Task complete

## Task 8: Row Selection and Bulk Actions (REQ-LST-003)
- **spec_ref**: `specs/saas-productivity-connectors/spec.md#req-lst-003`
- **files**: `src/views/SynchronisationsIndex.vue`, `src/store/modules/synchronisations.js`
- **acceptance_criteria**:
  - GIVEN user clicks row checkbox WHEN selected THEN row highlighted using Nextcloud CSS variables and `CnMassActionBar` appears
  - GIVEN multiple rows selected WHEN "Re-sync selected" clicked THEN selected records queued for immediate re-sync via `JobService`
- Add `selectionPlugin` to the synchronisations object store; wire `CnMassActionBar` with a "Re-sync selected" action that calls the re-sync API endpoint; wrap all store actions in `try/catch` with user-facing error feedback via `NcDialog`
- [ ] Task complete

## Task 9: DXP Endpoint Configuration (REQ-DXP-001)
- **spec_ref**: `specs/saas-productivity-connectors/spec.md#req-dxp-001`
- **files**: `lib/Service/DXPConnectorService.php`
- **acceptance_criteria**:
  - GIVEN admin creates DXP source and saves settings THEN `DXPContentSync` record created with status `active` and `syncInterval`
  - GIVEN DXP source configured WHEN settings saved THEN initial content retrieval validates endpoint and returns item count
- Implement `DXPConnectorService` with `configureSource()` and `validateEndpoint()` methods; create `DXPContentSync` schema registration in `lib/Settings/openconnector_register.json`; add `@spec` PHPDoc tags; PHPUnit tests
- [ ] Task complete

## Task 10: DXP Automatic Content Retrieval and Incremental Sync (REQ-DXP-002, REQ-DXP-003)
- **spec_ref**: `specs/saas-productivity-connectors/spec.md#req-dxp-002`, `specs/saas-productivity-connectors/spec.md#req-dxp-003`
- **files**: `lib/Service/DXPConnectorService.php`, `lib/Cron/DXPSyncJob.php`
- **acceptance_criteria**:
  - GIVEN DXP source active WHEN `DXPSyncJob` triggers THEN content retrieved via `CallService` and stored as OpenRegister objects via `SynchronizationService`
  - GIVEN `lastSyncAt` set WHEN job runs THEN `modifiedAfter` filter appended to DXP request (incremental sync)
  - GIVEN vendor does not support incremental sync WHEN `supportsIncrementalSync: false` THEN full fetch with `SynchronizationService` change detection
- Implement `DXPSyncJob` (extends `TimedJob`); implement `fetchContent()` with incremental and full-fetch modes; integrate with `SynchronizationService`; update `DXPContentSync` `lastSyncAt` and `itemCount` after each run; PHPUnit tests
- [ ] Task complete

## Task 11: DXP Credential Error Handling (REQ-DXP-004)
- **spec_ref**: `specs/saas-productivity-connectors/spec.md#req-dxp-004`
- **files**: `lib/Service/DXPConnectorService.php`
- **acceptance_criteria**:
  - GIVEN DXP API returns 401 WHEN sync job runs THEN Source status set to `error`, `DXPContentSync` error recorded, and admin notified via `NotificationService`
  - GIVEN source in `error` state WHEN admin saves valid credentials THEN test retrieval runs; on success Source reset to `enabled` and `DXPContentSync` reset to `active`
  - GIVEN source in `error` state WHEN other sources/dashboards active THEN only the errored source is affected; other content displays normally
- Implement 401/403 error handler in `DXPConnectorService`; dispatch notification via `NotificationService`; implement `resetAfterReauth()` method; static generic error messages (no internal details in responses per ADR-005); PHPUnit tests
- [ ] Task complete

## Task 12: OpenConnector Register Seed Data
- **spec_ref**: ADR-001 seed data rules
- **files**: `lib/Settings/openconnector_register.json`
- Add `MondayBoardSync` and `DXPContentSync` schema definitions with 4 seed objects each (as specified in design.md Seed Data section) to `openconnector_register.json` under `components.objects[]` using `@self` envelope; verify idempotency via slug matching per `ImportHandler` behaviour
- [ ] Task complete

## Task 13: i18n Translations (ADR-007)
- **spec_ref**: ADR-007-i18n
- **files**: `l10n/en.json`, `l10n/nl.json`
- Add translation keys (sentence case) for all new user-facing strings: Monday.com connection labels, DXP configuration labels, list view action labels, error messages, notification texts; ensure `en.json` and `nl.json` have exactly the same keys with zero gaps
- [ ] Task complete

## Task 14: Unit and Integration Tests (ADR-008)
- **spec_ref**: ADR-008-testing
- **files**: `tests/Unit/Service/MondayConnectorServiceTest.php`, `tests/Unit/Service/DXPConnectorServiceTest.php`
- PHPUnit tests for `MondayConnectorService` (connection test, poll changes, push item, conflict detection, 401 handler) and `DXPConnectorService` (validate endpoint, fetch content incremental, fetch content full, 401 handler, reset after reauth) — ≥3 methods each; cover error paths (401, 429, 503) not just happy path
- [ ] Task complete

## Task 15: API Documentation (ADR-009)
- **spec_ref**: ADR-009-docs
- **files**: `docs/features/saas-productivity-connectors.md`
- Document Monday.com connector setup (API token, OAuth2, board configuration, field mapping), DXP connector setup (endpoint URL, auth, sync interval, incremental sync flag), and list view usage (sorting, selection, bulk re-sync); include screenshots from running app
- [ ] Task complete
