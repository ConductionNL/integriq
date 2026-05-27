---
retrofit: true
---

# mapping-editor-ui

The Vue surface for authoring and testing OpenConnector mappings: the mapping
detail page, the mapping/cast/unset rule editor, the edit-rule dialog, and the
test-mapping modals (input object, mapping/schema selectors, result panel). This
spec describes the observed behaviour of the already-shipping components.

## Requirements

### REQ-MAPUI-001: Mapping detail page load, edit, and persistence

The mapping detail page SHALL resolve the active mapping from the object store,
expose its title/description/pass-through flag, render derived rule collections
(mapping, cast, unset), surface load errors, and persist field/rule patches back
through the store. It registers the object type on creation and reloads on id change.

#### Scenario: Detail page loads a mapping
- WHEN the page mounts with a mapping id
- THEN `ensureRegistered` registers the object type and the mapping is fetched into the store

#### Scenario: A field or rule change is persisted
- WHEN the user toggles pass-through or edits rules
- THEN `persistPatch` writes the patch to the store and the derived collections update

#### Scenario: Load failure surfaces a message
- WHEN the fetch fails
- THEN `loadError`/`errorMessage` expose the failure and `reload` re-issues the fetch

Notes: `MappingDetailPage.vue` (30 methods/computeds/watchers/lifecycle).

### REQ-MAPUI-002: Mapping / cast / unset rule editing

The rule editor SHALL render mapping, cast, and unset rules as tabbed, reorderable
row lists, support create/edit/delete per kind, prevent duplicate keys, and commit
changes (key rename, value change, reorder) back to the parent as updated rule maps.

#### Scenario: Creating a rule
- WHEN the user invokes `openCreate(kind)` and submits the dialog
- THEN `commitMapping`/`commitCast`/`commitUnset` adds the new rule and emits the updated collection

#### Scenario: Reordering rules
- WHEN the user reorders rows or invokes `moveRow`
- THEN the rule order is updated and emitted to the parent

#### Scenario: Deleting a rule
- WHEN the user invokes `deleteRule`/`deleteUnset`
- THEN the rule is removed and the updated collection is emitted

Notes: `MappingRulesEditor.vue` (19).

### REQ-MAPUI-003: Edit-mapping-rule dialog with validation

The edit-rule dialog SHALL adapt its fields to the rule kind (mapping/cast/unset),
offer cast-type options, validate the property key (required, no duplicate), compute
contextual title/labels/placeholders/help, and emit a submit payload only when valid.

#### Scenario: Submit guarded by validation
- WHEN the property key is empty or duplicates an existing key
- THEN `canSubmit` is false and `propertyError` describes the problem

#### Scenario: Cast type selection
- WHEN the user picks a cast type via `onCastTypeInput`
- THEN the selected cast value is recorded for the submit payload

Notes: `EditMappingRuleDialog.vue` (12).

### REQ-MAPUI-004: Test-mapping execution and result rendering

The test-mapping surface SHALL collect an input object (validating JSON), let the
user select a mapping and target schema, run the mapping test against the backend,
and render the transformed result. It loads mappings/schemas on open, guards the run
when inputs are incomplete, and surfaces validation errors.

#### Scenario: Running a mapping test
- WHEN the user supplies a valid input object and selects a mapping, then runs the test
- THEN `testMapping`/`runTest` posts to the backend and the result panel renders the output

#### Scenario: Invalid input JSON blocks the test
- WHEN the input object is not valid JSON
- THEN `validJson` reports the error and the test is not run

Notes: `TestMapping.vue` (5), `TestMappingInputObject.vue` (2),
`TestMappingMappingSelect.vue` (14), `TestMappingResult.vue` partial (2),
`v2/TestMappingModal.vue` (10).

### REQ-MAPUI-005: Persist a mapped result as an OpenRegister object

The result panel SHALL let the user pick a target register and save the transformed
mapping output as an OpenRegister object.

#### Scenario: Saving the mapped result
- WHEN the user selects a register and invokes save
- THEN `saveObject` persists the transformed payload as a new object in the chosen register

Notes: `TestMappingResult.vue` `fetchRegisters` + `saveObject` (1 of its 3 methods is `setup`, covered under REQ-MAPUI-004).
