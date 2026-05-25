# Retrofit — endpoint-job-editor-ui

Describes the observed behaviour of 27 frontend methods under the
`endpoint-job-editor-ui` cluster as 5 new REQs. Code already exists — this change
retroactively specifies it. Source: `openspec/coverage-report.md` generated
2026-05-25, frontend coverage gap.

## Affected code units

- src/modals/Endpoint/EditEndpoint.vue
- src/modals/v2/JobFormFields.vue
- src/modals/Job/RunJob.vue
- src/modals/Job/TestJob.vue
- src/modals/TestSource/TestSource.vue

## Approach

- Group the 27 methods by observable behaviour into 5 REQs (≤5 per ADR-032).
- Draft REQs that match observed behaviour (not aspirational).

Source: openspec/coverage-report.md generated 2026-05-25. See retrofit playbook.
