# Retrofit — sync-editor-ui

Describes the observed behaviour of 84 frontend methods under the
`sync-editor-ui` cluster as 5 new REQs. Code already exists — this change
retroactively specifies it. Source: `openspec/coverage-report.md` generated
2026-05-25, frontend coverage gap.

## Motivation

The synchronization authoring + run/test UI (detail page, config widget, mapping
picker/preview, reference list, edit/run/test modals) is core platform behaviour
with no spec coverage. It is an openconnector-local Vue surface, so it gets a
foundational capability spec under `openspec/specs/`.

## Affected code units

- src/views/Synchronization/SynchronizationDetailPage.vue
- src/views/Synchronization/SyncConfigWidget.vue
- src/views/Synchronization/SyncMappingPicker.vue
- src/views/Synchronization/SyncMappingPreview.vue
- src/views/Synchronization/SyncReferenceList.vue
- src/modals/Synchronization/EditSynchronization.vue
- src/modals/Synchronization/RunSynchronization.vue
- src/modals/Synchronization/TestSynchronization.vue

## Approach

- Group the 84 methods by observable behaviour into 5 REQs (≤5 per ADR-032).
- Draft REQs that match observed behaviour (not aspirational).

Source: openspec/coverage-report.md generated 2026-05-25. See retrofit playbook.
