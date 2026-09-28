# Tasks: automation-endpoint-flow-trigger

Rows: `integriq:auto-webhook-trigger`.

## Implementation tasks

### Task 1: Offer the flow action with a flow picker
- **spec_ref**: `openspec/changes/automation-endpoint-flow-trigger/specs/rule-editor-ui/spec.md#requirement-an-administrator-can-start-a-flow-from-an-endpoint-rule-req-aft-001`
- **files**: `src/views/Rule/ruleDraft.js`, `src/views/Rule/actionForms/FlowForm.vue`, `src/views/Rule/RuleActionConfig.vue`, Dutch and English strings
- [ ] Implement
- [ ] Test (vitest mounting the real form: the flows load, picking one writes `configuration.flow`)

### Task 2: A flow rule without a flow is not saved
- **spec_ref**: `openspec/changes/automation-endpoint-flow-trigger/specs/rule-editor-ui/spec.md#requirement-an-administrator-can-start-a-flow-from-an-endpoint-rule-req-aft-001`
- **files**: `src/modals/v2/RuleEditorModal.vue`
- [ ] Implement
- [ ] Test

## Verification

- [ ] `openspec validate automation-endpoint-flow-trigger --strict`
- [ ] vitest, exit code read
