# connection-registry Specification Delta (integriq)

**Status**: proposed
**Scope**: integriq. Implements the integriq side of the hydra umbrella change `connection-registry` (REQ-CONN-001 to REQ-CONN-007). The contract shapes live in the umbrella design, D1 to D9, amended by D12.

## ADDED Requirements

### Requirement: The sync turns declaration files into connection rows (REQ-CONN-001)

Integriq SHALL read `lib/Settings/connections.json` of every enabled app through `IAppManager::getAppPath`. It MUST validate the file against `lib/Settings/connections.schema.json` and MUST refuse a file whose `app` differs from the id of the app it was read from. A valid file SHALL become one `app_connection` row per entry, with slug `connection-{app}-{key}`.

@e2e exclude The sync is a backend step with no browser surface. ConnectionRegistryServiceTest and ConnectionDeclarationValidatorTest prove every scenario here.

#### Scenario: a valid file becomes one row per entry

- GIVEN an enabled app `dossiq` ships a valid `connections.json` with two entries
- WHEN the sync runs
- THEN two `app_connection` rows exist with `app` equal to `dossiq`
- AND each row's slug is `connection-dossiq-{key}`

#### Scenario: an invalid file is skipped whole

- GIVEN an app ships a `connections.json` where one entry has no `title`
- WHEN the sync runs
- THEN no row is written for that app
- AND the log carries an error naming the app and the failing path

#### Scenario: a file claiming another app's id is refused

- GIVEN the app `pipelinq` ships a `connections.json` whose `app` is `dossiq`
- WHEN the sync runs
- THEN no row is written or deleted from that file

### Requirement: The sync is idempotent and keeps linked rows (REQ-CONN-002)

The sync SHALL upsert by app and key. It SHALL delete a row whose key left the declaration only when the row has no `source`. A row with a `source` SHALL stay, with status `unavailable` and message "No longer declared by {app}.". Every write MUST run inside OpenRegister's system operation context.

@e2e exclude Backend behaviour with no browser surface. ConnectionRegistryServiceTest covers each scenario.

#### Scenario: running the sync twice writes nothing the second time

- GIVEN the sync has run once for an app
- WHEN it runs again with an unchanged file
- THEN no row is created, saved or deleted

#### Scenario: a removed key without a source is deleted

- GIVEN a row for key `brp` has no source
- WHEN the app ships a file without `brp` and the sync runs
- THEN the row is deleted

#### Scenario: a removed key with a source is kept

- GIVEN a row for key `brp` has a linked source
- WHEN the app ships a file without `brp` and the sync runs
- THEN the row still exists with its `source`
- AND its status is `unavailable` with "No longer declared by dossiq."

### Requirement: The resolver applies the D4 rules in order (REQ-CONN-003)

Integriq SHALL resolve `status`, `statusMessage` and `checkedAt` by the first rule of umbrella design D4 that applies: app disabled, declared unavailable, a switch that reads as off, an adapter value in `adapter.simulatedValues`, a simulated report, newest observation, required settings filled, otherwise not checked. It MUST read the declaring app's settings through `IAppConfig::getValueString`, and a value stored under another type through the getter for that type.

The adapter value SHALL be the config value at `adapter.configKey`, or, when `adapter.jsonPath` is set, the scalar at that dot path inside the JSON object the key holds. A missing path, invalid JSON or a non-scalar value MUST read as the empty string. `adapter.simulatedValues` SHALL be compared case-insensitively after trimming and SHALL default to `[""]`, so a declaration without it keeps its meaning. A row whose declaration carries `reportedOnly: true` MUST skip the adapter rule and the required settings rule. A `lastReport` with status `simulated` SHALL win over any probe, with `checkedAt` set to the report's `at`. No rule SHALL produce `limited` from a declaration.

A `requiredConfig` entry SHALL be an app-config key, read as the whole key even when it contains dots, or `{configKey, jsonPath}`, read through the same path walk as `adapter.jsonPath`. A value MUST count as empty when, after trimming, it is `""`, `false` or `0` (case-insensitive), when it is a JSON or stored `false`, `0` or `null`, when it is an empty JSON array or object (decoded, stored under the array type, or text such as `[]` or `{}`), or when the path is missing. Every other value SHALL count as filled, `[0]` included.

A declaration MAY carry `switch` `{configKey, jsonPath?, offValues?}` and `disabledMessage`. The switch value SHALL be read the way a `requiredConfig` entry is. Without `offValues` the switch MUST read as off when that value is empty. With `offValues` it MUST read as off only when the trimmed value equals one of them case-insensitively, so an unset key is off only when `""` is listed. A switch that reads as off SHALL give `disabled` with `disabledMessage`, else "Switched off in {app}'s settings." This rule SHALL sit below the declared-unavailable rule and above the adapter rule and both observation rules, and it MUST apply to `reportedOnly` rows too. A declaration without `switch` SHALL resolve as before.

Rules 4a and 4b SHALL count only a `lastReport` or `lastProbe` whose `at` is not older than the row's `refreshedAt`. An `at` equal to `refreshedAt` MUST count. A row without `refreshedAt` retires nothing.

@e2e exclude The resolver is a pure backend rule set. ConnectionStatusResolverTest asserts every D4 row, both orderings, rule 4a against a newer probe, the JSON path edge cases, the unchanged defaults, every observation a refresh retires, each value that counts as empty for `requiredConfig`, and the switch against a newer probe, a mock adapter, a JSON path and unset keys.

#### Scenario: a disabled app shows unavailable

- GIVEN the declaring app is disabled
- WHEN the resolver runs
- THEN the status is `unavailable` with "The dossiq app is disabled."

#### Scenario: an empty adapter key shows simulated

- GIVEN the declaration names `adapter.configKey` `berichtenbox_adapter`
- AND the app's config holds no value for that key
- WHEN the resolver runs
- THEN the status is `simulated` with the declared `simulatedMessage`

#### Scenario: simulated outranks a passing probe

- GIVEN a row whose last probe passed
- AND its adapter key is empty
- WHEN the resolver runs
- THEN the status is `simulated`

#### Scenario: a provider name selects simulated

- GIVEN the email declaration names `adapter.configKey` `email_transport_type` with `simulatedValues` `["", "null"]`
- AND the app config holds `null`
- WHEN the resolver runs
- THEN the row's status is `simulated`

#### Scenario: a JSON path reads inside a settings blob

- GIVEN the llm declaration names `adapter.configKey` `llm` with `jsonPath` `provider` and `simulatedValues` `["", "none"]`
- AND the app config `llm` holds `{"provider": "openai"}`
- WHEN the resolver runs
- THEN rule 3 does not apply to the row

#### Scenario: a reported-only row ignores filled settings

- GIVEN the cti declaration carries `reportedOnly: true` and `requiredConfig`
- AND every required key is filled
- AND the row has no report and no probe
- WHEN the resolver runs
- THEN the status is `unconfigured`

#### Scenario: a simulated report stands against a newer probe

- GIVEN a row's last report says `simulated` at 10:00
- AND its last probe says `ok` at 11:00
- WHEN the resolver runs
- THEN the status is `simulated` and `checkedAt` is 10:00

#### Scenario: the newer observation wins

- GIVEN a row's last report says `configured` at 10:00
- AND its last probe says `error` at 11:00
- WHEN the resolver runs
- THEN the status is `error` and `checkedAt` is 11:00

#### Scenario: a declared unconfigured message replaces the default

- GIVEN the brp declaration carries `unconfiguredMessage` naming `integration.brp.mode`
- AND the row has no probe, no report and no required settings
- WHEN the resolver runs
- THEN the status is `unconfigured` with the declared message

#### Scenario: saved settings show configured

- GIVEN the declaration requires `register` and `case_schema`
- AND both hold values in the app's config
- AND the row has no probe and no report
- WHEN the resolver runs
- THEN the status is `configured` with "Required settings are filled."

#### Scenario: an empty JSON list is not filled

- GIVEN the live-tiles declaration requires `live_tile_allowed_hosts`
- AND launchpad's app config holds `[]` for it
- AND the row has no probe and no report
- WHEN the resolver runs
- THEN the status is `unconfigured`

#### Scenario: a connection switched off reads disabled

- GIVEN the hibp declaration carries `switch` `{"configKey": "breach_check_enabled"}`
- AND keepiq's app config holds `false` for it
- AND the row's last report says `error` at 10:00
- WHEN the resolver runs
- THEN the status is `disabled` with "Switched off in keepiq's settings."

#### Scenario: off values leave a working default alone

- GIVEN the geo-db declaration carries `switch` `{"configKey": "traffic.geo.provider", "offValues": ["none"]}`
- AND portaliq's app config holds no value for `traffic.geo.provider`
- WHEN the resolver runs
- THEN rule 2b does not apply to the row

#### Scenario: an off value switches the connection off

- GIVEN the same geo-db declaration
- AND the app config holds `None` for `traffic.geo.provider`
- WHEN the resolver runs
- THEN the status is `disabled`

#### Scenario: a switch stored as false is not filled

- GIVEN the federation declaration requires `federation_enabled`
- AND stackiq's app config holds `false` for it
- AND the row has no probe and no report
- WHEN the resolver runs
- THEN the status is `unconfigured`

#### Scenario: a required value inside a JSON setting

- GIVEN the eol-feed declaration requires `{"configKey": "eolSync", "jsonPath": "enabled"}`
- AND the app config `eolSync` holds `{"enabled": true, "interval": 24}`
- AND the row has no probe and no report
- WHEN the resolver runs
- THEN the status is `configured` with "Required settings are filled."

#### Scenario: a dotted key is read as one key

- GIVEN the brp declaration requires `integration.brp.mode`
- AND the app config holds `live` under the key `integration.brp.mode`
- WHEN the resolver runs
- THEN that entry counts as filled

### Requirement: Apps report and refresh through two typed events (REQ-CONN-004)

Integriq SHALL listen for `OCA\Integriq\Event\ConnectionStatusReportedEvent` and `OCA\Integriq\Event\ConnectionRefreshRequestedEvent`. A report MAY carry any of the seven statuses `configured`, `limited`, `unconfigured`, `simulated`, `disabled`, `unavailable` and `error`, and `disabled` MUST be accepted like the others. The report listener MUST refuse an unknown status or an undeclared app and key with a warning. It SHALL write `lastReport` and resolve the row. The refresh listener SHALL write `refreshedAt` as the current time on the requested row, or on every row of the app when the key is null, and then resolve those rows. No other path SHALL write `refreshedAt`: a sync, a report, a probe and the hourly resolve MUST keep the stored value. A refresh MUST NOT delete `lastReport` or `lastProbe`. Neither listener SHALL throw into the sender.

@e2e exclude An in-process event exchange with no browser surface. ConnectionEventListenersTest and ConnectionRegistryServiceTest prove it.

#### Scenario: a report reaches the row

- WHEN dossiq sends `ConnectionStatusReportedEvent('dossiq', 'mailbox', 'configured', 'Logged in')`
- THEN the mailbox row's `lastReport` holds that status and message
- AND the row has been resolved

#### Scenario: an app reports a switch it keeps elsewhere

- GIVEN the siem declaration is `reportedOnly` and has no `switch`
- WHEN keepiq sends `ConnectionStatusReportedEvent('keepiq', 'siem', 'disabled', 'Every SIEM sink is switched off.')`
- THEN the row's status is `disabled` with that message

#### Scenario: an app reports a connection that works in part

- WHEN pipelinq sends `ConnectionStatusReportedEvent('pipelinq', 'social-bluesky', 'limited', 'Preview API: posting works, reading replies does not.')`
- THEN the row's status is `limited` with that message

#### Scenario: an unknown key is refused without an exception

- WHEN an app sends a report for a key it never declared
- THEN no row changes
- AND the log carries a warning naming the app and key
- AND the listener returns normally

### Requirement: The health job probes linked sources every hour (REQ-CONN-005)

`ConnectionHealthJob` SHALL run every 3600 seconds and probe at most 25 rows with a `source`, oldest `lastProbe.at` first. A source whose `circuitBreakerState` is `open` SHALL be recorded as `error` without a call. Otherwise the job SHALL make the call `SourcesController::test` makes, with `persistLog` false. The job SHALL also sync every app whose version differs from its rows' `declaredVersion`. After the probes, the job SHALL resolve every row, linked or not. That step MUST make no outbound call and has no cap.

@e2e exclude A cron job and an in-process event with no browser surface. ConnectionHealthJobTest, ConnectionRegistryServiceTest and ConnectionStatusResolverTest prove the cap, the order, the breaker rule, the resolve of unlinked rows and the observations a refresh retires.

#### Scenario: an open breaker is not called

- GIVEN a linked source's `circuitBreakerState` is `open` after 5 failures
- WHEN the health job runs
- THEN no outbound call is made for that source
- AND the row's `lastProbe` is `error` with "The circuit breaker is open after 5 failures."

#### Scenario: a save retires an older error

- GIVEN the zrc row's last report says `error` at 10:00
- AND its required settings are filled
- WHEN zaakafhandelapp sends `ConnectionRefreshRequestedEvent('zaakafhandelapp', 'zrc')` at 10:05
- THEN the row's `refreshedAt` is 10:05
- AND its status is `configured` with "Required settings are filled."
- AND its `lastReport` still holds the 10:00 error

#### Scenario: a report after the refresh counts again

- GIVEN the zrc row was refreshed at 10:05
- WHEN zaakafhandelapp reports `error` at 10:07
- THEN the row's status is `error` and `checkedAt` is 10:07

#### Scenario: a key set with occ shows within the hour

- GIVEN a row has no source and reads `unconfigured`
- AND an admin fills its required keys with `occ config:app:set`
- WHEN the health job runs
- THEN the row reads `configured` without any outbound request

#### Scenario: at most 25 probes per run

- GIVEN 30 rows with a linked source
- WHEN the health job runs
- THEN 25 rows are probed, those with the oldest `lastProbe.at` first

### Requirement: Integriq shows all connections on one admin page (REQ-CONN-006)

Integriq SHALL render an admin-only `index` page at `/connections` over `integriq/app_connection` under the Connections menu group, with `app` as a column and as the folder sidebar field. The page MUST NOT offer the built-in add, edit, copy or import actions.

#### Scenario: the overview lists connection rows with their app

- GIVEN integriq holds connection rows
- WHEN an admin opens App connections from the Connections group
- THEN the table shows the app, connection, status, status message, last checked and settings columns

#### Scenario: the overview offers no free-form row

- WHEN an admin opens App connections
- THEN no Add button is shown

### Requirement: Add integration links a source and probes it at once (REQ-CONN-007)

The overview SHALL offer "Add integration", which opens `LinkSourceDialog`. The dialog SHALL list only declared connections without a source. The admin SHALL pick an existing source or create one from the connection's `sourceTemplate`. Saving SHALL link the source, probe it at once and show the result. The route query `link=1` SHALL open the same dialog, pre-filtered by `app`.

#### Scenario: Add integration opens the dialog

- WHEN an admin chooses Add integration on App connections
- THEN the link a source dialog opens
- AND it offers no field to type a new connection key

#### Scenario: the link query opens the dialog pre-filtered

- WHEN an admin opens `/apps/integriq/connections?app=dossiq&link=1`
- THEN the link a source dialog opens
- AND its app filter is dossiq

#### Scenario: linking a source probes it straight away

@e2e exclude Linking writes a source link and fires a live probe against an outside system, which the nightly instance cannot answer deterministically. ConnectionProbeServiceTest and ConnectionsControllerTest prove the link, the probe and the response.

- GIVEN a declared connection without a source
- WHEN an admin links an existing source through the link endpoint
- THEN the row's `source` holds that source's uuid
- AND the response carries the probe result

#### Scenario: a connection that already has a source is refused

@e2e exclude An API refusal with no page of its own. ConnectionProbeServiceTest and ConnectionsControllerTest assert the 409 and the unchanged row.

- GIVEN a connection with a linked source
- WHEN an admin posts a link for it
- THEN the response is 409 and the row keeps its source
