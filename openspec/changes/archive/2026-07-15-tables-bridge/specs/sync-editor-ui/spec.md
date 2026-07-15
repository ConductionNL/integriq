# sync-editor-ui — Delta: Nextcloud Table picker and column-mapping helper

## Purpose

Extends the source/target configuration widget (base spec REQ-SYNCUI-002)
with a `nextcloud-table` kind: a table picker (reusing the Source
selector already present for `api` sources) and a column-mapping helper
prefilled from the selected table's column schema, calling the discovery
endpoints defined in `tables-bridge`'s `contract.md`. The type only appears
when the backend reports the Tables app is enabled (`tables-bridge` REQ-004).

## ADDED Requirements

### Requirement: Table picker for the `nextcloud-table` source/target kind (REQ-SYNCUI-006)

`SyncConfigWidget.vue` SHALL present a `nextcloud-table` option in the
source/target kind selector only when the backend's available-types list
includes it (`tables-bridge` REQ-004/REQ-007). When selected, the widget
SHALL require picking a `Source` (reusing the existing Source selector used
for `api` sources) and then fetching and presenting that Source's accessible
tables via `GET .../synchronizations/tables-bridge/tables`, storing the
chosen table's id into `sourceConfig.tableId`/`targetConfig.tableId`.

#### Scenario: nextcloud-table kind is hidden when Tables is unavailable

- **GIVEN** the backend's available source/target types response does not
  include `nextcloud-table`
- **WHEN** the source/target kind selector renders
- **THEN** `nextcloud-table` is not offered as an option

#### Scenario: picking a Source populates the table list

- **GIVEN** the `nextcloud-table` kind is selected and a `Source` is picked
- **WHEN** the widget fetches tables for that Source
- **THEN** the returned tables are presented in a picker, and choosing one
  sets `tableId` in the relevant config object

### Requirement: Column-mapping helper prefilled from table schema (REQ-SYNCUI-007)

When a table is selected for a `nextcloud-table` target, the widget SHALL
fetch that table's columns
(`GET .../synchronizations/tables-bridge/tables/{tableId}/columns`) and
present a column-mapping helper listing each column's title and type,
letting the user pick a mapping output field (or literal/Twig expression,
consistent with the existing mapping picker's input model) per column. The
helper SHALL surface the column's `type`/`subtype`/constraints (e.g.
`selectionOptions`) so the user can see what values are valid before saving,
matching the coercion rules in `tables-bridge` REQ-003.

#### Scenario: column-mapping helper lists columns with type hints

- **GIVEN** a selected table with a `number` column titled "Amount" and a
  `selection` column titled "Status" with options `open`/`paid`/`overdue`
- **WHEN** the column-mapping helper renders
- **THEN** it lists both columns with their titles and types, and shows the
  `selectionOptions` for the "Status" column

#### Scenario: saved mapping is stored by column title

- **GIVEN** the user maps the "Amount" column to a mapping output field
- **WHEN** the synchronization is saved
- **THEN** `targetConfig.columnMapping` contains an entry keyed by the
  column's title (not its numeric id), consistent with
  `tables-bridge` REQ-001's title-keyed mapping storage

## Notes

- `SyncConfigWidget.vue` (19 methods/computeds per the base spec) gains
  these behaviors as additions to its existing kind-branching logic (API
  source, register+schema, file path) — this delta does not modify any of
  those three existing branches.
- The column-mapping helper is a new component analogous in spirit to
  `SyncMappingPicker.vue`/`SyncReferenceList.vue` (REQ-SYNCUI-003) but
  purpose-built for column titles + type hints rather than mapping-object
  selection; it does not replace or alter those components.
