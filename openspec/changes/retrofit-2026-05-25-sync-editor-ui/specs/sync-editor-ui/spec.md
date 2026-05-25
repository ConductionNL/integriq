---
retrofit: true
---

# sync-editor-ui

The Vue surface for authoring, configuring, previewing, and running OpenConnector
synchronizations: the synchronization detail page, the source/target config widget,
the mapping picker + live preview, the reference list, the edit modal, and the
run/test modals. This spec describes the observed behaviour of the already-shipping
components.

## Requirements

### REQ-SYNCUI-001: Synchronization detail page load, edit, conditions, and save

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

### REQ-SYNCUI-002: Source / target configuration widget

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

### REQ-SYNCUI-003: Mapping picker, live preview, and reference list

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

### REQ-SYNCUI-004: Synchronization edit modal

The edit modal SHALL load source/register/schema/rule/mapping option collections,
react to source-type changes, install OpenRegister on demand, and submit the
synchronization through the store. It supports launching the test flow and closing.

#### Scenario: Editing a synchronization
- WHEN the user fills the modal and submits
- THEN `editSynchronization` persists the synchronization and the modal closes

#### Scenario: Option collections load
- WHEN the modal mounts
- THEN `getSources`/`getRegisterWithSchemas`/`getSourceTargetMappings`/`getRules` populate the selectors

Notes: `EditSynchronization.vue` (12).

### REQ-SYNCUI-005: Run and test a synchronization

The run and test modals SHALL trigger a synchronization run (or dry-run test) against
the backend, surface the result/log, and close.

#### Scenario: Running a synchronization
- WHEN the user confirms the run modal
- THEN `runSynchronization` invokes the backend run and the result is surfaced

#### Scenario: Testing a synchronization
- WHEN the user confirms the test modal
- THEN `testSynchronization` invokes the backend dry-run and the result is surfaced

Notes: `RunSynchronization.vue` (2), `TestSynchronization.vue` (2).
