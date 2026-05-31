# Retrofit — rule-editor-ui

Describes the observed behaviour of 144 frontend methods under the
`rule-editor-ui` cluster as 5 new REQs. Code already exists — this change
retroactively specifies it. Source: `openspec/coverage-report.md` generated
2026-05-25, frontend coverage gap.

## Motivation

The rule authoring UI (detail page, JsonLogic condition tree, action-type forms,
edit modal, endpoint-attach dialogs) is core platform behaviour with no spec
coverage. It is an openconnector-local Vue surface (no OpenRegister equivalent),
so it gets a foundational capability spec under `openspec/specs/`.

## Affected code units

- src/views/Rule/RuleDetailPage.vue
- src/views/Rule/RuleConditionGroup.vue
- src/views/Rule/RuleConditionLeaf.vue
- src/views/Rule/RuleActionConfig.vue
- src/views/Rule/actionForms/*.vue (14 forms)
- src/modals/Rule/EditRule.vue
- src/modals/Endpoint/AddEndpointRule.vue
- src/modals/v2/AddEndpointRuleModal.vue

## Approach

- Group the 144 methods by observable behaviour into 5 REQs (≤5 per ADR-032).
- Draft REQs that match observed behaviour (not aspirational).
- Notes sections record the file-to-REQ mapping.

Source: openspec/coverage-report.md generated 2026-05-25. See retrofit playbook.
