# Legacy modals — extraction reference

The three SFCs in the *Files preserved* table below are **dead at runtime** —
`src/modals/Modals.vue` (the dispatcher that imported all 47 pre-chain-C
modals) was deleted along with the 30+ modal files whose UX is covered by
nc-vue primitives (`CnFormDialog`, `CnDeleteDialog`, `CnDetailPage`,
`CnMassDeleteDialog`).

The other SFCs still under this tree — `PromotePreviewModal.vue`,
`Subscription/SubscriptionSigningModal.vue`,
`Synchronization/SyncDeadLetterDetailModal.vue`,
`EventDelivery/EventDeliveryDetailModal.vue`,
`EventSubscription/SubscriptionActionFields.vue` and
`NotificatiesAbonnement/NotificatiesAbonnementForm.vue` — are **live** and
imported; they are not part of the extraction backlog.

What remains here is the set of modals flagged by the chain-C src/ audit as
needing **bespoke extraction** rather than blanket deletion. Their existing
imports against deleted per-schema stores (`sourceStore`, `mappingStore`,
…) and the deleted `src/entities/` tree are intentionally broken; they only
serve as a template / UX spec for the upcoming PR series that re-implements
each modal as a CnIndexPage `#form-dialog` slot component wired to
`useObjectStore` (nc-vue) + the OR `/api/objects/openconnector/{schema}/*`
endpoints.

ESLint and webpack ignore this directory. Once a modal is reborn as a
fresh bespoke component, the legacy file gets removed in the same PR —
unless part of its behaviour has no v2 home yet, in which case say so in the
table below rather than deleting the reference.

Caveat on that first sentence: `eslint.config.js` intends `src/modals/v2/` to
lint (it carries a `!src/modals/v2/**` un-ignore) but does not achieve it —
`src/modals/**` matches the directory entry too, and `eslint src` prunes an
ignored directory before the negation can match a file, so all of v2 is
skipped. Verifiable by appending an unused const to any v2 file and running
`npm run lint`. Fixing it un-ignores 13 previously unchecked files and
surfaces ~25 JSDoc/attribute-order warnings in five of them, so it wants its
own change rather than riding along with a feature.

## Files preserved

| File | LoC | Replacement plan |
|---|---|---|
| `Rule/EditRule.vue` | 1919 | **Partly extracted.** Its basic-field surface — name, description, conditions, timing, order, action, type and the `configuration.error.*` block — is reborn as `src/modals/v2/RuleEditorModal.vue`, wired to the Rules index through `pages[Rules].slots["form-dialog"]`. The file stays because the remaining 14 per-action-type blocks are not extracted from *here*: their bespoke forms live under `src/views/Rule/actionForms/` and are hosted by `RuleActionConfig` on the rule detail page, and only the `authentication`/API-key half still has behaviour with no v2 home — `buildAuthenticationConfiguration.js` (covered by `tests/vitest/buildAuthenticationConfiguration.spec.js`) is imported from nowhere else. Remove both once API-key authoring moves into `AuthenticationForm`. |
| `Endpoint/AddEndpointRule.vue` | 172 | Bespoke endpoint-rule relation picker |
| `Endpoint/EditEndpoint.vue` | 451 | Bespoke endpoint create/edit modal |

## Removed

| File | Removed in | Replacement |
|---|---|---|
| `Mapping/EditMapping.vue` | #874 | `src/views/wrappers/MappingDetailPage.vue` + `MappingRulesEditor.vue` + `EditMappingRuleDialog.vue` (3-tab transformation-rules editor on CnDetailPage) |
| `Mapping/mappingItem/EditMappingItem.vue` | #874 | `src/dialogs/EditMappingRuleDialog.vue` |
| `Mapping/mappingItem/DeleteMappingItem.vue` | #874 | CnDetailPage's built-in delete action on the rule row |
| `MappingTest/TestMapping.vue` + its 3 sub-widgets | — | `src/modals/v2/TestMappingModal.vue` + `src/components/mapping/MappingResultPanel.vue`. The last capability still only living in the legacy tree — saving a mapped result into a register (`TestMappingResult.vue`) — moved into MappingResultPanel, which the wide editor modal, the detail page and the test modal all share. |
| `Synchronization/EditSynchronization.vue` | — | `src/modals/v2/SynchronizationEditorModal.vue` — the same three-column source/transform/target surface, rebuilt on the components `SynchronizationDetailPage` already uses (`SyncConfigWidget`, `SyncMappingPicker`, `SyncReferenceList`, `RuleConditionGroup`) with the shared logic in `views/Synchronization/syncDraft.js`. |
| `Synchronization/RunSynchronization.vue` | — | `src/modals/v2/RunActionModal.vue` — the same "switches, then a result table" shape, rebuilt generically. Its test-mode and force switches are back (and joined by `forceDeletion`, which no UI had ever exposed); its flat 18-row result table is now a counter grid plus a meta list. |
| `Synchronization/TestSynchronization.vue` | — | `src/modals/v2/RunActionModal.vue` (`synchronization/test` descriptor). Nothing was carried over: it rendered an HTTP status/headers/body table copied from `TestSource`, which does not match the run-log payload `POST /api/synchronizations/{id}/test` actually returns, so it would have shown an empty table even had it been wired up. |
| `TestSource/TestSource.vue` | #f5af2cac | `src/modals/v2/TestSourceModal.vue`. |
| `Job/RunJob.vue` | — | `src/modals/v2/RunActionModal.vue` (`job/run` descriptor). |
| `Job/TestJob.vue` | — | `src/modals/v2/RunActionModal.vue` (`job/test` descriptor) — under the honest name. `JobsController::test()` is `run()` with `forceRun` forced on, not a dry run, so the modal and the row-action label both say "Force run". |
