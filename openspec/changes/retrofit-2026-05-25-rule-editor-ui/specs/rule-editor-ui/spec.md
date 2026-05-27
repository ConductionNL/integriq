---
retrofit: true
---

# rule-editor-ui

The Vue surface for authoring OpenConnector rules: the rule detail page, the
JsonLogic condition-tree editor, the action-type configuration panel with its
per-action-type forms, the rule edit modal, and the dialogs that attach existing
rules to an endpoint. This spec describes the observed behaviour of the
already-shipping components.

## Requirements

### REQ-RULEUI-001: Rule detail page load, edit, and save lifecycle

The rule detail page SHALL fetch the active rule, expose its fields for editing,
track a dirty flag, normalise the rule's `conditions` between string/array/object
representations, and persist changes through the object store. Save and cancel
actions reset or restore local state; a load failure surfaces an error message
with a retry affordance.

#### Scenario: Editing a field marks the page dirty
- WHEN the user changes a rule field via `updateField`
- THEN the local copy is updated AND the `dirty` computed flag becomes true

#### Scenario: Conditions are normalised on input
- WHEN the user types raw conditions JSON
- THEN `normaliseConditions` parses string/array/object/`and`/`or` shapes into the
  canonical condition tree, and an empty value yields an empty condition set

#### Scenario: Load failure offers retry
- WHEN the initial `load()` fails
- THEN an error message is shown AND `onRetry` re-issues the fetch

Notes: `RuleDetailPage.vue` (16 methods/computeds/watchers).

### REQ-RULEUI-002: JsonLogic condition-tree editing

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

### REQ-RULEUI-003: Action-type configuration and per-action-type forms

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

### REQ-RULEUI-004: Rule edit modal with dynamic list management

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

### REQ-RULEUI-005: Attach existing rules to an endpoint

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
