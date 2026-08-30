# rule-editor-ui — Rules index create/edit modal delta

**Spec refs**: `rule-editor-ui`, `rule-pipeline`

## ADDED Requirements

### Requirement: Rules index offers a complete create/edit modal (REQ-RULEUI-006)

The Rules index page SHALL replace the schema-generated form dialog with a wide bespoke
modal, mounted through CnIndexPage's `form-dialog` slot, exposing every field needed to
author a runnable rule: name, description, JsonLogic conditions, timing, execution order,
action, and action type. Timing, action and type SHALL be closed-vocabulary selects, not
free-text inputs. Persisting goes through the slot's `confirm` binding so the index list
refreshes.

#### Scenario: Creating a runnable rule without a second screen

- WHEN the user opens "+ Add" on the Rules index, fills name and action, picks a timing
  and type, and saves
- THEN the rule is persisted with a top-level `type`, an `action`, a `timing` and an
  integer `order`, AND the new row appears in the index without a page reload

#### Scenario: Closed vocabularies are offered as selects

- WHEN the modal renders
- THEN `timing` offers exactly `before` and `after`, `action` offers the four request
  methods, and `type` offers the authorable action types — none of them as a text input

#### Scenario: A stored type with no option is preserved, not rewritten

- GIVEN a rule whose `type` is one the picker does not list (e.g. `audit_trail`)
- WHEN the user opens the modal and saves without touching the Type field
- THEN the stored `type` is unchanged AND the select displays it rather than reading as
  unset

#### Scenario: Cancelling leaves nothing behind

- WHEN the user edits fields and cancels, then reopens the modal on another row
- THEN the draft is re-seeded from that row, carrying none of the abandoned edits

Notes: `modals/v2/RuleEditorModal.vue`, option vocabularies in `views/Rule/ruleDraft.js`.

### Requirement: Conditions are authored visually or as raw JSON, and persisted as an object (REQ-RULEUI-007)

The conditions field SHALL offer the recursive `RuleConditionGroup` builder with a toggle
to a raw-JSON editor, on both the Rules index modal and the rule detail page. Raw text
SHALL be kept verbatim while typing and committed only when it parses; unparseable text
SHALL surface an error and block saving rather than silently persisting the last valid
tree. Conditions SHALL be persisted as a bare JsonLogic **object**, matching the `rule`
schema — distinct from the `synchronization` schema, which types the field as
`array<object>`.

#### Scenario: Raw JSON round-trips through the visual builder

- WHEN the user pastes `{"and": [{"==": [{"var": "status"}, "active"]}, {">=": [{"var": "age"}, 18]}]}`
  into the raw editor and toggles to the visual builder
- THEN the builder renders an AND group with two leaves, AND toggling back shows
  equivalent JSON

#### Scenario: Invalid JSON blocks the save

- WHEN the raw conditions editor holds text that does not parse
- THEN an error is shown AND the save action is unavailable

#### Scenario: Legacy condition shapes normalise on load

- GIVEN a rule whose stored `conditions` is a JSON string, a bare leaf object, or an
  array (all shapes the pre-manifest raw editor wrote)
- WHEN either editing surface loads it
- THEN it is coerced to an `and`/`or` group root so the builder never renders an
  inconsistent tree

#### Scenario: An empty condition set is stored as an empty object

- WHEN the user clears the conditions editor and saves
- THEN `conditions` is persisted as `{}`, not as an empty operator or an array

Notes: `normaliseConditions` / `serializeRuleConditions` in `views/Rule/ruleDraft.js`,
shared with `views/Synchronization/syncDraft.js` (which keeps its own array-shaped
serialiser).

### Requirement: The error response is configurable where the type is `error` (REQ-RULEUI-008)

When the selected action type is `error`, the modal SHALL expose the error response's HTTP
code, title, message and the "include JSON Logic results in errors array" switch, writing
them to `configuration.error.{code,name,message,includeJsonLogicResult}` and pre-filling
sensible defaults. The block SHALL NOT render for other types, and a rule of another type
SHALL NOT gain a `configuration.error` bag by passing through the modal.

#### Scenario: Error fields appear only for the error type

- WHEN the user selects type `error`
- THEN the four error fields render, pre-filled
- AND WHEN the user selects any other type, they disappear

#### Scenario: A non-error rule gains no error configuration

- GIVEN a rule of type `mapping` with no `configuration.error`
- WHEN the user edits its name in the modal and saves
- THEN the persisted `configuration` still has no `error` key

#### Scenario: Sibling configuration keys survive an edit

- GIVEN a rule whose `configuration` holds keys for other action types (including
  `configuration.authentication.keys`, which carries live credentials per the rule
  lockdown overlay)
- WHEN the user edits any field in the modal and saves
- THEN every sibling key under `configuration` is preserved

Notes: `DEFAULT_ERROR_CONFIG` in `views/Rule/ruleDraft.js`.

## MODIFIED Requirements

### Requirement: Rule detail page load, edit, and save lifecycle (REQ-RULEUI-001)

The rule detail page SHALL fetch the active rule, expose its fields for editing, track a
dirty flag, normalise the rule's `conditions` between string/array/object representations,
and persist changes through the object store. Save and cancel actions reset or restore
local state; a load failure surfaces an error message with a retry affordance.

The page SHALL additionally expose the rule's `action` — a `required` property on the
`rule` schema that the page previously had no editor for — and SHALL offer `timing` as a
closed two-value select rather than free text. It SHALL take its conditions normaliser
from the shared `views/Rule/ruleDraft.js` rather than carrying its own copy.

#### Scenario: Editing a field marks the page dirty

- WHEN the user changes a rule field via `updateField`
- THEN the local copy is updated AND the `dirty` computed flag becomes true

#### Scenario: The required action field is editable

- WHEN the user opens a rule on the detail page
- THEN `action` is offered as a select over the four request methods, and saving persists
  the picked value

#### Scenario: Conditions are normalised on input

- WHEN the user types raw conditions JSON
- THEN `normaliseConditions` parses string/array/object/`and`/`or` shapes into the
  canonical condition tree, and an empty value yields an empty condition set

#### Scenario: Load failure offers retry

- WHEN the initial `load()` fails
- THEN an error message is shown AND `onRetry` re-issues the fetch

Notes: `RuleDetailPage.vue`.

### Requirement: Action-type configuration and per-action-type forms (REQ-RULEUI-003)

The action configuration panel SHALL present the available rule action types, swap in the
matching action form component for the selected type, and relay the form's slot updates
(mapping id, JavaScript code, raw JSON) back to the rule. Each action form reads and emits
its own action-specific configuration shape.

Picking a type SHALL propagate to the rule's **top-level** `type` property, not only to
`configuration.type`. `EndpointService::handleRuleProcessing()` dispatches on the
top-level field and throws `Unsupported rule type:` for an empty one, so a rule saved with
only `configuration.type` set can never execute. `configuration.type` continues to be
written, for `RuleService::processCustomRule()`'s `custom` sub-dispatch and for the
panel's own selection state. Where `configuration.type` is absent, the panel SHALL fall
back to the rule's top-level `type`, so rules written before this panel existed do not
render an empty picker.

The canonical action-type list SHALL live in `views/Rule/ruleDraft.js`, shared with the
Rules index modal.

#### Scenario: Selecting an action type swaps the form

- WHEN the user picks an action type via `onTypePick`
- THEN `formComponent` resolves to the matching action form and the action config is
  reset/seeded for that type

#### Scenario: The picked type reaches the property the engine reads

- WHEN the user picks an action type on the rule detail page and saves
- THEN the persisted rule carries that value at its top-level `type`, and invoking the
  rule dispatches the matching handler instead of throwing `Unsupported rule type:`

#### Scenario: A legacy rule's type populates the picker

- GIVEN a rule with a top-level `type` and no `configuration.type`
- WHEN the detail page renders the action panel
- THEN the picker shows that type as selected

#### Scenario: A form edits only its own configuration shape

- WHEN the user edits a field in an action form
- THEN the form emits an update carrying only that action's configuration keys

Notes: `RuleActionConfig.vue` + 18 `actionForms/`.
