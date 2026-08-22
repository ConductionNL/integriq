# Retrofit — mapping-editor-ui

Describes the observed behaviour of 95 frontend methods under the
`mapping-editor-ui` cluster as 5 new REQs. Code already exists — this change
retroactively specifies it. Source: `openspec/coverage-report.md` generated
2026-05-25, frontend coverage gap.

## Motivation

The mapping authoring + testing UI (detail page, rule editor, edit dialog, test
modals, result panel) is core platform behaviour with no spec coverage. It is an
integriq-local Vue surface, so it gets a foundational capability spec under
`openspec/specs/`.

## Affected code units

- src/views/wrappers/MappingDetailPage.vue
- src/views/wrappers/MappingRulesEditor.vue
- src/views/wrappers/EditMappingRuleDialog.vue
- src/modals/MappingTest/TestMapping.vue
- src/modals/MappingTest/components/TestMappingInputObject.vue
- src/modals/MappingTest/components/TestMappingMappingSelect.vue
- src/modals/MappingTest/components/TestMappingResult.vue
- src/modals/v2/TestMappingModal.vue

## Approach

- Group the 95 methods by observable behaviour into 5 REQs (≤5 per ADR-032).
- Draft REQs that match observed behaviour (not aspirational).

Source: openspec/coverage-report.md generated 2026-05-25. See retrofit playbook.
