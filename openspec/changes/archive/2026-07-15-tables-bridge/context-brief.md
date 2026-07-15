# Context Brief: tables-bridge
Source: Specter deep-research 2026-07-14 (insight #1252). VERIFY every code claim against HEAD before writing artifacts.

## Problem / Opportunity
Nextcloud Tables has a thin OCS v2 row API, CSV import, and row-change webhook events — but NO scheduled imports and NO external-source sync. Community demand documented (tables#2237 API ergonomics complaints; community n8n-nodes-nextcloud-tables exists). For generic (non-gov) Nextcloud admins, "sync an external API into a Table / push Table rows to an external system" is the most-requested automation. No store app does it.

## Current state (verify at HEAD)
- Synchronization targets: OR register/schema, another Source, file path (verify target-type handling in SynchronizationService).
- Source types: HTTP-family via CallService.
- Tables app: OCS API /ocs/v2.php/apps/tables/... (rows, columns, tables); events OCP\Tables events or webhook_listeners entries (verify what's available in NC 28-34 as PHP API; the Tables app exposes a PHP API via OCA\Tables\Api or OCS only — feature-detect).

## In scope
1. Tables as sync TARGET: new target type "nextcloud-table" — map source fields to table columns (reuse Mapping), create/update/delete rows via Tables API honoring SynchronizationContracts (originId ↔ rowId), column-type coercion (text/number/datetime/select).
2. Tables as sync SOURCE: read rows (paginated) as sync input; hash-based change detection as with other sources.
3. Table picker UI in the synchronization editor (list tables/views the sync-owner user can access); column-mapping helper prefilled from table schema.
4. Permission model: syncs run as configured user context — Tables ACLs must be respected; document the identity used (relates to #1006 identity hygiene).
5. Feature detection: Tables app absent → target type hidden; soft dependency only.
6. Tests: unit for column coercion/contract mapping; integration against Tables app in the dev container (Tables installed in CI image? verify — else mock the OCS client behind an interface).
## Out of scope
- Tables row-change events as triggers (that's nextcloud-event-hub).
- Nextcloud Forms (event-hub covers submissions as trigger; Forms has no write API).

## Constraints
- Do NOT reimplement OR capabilities (openconnector-direct-or-usage); Tables is an additional external-ish target accessed via its public API.
- Specs: new capability spec tables-bridge + deltas to synchronization-engine (target types), sync-editor-ui.
