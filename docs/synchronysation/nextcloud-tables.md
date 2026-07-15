# Nextcloud Tables as a synchronization source or target

OpenConnector can read rows from — and write mapped external data into — a
**Nextcloud Tables** table, using the same Source → Synchronization →
SynchronizationContract machinery, `CallService` transport, and
`MappingService` transformation pipeline as every other source/target kind.

Tables is a **soft (feature-detected) runtime dependency**: when the Tables
app is absent or disabled, the `nextcloud-table` kind simply does not appear
in the synchronization editor, and any synchronization already configured
with it fails its run with a clear "Tables app is not enabled" config error
before any HTTP call is attempted.

## How it is modelled

A `nextcloud-table` synchronization's `sourceId`/`targetId` points at an
ordinary **Source** object (register `openconnector`, schema `source`) — no
new entity type. The Source's `location` is the base URL of the Nextcloud
instance hosting the table, and its `authentication` is a normal Basic Auth
credential (or a brokered `credentialRef`), exactly like any other HTTP
source. Table-specific settings live in the free-form config blob:

| Config key      | Side          | Meaning                                                        |
|-----------------|---------------|----------------------------------------------------------------|
| `tableId`       | source+target | The Tables table id (required).                                |
| `viewId`        | source        | Optional — scope reads to a Tables *view* instead of the table.|
| `columnMapping` | target        | Array of `{ "column": "<title>", "value": "<field/expr>" }`.   |

The Tables REST API is consumed through its mature, versioned
`index.php/apps/tables/api/1/*` surface (full authenticated row/table/column
CRUD), not the newer OCS v2 surface, which lacks authenticated single-row
GET/PUT/DELETE.

## Tables as a target

For each transformed object, OpenConnector:

1. Resolves each `columnMapping` entry's **column title** to the current
   numeric column id (fetched once per run and cached). An ambiguous title
   (two columns share it) is a hard config error for that row — never a
   first-match guess.
2. Coerces the mapped value against the target column's declared type:
   - **text** — cast to string; a value longer than `textMaxLength` fails
     that row (never silently truncated).
   - **number** — numeric cast, rounded to `numberDecimals`; a non-numeric
     value fails that row.
   - **datetime** — normalised to ISO-8601 respecting the
     `date`/`time`/`datetime` subtype.
   - **selection** — matched against `selectionOptions` by label; no match
     fails that row.
   - **usergroup** — not supported for writes in this version.
3. Creates a row (`POST .../tables/{tableId}/rows`) when no contract exists
   for the object's origin id, or updates the existing row
   (`PUT .../rows/{rowId}`) when one does — recording the Tables row id as
   the contract's `targetId`. An unchanged mapped payload is a no-op write.

A single row's coercion/mapping failure is logged and skipped; the run
continues. A **permission denial (401/403)** on the first write aborts the
whole run (no partial writes) — Tables' own ACL is the sole authority;
OpenConnector never re-implements it.

Rows whose contracts are no longer present in the source are removed through
the same guarded `deleteInvalidObjects()` deletion path every other target
type uses.

## Tables as a source

OpenConnector reads rows page-by-page (`GET .../tables/{tableId}/rows`) and
feeds each row's `data` into the mapping pipeline exactly as an API-sourced
object. The Tables row id is used as the origin id, and change detection uses
the same order-independent hash as every other source.

## Editor UI

In the synchronization editor, selecting the **Nextcloud Table** kind lets
you:

1. Pick the **Source** whose credential reaches the Tables API.
2. Pick a **table** the source can access (fetched live).
3. For a target, map each of the table's columns to a source field or Twig
   expression via the **column-mapping helper**, which shows each column's
   type and allowed values (e.g. a selection column's options) so you can see
   what a column accepts before saving. The mapping is stored keyed by column
   title.

The kind is only offered when the backend reports the Tables app is enabled.

## Out of scope

- Tables row-change events as synchronization *triggers* (that is the
  `nextcloud-event-hub` change).
- Auto-creating tables or columns — this change only writes into columns that
  already exist.
