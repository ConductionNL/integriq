# Retrofit — app-shell-and-logs-ui

Describes the observed behaviour of 20 frontend methods under the
`app-shell-and-logs-ui` cluster as 3 new REQs. Code already exists — this change
retroactively specifies it. Source: `openspec/coverage-report.md` generated
2026-05-25, frontend coverage gap.

## Affected code units

- src/App.vue
- src/modals/v2/ModalHost.vue
- src/views/wrappers/LogIndex.vue

## Approach

- Group the 20 methods by observable behaviour into 3 REQs (≤5 per ADR-032).
- Draft REQs that match observed behaviour (not aspirational).

Source: openspec/coverage-report.md generated 2026-05-25. See retrofit playbook.
