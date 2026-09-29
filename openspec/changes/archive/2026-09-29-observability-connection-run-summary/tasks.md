# Tasks: observability-connection-run-summary

Kind: code. Rows `opencatalogi:int-connector-monitor` (integriq's half) and
`integriq:obs-threshold-counts`.

### Task 1: Source and trigger on the run record
- **spec_ref**: openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-every-run-records-its-source-and-what-started-it-req-crun-001
- **files**: `lib/Settings/register.d/sync-run-progress.json`, `lib/Service/SynchronizationRunProgressService.php`, `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a scheduled run WHEN it starts THEN the record holds `sourceId` and `triggeredBy: cron`
  - GIVEN a manual run WHEN it starts THEN `triggeredBy` is `manual`
- [x] Implement
- [x] Test (PHPUnit on the progress service)

### Task 2: The per-day summary route and the source page widgets
- **spec_ref**: openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
- **files**: `lib/Service/RunSummaryService.php`, `lib/Controller/SourcesController.php`, `appinfo/routes.php`, `src/components/SourceRunSummaryWidget.vue`, `src/registry.js`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN fourteen runs over seven days WHEN the summary is requested THEN seven rows sum to fourteen runs
  - GIVEN a 40-day window WHEN requested THEN 400
  - GIVEN the source detail page WHEN opened THEN the table and the runs list show
- [x] Implement
- [x] Test (PHPUnit on the service and controller; Playwright `tests/e2e/connection-run-summary.spec.ts`)

### Task 3: Run again on a failed run
- **spec_ref**: openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-a-failed-pull-restarts-with-one-click-req-crun-003
- **files**: `src/handlers/actionHandlers.js`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a failed run WHEN "Run again" is chosen THEN one POST to the run route is made and no dialog opens
  - GIVEN a successful run WHEN its actions are shown THEN "Run again" is not offered
- [x] Implement
- [x] Test (vitest on the handler; Playwright for the notice)

### Task 4: Thresholds, the job and the alert object
- **spec_ref**: openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
- **files**: `lib/Settings/register.d/observability-connection-run-summary.json`, `lib/BackgroundJob/ConnectionThresholdJob.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a threshold of 10 in 60 minutes WHEN 11 calls fail THEN one open alert exists
  - GIVEN an open alert WHEN the count stays above THEN no second alert opens
  - GIVEN an open alert WHEN the count falls back THEN it is cleared
- [x] Implement
- [x] Test (PHPUnit on the job with a mocked ObjectService)

### Task 5: Declared notification and the alerts page
- **spec_ref**: openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
- **files**: `lib/Settings/register.d/observability-connection-run-summary.json`, `src/manifest.d/observability-connection-run-summary.json`, `lib/Notification/ConnectionAlertRecipientResolver.php`, `lib/Controller/ConnectionAlertSettingsController.php`, `src/views/admin/ConnectionAlertSettings.vue`
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN `connection_alert` declares a `created` rule whose recipient is ConnectionAlertRecipientResolver
  - GIVEN no group named WHEN an alert opens THEN nobody is notified (design D5)
  - GIVEN an opened alert WHEN a member of the named group checks notifications THEN the alert is there
- [x] Implement
- [x] Test (`node tests/validate-register.js` including the notification dialect gate; Playwright for the alerts page)

## Verification

- `openspec validate observability-connection-run-summary --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/connection-run-summary.spec.ts` green against a local instance
