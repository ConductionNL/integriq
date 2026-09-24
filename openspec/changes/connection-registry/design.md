# Design: connection-registry (integriq)

The contract is the hydra umbrella design, `openspec/changes/connection-registry/design.md` on hydra branch `feat/connection-registry`, amended by hydra#673, hydra#674 and the hydra `requiredConfig` amendment on branch `feat/connection-required-config-reads-values`. Its sections D1 to D12 are referenced by number here and are not copied. Where this file makes a choice the umbrella leaves open, it says so.

## Where each decision lands

| Umbrella | Integriq file |
|---|---|
| D2 declaration file | `lib/Settings/connections.schema.json`, `lib/Service/ConnectionDeclarationValidator.php` |
| D3, D11 schema | `lib/Settings/register.d/app-connection-schema.json` (slug `app_connection`) |
| D4 resolver | `lib/Service/ConnectionStatusResolver.php` |
| D5 sync | `lib/Service/ConnectionRegistryService.php`, `lib/Repair/SyncConnectionDeclarations.php`, `lib/EventListener/ConnectionAppLifecycleListener.php` |
| D6 events | `lib/Event/ConnectionStatusReportedEvent.php`, `lib/Event/ConnectionRefreshRequestedEvent.php` and their listeners |
| D7 health job | `lib/BackgroundJob/ConnectionHealthJob.php`, `lib/Service/SourceTestService.php` |
| D8 overview page | `src/manifest.json` page `AppConnections`, `src/formatters.js` |
| D9 add integration | `src/dialogs/LinkSourceDialog.vue`, `lib/Controller/ConnectionsController.php` |

## Choices the umbrella leaves open

**A hand-written validator beside the JSON Schema.** `opis/json-schema` is only a dev dependency here, so the runtime cannot rely on it. The validator mirrors `connections.schema.json` rule for rule. A unit test runs both over the same fixtures, so they cannot drift apart unnoticed.

**"Sync time" in D4 rules 2 and 3.** The resolver keeps the stored `checkedAt` while the status and message stay the same, and stamps now when either changes. Re-running the sync therefore changes nothing, which is what REQ-CONN-002 asks. Rule 5 follows the same rule, so the hourly job does not rewrite every configured row.

**A key that left the file while a source is linked.** The sync keeps the row and sets its stored `declaration.available` to `false` with `unavailableMessage` "No longer declared by {app}." Rule 2 then gives the D5 status and message on every later resolve, so a probe cannot turn the row green again. When the key comes back, the next sync writes the declared entry over it.

**A file that disappears.** When an enabled app that has rows no longer ships `connections.json`, the sync treats it as an empty declaration. Unlinked rows go, linked rows stay as above.

**Amendments from dossiq#2715, adopted in the umbrella.** A declaration may carry `unconfiguredMessage`, which D4 rule 6 shows instead of "Not checked yet.". Rule 5 says "Required settings are filled.", because a register import can fill the keys without an admin saving anything.

**Tie between a report and a probe.** When `lastReport.at` equals `lastProbe.at`, the probe wins. It is integriq's own observation.

**Reading another app's config.** `IAppConfig::getValueString($app, $key, '', lazy: true)`. With `lazy: true` Nextcloud returns lazy and non-lazy values alike, so one call covers both.

**An app that is disabled.** `AppDisableEvent` re-resolves that app's rows, so rule 1 shows within the request instead of after the next job run. The hourly job re-resolves every row as well.

**Rows are matched by app and key, not by slug.** The sync reads an app's rows with an `app` filter and matches `key` in PHP. The slug is still written as `connection-{app}-{key}`, but lookups do not depend on how OpenRegister indexes a property called `slug`.

**Writes as the system.** Every read and write of `app_connection` rows runs inside `SystemOperationContext::run()`. A report can arrive from a non-admin request in another app, and the schema is admin-only.

**Link and probe in one request.** The dialog posts to `POST /api/connections/{id}/link`. The endpoint is admin-only. It links an existing source, or creates one from the connection's `sourceTemplate` by reusing a source with that slug or the seed payload the catalog uses. It then probes, resolves and returns the row and the probe. A connection that already has a source is refused with 409, because the dialog only offers connections without one.

**Overview page.** Route `/connections`, so an adopting app's "Add integration" can link to `/apps/integriq/connections?app=<id>&link=1`. Placed under the existing Connections group as "App connections", beside Sources, so the menu gains no top-level entry (ADR-097). The built-in add, edit, copy, delete and import actions are off: a row nothing declared has nothing to check, and a hand edit would be overwritten by the next resolve. `?app=<id>&link=1` opens the dialog, pre-filtered, from `ModalHost`, which already sits outside the routed view.

## Amendments from umbrella D12 (hydra#673)

**What the resolver reads as the adapter value.** `ConnectionConfigReader` holds this, so the resolver keeps only the rule order. Without `adapter.jsonPath` it is the trimmed config value, as before. With it, the resolver decodes the value as JSON and walks the dot path. Missing segments, invalid JSON, `null`, objects and lists all read as the empty string. A boolean reads as `true` or `false` and a number as its digits, so a declaration can list them in `simulatedValues`. A value Nextcloud stores under the array type is read with `getValueArray`, because `getValueString` refuses it.

**A declared `simulatedValues` that is not a list.** The validator refuses the file, so the resolver only meets one in a row written before the file changed. It then uses the default `[""]`.

**Rule numbers in the outcome.** Rules 4a and 4b both report rule 4. Status and `checkedAt` tell them apart, and nothing reads the number but the tests.

**Where `limited` lives.** `ConnectionStatusResolver::STATUSES` is the one list the report path checks, so adding `limited` there is the listener's allow-list too. The schema moves to 1.1.0 and the app version moves, so the register import picks up the new enum value on upgrade.

**The order of the health job.** Sync, probes, then the resolve of every row. The resolve used to run before the probes. Running it last means a probe written this hour and a key set with `occ` both show in the same run.

## Amendment: a refresh retires older observations (umbrella D6, D12 item 5, hydra#674)

**Two refresh paths, one of them stamps.** `ConnectionRegistryService::refreshRequested()` is what the refresh listener calls. It writes `refreshedAt` and saves every requested row whose data changed. That is every row, unless it was already stamped in the same second. `refresh()` is the plain resolve that the hourly job and `AppDisableEvent` use. It never writes `refreshedAt`, so the job cannot retire an observation an hour after it was made.

**How the comparison reads.** Both times are parsed to instants, so `12:05+02:00` equals `10:05Z`. An observation retires only when it is strictly older. An equal time counts, because `now()` has one-second precision and a report sent in the same second as the save may describe the new settings. An observation without `at` is older than any refresh. A `refreshedAt` that does not parse retires nothing.

**What stays on the row.** A retired `lastReport` or `lastProbe` is not cleared. The resolver skips it, and the row keeps it for reading.

**Schema.** `refreshedAt` joins `app_connection` as a date-time property, and the schema moves to 1.2.0. `ConnectionStore::PROPERTIES` lists it, so a write keeps it. The app version moves, so the register import picks up the new property on upgrade.

## Amendment: what counts as a filled setting (umbrella D2, D4, D12 items 6 and 7)

**One reader for both amendments.** `ConnectionConfigReader::allFilled()` resolves each `requiredConfig` entry. A string is read as the whole key, dots included. An object walks its `jsonPath` with the same path walk and the same array-type fallback that `adapter.jsonPath` uses. The resolver only passes the entries on, so it stays under the complexity limit.

**How emptiness is checked.** Every scalar is turned into text the way the adapter path already does: `true` and `false` as words, numbers as digits, `null` as the empty string. That text is trimmed, lowercased and compared with `EMPTY_VALUES`, which is `""`, `false` and `0`. So one list decides for a stored string, a JSON value and a typed value alike. A non-empty object or list at the key or the path counts as filled. An empty one used to count as filled too, until the amendment below. `"no"`, `"off"` and `"00"` count as filled too.

**A value stored under another type.** Stackiq stores `federation_enabled` with `setValueBool`, and `getValueString` refuses such a key. The reader then asks `getValueType` and reads the value with `getValueBool`, `getValueInt`, `getValueFloat` or `getValueArray`. A boolean `false` therefore reads as empty. Before this amendment such a key always counted as filled. A type the reader cannot name, or a key `getValueType` does not know, still counts as filled, as before.

**An invalid entry.** The validator refuses a file with one, so the resolver only meets it in a row written before the file changed. Such an entry reads as empty, as a non-string entry did before.

**What stays the same.** The adapter value is still read as before, including a typed key. The `app_connection` schema stores `declaration` as an untyped object, so it does not move.

## Amendment: switched off, and empty JSON lists (umbrella D2, D3, D4 rule 2b, D12 items 8 and 9, hydra#677)

**Where the switch is read.** `ConnectionConfigReader::isSwitchedOff()` turns the switch into a `requiredConfig` entry and reads it with the same path walk. Without `offValues` it asks the same emptiness check. With `offValues` it compares the value as text, the way `simulatedValues` is compared. Judging a value moved into `ConnectionConfigValue`: filled or empty, one of a list, and scalar as text. With the switch added, phpmd rated the reader at 55 and the resolver at 51, against a limit of 50. After the split they sit at 45 and 49. A stored switch without a string `configKey` never reads as off.

**A list or object and `offValues`.** A non-empty list or object never equals an off value. An empty one reads as the empty string, so it is off only when `""` is listed.

**Rule number and time.** Rule 2b reports rule number 2, as rules 4a and 4b both report 4. Its time is now, kept while status and message stay the same, as rule 5 does. A sync over a row that stays off then writes nothing.

**Empty JSON as text.** Only text that starts with `[` or `{` is decoded. `[ ]` is an empty JSON array, so it counts as empty, and `[0]` holds a value, so it counts as filled. `null` as text is not decoded and stays filled, as before. A text `[]` inside a JSON setting counts as empty too, because every value runs through one check.

**Where `disabled` lives.** `ConnectionStatusResolver::STATUSES` gains `disabled`, which is also the report allow-list. The `app_connection` enum gains it with the label Switched off, the schema moves to 1.3.0 and the app version moves, so the register import picks up the new value on upgrade. The `connectionStatus` formatter shows Switched off, Uitgeschakeld in Dutch.
