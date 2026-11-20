# Tasks: sources-database-adapter

Kind: code. Size M. Row `integriq:src-database`.

## Implementation tasks

### Task 1: The database adapter
- **spec_ref**: `openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001`
- **files**: `lib/Service/Adapter/DataInfra/DatabaseAdapter.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a source with a read statement WHEN the adapter runs it with a parameter THEN the rows come back and the statement's SQL was bound, not concatenated
  - GIVEN an undeclared statement name WHEN called THEN it is refused before any connection is opened
- [x] Implement
  - `DatabaseAdapter` (`data-infra-database`, capabilities read, write, schema-discover, query) over `DatabaseConnectionFactoryInterface`; `DbalDatabaseConnectionFactory` / `DbalDatabaseConnection` hold the only DBAL code (Nextcloud's DBAL 3 or 4). `DatabaseStatement` checks a declaration (one statement, named placeholders only, every placeholder declared) and binds by name and type. Undeclared names and wrong kinds are refused before a connection opens; the password comes from `configuration.authentication.password` through `BrokeredCallService::hydrateInjectableCredentials()`, an inline password is refused; timeout per platform (PostgreSQL `statement_timeout`, MySQL `max_execution_time`, MariaDB `max_statement_time`, SQL Server driver option), page cap 5000, default 500. `health()` names a missing extension. Registered in the integration registry (`ServiceBindings::boot`).
- [ ] Test (PHPUnit against PostgreSQL and MariaDB containers in the dev compose; a missing driver reported by health)
  - PHPUnit: `DatabaseStatementTest` (12), `DatabaseAdapterTest` (11, fake connection: brokered password, binding, keyset pages, cap, undeclared statement opens nothing, kind, inline password, broker refusal, missing driver in health). Checked by hand against PostgreSQL 16 (1,200 rows read as 500/500/200, a 2 s timeout cancels `pg_sleep(5)`, discovery leaves out the system catalogue, the password never appears in an error) and against SQLite on DBAL 3 and 4. Not done: the PHPUnit run against PostgreSQL and MariaDB containers in the dev compose.

### Task 2: Statements and schema discovery on the source page
- **spec_ref**: `openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001`
- **files**: `lib/Settings/integriq_register.json` (source `statements`, `database` connection fields, corrected `type` description), the source detail page, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator on a database source WHEN they open schema discovery THEN tables and columns are listed, and a test run of a statement shows the first page and its duration
- [x] Implement
  - Register fragment `register.d/sources-database-adapter.json` (source 1.9.0: `database`, `statements`, the corrected `type` description, `file` dropped). Routes `databaseSource#schema` and `#testStatement` (admin only, `DatabaseSourceController`; the test run reads at most 50 rows and never runs a write statement), in `openapi.json`. Body widget `DatabaseSourcePanel` on SourceDetail: connection, read-only advice or the write warning, statements with Test the statement (first page and duration), Show tables and columns. en and nl strings.
- [ ] Test (Playwright)
  - Written: `tests/e2e/database-source.spec.ts` (the panel and an unreachable database on any instance; the zaken-sinds test run when `E2E_DB_HOST` names a database). Not run yet: needs a live instance. Vitest `databaseSourcePanel.spec.js` (8), `DatabaseSourceControllerTest` (5).

### Task 3: The database branch of the synchronization engine
- **spec_ref**: `openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-synchronization-reads-from-a-database-page-by-page-req-dbsf-002`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a synchronization with sourceType database and a keyset read statement WHEN it runs over 1,200 rows with a page of 500 THEN three pages are read and 1,200 objects are mapped
- [x] Implement
  - `getAllObjectsFromDatabase()`: `sourceConfig.statement`, `parameters`, `pageSize`; pages until a short page, a test run returns one row, a later failure keeps what was read and marks the fetch incomplete, a key that does not advance stops. `updateTargetDatabase()`: `targetConfig.statement`, `deleteStatement`, `idField`. The adapter is resolved from the container when a database synchronization first needs it.
- [ ] Test (PHPUnit with a fake adapter; one run against the PostgreSQL container)
  - PHPUnit with a fake adapter: `SynchronizationDatabaseBranchTest` (7, including 1,200 rows in three pages of 500). Not done: the run through the engine against the PostgreSQL container.

### Task 4: Category contract and seed
- **spec_ref**: `openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001`
- **files**: the connector manifest entry, `lib/Settings/integriq_seed_data.json`, `docs/`
- **acceptance_criteria**:
  - GIVEN the store page WHEN opened THEN the database adapter is listed with its capabilities
- [x] Implement
  - The store lists the adapter under Data infrastructure (`CatalogRegistryService` category override, test added). Seed: the dormant `example-postgres` with `zaken-sinds`. Docs: `docs/administrators/sources/database.md`. Not built: a `connectors[]` manifest entry; the manifest schema of @conduction/nextcloud-vue allows no such key (additionalProperties false), and S3 has none either.
- [ ] Test (the REQ-DIC-001 to REQ-DIC-006 checks of the category spec)
  - `DatabaseSourceDeclarationTest` (2), `CatalogRegistryServiceTest::testTheDatabaseAdapterIsListedUnderDataInfrastructure`. REQ-DIC-002's manifest entry is not checked, see above.

## Verification
- [ ] `openspec validate sources-database-adapter --type change --strict` passes
- [x] No TimedJob class added (REQ-DIC-005)
- [ ] PHPUnit and Playwright run, exit codes read
