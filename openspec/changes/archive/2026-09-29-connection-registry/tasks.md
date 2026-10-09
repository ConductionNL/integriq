# Tasks: connection-registry (integriq)

Contract: hydra umbrella `openspec/changes/connection-registry/design.md`, branch `feat/connection-registry`.

## 1. Contract files

- [x] 1.1 `lib/Settings/connections.schema.json`, the JSON Schema for `connections.json` (D2).
- [x] 1.2 `lib/Settings/register.d/app-connection-schema.json`, admin-only `app_connection` schema (D3, D11).

## 2. Backend

- [x] 2.1 `ConnectionDeclarationValidator`, mirroring the JSON Schema, with a drift test against it.
- [x] 2.2 `ConnectionStatusResolver`, the D4 rules in order, reading app config through `ConnectionConfigReader`.
- [x] 2.3 `ConnectionRegistryService::sync()`, `report()`, `refresh()`, `probe()`, `link()` (D5, D6, D7, D9).
- [x] 2.4 `ConnectionStatusReportedEvent` and `ConnectionRefreshRequestedEvent` exactly as D6, with listeners that never throw.
- [x] 2.5 `SyncConnectionDeclarations` repair step on install and post-migration, and an app lifecycle listener for `AppEnableEvent` and `AppDisableEvent`.
- [x] 2.6 `SourceTestService`, shared by `SourcesController::test` and the health job.
- [x] 2.7 `ConnectionHealthJob`, hourly, 25 probes, open breaker without a call, stale declarations re-synced (D7).
- [x] 2.8 `ConnectionsController::link`, admin-only `POST /api/connections/{id}/link`.

## 3. Frontend

- [x] 3.1 `AppConnections` index page and menu entry under the Connections group (D8).
- [x] 3.2 `connectionStatus` and `connectionSettingsLabel` formatters.
- [x] 3.3 `LinkSourceDialog`, opened by Add integration and by `?link=1` (D9).
- [x] 3.4 English and Dutch strings in `l10n/en.json` and `l10n/nl.json`, then `npm run l10n:build`.

## 4. Tests

- [x] 4.1 PHPUnit: resolver (every D4 row and both orderings), validator, sync, listeners, health job, controller.
- [x] 4.2 Playwright spec for the overview page and the dialog, `tests/e2e/spec-coverage/connection-registry.spec.ts` (runs nightly, not run in this PR).

## 5. Amendments after the first adopters (umbrella D12)

- [x] 5.1 `adapter.jsonPath` and `adapter.simulatedValues` in `connections.schema.json`, the validator and D4 rule 3, with defaults that keep every existing declaration's meaning.
- [x] 5.2 `reportedOnly` skips rules 3 and 5.
- [x] 5.3 Rule 4a: a `simulated` report wins over any probe, with the report's time. Rule 4b is the old rule 4.
- [x] 5.4 `limited` in the `app_connection` enum (schema 1.1.0), the report allow-list, the `connectionStatus` formatter and the English and Dutch catalogues.
- [x] 5.5 `ConnectionHealthJob` resolves every row after the probes, with no outbound call and no cap.
- [x] 5.6 PHPUnit for each new D4 behaviour, the JSON path edge cases, the defaults, a `limited` report and the job's resolve of unlinked rows. Rule 4a's order is mutation-checked.

## 6. A refresh retires older observations (umbrella D6, D12 item 5)

- [x] 6.1 `refreshedAt` in the `app_connection` schema (1.2.0) and in `ConnectionStore::PROPERTIES`, with English and Dutch strings.
- [x] 6.2 `ConnectionRegistryService::refreshRequested()` stamps `refreshedAt` on the requested row, or every row of the app for a null key, then resolves. The refresh listener calls it.
- [x] 6.3 Rules 4a and 4b skip a report or probe older than `refreshedAt`. An equal time counts.
- [x] 6.4 PHPUnit for both spec scenarios, equal times, a null key, a probe newer than the refresh, and a sync, report and plain resolve that keep `refreshedAt`. The older-than comparison is mutation-checked.

## 7. What counts as a filled setting (umbrella D2, D4, D12 items 6 and 7)

- [x] 7.1 `requiredConfig.items` in `connections.schema.json` is a non-empty key or `{configKey, jsonPath}` with no other fields, and the validator mirrors it.
- [x] 7.2 `ConnectionConfigReader` reads each entry, a dotted string as one key, and an object through the `adapter.jsonPath` walk.
- [x] 7.3 A value is empty when it reads as `""`, `false` or `0`, or is `null` or a missing path. A typed key is read with the getter for its type.
- [x] 7.4 PHPUnit for the three spec scenarios, each empty value, `"no"` and `"00"` as filled, object and dotted entries in the validator and the schema, and unchanged string declarations. Removing `false` from the empty values turns the switch scenario red on its assertion.

## 8. Switched off, and empty JSON lists (umbrella D2, D3, D4 rule 2b, D12 items 8 and 9)

- [x] 8.1 `switch` `{configKey, jsonPath?, offValues?}` and `disabledMessage` in `connections.schema.json`, and the validator mirrors them.
- [x] 8.2 `ConnectionConfigReader::isSwitchedOff()` reads the switch through the `requiredConfig` path walk and emptiness check.
- [x] 8.3 Rule 2b in the resolver, below rule 2 and above rules 3, 4a and 4b, for `reportedOnly` rows too.
- [x] 8.4 An empty JSON array or object counts as empty, decoded, typed or as text.
- [x] 8.5 `disabled` in `STATUSES`, the `app_connection` enum (schema 1.3.0), the `connectionStatus` formatter and the English and Dutch catalogues.
- [x] 8.6 PHPUnit for the five spec scenarios, an off switch above a newer probe and a mock adapter, a switch with `jsonPath`, unset keys with and without `offValues`, a reported `disabled`, `[]`, `{}`, `[ ]` and `[0]`, and unchanged declarations without a switch. Rule 2b's place and the empty list are mutation-checked.
