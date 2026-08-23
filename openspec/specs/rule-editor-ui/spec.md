---
status: done
---

# rule-editor-ui Specification

## Purpose
Provides the rule editor frontend for Integriq, where users edit a rule's fields with dirty-state tracking, build JsonLogic condition trees through a recursive editor of and/or groups and typed leaf conditions, and configure action-type-specific forms (authentication, mapping, save-object, synchronization, file operations, and more). It also manages dynamic repeating lists with auto-appended blank rows and lets users attach existing rules to an endpoint via the OpenRegister object API.

@e2e exclude Vue component-internal method/computed behaviour (dirty flag, normaliseConditions, argsForKind, onTypePick/formComponent, dynamic-row prune, onSave guard) reverse-engineered from RuleDetailPage/RuleConditionGroup/RuleConditionLeaf/RuleActionConfig/EditRule/AddEndpointRule .vue files — unit-level (vitest), not browser-observable; the rule detail-page render + Add Rule modal surfaces are covered by manifest-pages e2e under rule-pipeline

## Requirements
### Requirement: Rule detail page load, edit, and save lifecycle (REQ-RULEUI-001)

The rule detail page SHALL fetch the active rule, expose its fields for editing,
track a dirty flag, normalise the rule's `conditions` between string/array/object
representations, and persist changes through the object store. Save persists local
state and Discard restores it from the last persisted version; both are available
only while the page is dirty. A load failure surfaces an error message with a
retry affordance.

#### Scenario: Editing a field marks the page dirty
- WHEN the user changes a rule field via `updateField`
- THEN the local copy is updated AND the `dirty` computed flag becomes true

#### Scenario: Discard is unavailable with no unsaved edits
- WHEN the page is not dirty
- THEN the Discard action is disabled AND `resetEdits` is a no-op

#### Scenario: Discard restores the last persisted version
- WHEN the user discards while the page is dirty
- THEN the local copy is replaced by the pristine snapshot AND any raw-conditions
  JSON input is re-rendered from the restored condition tree

#### Scenario: Conditions are normalised on input
- WHEN the user types raw conditions JSON
- THEN `normaliseConditions` parses string/array/object/`and`/`or` shapes into the
  canonical condition tree, and an empty value yields an empty condition set

#### Scenario: Load failure offers retry
- WHEN the initial `load()` fails
- THEN an error message is shown AND `onRetry` re-issues the fetch

Notes: `RuleDetailPage.vue` (16 methods/computeds/watchers).

### Requirement: JsonLogic condition-tree editing (REQ-RULEUI-002)

The condition editor SHALL render a recursive tree of `and`/`or` groups and leaf
conditions. Group nodes support operator selection, adding leaves/sub-groups, and
removing children. Leaf nodes resolve the active operator's argument schema
(unary/binary/var), parse JSON slot values, coerce typed values, and emit
structured update patches to the parent.

#### Scenario: Adding a child to a group
- WHEN the user invokes `addLeaf` or `addGroup` on a condition group
- THEN a new child node is appended and an update is emitted to the parent

#### Scenario: Operator change reshapes leaf arguments
- WHEN the user picks a different operator on a leaf via `onOperatorPick`
- THEN `argsForKind` rebuilds the argument slots for the new operator kind, carrying
  forward the prior var/value where compatible, and `emitUpdate` propagates the patch

#### Scenario: Invalid JSON slot input is rejected gracefully
- WHEN the user enters non-array/non-JSON text into a JSON slot
- THEN the malformed value is not committed to the condition tree

Notes: `RuleConditionGroup.vue` (11), `RuleConditionLeaf.vue` (19).

### Requirement: Action-type configuration and per-action-type forms (REQ-RULEUI-003)

The action configuration panel SHALL present the available rule action types,
swap in the matching action form component for the selected type, and relay the
form's slot updates (mapping id, JavaScript code, raw JSON) back to the rule. Each
action form (authentication, mapping, save-object, synchronization, file fetch/write,
filepart create/upload, locking, download, error, extend-input, extend-external-input,
upload) reads and emits its own action-specific configuration shape.

#### Scenario: Selecting an action type swaps the form
- WHEN the user picks an action type via `onTypePick`
- THEN `formComponent` resolves to the matching action form and the action config is reset/seeded for that type

#### Scenario: A form edits only its own configuration shape
- WHEN the user edits a field in an action form (e.g. authentication type, mapping id, file path)
- THEN the form emits an update carrying only that action's configuration keys

Notes: `RuleActionConfig.vue` (12) + 14 actionForms (`AuthenticationForm`,
`DownloadForm`, `ErrorForm`, `ExtendExternalInputForm`, `ExtendInputForm`,
`FetchFileForm`, `FilepartUploadForm`, `FilepartsCreateForm`, `LockingForm`,
`MappingForm`, `SaveObjectForm`, `SynchronizationForm`, `UploadForm`, `WriteFileForm`).

### Requirement: Rule edit modal with dynamic list management (REQ-RULEUI-004)

The rule edit modal SHALL load an existing rule (or initialise a blank one), bind
its action-type-specific fields, and manage dynamic repeating lists (API keys,
header/property mappings) by auto-appending a blank trailing row and pruning empty
interior rows as the user types.

#### Scenario: Auto-append a blank row
- WHEN the user fills the last row of a dynamic list (API keys / mappings)
- THEN a new blank trailing row is appended automatically

#### Scenario: Prune empty interior rows
- WHEN an interior row of a dynamic list is left empty
- THEN that empty row is removed, keeping only filled rows plus the trailing blank

Notes: `EditRule.vue` (22).

### Requirement: Attach existing rules to an endpoint (REQ-RULEUI-005)

The add-endpoint-rule dialogs SHALL fetch the available rules, let the user select
one or more, and persist the selection onto the endpoint object via the OpenRegister
object API. Success and failure are surfaced via toast notifications; the dialog
guards save when nothing is selected or the endpoint id is missing.

#### Scenario: Save attaches selected rules to the endpoint
- WHEN the user confirms a selection and an endpoint id is present
- THEN the endpoint object is updated with the chosen rule ids and a success toast shows

#### Scenario: Save is guarded
- WHEN no rule is selected or the endpoint id is missing
- THEN `onSave` is a no-op

Notes: `AddEndpointRule.vue` (3), `v2/AddEndpointRuleModal.vue` (9).

