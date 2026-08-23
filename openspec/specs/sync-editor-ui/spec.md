---
status: done
---

# sync-editor-ui Specification

## Purpose
Provides the synchronization editor frontend for Integriq, where users edit a synchronization's fields, source and target configuration (API source, register and schema, or file path), and JsonLogic conditions with dirty-state tracking and guarded save. It includes mapping pickers with debounced live preview and reference lists. Two surfaces share those components: the detail page at `/synchronizations/:id`, and a wide three-column create/edit modal on the Synchronizations index. Run and dry-run test are triggered from the index row actions, and the dry-run result is rendered in the modal.

@e2e exclude Vue component-internal method/computed behaviour (updateDraft, normalizeForDiff dirty flag, normaliseConditions/serializeConditions round-trip, save guards) reverse-engineered from the synchronization detail-page .vue components — unit-level (vitest), not browser-observable; the synchronization detail-page render surface is covered by manifest-pages e2e under synchronization-engine
## Requirements
### Requirement: Synchronization detail page load, edit, conditions, and save (REQ-SYNCUI-001)

The synchronization detail page SHALL load the active synchronization, expose its
fields and source/target type selectors, normalise its `conditions` into a JsonLogic
group (string/array/object), track a dirty flag via normalised diff, and persist
edits. It guards save when invalid and supports reset.

#### Scenario: Editing marks the page dirty
- WHEN the user changes a field via `updateDraft` or the condition tree
- THEN `normalizeForDiff` recomputes and `dirty` becomes true

#### Scenario: Conditions normalisation and serialisation
- WHEN conditions are loaded or edited
- THEN `normaliseConditions`/`serializeConditions` round-trip between the stored value and the visual group editor

#### Scenario: Save guarded and reset
- WHEN `canSave` is false the save is blocked; WHEN the user resets, `resetEdits` restores the loaded state

Notes: `SynchronizationDetailPage.vue` (27 methods/computeds/watchers/setup).

### Requirement: Source / target configuration widget (REQ-SYNCUI-002)

The config widget SHALL present source-kind-specific configuration (API source,
register+schema, file path), fetch the available sources and registers, derive schema
options for the chosen register, relay config-key updates to the parent, and open a
file picker for file-based sources.

#### Scenario: Picking a register populates schema options
- WHEN the user selects a register via `onRegisterPick`
- THEN `schemaOptions` is derived for that register and `selectedSchema` resolves

#### Scenario: Config updates are relayed
- WHEN the user edits a config field via `onConfigUpdate`
- THEN the updated config key/value is emitted to the parent

#### Scenario: File source opens a picker
- WHEN the source is file-based and the user opens the picker
- THEN `openFilePicker` resolves a path into the file-path config field

Notes: `SyncConfigWidget.vue` (19).

### Requirement: Mapping picker, live preview, and reference list (REQ-SYNCUI-003)

The mapping picker SHALL select primary/reverse/hash mappings (fetched on demand);
the preview panel SHALL run a debounced live mapping preview against the backend and
render the result; the reference list SHALL manage a multi-select of reference ids
with fetched options.

#### Scenario: Selecting mappings
- WHEN the user picks a primary/reverse/hash mapping
- THEN the selection resolves against fetched options and is emitted

#### Scenario: Debounced preview
- WHEN the user changes the preview input
- THEN `scheduleRun`/`runPreview` posts to the backend after the debounce and renders the result

Notes: `SyncMappingPicker.vue` (8), `SyncMappingPreview.vue` (9),
`SyncReferenceList.vue` (5).

### Requirement: Wide create/edit synchronization modal (REQ-SYNCUI-004)

The Synchronizations index SHALL offer a three-column create/edit modal — source,
transform, and target — replacing the generic four-field form dialog. It is mounted
through CnIndexPage's `form-dialog` slot, declared in the manifest as
`pages[Synchronizations].slots["form-dialog"]`, and reuses the detail page's
components rather than reimplementing them: `SyncConfigWidget`, `SyncMappingPicker`,
`SyncReferenceList` and `RuleConditionGroup`, with the shared draft, option and
condition logic in `views/Synchronization/syncDraft.js`.

All edits SHALL be held in a local draft and persisted only on save, so a
synchronization can be configured completely before it first exists. Saving SHALL go
through the slot's `confirm` binding so the index's own save path and list refresh run.

#### Scenario: creating a configured synchronization in one pass

- **WHEN** the user opens Add, fills in a name, picks a source kind and config, a
  target kind and config, and a mapping
- **THEN** a single save creates the synchronization with all of it
- **AND** the row appears in the index without a manual reload

#### Scenario: cancelling discards every edit

- **WHEN** the user changes conditions, rules or mappings on an existing
  synchronization and cancels
- **THEN** nothing is persisted and the stored record is unchanged

#### Scenario: the dry run is only offered where it can be honest

- **GIVEN** `POST /api/synchronizations/{id}/test` resolves the saved record by id
- **WHEN** the modal is in create mode
- **THEN** the Test button is absent
- **AND** in edit mode with unsaved changes it is disabled, explaining that the dry
  run tests the saved version
- **AND** when it runs, the returned result payload is rendered rather than discarded

Notes: `modals/v2/SynchronizationEditorModal.vue`, `views/Synchronization/syncDraft.js`.
Replaces the dead pre-manifest `modals/Synchronization/EditSynchronization.vue`, whose
hand-rolled on-demand OpenRegister installer was deliberately not carried over.

### Requirement: Run and test a synchronization (REQ-SYNCUI-005)

The index row actions SHALL let the user run or dry-run a synchronization, gating the
request behind a confirmation step that exposes the run flags and rendering the run log
the backend returns. The handlers themselves SHALL only open that surface — the shared
run/test modal specified by `app-shell-and-logs-ui` REQ-SHELLUI-004 — and SHALL make no
request of their own.

#### Scenario: Running a synchronization
- WHEN the user invokes the "Run now" row action
- THEN `runSynchronizationHandler` opens the run modal for `synchronization/run`
- AND the run fires only once the user confirms, with whichever of `force` /
  `forceDeletion` they selected
- AND the returned run log's object counters are rendered

#### Scenario: Testing a synchronization
- WHEN the user invokes the "Test (dry run)" row action
- THEN `testSynchronizationHandler` opens the same modal for `synchronization/test`
- AND the dry run's result is rendered rather than reduced to a toast

Notes: `handlers/actionHandlers.js` — `runSynchronizationHandler` /
`testSynchronizationHandler`, now bus emitters. The result rendering lives in
`modals/v2/RunActionModal.vue` + `modals/v2/runTargets.js` (REQ-SHELLUI-004); the Test
button inside the editor modal (REQ-SYNCUI-004) renders the same payload for the
edit-mode dry run.

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

### Requirement: Form picker for the `nextcloud-form` source kind (REQ-SYNCUI-008)

`SyncConfigWidget.vue` SHALL present a `nextcloud-form` option in the
source-kind selector only when the backend's available-types list includes
it (`nextcloud-forms-connector` REQ-001/REQ-005). `nextcloud-form` SHALL
NOT be offered in the target-kind selector under any condition
(`nextcloud-forms-connector` REQ-002 is source-only). When `nextcloud-form`
is selected as the source kind, the widget SHALL require picking a
`Source` (reusing the existing Source selector used for `api` sources) and
then fetching and presenting that Source's accessible forms via
`GET .../synchronizations/forms-bridge/forms`, storing the chosen form's id
into `sourceConfig.formId`.

#### Scenario: nextcloud-form kind is hidden when Forms is unavailable

- **GIVEN** the backend's available source-types response does not include
  `nextcloud-form`
- **WHEN** the source-kind selector renders
- **THEN** `nextcloud-form` is not offered as an option

#### Scenario: nextcloud-form is never offered as a target kind

- **GIVEN** the Forms app is enabled and `nextcloud-form` is present in the
  available source-types response
- **WHEN** the target-kind selector renders
- **THEN** `nextcloud-form` is not offered as an option there, regardless
  of the source-types response

#### Scenario: picking a Source populates the form list

- **GIVEN** the `nextcloud-form` source kind is selected and a `Source` is
  picked
- **WHEN** the widget fetches forms for that Source
- **THEN** the returned forms are presented in a picker, and choosing one
  sets `formId` in `sourceConfig`

### Requirement: Field-mapping helper prefilled from form questions (REQ-SYNCUI-009)

When a form is selected for a `nextcloud-form` source, the widget SHALL
fetch that form's questions
(`GET .../synchronizations/forms-bridge/forms/{formId}/questions`) and
present a read-only field reference list showing each question's `text`,
`name`, and `type`, so the user can see the exact question-text/id
references available to a `Mapping` or an outbound `action.kind: 'mapping'`
configuration before writing mapping expressions by hand (the mapping
picker itself, REQ-SYNCUI-003, is unchanged and already exists). The helper
SHALL visually distinguish `multiple`/`multiple_unique`-type questions
(array-valued answers, `nextcloud-forms-connector` REQ-003) from
single-valued question types.

#### Scenario: field reference list shows question text, id, and type

- **GIVEN** a selected form with a `short`-type question titled
  "Company name" (`id: 7`) and a `multiple`-type question titled
  "Interested in" (`id: 12`)
- **WHEN** the field-mapping helper renders
- **THEN** both questions are listed with their `text`, `id`, and `type`
- **AND** "Interested in" is visually flagged as array-valued

#### Scenario: an ambiguous question text is flagged in the helper, not just at run time

- **GIVEN** a selected form with two questions both titled "Comments"
- **WHEN** the field-mapping helper renders
- **THEN** both "Comments" entries are shown with their distinct `id`s and
  a visible warning that referencing this text by name is ambiguous
  (`nextcloud-forms-connector` REQ-003), steering the user toward
  referencing by id instead
