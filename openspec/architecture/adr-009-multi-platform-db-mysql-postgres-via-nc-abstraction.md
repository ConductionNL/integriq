# ADR-009: All DB queries target MySQL/MariaDB AND PostgreSQL via Nextcloud's QueryBuilder, with known MySQL-only leaks

## Status
Accepted (capturing existing decision, with known violations)

## Date
2026-05-20

## Context

Nextcloud supports MySQL/MariaDB and PostgreSQL as database backends. All
openconnector `lib/Db/` mapper classes extend `QBMapper` and inject
`OCP\IDBConnection`, using `OCP\DB\QueryBuilder\IQueryBuilder` for
query construction. This is the correct cross-platform approach:
`IQueryBuilder` abstracts dialect differences, and `QBMapper` handles entity
mapping.

However, three files contain MySQL-specific raw SQL that is NOT portable to
PostgreSQL:

**1. `lib/Service/SettingsService.php` — multiple raw-SQL UPDATE/CHECK calls**

- Lines 148-149: `$countQuery = "SELECT COUNT(*) as total FROM {$tableName}"` —
  MySQL backtick-quoted table names; Postgres prefers double-quotes, though
  backticks fail on Postgres.
- Lines 335-336: `$checkQuery = "SHOW COLUMNS FROM ... LIKE 'expires'"` —
  MySQL-only DDL introspection (`SHOW COLUMNS` does not exist in Postgres).
- Lines 298, 317, 341, 361, 380, 399: bulk UPDATE with
  `DATE_ADD(created, INTERVAL ? MICROSECOND)` — `DATE_ADD` / `INTERVAL` is
  MySQL syntax; Postgres equivalent is `created + INTERVAL '...'` or
  `created + ($1 * INTERVAL '1 microsecond')`.

**2. `lib/Service/SearchService.php:267-278`** — `createMySQLSearchConditions()`
and `createMySQLSearchParams()` (lines 267, 306) — methods named after MySQL;
the LIKE condition `LOWER($field) LIKE :search` is SQL-standard and DOES work on
Postgres, but the method name falsely implies MySQL-only scope.

**3. `src/Mapper/JobLogMapper.php:12-16`** (orphaned PHP snippet, see ADR-006)
— raw `SELECT * FROM *PREFIX*openconnector_job_logs ... LIMIT ? OFFSET ?`
with MySQL backtick quoting. This file is dead code (no PHP namespace, never
imported) but documents the intent to write platform-specific queries.

**4. `lib/Db/CallLogMapper.php:122-130` and log mappers in general** — use
`$qb->createFunction('NOW()')` for timestamp comparisons; `NOW()` is available
on both MySQL and Postgres, but `createFunction()` bypasses type-safe QB
wrapping, making it harder to detect dialect regressions.

The `lib/Controller/HealthController.php:75,89` uses `$qb->executeQuery()` via
the IQueryBuilder, which is correctly platform-neutral.

## Decision

All new queries in `lib/Db/` MUST use `IQueryBuilder` / `QBMapper` exclusively.
Raw SQL strings are prohibited unless branched per platform using
`$this->db->getDatabasePlatform()` with explicit MySQL and Postgres paths.

For the three known MySQL-only leaks:

1. The `SHOW COLUMNS` check in `SettingsService.php:335-336` must be replaced
   with the Nextcloud `IDBConnection::getSchemaManager()` or a try/catch
   fallback.
2. The `DATE_ADD(…, INTERVAL ? MICROSECOND)` updates in `SettingsService.php`
   must be replaced with `IQueryBuilder`-native datetime arithmetic or
   branched per platform.
3. The backtick-quoted table names in raw SQL must use the `IDBConnection`
   prefix helper (`IDBConnection::tablePrefix()`) and standard SQL quoting.

These fixes are deferred to the in-flight `openconnector-adopt-or-abstractions`
cleanup or a dedicated "Postgres portability" ticket; they MUST be fixed before
official Postgres support is declared for openconnector.

The `SearchService` method names (`createMySQLSearchConditions`,
`createMySQLSearchParams`) are misleading but functional on both platforms;
they may be renamed during the D2 frontend rewrite (chain
`openconnector-frontend-vue-rewrite`).

## Consequences

- Running openconnector against a Postgres-backed Nextcloud will currently
  fail when `SettingsService::applyRetention()` is called (the `SHOW COLUMNS`
  and `DATE_ADD` queries will throw `\Doctrine\DBAL\Exception`).
- All new migration files (under `lib/Migration/`) MUST use the Nextcloud
  Schema API (`ISchemaWrapper`, `$schema->createTable()`), not raw `CREATE
  TABLE` SQL.
- Cross-reference: ADR-006 (`src/Mapper/JobLogMapper.php` is dead code) —
  the MySQL-only raw SQL there does not affect runtime but should be removed.
- Cross-reference: `openspec/changes/openconnector-register-storage/` — chain
  B introduced a dual-platform JSON-build path for OR object storage
  (`MySQL JSON_OBJECT vs Postgres jsonb_build_object`) as a downstream
  consequence of this constraint; openconnector's own mapper layer must reach
  the same level of portability.

## Evidence

- `lib/Service/SettingsService.php:148-149` — backtick-quoted table names in
  raw `executeQuery()` call.
- `lib/Service/SettingsService.php:335-336` — `SHOW COLUMNS … LIKE 'expires'`
  MySQL DDL introspection.
- `lib/Service/SettingsService.php:298, 317, 341, 361` — `DATE_ADD(created,
  INTERVAL ? MICROSECOND)` MySQL-only date arithmetic.
- `lib/Service/SearchService.php:267` — `createMySQLSearchConditions()` method
  signature naming MySQL in the API surface.
- `lib/Db/CallLogMapper.php:126` — `$qb->createFunction('NOW()')` bypasses
  type-safe QB; functional but fragile.
- `lib/Db/EndpointMapper.php:8-9,28` — correct pattern:
  `use OCP\DB\QueryBuilder\IQueryBuilder` + `IDBConnection $db` injected via
  constructor; no raw SQL.
