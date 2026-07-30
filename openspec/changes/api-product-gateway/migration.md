# Migration: api-product-gateway

## Current State

`openconnector_register.json` (loaded via `ConfigurationService::importFromApp`,
`openconnector-storage-migration`) declares 15 core schemas, including
`call_log` with `uuid`, `statusCode`, `statusMessage`, `direction`
(`inbound`|`outbound`), `request`, `response`, `sourceId`/`source`,
`actionId`, `synchronizationId`/`synchronization`, `userId`, `sessionId`,
`expires`, `created`, `size`. There is no `api_product` or
`api_product_subscription` schema. `call_log` carries no `product`,
`endpoint`, or top-level `responseTime` field. `lib/Settings/register.d/`
holds one fragment per prior change (`hitl-approval-rule-action.json`,
`99-source-secrets-writeonly.json`, etc.), merged at load per ADR-037.

## Target State

- New register.d fragment `lib/Settings/register.d/api-product-gateway.json`
  declaring:
  - `api_product` schema (new).
  - `api_product_subscription` schema (new).
  - A deep-merge onto the existing `call_log` schema's `properties` adding
    `product` (uuid FK → `api_product`, `onDelete: SET_NULL`), `endpoint`
    (uuid FK → `endpoint`, `onDelete: SET_NULL`), and `responseTime`
    (integer, milliseconds).
- No existing `call_log` row is touched — the three new fields are simply
  absent (`null`/undefined) on every row written before this change deploys;
  `endpoint-runtime` `REQ-EP-009`'s new inbound logging only affects rows
  written *after* deploy, for endpoints attached to an `api_product` (which
  cannot exist before this change either, since the schema is new).

## Migration Class

```
Version: N/A — no PHP Nextcloud migration class needed.
File: none.
Key operations:
- Register fragments are merged into the in-memory register definition at
  application boot / `ConfigurationService::importFromApp()` time (see
  openconnector-storage-migration#REQ — "Migration class MUST provision the
  register via importFromApp"), which already re-runs on every app upgrade
  and is idempotent. Adding a new register.d/*.json file requires no new
  Version*.php migration class — the existing migration class that calls
  importFromApp picks it up automatically on next `occ upgrade` /
  `occ app:update openconnector`.
```

No `Version*.php` class is added by this change. The existing storage
migration's `postSchemaChange` hook already re-imports the full merged
register (base + all `register.d/*.json` fragments) on every upgrade,
per `openconnector-storage-migration` "Migration class MUST provision the
register via importFromApp" — idempotent by design (re-running is a no-op
for schemas/fields that already exist, additive for new ones).

## Migration Steps

1. Add `lib/Settings/register.d/api-product-gateway.json` with the two new
   schema declarations and the `call_log` deep-merge block (verifiable:
   `git diff` shows only new-file addition + no edits to
   `openconnector_register.json`).
2. Deploy the app version carrying the fragment; on `occ app:update
   openconnector` (or fresh `occ app:enable`), the existing migration class
   re-runs `ConfigurationService::importFromApp()`, which merges the
   fragment into the live OpenRegister register/schema tables (verifiable:
   `oc_openregister_schemas` gains 2 rows for `api_product` and
   `api_product_subscription`; the existing `call_log` schema row's
   `properties` JSON gains the 3 new keys, in place — no new row).
3. No data backfill is needed or attempted — existing `call_log` rows simply
   have the 3 new fields absent; nothing reads them as required (all three
   are optional/nullable and every read site added by this change
   null-coalesces).

## Data Impact

- **Records affected:** 0 existing rows are modified. The 2 new schemas
  start empty. The `call_log` schema **definition** row gains 3 optional
  properties; existing `call_log` **object** rows are untouched (their
  `object` JSON blobs are not rewritten — OpenRegister schemas are
  additive-by-default for optional properties, no `NOT NULL` backfill
  required).
- **Data loss:** none.
- **Live-data safe:** yes — purely additive schema changes; no column type
  changes, no destructive DDL. Import runs within the existing idempotent
  `importFromApp()` call already exercised on every upgrade.

## Rollback Procedure

1. Remove `lib/Settings/register.d/api-product-gateway.json` and redeploy
   the prior app version.
2. On the next `importFromApp()` run, the 2 new schemas remain registered
   (OpenRegister does not auto-drop schemas absent from a re-import by
   default) but become unreferenced/orphaned — acceptable for a rollback,
   since no other code path depends on their absence. If a clean rollback of
   the schema rows themselves is required, an operator runs OpenRegister's
   existing schema-deletion tooling manually against `api_product` and
   `api_product_subscription` (out of band — this change does not ship an
   automated schema-deletion step, consistent with every other register.d
   fragment in this app, none of which ship a reverse migration either).
3. The `call_log` deep-merged properties (`product`, `endpoint`,
   `responseTime`) similarly remain on the schema definition but stop being
   written to once the code that populates them (`EndpointService`'s new
   logging) is reverted — no functional impact, since they were
   optional/nullable throughout.

## Validation

- `SELECT COUNT(*) FROM oc_openregister_schemas WHERE title IN ('Api
  Product', 'Api Product Subscription');` → expect `2` after deploy.
- `SELECT properties::jsonb ? 'responseTime' FROM oc_openregister_schemas
  WHERE slug = 'call_log';` (Postgres) → expect `true` after deploy.
- Re-run `occ app:update openconnector` a second time → expect no error, no
  duplicate schema rows (idempotency, per
  openconnector-storage-migration's "Idempotent re-run" scenario).
- Create one `api_product` and one `api_product_subscription` via the OR
  generic object API → expect both persist and are retrievable, confirming
  the fragment merged correctly.
