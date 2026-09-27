# Tasks: platform-admin-defaults

Kind: code. Matrix row `integriq:plt-admin-settings`.

### Task 1: The retention resolver and its drift test
- **spec_ref**: openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-one-resolver-supplies-retention-with-the-schemas-defaults-req-adef-001
- **files**: `lib/Service/RetentionSettings.php`, `lib/Service/JobService.php`, `lib/Service/CallService.php`, `lib/Service/SynchronizationService.php`, `tests/Unit/Service/RetentionSettingsTest.php`
- **acceptance_criteria**:
  - GIVEN no config key WHEN a synchronization log is written THEN it expires after 30 days
  - GIVEN a resolver default that differs from its schema WHEN the suite runs THEN the drift test fails naming the schema
  - GIVEN an existing `occ`-set value in milliseconds WHEN the services start THEN they apply it unchanged
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 2: Settings read and update
- **spec_ref**: openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
- **files**: `lib/Service/SettingsService.php`, `lib/Controller/SettingsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister's `/api/settings/*` surface WHEN checked against the `integriq` / `retention` key THEN the task records whether it reads and writes it, and adds integriq routes only if it does not
  - GIVEN a non-administrator WHEN they send an update THEN 403
  - GIVEN a negative or non-numeric value WHEN saved THEN 400 naming the field
- [ ] Implement
- [ ] Test (PHPUnit on the service and controller)

### Task 3: Retention section on the admin page
- **spec_ref**: openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
- **files**: `src/views/admin/AdminSettings.vue`, `src/views/admin/RetentionSettings.vue`
- **acceptance_criteria**:
  - GIVEN the admin page WHEN it loads THEN each retention shows in days or hours with its default
  - GIVEN a change to 90 days WHEN saved and reloaded THEN 90 days is shown
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/admin-retention-defaults.spec.ts`)

### Task 4: Rebase as a background job
- **spec_ref**: openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
- **files**: `lib/BackgroundJob/RetentionRebaseJob.php`, `lib/Service/SettingsService.php`, `lib/Controller/SettingsController.php`, `src/views/admin/RetentionSettings.vue`
- **acceptance_criteria**:
  - GIVEN a changed call log retention WHEN "Apply to existing logs" is chosen THEN a job is queued and no SQL runs against `openconnector_*` tables
  - GIVEN the job WHEN it runs THEN it pages through call logs and sets `expires` from `created`
- [ ] Implement
- [ ] Test (PHPUnit on the job with a mocked ObjectService; Playwright that the button queues it)

## Verification

- `openspec validate platform-admin-defaults --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/admin-retention-defaults.spec.ts` green against a local instance
