# Retrofit — object-service-shim

Describes observed behavior of 9 methods under `object-service-shim` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Service/ObjectService.php::getClient()`
- `lib/Service/ObjectService.php::saveObject()`
- `lib/Service/ObjectService.php::findObjects()`
- `lib/Service/ObjectService.php::findObject()`
- `lib/Service/ObjectService.php::updateObject()`
- `lib/Service/ObjectService.php::deleteObject()`
- `lib/Service/ObjectService.php::aggregateObjects()`
- `lib/Service/ObjectService.php::getOpenRegisters()`
- `lib/Service/ObjectService.php::getMapper()`

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces any observed-but-suspicious behavior

## Status note

The `ObjectService` shim class is on a deletion path: change
`openconnector-services-direct-or-usage` cuts every caller over to OpenRegister's
own `\OCA\OpenRegister\Service\ObjectService`. This retrofit documents the shim's
observed behaviour as it exists on `origin/development` today; once the cutover
change merges, this spec should be archived alongside the deleted class.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
