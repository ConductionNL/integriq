# Discovery: mariadb-compatibility

## Question

Why do OpenConnector/OpenRegister operations fail on MariaDB/MySQL (primarily
around datetimes) when the same code works on PostgreSQL, and where exactly do
the failures originate — so a targeted fix can be scoped rather than a blind
find-and-replace of date formatting?

## Approach Taken

- Traced datetime handling across both apps (`DateTimeNormalizer`, `MagicMapper`,
  `SynchronizationLogService`, the JSON-schema `format: date-time` declarations).
- Inspected a **live MariaDB 10.6 instance** (`master-database-mysql-1`, the
  `nextcloud` schema, 164 tables) with OpenRegister + OpenConnector installed and
  migrated.
- Confirmed the real storage model by querying `information_schema` for the
  OpenRegister **magic tables** rather than the deprecated `oc_openregister_objects`
  blob table.

## Findings

### 1. Storage model — per-schema "magic tables" with real DATETIME columns
OpenRegister stores objects in per-register/schema **magic tables**
(`oc_openregister_table_<registerId>_<schemaId>`, prefix `openregister_table_`,
`MagicMapper` line 164), **not** the deprecated `oc_openregister_objects` blob.
Each magic table has metadata columns `_created`, `_updated`, `_expires` **and a
physical `datetime` column per schema property declared `format: date-time`/`date`**.
Observed live, e.g.:

| Magic table | date-time property columns (besides `_created/_updated/_expires`) |
|-------------|-------------------------------------------------------------------|
| `oc_openregister_table_1_1` | `last_call`, `last_sync`, `date_created`, `date_modified` |
| `oc_openregister_table_2_17` | `notification_sent_at`, `objection_deadline`, `objection_received_at`, `valid_from`, `valid_until` |
| `oc_openregister_table_5_27` | `anonymized_at` |
| `oc_openregister_table_6_26` | `checked_on` |

So a `format: date-time` property is a genuine MySQL `DATETIME` column — which
accepts only `Y-m-d H:i:s`, never the ISO8601 `T`+offset form.

### 2. There are no OpenConnector `synchroniz*` DATETIME tables
The live DB has **no** synchronization-named tables. Synchronizations, logs, and
contracts are OpenRegister objects (magic tables), so the legacy OpenConnector
migration `Version1Date20250109093325.php` (`Types::DATETIME` +
`'default' => 'CURRENT_TIMESTAMP'`) appears to be **dead code post-retrofit** —
lower priority, but worth a cleanup pass, and a MariaDB-hostile pattern if ever
re-activated.

### 3. The normalizer offers BOTH formats
`OCA\OpenRegister\Service\DateTimeNormalizer` exposes:
- `formatForDatabase()` → `Y-m-d H:i:s` (`DATABASE_FORMAT`, MariaDB-compatible).
- `formatForIso8601()` → `DateTimeInterface::ATOM` (ISO8601 with offset).

### 4. The WRITE path casts date-time values down to `Y-m-d H:i:s`
`MagicMapper` casts on write to fit the DATETIME columns:
- Metadata `created/updated/expires`: line 3058-3072 → `Y-m-d H:i:s`.
- **Schema properties with `format: date-time`/`date`: line 3192-3202** — the
  comment reads *"Normalise date/date-time properties to Y-m-d H:i:s for MySQL
  DATETIME columns."* An incoming ISO8601 value (e.g. from
  `SynchronizationLogService.php:139` `(new DateTime('+1 hour'))->format('c')`
  for `expires`) is normalised to `Y-m-d H:i:s`. **Correct for storage.**

### 5. Root-cause hypothesis — asymmetric round-trip (write casts down, read/validate expects ISO8601)
Schemas declare `format: date-time` (ISO8601) — e.g.
`lib/Settings/ori_register.json` lines 77/82. A MySQL `DATETIME` column returns
`Y-m-d H:i:s` on read. The read/hydrate path in `MagicMapper` /
`ObjectHandlers` shows **no re-normalisation back to ISO8601** (grep for
`formatForIso8601`/`ATOM`/hydrate in the read path returned nothing). So the
value round-trips as `Y-m-d H:i:s` and then **fails the schema's `format:
date-time` (ISO8601) validation** on serialization / the next save/update.
PostgreSQL does not surface this (its timestamp handling / driver returns a form
the validator tolerates, or the cast path differs), which is exactly why the
failure is MariaDB-specific — matching the reported symptom.

This is the "validations enforce the `c` format after everything is cast to
MariaDB-compatible format" conflict.

## Recommendation

Fix in **OpenRegister** (consistent with the mandate to fix DB-compat issues even
when they live in OpenRegister): make datetime handling **symmetric**. Store
`Y-m-d H:i:s` in the DATETIME columns (unchanged), but the **read/serialize path
MUST re-normalise date-time property (and metadata) values back to ISO8601
(`formatForIso8601`)** before the object is validated or returned, so the value
always satisfies the declared `format: date-time`. This is DB-agnostic and closes
the round-trip. (Alternative — relax the validator to accept `Y-m-d H:i:s` — is
worse: it weakens the schema contract for every consumer.)

Do **not** attempt a blanket `format('c')` search-and-replace: ~34 occurrences in
OpenConnector and ~169 in OpenRegister are mostly **correct** (JSON payloads,
CloudEvents, API responses where ISO8601 is right). Only the values that flow
through DATETIME columns + `format: date-time` validation are in scope.

## Risks Uncovered

- **Timezone loss.** `Y-m-d H:i:s` drops the offset. Re-normalising to ISO8601 on
  read must assume the stored instant's timezone (UTC?) or times will shift. Must
  confirm what tz the write path assumes.
- **Validator call site not yet pinned.** The exact code that rejects
  `Y-m-d H:i:s` against `format: date-time` (opis/json-schema format assertion)
  was not located to a line — confirm before the fix.
- **Scope creep.** The correct-usage `format('c')` calls must be left alone; the
  fix must target the OR read/write normalisation seam only.

## Broader access-point audit (beyond datetime)

Swept both codebases for the full class of MySQL/MariaDB-hostile patterns.
Headline: the code is **systematically dialect-aware** and OpenConnector keeps
**no local tables**, which sharply narrows the real risk surface.

### Architecture facts confirmed on the live instance
- OpenConnector and OpenRegister are both **enabled**.
- OpenConnector stores **everything** in OpenRegister magic tables (register 1 =
  "OpenConnector Register", data in `oc_openregister_table_1_1`). There are **no
  `oc_openconnector*` tables** in the DB.
- Migrations **succeeded on MariaDB 10.6** (164 tables, incl. magic tables), so
  no DDL-level landmine blocks install.

### Platform detection is consistent and safe for MariaDB
Both apps detect the platform via DBAL `getDatabasePlatform()` +
`instanceof PostgreSQLPlatform` / `stripos('PostgreSQL')`, always shaped as
`if (isPostgres) { …PG SQL… } else { …MySQL/MariaDB SQL… }`
(`RegisterService:573`, `ReferentialIntegrityService:380`, `SettingsService`,
`BlobMigrationJob:289`, `LegacyToRegisterMigrator:377`). Postgres-only operators
(`::jsonb`, `@>`, `jsonb_set`, `to_jsonb`, `~`) are all **guarded** — MariaDB
takes the else branch. No unguarded Postgres-isms were found.

### Risk register

| # | Area | Location | MariaDB severity | Notes |
|---|------|----------|------------------|-------|
| 1 | **Datetime round-trip** | `MagicMapper:3192`, read path | **HIGH (confirmed)** | The primary bug — see findings above. |
| 2 | Aggregation/statistics SQL | `AggregationRunner` (jsonb vs MySQL vs SQLite `strftime`, `SUM(NULLIF(::text,'')::numeric)`) | **MEDIUM — runtime test** | Most complex dialect-branched code; the MySQL branch is hand-written and less exercised than PG. Test aggregations on MariaDB. |
| 3 | Relation / containment filters | `MagicMapper:6391/6495` (`JSON_SEARCH` MySQL vs `@>` PG) | **MEDIUM — runtime test** | Filtering objects by relation/array membership; verify the `JSON_SEARCH` branch returns correct matches on MariaDB. |
| 4 | Referential-integrity checks | `ReferentialIntegrityService:902` (`JSON_CONTAINS(col, JSON_QUOTE(?))`) | **MEDIUM — runtime test** | Cascade/reference checks on delete; exercise a delete of a referenced object on MariaDB. |
| 5 | Read-path inconsistency | `MagicStatisticsHandler:594` uses `formatForIso8601` on read, but the object read path does **not** | **MEDIUM (evidence)** | Statistics already re-normalises to ISO8601 — proves the object read path is the one missing it. The datetime fix should mirror this. |
| 6 | OpenConnector local migration DATETIME + `CURRENT_TIMESTAMP` defaults | `Version1Date20250109093325.php`, `Version1Date20250118124025.php` | **LOW / moot** | Those tables do not exist (data is in OR magic tables). Dead code — cleanup candidate, not a live bug. |
| 7 | OpenRegister `CURRENT_TIMESTAMP` string defaults | `Version1Date20260106000000.php:227/236` | **LOW** | Migrations installed fine on 10.6; possible literal-vs-function default subtlety worth a spot check, not a blocker. |
| 8 | Hardcoded MySQL-only SQL | `SourcesController:164` (`JSON_EXTRACT`), OR `MetricsService` (`FROM_UNIXTIME`) | **NONE on MariaDB** | These are MySQL-native → safe on MariaDB (they are a *Postgres*-breakage risk, out of scope here). |
| 9 | `LegacyToRegisterMigrator` cross-dialect JSON SQL | `openconnector/.../LegacyToRegisterMigrator.php:857` | **LOW** | Guarded `pgsql` vs MySQL branches; operates on the deprecated `oc_openregister_objects` blob — one-shot legacy migration, verify only if still run. |

### Audit conclusion
MariaDB compatibility is in far better shape than the symptom suggested — the
dialect branching is real and complete. The **one confirmed defect is the
datetime round-trip (#1)**. Items #2–#5 are not known failures but hand-written
MySQL branches that deserve **runtime verification on MariaDB** rather than
code-reading (aggregations, relation filters, referential integrity). #6–#9 are
low/moot.

## Next Steps

1. **Live repro**: create/update an object with a `format: date-time` property on
   MariaDB via the API, capture the exact validation error in `nextcloud.log`, and
   read the stored + returned value to confirm the `Y-m-d H:i:s`-vs-ISO8601 break.
2. **Pin two sites**: the read/hydrate path (confirm no ISO8601 re-normalisation)
   and the validator format assertion that rejects `Y-m-d H:i:s`.
3. **Open a change** `openregister-datetime-roundtrip` (`kind: code`, OpenRegister)
   to add symmetric ISO8601 re-normalisation on read/serialize, with tests that a
   `format: date-time` property survives a MariaDB save→read→re-save round-trip.
4. Optional follow-up: remove/repair the dead OpenConnector `CURRENT_TIMESTAMP`
   DATETIME migration if confirmed unused.