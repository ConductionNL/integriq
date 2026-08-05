# Legacy modals — extraction reference

The 16 SFCs under this tree are **dead at runtime** — `src/modals/Modals.vue`
(the dispatcher that imported all 47 pre-chain-C modals) was deleted along
with the 30+ modal files whose UX is covered by nc-vue primitives
(`CnFormDialog`, `CnDeleteDialog`, `CnDetailPage`, `CnMassDeleteDialog`).

What remains here is the set of modals flagged by the chain-C src/ audit as
needing **bespoke extraction** rather than blanket deletion. Their existing
imports against deleted per-schema stores (`sourceStore`, `mappingStore`,
…) and the deleted `src/entities/` tree are intentionally broken; they only
serve as a template / UX spec for the upcoming PR series that re-implements
each modal as a CnIndexPage `#form-dialog` slot component wired to
`useObjectStore` (nc-vue) + the OR `/api/objects/openconnector/{schema}/*`
endpoints.

ESLint and webpack ignore this directory. Once a modal is reborn as a
fresh bespoke component, the legacy file gets removed in the same PR.

## Files preserved

| File | LoC | Replacement plan |
|---|---|---|
| `Synchronization/EditSynchronization.vue` | 1076 | Bespoke create/edit modal — strip legacy/system fields |
| `Rule/EditRule.vue` | 1888 | Bespoke rule-builder modal (visual condition/action editor) |
| `Endpoint/AddEndpointRule.vue` | 172 | Bespoke endpoint-rule relation picker |
| `Endpoint/EditEndpoint.vue` | 451 | Bespoke endpoint create/edit modal |
| `Job/RunJob.vue` | 175 | Action-surface modal (run-job confirm) |
| `Job/TestJob.vue` | 176 | Action-surface modal (test-job dry run) |
| `Synchronization/RunSynchronization.vue` | 345 | Action-surface modal (run-sync confirm) |
| `Synchronization/TestSynchronization.vue` | 222 | Action-surface modal (test-sync dry run) |
| `TestSource/TestSource.vue` | 193 | Action-surface modal (test-source connection) |

## Removed

| File | Removed in | Replacement |
|---|---|---|
| `Mapping/EditMapping.vue` | #874 | `src/views/wrappers/MappingDetailPage.vue` + `MappingRulesEditor.vue` + `EditMappingRuleDialog.vue` (3-tab transformation-rules editor on CnDetailPage) |
| `Mapping/mappingItem/EditMappingItem.vue` | #874 | `src/dialogs/EditMappingRuleDialog.vue` |
| `Mapping/mappingItem/DeleteMappingItem.vue` | #874 | CnDetailPage's built-in delete action on the rule row |
| `MappingTest/TestMapping.vue` + its 3 sub-widgets | — | `src/modals/v2/TestMappingModal.vue` + `src/components/mapping/MappingResultPanel.vue`. The last capability still only living in the legacy tree — saving a mapped result into a register (`TestMappingResult.vue`) — moved into MappingResultPanel, which the wide editor modal, the detail page and the test modal all share. |
