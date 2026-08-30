# Migration: openconnector-register-schema-declaration

## Current State

OpenConnector ships no register descriptor. Its data model is encoded
implicitly across 15 entity classes in `lib/Db/*.php` and persisted in 15
`oc_openconnector_*` tables managed by hand-rolled mappers. No OR-side
provisioning happens at install/upgrade.

```
oc_openconnector_sources                  oc_openconnector_synchronizations
oc_openconnector_consumers                oc_openconnector_synchronization_contracts
oc_openconnector_endpoints                oc_openconnector_call_logs           (append-only by convention only)
oc_openconnector_events                   oc_openconnector_job_logs            (append-only by convention only)
oc_openconnector_event_messages           oc_openconnector_synchronization_logs (append-only by convention only)
oc_openconnector_event_subscriptions      oc_openconnector_synchronization_contract_logs (append-only by convention only)
oc_openconnector_jobs
oc_openconnector_mappings
oc_openconnector_rules
```

`oc_openregister_*` tables are untouched by openconnector. The
`oc_openregister_registers` table contains no row with `slug='openconnector'`.

## Target State

Two new descriptor files exist on disk:

```
openconnector/lib/Settings/openconnector_register.json    (~80 KB, 15 schemas)
openconnector/lib/Settings/openconnector_seed_data.json   (~15 KB, ~33 seed objects)
```

The legacy `oc_openconnector_*` tables remain untouched (the storage chain
handles them). The `oc_openregister_*` tables are also untouched **by this
change** — the descriptor lives dormant on disk until the storage chain's
migration class invokes `ConfigurationService::importFromApp(...)`.

## Migration Class

**No openconnector migration class is introduced by this change.**

This is intentional: shipping a migration class without the storage chain in
place would either (a) provision a schema with no data path to populate it
(harmless but confusing), or (b) couple the descriptor's release to the
storage chain's release (defeats the ADR-032 split).

The migration class that consumes this descriptor (`Version2Date20260520xxxxxx`
or similar) is introduced by `openconnector-register-storage`. The contract
between this change and that one is:

```
Version: <handled by openconnector-register-storage>
File:    <handled by openconnector-register-storage>
Reads:   openconnector/lib/Settings/openconnector_register.json   (added here)
Reads:   openconnector/lib/Settings/openconnector_seed_data.json  (added here)
Calls:   ConfigurationService::importFromApp(
           appId:        'openconnector',
           registerJson: <absolute path to openconnector_register.json>,
           version:      <app version>,
           force:        false,
         )
```

## Migration Steps

This change is purely additive at the file-system level. No DB step runs as
part of this change:

1. **Add file** `openconnector/lib/Settings/openconnector_register.json`.
2. **Add file** `openconnector/lib/Settings/openconnector_seed_data.json`.
3. **Validate** by running `php -r "json_decode(file_get_contents('lib/Settings/openconnector_register.json'), true) === null && exit(1);"` in CI.
4. **Validate** by running OR's `validate-manifest.js` (if present in dev tools)
   against the descriptor.
5. **Smoke test** in dev environment: call
   `ConfigurationService::importFromApp(...)` manually via `occ` and confirm
   `oc_openregister_schemas` gains 15 new rows.

The dev-environment smoke test in step 5 is **NOT** wired up as a real
migration in this change. It is a manual verification performed during apply.

## Data Impact

- **Records modified by this change:** 0 (zero). No DB writes occur.
- **Records modified by the consuming storage chain:** 1 register row + 15
  schema rows + ~33 seed object rows on first import. Subsequent imports update
  the schema metadata in place (idempotent).
- **Data loss risk:** None. Existing `oc_openconnector_*` data is untouched.
- **Live-data compatibility:** Safe to ship on a live deployment — the
  descriptor sits dormant on disk until the storage chain merges.

## Rollback Procedure

Two-step rollback, simplest first:

1. **Git revert the commit** that added the two JSON files. Re-deploy. The
   descriptor disappears; openconnector continues running unchanged because no
   code reads it (yet). This is the canonical rollback.

2. **If the storage chain has already merged AND imported the descriptor:**
   - First revert the storage chain (its own rollback procedure).
   - Then delete the descriptor from disk.
   - Optionally: drop the orphaned `oc_openregister_*` rows for register
     `openconnector` via SQL: `DELETE FROM oc_openregister_objects WHERE
     register IN (SELECT id FROM oc_openregister_registers WHERE
     slug='openconnector'); DELETE FROM oc_openregister_schemas WHERE register IN
     (SELECT id FROM oc_openregister_registers WHERE slug='openconnector');
     DELETE FROM oc_openregister_registers WHERE slug='openconnector';`

## Validation

| Check                                            | Method                                                                        | Expected result                |
|--------------------------------------------------|-------------------------------------------------------------------------------|--------------------------------|
| Descriptor file is valid JSON                    | `php -r "json_decode(file_get_contents(...), true);"`                         | No PHP warning, return code 0  |
| Descriptor is valid OpenAPI 3.0                  | `npx openapi-cli lint lib/Settings/openconnector_register.json`               | Zero errors                    |
| All 15 schemas declared                          | `jq '.components.schemas | keys | length' lib/Settings/openconnector_register.json` | `15`                           |
| Log schemas marked append-only + immutable       | `jq '.components.schemas | with_entries(select(.value.appendOnly == true)) | keys' …` | The 4 log slugs only           |
| FK relations resolve                             | OR dev-env import + `SELECT count(*) FROM oc_openregister_schemas WHERE register IN (…)` | 15 rows                        |
| Seed file valid JSON                             | `php -r "json_decode(file_get_contents(...), true);"`                         | No PHP warning                 |
| Seed file contains no log entries                | `jq 'keys' lib/Settings/openconnector_seed_data.json`                         | None of the 4 log slugs appear |
| Seed credentials are placeholders                | `grep -E '"(apikey|password|secret|jwt)":' lib/Settings/openconnector_seed_data.json` | All values match safe placeholders |
