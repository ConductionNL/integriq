# Tasks: sources-database-adapter

Kind: code. Size M. Row `integriq:src-database`.

## Implementation tasks

### Task 1: The database adapter
- **spec_ref**: `openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001`
- **files**: `lib/Service/Adapter/DataInfra/DatabaseAdapter.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a source with a read statement WHEN the adapter runs it with a parameter THEN the rows come back and the statement's SQL was bound, not concatenated
  - GIVEN an undeclared statement name WHEN called THEN it is refused before any connection is opened
- [ ] Implement
- [ ] Test (PHPUnit against PostgreSQL and MariaDB containers in the dev compose; a missing driver reported by health)

### Task 2: Statements and schema discovery on the source page
- **spec_ref**: `openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001`
- **files**: `lib/Settings/integriq_register.json` (source `statements`, `database` connection fields, corrected `type` description), the source detail page, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator on a database source WHEN they open schema discovery THEN tables and columns are listed, and a test run of a statement shows the first page and its duration
- [ ] Implement
- [ ] Test (Playwright)

### Task 3: The database branch of the synchronization engine
- **spec_ref**: `openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-synchronization-reads-from-a-database-page-by-page-req-dbsf-002`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a synchronization with sourceType database and a keyset read statement WHEN it runs over 1,200 rows with a page of 500 THEN three pages are read and 1,200 objects are mapped
- [ ] Implement
- [ ] Test (PHPUnit with a fake adapter; one run against the PostgreSQL container)

### Task 4: Category contract and seed
- **spec_ref**: `openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001`
- **files**: the connector manifest entry, `lib/Settings/integriq_seed_data.json`, `docs/`
- **acceptance_criteria**:
  - GIVEN the store page WHEN opened THEN the database adapter is listed with its capabilities
- [ ] Implement
- [ ] Test (the REQ-DIC-001 to REQ-DIC-006 checks of the category spec)

## Verification
- [ ] `openspec validate sources-database-adapter --type change --strict` passes
- [ ] No TimedJob class added (REQ-DIC-005)
- [ ] PHPUnit and Playwright run, exit codes read
