# Retrofit — logs-and-statistics

Describes observed behavior of 14 methods under `logs-and-statistics` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Controller/LogsController.php::index()`
- `lib/Controller/LogsController.php::show()`
- `lib/Controller/LogsController.php::destroy()`
- `lib/Controller/LogsController.php::statistics()`
- `lib/Controller/LogsController.php::export()`
- `lib/Controller/SourcesController.php::logs()`
- `lib/Controller/SourcesController.php::test()`
- `lib/Controller/SettingsController.php::rebase()`
- `lib/Service/SettingsService.php::getStats()`
- `lib/Service/SettingsService.php::getSettings()`
- `lib/Service/SettingsService.php::updateSettings()`
- `lib/Service/SettingsService.php::rebase()`
- `lib/Service/SettingsService.php::expiresExpression()` (private helper of rebase)
- `lib/Service/SettingsService.php::columnExists()` (private helper of rebase)

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section + design.md surface multiple HIGH-severity authorization findings (silent IDOR pattern across the logs / stats / rebase / source-test endpoints)
- Cap REQs at 5: paired controllers + service helpers under shared REQs

## Major security findings flagged

- **HIGH (IDOR — multiple endpoints)** — `LogsController` (index/show/destroy/statistics/export), `SourcesController` (logs/test), `SettingsController::rebase` ALL carry `@NoAdminRequired` + `@NoCSRFRequired` with zero per-object guards. Any authed user can read/delete/export all logs and trigger global DB rewrite via `rebase`.
- **HIGH (SSRF)** — `SourcesController::test` triggers an arbitrary outbound HTTP call to the source URL, controllable by any authed user.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
