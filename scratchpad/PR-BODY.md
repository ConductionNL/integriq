Phase 3 of the fleet rename: the **app id** moves from `openconnector` to
`integriq`. Phases 1 (display names) and 2 (repo name) are already done.

Per [renaming-an-app](https://docs.conduction.nl/hydra/operations/renaming-an-app),
**there is no in-place app-id upgrade in Nextcloud** — renaming `<id>` does not
rename anything, it makes the app ask for its data under a name nothing answers
to. So this is a data migration, not a find-and-replace.

## What moves

`<id>`, `<namespace>`, the PHP namespace `OCA\OpenConnector\` → `OCA\Integriq\`,
composer autoload, route names, `/apps/openconnector/*` URLs, the l10n domain,
`occ openconnector:*` command prefixes, webpack bundle names, the CI `app-name`
(which drives the `apps/<name>` checkout dir and both seed commands), and prose.

## The data migration

Two repair steps, registered **first** in BOTH `<install>` and
`<post-migration>`:

| Step | Carries | Why it is not a release note |
| --- | --- | --- |
| `MigrateAppConfigKeys` | every `oc_appconfig` row | `actions` is the ADR-023 action-authorization matrix. Lose it and `InitializeActions` re-seeds the **shipped defaults** — an admin who tightened an action silently gets it loosened back. Hence the ordering. |
| `MigrateUserPreferences` | every `oc_preferences` row | Every reader carries a default, so a lost value does not error, it **reverts**. A default-valued read turns missing data into wrong behaviour rather than into an error. |

Both enumerate **from the data** (`IAppConfig::getKeys()`;
`IUserManager::callForSeenUsers()` + `IConfig::getUserKeys()`) rather than from a
hardcoded key list. `getUsersForUserValue(app, key, value)` needs the value up
front, and this app's per-user keys are written by the AppHost
`GenericPreferencesController` and by shared nextcloud-vue widgets — an open set.
Used there it would migrate **nothing while reporting success**.

Both are idempotent, never overwrite a newer value, never delete the old rows
(so a rollback still finds them), and log-and-continue rather than abort. **Every
read sits inside the `try`** — these run under `<install>`, so a repair step that
throws does not merely fail an upgrade, the app never enables and every route
goes with it.

## FROZEN literals — deliberately still `openconnector`

Enumerated and hand-classified before any bulk pass. The danger is always a
value, never a casing.

| # | Literal | Why it cannot move |
| --- | --- | --- |
| 1 | OpenRegister **register slug** `openconnector` (+ `objects/openconnector/<schema>` paths, `components.registers.*`, `slug`/`tablePrefix`/`folder`, `x-openregister.app`) | OR matches registers by slug. Rename it and the import creates a fresh **empty** register while every existing object stays behind, orphaned — silently. |
| 2 | `oc_openconnector_*` table + index names in `lib/Migration/`, `lib/Db/` | Migrations are executed history recorded by version. Rewrite a table name in an already-run migration and a fresh install creates differently-named tables than an upgraded one. No rename migration is written here. |
| 3 | Flow node type ids `openconnector.{source-call,source-paginate,apply-mapping,contract-commit,contract-sweep,synchronization-run,fetch-file}` | These `type` values are written **into stored flow documents** (OR objects). Rename them and every existing flow references a node type nothing answers to. OpenRegister's own `openregister.trigger-manual` sits right beside them. |
| 4 | `StUFXMLBuilder` default `zenderApplicatie = 'OpenConnector'` | This app's identity as a municipal StUF zaaksysteem knows it. Renaming it here does not rename it there — messages are rejected until the municipality re-provisions. |
| 5 | `EudiCredentialOfferService` `'credential_issuer' => 'openconnector'` | OpenID4VCI issuer identifier already held by issued wallet credentials and offers. |
| 6 | `X-OpenConnector-Signature`, `X-OpenConnector-Event-Id` | HTTP header names. Outbound, every subscriber verifies the signature **by header name**; the same names are the `??` default used to verify **inbound** webhooks from payment providers, NotifyNL and StUF brokers. Renaming fails closed on both sides with nothing on our side to say why. |
| 7 | `BrokeredCallService::APP_ID`, `InlineSecretMigrationPlanner::APP_ID`, `InlineSecretMigrationExecutor::APP_ID` (all `'openconnector'`) + the hints naming them | **Not this app's own id.** It is the identity OpenRegister's credential broker matches against a credential's `allowedApps`, via a strict `in_array()`. Every already-minted credential carries `allowedApps: ["openconnector"]`. Rename it and every brokered credential **fails closed at call time** — as an authorisation refusal, not as a rename bug. Moves only when the stored credentials are re-provisioned. |
| 8 | `openconnector.conduction.nl` (docs `url`, `static/CNAME`, `documentation.yml` `cname:`, `llms.txt`, manifest `documentationUrl`) | Live DNS + Pages CNAME. **Verified by curl: old → 200, `integriq.conduction.nl` → 000.** Moves with DNS, not with code. |
| 9 | `https://www.conduction.nl/apps/openconnector` | **Verified by curl: old → 200, `/apps/integriq` → 404.** Moves when the web property does. |
| 10 | `openspec/changes/archive/**` and the `@spec` paths into it; `openspec/specs/openconnector-*` capability ids | History. Rewriting them breaks every `@spec` path that resolves against them (gate-46). |
| 11 | `@id` / `reference` provenance URIs in the exported bundles under `configurations/` | The importer matches on **slugs** (`lib/Service/ConfigurationHandlers/`), so renaming these changes nothing functionally and would falsify a record of what was exported from where. |
| 12 | CHANGELOG history | Past releases shipped under the old name. |

Because #7 is frozen, the operator docs telling you to add **`openconnector`** to
a credential's `allowedApps` stay *correct* — the app still identifies as
`openconnector` to the broker. A note next to each says so, so nobody later
"finishes the job".

## Cross-app references — inventoried, NOT touched

These are duck-typed **runtime** lookups: pointing one at a name nothing answers
to makes the integration **silently no-op**, not error. Every one of these apps
still declares its old id, so they move in a coordinated pass, not here.

`procest`, `decidesk`, `docudesk`, `shillinq`, `scholiq`, `pipelinq`,
`softwareCatalog`, `Doriath`, `OpenCatalogi`, `OpenZaak`, `OpenKlant`, and the
`OCA\OpenRegister\` / `OCA\DAV\` / `OCA\Forms\` / `OCA\Tables\` namespaces.

## Operational consequences to schedule separately

- **Prometheus metric names become `integriq_*`.** The AppHost engine derives the
  prefix from the app id, so this is not preventable at the code level. Existing
  Grafana dashboards and alert rules need updating; docs + spec are updated here.
- **Registered Notificaties API callback URLs** were minted from the old route
  and now 404. Existing remote abonnementen must be re-registered.
- **`allowedApps` re-provisioning** (frozen #7) whenever OpenRegister's stored
  credentials are next touched.
- **DNS + web property** moves for frozen #8 and #9.
