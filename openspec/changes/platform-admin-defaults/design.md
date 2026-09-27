# Design: platform-admin-defaults

Kind: code. One resolver owns the retention defaults. The admin page reads and
writes them through the ADR-076 settings dialect. The rebase becomes a
background job over OpenRegister objects.

## Where it fits

- Resolver: a new `lib/Service/RetentionSettings.php` with `get(string $kind): int`
  (milliseconds) for `success`, `callLog`, `jobLog`, `syncLog`,
  `syncContractLog`, `eventMessage` and `executionTrace`, reading the
  `integriq` / `retention` app config key and falling back to defaults equal to
  the `x-openregister-archival` defaults declared on the schemas
  (`lib/Settings/integriq_register.json:2240` for `call_log`, `:2412` for
  `job_log`, `:2611` for `synchronization_log`, `:2751` for
  `synchronization_contract_log`, and
  `lib/Settings/register.d/execution-trace-observability.json:125` for
  `execution_trace`).
- Callers: `lib/Service/JobService.php:113`, `lib/Service/CallService.php:262`
  and `lib/Service/SynchronizationService.php:633` read the resolver instead of
  their own fallbacks. `SynchronizationService::DEFAULT_ERROR_LOG_RETENTION`
  at `:384` is removed, which moves the unset default for synchronization logs
  from 3 days to 30, as integriq's ADR-004 already states it should.
- Settings service: `lib/Service/SettingsService.php:135` (`getSettings()`)
  reads the resolver, and a new `updateSettings(array)` validates and writes
  the retention payload, the method `logs-and-statistics` REQ-004 describes.
- Routes: `settings#index` (GET `/api/settings`) and `settings#update` (PUT
  `/api/settings`) on `lib/Controller/SettingsController.php`, admin only
  through `#[AuthorizedAdminSetting(IntegriqAdmin::class)]` like `rebase()` at
  `:81`, in the index and update shape of ADR-076. The comment at
  `appinfo/routes.php:632` says these two routes were replaced by
  OpenRegister's `/api/settings/*` surface, yet nothing on integriq's admin
  page calls it and the matrix finds `occ` the only way to change retention.
  The first task checks whether that surface can read and write the `integriq`
  / `retention` key; if it can, the page uses it and no integriq route is added
  (ADR-022).
- Page: `src/views/admin/AdminSettings.vue:12` gains a `RetentionSettings.vue`
  section next to `ActionAuthMatrix` and `DsoPkiSettings`, ADR-079's place for
  instance configuration.
- Rebase: `settings#rebase` (`appinfo/routes.php:635`) queues a new
  `lib/BackgroundJob/RetentionRebaseJob.php` for the log kinds whose retention
  changed. The job pages through the log objects of each kind and sets
  `expires` from `created` plus the new retention. The SQL in
  `lib/Service/SettingsService.php:180` (`rebase()`) against
  `openconnector_call_logs` and `openconnector_event_messages` is removed.

## D1. One resolver, defaults from the schemas

Three services with three fallbacks drifted: the synchronization service keeps
3 days where the schema, the settings service and ADR-004 all say 30. The
resolver has one table of defaults, and a unit test compares it with the
`x-openregister-archival` defaults in the register files, so the two cannot
drift again. The alternative was to fix only the synchronization constant.
Rejected: the next constant would drift the same way.

## D2. Human units on the page, milliseconds in storage

The stored JSON stays in milliseconds, so existing `occ` settings and the three
callers keep working. The page shows days and hours and converts on save. The
alternative was to change the stored unit. Rejected: every instance that set
retention through `occ` would be read wrong on upgrade.

## D3. Rebase is a job over objects, and it is explicit

Changing a retention value applies to logs written afterwards. "Apply to
existing logs" is a separate button, because recomputing `expires` on a large
`call_log` is heavy. It runs as a background job in bounded pages (ADR-058,
ADR-069) and reports progress on the page. The alternative was to rewrite
`expires` on save. Rejected: a save would time out on a large instance.

## Declarative versus imperative

The defaults are declared once, on the schemas' `x-openregister-archival`
blocks, and the resolver reads the same values. Writing `expires` on a log is
imperative in the services today and stays so. Whether OpenRegister's archival
engine could own expiry entirely is ADR-004's migration and not this change.

## Risks

- Moving the unset synchronization log default from 3 to 30 days keeps more
  rows. The change log says so, and an administrator can set 3 days on the page.
- The rebase job touches every log object of a kind. It runs off-peak by
  default and can be cancelled from the page.
