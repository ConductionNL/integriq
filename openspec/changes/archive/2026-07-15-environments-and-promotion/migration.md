# Migration: environments-and-promotion

## Current State
The `openconnector` OpenRegister register (`lib/Settings/openconnector_register.json`,
per `openconnector-register-schema`) declares 15 schemas (`source`,
`endpoint`, `mapping`, `rule`, `job`, `synchronization`, the 4 log schemas,
etc.) but has no `environment` or `promotion_audit` schema. There are no
`oc_openconnector_environment*` or `oc_openconnector_promotion*` SQL tables
— every OpenConnector entity is a generic OpenRegister object, not a
bespoke table, so this change adds NO new PostgreSQL tables or columns of
its own.

## Target State
Two new schemas exist inside the `openconnector` register:
- `environment` (mutable config schema): `name`, `slug`, `role`
  (`source`|`target`|`both`), `sourceRef` (UUID `$ref` → `source`),
  `description`.
- `promotion_audit` (append-only, immutable log schema, matching
  `call_log`/`job_log`'s `appendOnly: true`/`immutable: true` +
  `x-openregister-archival` retention convention): `actorUid`,
  `configurationId`, `fromEnvironmentSlug`, `toEnvironmentSlug`,
  `startedAt`, `completedAt`, `outcome`, `previewSummary`,
  `credentialRebindCount`, `callLogId`.

Both are added via a per-change register fragment
(`lib/Settings/register.d/environments-and-promotion.json`, an OpenAPI
`components.schemas` fragment), per ADR-037's "each change adds its own
`<change>.json` instead of editing `openconnector_register.json`" rule
(`lib/Settings/register.d/README.md`) — the same mechanism every other
recent OpenConnector schema addition uses (e.g.
`register.d/eudi-wallet-credential-issuance.json`).

## Migration Class
**This change does NOT introduce a Nextcloud `IMigrationStep`/`changeSchema()`
class.** OpenConnector's schema additions are NOT applied via the standard
Nextcloud DB-migration framework — verified against HEAD
(`lib/Repair/InitializeRegister.php`): the register descriptor + its
`register.d/*.json` fragments are imported into OpenRegister via OR's own
`ConfigurationService::importFromApp()`, invoked by the `InitializeRegister`
`IRepairStep` wired in `appinfo/info.xml` under both `<install>` and
`<post-migration>`. This exists specifically because a `postSchemaChange()`
Nextcloud migration runs before peer apps' (OpenRegister's) autoloaders are
guaranteed available on a fresh `occ app:enable`, whereas an `IRepairStep`
runs after all enabled apps are bootstrapped.

```
No Version*.php migration class. Schema delivery mechanism:
File: lib/Settings/register.d/environments-and-promotion.json  (new)
Repair step: lib/Repair/InitializeRegister.php (existing, unmodified — already
  wired to import every register.d/*.json fragment; requires no code change,
  only the new fragment file)
Idempotency: OR's importFromApp() short-circuits on the descriptor's `version`
  field, exactly as it does today for the other 15 schemas.
```

## Migration Steps
1. Add `lib/Settings/register.d/environments-and-promotion.json` declaring
   the `environment` and `promotion_audit` schemas (OpenAPI
   `components.schemas` + `x-openregister` annotations), following the
   existing fragment format (see any file already under `register.d/`).
2. No change to `InitializeRegister.php` — it already merges every
   `register.d/*.json` fragment into the register descriptor before calling
   `importFromApp()`.
3. On `occ app:enable openconnector` (fresh install) or `occ upgrade`
   (existing install), the repair step runs automatically and creates the
   two new schemas inside the existing `openconnector` register — no
   separate register is created.
4. Seed data (design.md's Seed Data section: two `environment` objects,
   `local` and `acceptance`, each referencing a seeded `source` object) is
   created the same way existing seed objects are — via the app's existing
   seed-loading path (`lib/sources.seed.json`-style convention), not a
   migration step.

## Data Impact
Zero rows affected on existing schemas — this migration is purely additive
(two new, initially-empty schemas). No existing `source`, `endpoint`,
`mapping`, `rule`, `job`, or `synchronization` object is read, written, or
reshaped. Safe to run on live data; `promotion_audit` and `environment`
start empty (aside from seed data) on every install.

## Rollback Procedure
Remove `lib/Settings/register.d/environments-and-promotion.json` and
redeploy. The two schemas remain declared inside OpenRegister (OR does not
retroactively delete schemas on a descriptor rollback) but become
unreachable from the OpenConnector UI/API once the routes and controller
are also rolled back (this change is deployed as one unit — see
proposal.md's Rollback Strategy). No SQL rollback is needed since no SQL
schema changed.

## Validation
- `occ app:enable openconnector` on a fresh instance completes without
  error and `InitializeRegister`'s repair-step log line reports the
  `environment` and `promotion_audit` schemas among the imported set.
- `GET /api/environments` (new) returns the two seeded environments
  (`local`, `acceptance`) with `HTTP 200` on a fresh install.
- Creating a `promotion_audit` object directly via the OpenRegister object
  API and then attempting to `PUT`/`DELETE` it fails with OR's
  `appendOnly`/`immutable` enforcement, identically to an existing
  `call_log` object.
