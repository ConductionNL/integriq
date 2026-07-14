# Proposal: tables-bridge

## Summary

Adds Nextcloud Tables as a first-class synchronization source and target
(`sourceType`/`targetType: nextcloud-table`) so OpenConnector can pull rows
from a Table into the sync pipeline and push mapped external data into a
Table's rows, using the same Source → Synchronization → SynchronizationContract
triad and origin/target-id + hash change detection already used for every
other source/target kind. The Table is accessed exclusively through its
public API (verified: the mature `index.php/apps/tables/api/1/*` REST
surface, not the incomplete `ocs/v2.php/apps/tables/api/2/*` surface, which
has no authenticated single-row GET/PUT/DELETE — see design.md) behind a new
`TablesClientInterface`, feature-detected via `IAppManager` so the target
type is hidden when the Tables app is absent or disabled.

## Motivation

Nextcloud Tables has a thin row API, CSV import, and row-change webhooks —
but no scheduled import from an external system and no way to push synced
data into a Table. Community demand is documented (nextcloud/tables#2237:
API ergonomics complaints on the row-write shape; the unofficial
`n8n-nodes-nextcloud-tables` community package exists precisely to fill this
gap). For generic (non-government) Nextcloud admins running OpenConnector,
"sync an external API into a Table" / "push Table rows to an external
system" is a frequently-requested, currently unsupported automation. No
Nextcloud Store app currently provides it, and OpenConnector already owns
every piece of machinery this needs (mapping, contracts, hash-based change
detection, credentialed HTTP dispatch) except the Tables-specific read/write
adapter and editor UI.

## Affected Projects

- [ ] Project: `openconnector` — new `TablesClientInterface` +
  `TablesOcsClient` (naming retained for continuity with the brief;
  implementation targets the versioned REST API, see design.md) adapter
  classes; `SynchronizationService` gains `nextcloud-table` source/target
  branches; `Synchronizations` REST surface gains table/column discovery
  endpoints for the editor; `sync-editor-ui` Vue components gain a table
  picker and column-mapping helper; capability/feature-detection wiring via
  `IAppManager`.

## Scope

### In Scope

1. Tables as sync **target**: new target type `nextcloud-table` — map
   source fields to table columns (reusing the existing `MappingService`),
   create/update/delete rows via the Tables API honoring
   `SynchronizationContract` semantics (`originId` ↔ row id), with
   column-type coercion for text/number/datetime/selection columns.
2. Tables as sync **source**: read rows (paginated) as sync input, using the
   same hash-based change detection (`hashObject()`/`sortNestedArray()`) as
   every other source type.
3. Table picker + column-mapping helper in the synchronization editor UI:
   list tables the sync-owner identity can access, list a table's columns
   with type/subtype to prefill the mapping helper.
4. Permission model: syncs run under the identity configured on the
   underlying `Source` (Basic Auth / brokered credential per
   `source-broker-credentials`); Tables' own ACLs are the sole
   authorization authority — OpenConnector never re-implements them.
5. Feature detection: when the Tables app is absent or disabled,
   `nextcloud-table` is hidden from the source/target type pickers and
   rejected server-side with a clear config error if selected anyway.
6. Tests: unit coverage for column coercion, contract id mapping, and the
   OCS-client adapter against a stubbed `TablesClientInterface`; integration
   coverage is best-effort and MUST fall back to the stub when the Tables
   app is not present in the CI image (verify in tasks.md).

### Out of Scope

- Tables row-change events as synchronization **triggers** — that is
  `nextcloud-event-hub` (a parallel change).
- Nextcloud Forms integration (unrelated app; `nextcloud-event-hub` covers
  form submissions as a trigger, Forms has no write API).
- Auto-creating new columns/tables from a synchronization — this change only
  writes into columns that already exist; column *creation* is a possible
  follow-up.
- The synchronization-engine's deletion-ratio guard and fetch-failure
  incomplete-run guard themselves — those are specced by the parallel
  `sync-safety-guardrails` change. `nextcloud-table` deletions compose with
  whatever guard that change lands; this change does not duplicate or
  bypass it (see design.md and the synchronization-engine delta).
- Nextcloud Tables OCS v2 `contexts`/`favorites`/`shares` management — out
  of scope; this change only reads/writes rows and reads table/column
  metadata.

## Approach

Model Tables access as an ordinary `Source` (its `location` is the base URL
of the Nextcloud instance hosting the Table — same instance or, since
Tables' API is plain HTTP+Basic-Auth, potentially a remote federated one —
and its `authentication` is a normal Basic Auth / `credentialRef` config
exactly like any other HTTP source). The *new* piece is a
`nextcloud-table`-specific adapter layer that speaks the Tables REST dialect
on top of the existing `CallService::call()` primitive (so CallLog,
redaction, rate-limit, and credential-broker behavior are inherited for
free, per the `openconnector-direct-or-usage` / `source-broker-credentials`
constraints — no new HTTP client, no new secret storage). `targetConfig`
gains `tableId` (+ optional `viewId`); `sourceConfig` gains the same plus
pagination. See design.md for the full interface shape and the v1-vs-v2 API
decision.

## New Dependencies

None. No new Composer packages — the Tables API is consumed as plain HTTP
via the existing Guzzle-backed `CallService`. The Tables app itself is a
soft (optional, feature-detected) runtime dependency, not a Composer/build
dependency.

## Impact

- `lib/Service/SynchronizationService.php`: `getAllObjectsFromSource()`,
  `updateTarget()`, and `deleteInvalidObjects()` gain a `nextcloud-table`
  branch (currently these `switch` statements only know
  `register/schema`/`api`/`database`).
- New `lib/Service/Tables/TablesClientInterface.php` +
  `lib/Service/Tables/TablesOcsClient.php` (naming per design.md) +
  a `TablesSyncAdapter` service used by `SynchronizationService`.
- `lib/Controller/SynchronizationsController.php` (or a new controller):
  new read-only discovery endpoints for the editor's table picker / column
  list.
- `src/` (Vue): `SyncConfigWidget.vue` gains a `nextcloud-table`
  source/target kind branch; a new table-picker + column-mapping component.
- `openspec/specs/synchronization-engine/spec.md`,
  `openspec/specs/sync-editor-ui/spec.md`: deltas (this change).
- New capability spec `openspec/specs/tables-bridge/spec.md`.

## Cross-Project Dependencies

- **sync-safety-guardrails** (parallel change, same repo): the deletion-ratio
  guard and fetch-failure/incomplete-run guard it specs against
  `deleteInvalidObjects()` MUST apply uniformly to `nextcloud-table` targets
  once both changes land. This change writes its deletion scenario to
  compose with, not duplicate, that guard.
- **source-broker-credentials** (parallel change, same repo): recommended
  (not required) identity mechanism for the underlying `Source`'s
  authentication — a plain embedded Basic Auth credential also works.
- **nextcloud-event-hub** (parallel change, same repo): owns Tables
  row-change events as synchronization triggers; explicitly out of scope
  here to avoid overlap.
- No other apps in apps-extra are affected; this is fully internal to
  `openconnector`.

## Risks

### Risk 1: Tables' OCS v2 row API is incomplete (no authenticated GET/PUT/DELETE on a single row)

**Severity:** Medium — **Mitigation:** verified against the Tables app's
published OpenAPI schema (nextcloud/tables `openapi.json`, 2026-07): the
`ocs/v2.php/apps/tables/api/2/{nodeCollection}/{nodeId}/rows` route only
exposes `POST`; per-row `GET`/`PUT`/`DELETE` only exist under the
`api/2/public/{token}/rows/{rowId}` (share-token) path. The mature
`index.php/apps/tables/api/1/*` surface has full row/table/column CRUD and
is used instead (design.md). Both surfaces are wrapped behind
`TablesClientInterface` so a future, more-complete v2 can be swapped in
without touching `SynchronizationService`.

### Risk 2: Column schema drift between sync runs

**Severity:** Low — **Mitigation:** column metadata (id, title, type,
subtype) is fetched once per run and cached in-memory for that run only;
a column deleted/retyped mid-run surfaces as a per-row write failure logged
to the synchronization log, not a silent data-shape mismatch.

### Risk 3: CI image may not have the Tables app installed

**Severity:** Low — **Mitigation:** all coercion/contract-mapping logic is
unit-tested against a stubbed `TablesClientInterface`; integration tests
against a real Tables app are additive and skip (not fail) when the app is
absent, verified in tasks.md.

## Rollback Strategy

The change is additive: a new `nextcloud-table` discriminator value plus new
adapter classes and two new `switch` branches in `SynchronizationService`.
Reverting is a plain code revert with no data migration — existing
`register/schema`/`api`/`database`/`file` synchronizations are untouched.
Any synchronization already configured with `sourceType`/`targetType:
nextcloud-table` would fail closed (unsupported type exception, matching the
existing `default` branch behavior) after a rollback rather than silently
misbehave.

## Open Questions

- Should column *creation* (auto-provisioning a missing mapped column) be a
  fast-follow, or permanently out of scope? Deferred — see
  `DEFERRED_QUESTIONS` in the final report; current scope only writes into
  pre-existing columns.
- Should the Tables `Source`'s `location` be restricted to the *same*
  Nextcloud instance (simpler, no CORS/federation concerns) in v1, with
  cross-instance Tables sync deferred? design.md takes a position; flagged
  here for reviewer sign-off.
