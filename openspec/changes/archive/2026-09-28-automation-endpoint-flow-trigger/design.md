# Design: automation-endpoint-flow-trigger

## Context

`RuleEditorModal.vue` renders an action form per `ACTION_TYPES` entry (`RuleActionConfig.vue` and `src/views/Rule/actionForms/`). A type with no form cannot be authored, which is why `flow` was held back. The runtime arm exists and throws "flow rule type requires configuration.flow" when the id is missing.

## D1. A flow form like the other action forms

A `FlowForm.vue` in `src/views/Rule/actionForms/` shows one `NcSelect` with `inputLabel` "Flow", loaded from OpenRegister's `flow` objects with `_limit`, and writes `configuration.flow` through the same `patchMethod` helper the other forms use.

## D2. The editor refuses what the runtime would refuse

`canSave` is false for a `flow` rule with no `configuration.flow`, with the message the runtime would give, so the first failure is not a consumer's 500.

## D3. The endpoint is the webhook

No new route. An administrator creates an endpoint (its path is the webhook URL) and adds a `flow` rule to it; that is the whole setup, and the endpoint's own consumer authentication guards it.
