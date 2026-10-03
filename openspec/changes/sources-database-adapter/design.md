# Design: sources-database-adapter

Kind: code. Size M. A `DatabaseAdapter` in `lib/Service/Adapter/DataInfra/`, the `database` branch in `SynchronizationService`, source fields, and the source page.

## Context at development 92f282bc

- `AbstractCategoryAdapterProvider` (`lib/Service/Adapter/AbstractCategoryAdapterProvider.php:56`) with `getCapabilities()` (`:87`), credential lookup (`:112`), `health()` (`:235`); `S3Adapter` registers through the integration registry in `lib/AppInfo/Application.php:1565`.
- `SynchronizationService::getAllObjectsFromSource()` (`lib/Service/SynchronizationService.php:5846`) switches on `sourceType`; the `database` case is a `@todo`.
- `CallService::call()` dispatches `soap` to `SOAPService` and everything else to Guzzle (`lib/Service/CallService.php:1213-1220`).
- Nextcloud ships Doctrine DBAL (`composer.lock` constrains `doctrine/dbal` to `^3.6|^4` through a dependency).
- ADR-064 decision 3: the broker's `resolveInjectable()` hands a secret to the app only for `inject_only` providers, the `generic-*` entries, meant for hosts the broker cannot proxy. A database connection is not HTTP and cannot go through the broker's proxy.

## D1. Doctrine DBAL, not raw PDO

`DriverManager::getConnection()` with the source's driver (`pdo_pgsql`, `pdo_mysql`, `pdo_sqlsrv`), host, port, database name and user, and the password from `resolveInjectable()` on a `generic-*` provider with `organisation` scope. DBAL gives one API across drivers, a schema manager for discovery, and platform-aware quoting. The connection lives for one adapter call and is closed after it. A driver whose PHP extension is missing makes the adapter report `unavailable` with the extension named, through `health()`.

## D2. Statements are configuration, callers send parameters

The source gains `statements`: named entries of `{ name, kind: read|write, sql, parameters, pageKey }`. SQL uses named placeholders only. At run time the adapter binds values by name and refuses any statement name the source does not declare. Reads page by keyset on `pageKey` (for example `WHERE id > :after ORDER BY id LIMIT :limit`), because offset paging drifts on a live table. Every statement runs with a timeout (default 30 seconds) and reads with a row cap per page (default 500).

Rejected: letting a synchronization or flow send free SQL. That turns every mapping author into someone with database access.

## D3. The synchronization branch

The read-side `database` case (`lib/Service/SynchronizationService.php:5871`) calls `DatabaseAdapter::read(source, statementName, parameters, after)` until a page returns fewer rows than the cap, and hands rows to the existing mapping and upsert path unchanged. `sourceConfig.statement` names the read statement. The target-side `database` case (`:5705`) writes through a declared `write` statement named in `targetConfig.statement`, so a synchronization can also land objects in a database.

## D4. Category contract

The adapter meets REQ-DIC-001 (registered in the integration registry), REQ-DIC-002 (manifest entry: `slug` `database`, `label`, `icon`, `authModes` [`basic`], `capabilities` [`read`, `write`, `schema-discover`, `query`]), REQ-DIC-003 (credentials on the integriq source as `credentialRef`), REQ-DIC-004 (`pollingMode: poll`, schema discovery through DBAL's schema manager), REQ-DIC-005 (scheduled pulls as OpenRegister ScheduledWorkflow records calling the adapter by slug, no new `TimedJob`), REQ-DIC-006 (health on the metrics endpoint).

## D5. The source type description becomes true

The source `type` description is corrected to say which types `CallService` dispatches and which dedicated services dispatch (`database` through `DatabaseAdapter`, `file` removed until something reads it).

## Declarative versus imperative

No lifecycle or notification behaviour. The adapter is the ADR-031 exception for an external integration.

## Seed data

A dormant source `example-postgres` (`type: database`, driver `pdo_pgsql`, host `db.example.nl`, no credential) with one read statement `zaken-sinds` (`SELECT id, zaaknummer, omschrijving FROM zaken WHERE id > :after ORDER BY id LIMIT :limit`).

## Risks

- An administrator writes a slow statement. Mitigation: the timeout, the row cap and a test run button that shows the first page and the duration.
- A writable account used for reads. Mitigation: the source page recommends a read-only account and shows whether any write statement is declared.
