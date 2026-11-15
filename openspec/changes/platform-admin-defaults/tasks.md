# Tasks: platform-admin-defaults

Kind: code. Matrix row `integriq:plt-admin-settings`.

### Task 1: The retention resolver and its drift test
- **spec_ref**: openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-one-resolver-supplies-retention-with-the-schemas-defaults-req-adef-001
- **files**: `lib/Service/RetentionSettings.php`, `lib/Service/JobService.php`, `lib/Service/CallService.php`, `lib/Service/SynchronizationService.php`, `tests/Unit/Service/RetentionSettingsTest.php`
- **acceptance_criteria**:
  - GIVEN no config key WHEN a synchronization log is written THEN it expires after 30 days
  - GIVEN a resolver default that differs from its schema WHEN the suite runs THEN the drift test fails naming the schema
  - GIVEN an existing `occ`-set value in milliseconds WHEN the services start THEN they apply it unchanged
- [x] Implement
  - `RetentionSettings::get()` over the `integriq` / `retention` key with `RetentionDefaults` as fallback (execution traces added, P30D); the call, job and synchronization services read it. A stored value is taken as `occ` set it, a number or a numeric string.
- [x] Test (PHPUnit)
  - `RetentionSettingsTest`, including the drift guard against the `x-openregister-archival` defaults of call_log, job_log, synchronization_log, synchronization_contract_log and execution_trace (fails naming the schema).

### Task 2: Settings read and update
- **spec_ref**: openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
- **files**: `lib/Service/SettingsService.php`, `lib/Controller/SettingsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister's `/api/settings/*` surface WHEN checked against the `integriq` / `retention` key THEN the task records whether it reads and writes it, and adds integriq routes only if it does not
  - GIVEN a non-administrator WHEN they send an update THEN 403
  - GIVEN a negative or non-numeric value WHEN saved THEN 400 naming the field
- [x] Implement
  - OpenRegister's `/api/settings/retention` reads and writes OpenRegister's own retention, not the `integriq` / `retention` key, so integriq adds `settings#index` (GET) and `settings#update` (PUT) on `/api/settings`, admin only (`#[AuthorizedAdminSetting(IntegriqAdmin::class)]`). An unknown field, or a value that is not a whole non-negative number, is a 400 naming the field.
- [x] Test (PHPUnit on the service and controller)
  - `SettingsServiceTest`, `SettingsControllerTest` (the admin-only attribute on every route; Nextcloud answers 403 before the method runs).

### Task 3: Retention section on the admin page
- **spec_ref**: openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
- **files**: `src/views/admin/AdminSettings.vue`, `src/views/admin/RetentionSettings.vue`
- **acceptance_criteria**:
  - GIVEN the admin page WHEN it loads THEN each retention shows in days or hours with its default
  - GIVEN a change to 90 days WHEN saved and reloaded THEN 90 days is shown
- [x] Implement
  - `RetentionSettings.vue` on the admin page: days, hours for successful logs, the default beside each value.
- [ ] Test (Playwright `tests/e2e/admin-retention-defaults.spec.ts`)
  - Vitest `tests/vitest/retentionSettings.spec.js` covers the units, the save and the progress. The Playwright run needs a live instance and is left open.

### Task 4: Rebase as a background job
- **spec_ref**: openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
- **files**: `lib/BackgroundJob/RetentionRebaseJob.php`, `lib/Service/SettingsService.php`, `lib/Controller/SettingsController.php`, `src/views/admin/RetentionSettings.vue`
- **acceptance_criteria**:
  - GIVEN a changed call log retention WHEN "Apply to existing logs" is chosen THEN a job is queued and no SQL runs against `openconnector_*` tables
  - GIVEN the job WHEN it runs THEN it pages through call logs and sets `expires` from `created`
- [x] Implement
  - `RetentionRebaseJob` pages through the log objects of each kind (100 per page, 10 pages per run, then it queues the next run), sets `expires` from `created` (the success retention for a log its schema counts as a success), and keeps its progress in `retention_rebase`, which the page shows. The SQL rebase on the `openconnector_*` tables is removed, with its two tests and golden file.
- [ ] Test (PHPUnit on the job with a mocked ObjectService; Playwright that the button queues it)
  - PHPUnit `RetentionRebaseJobTest` done; the Playwright check needs a live instance and is left open.

## Verification

- `openspec validate platform-admin-defaults --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/admin-retention-defaults.spec.ts` green against a local instance
