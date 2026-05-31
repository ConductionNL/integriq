# Retrofit — repair-and-app-boot

Describes observed behavior of 2 methods under `repair-and-app-boot` as 2 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Repair/InitializeRegister.php::run()`
- `lib/AppInfo/Application.php::registerIntegrationProviders()`

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces observed-but-suspicious behavior (catch-and-warn-anywhere pattern; silent skip when OR autoloader unavailable)

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
