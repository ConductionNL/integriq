# Tasks — restore-rule-editor-modal

## 1. Shared rule draft/option module — DONE

`src/views/Rule/ruleDraft.js`: `EMPTY_ROOT_GROUP` + `emptyRootGroup()`, `ACTION_TYPES`
(moved out of `RuleActionConfig.vue`), `UNDISPATCHED_ACTION_TYPES`, `TIMING_OPTIONS`,
`ACTION_OPTIONS`, `DEFAULT_ERROR_CONFIG`, `emptyRuleDraft()`, `normaliseConditions()`,
`serializeRuleConditions()`.

`views/Synchronization/syncDraft.js` re-exports the normaliser instead of defining it.

## 2. The modal — DONE

`src/modals/v2/RuleEditorModal.vue`, registered in `src/registry.js` (default export —
slot components resolve there, not in the kind-tagged `registry`), wired via
`pages[Rules].slots["form-dialog"]` in `src/manifest.json`. `includeFields` dropped,
`showEditAction: true` added, the row `view` action relabelled "Open full editor".

## 3. Top-level `type` + `action` on the detail page — DONE

`RuleActionConfig.vue` emits `update:type` and takes a `type` prop as fallback;
`RuleDetailPage.vue` handles it, adds the `action` select and the `timing` select.

## 4. Strings — DONE

`l10n/en.json` gains the 13 new source strings, reusing the pre-manifest modal's already
translated keys (`Error Code`, `Error Title`, `Error Message`, …) instead of minting
re-cased duplicates.

## 5. Tests — DONE

`tests/vitest/ruleDraft.spec.js` (34 assertions): conditions round trip across every
persisted shape, object-not-array serialisation, draft-isolation (no shared nested
arrays), and the option vocabularies checked against both backend pipelines' `match`
arms.

## 6. Follow-ups (not this change)

- Make `src/modals/v2/` actually lint. The `!src/modals/v2/**` un-ignore in
  `eslint.config.js` does not take effect — `eslint src` prunes `src/modals` before the
  negation can match — so all 13 v2 files, this change's modal included, are unchecked.
  Listing the legacy per-entity directories individually fixes it and surfaces ~25
  JSDoc/attribute-order warnings in five v2 modals.
- Clear the `npm run test:l10n` backlog (70 pre-existing missing keys).
- Implement an `upload` rule handler, or retire the type. Trim
  `UNDISPATCHED_ACTION_TYPES` when the arm lands — `ruleDraft.spec.js` fails until it is.
- Offer the eight backend-dispatchable types with no authoring UI (`audit_trail`,
  `override`, `custom`, `composite_fanout`, `referentienummer`, `avg_bsn_policy`,
  `selfurl_hal`, `flow`); each needs a form.
- Fix the `rule` schema's `timing`/`type` descriptions (needs a 1.3.0 → 1.4.0 bump).
- Retire `src/modals/Rule/EditRule.vue` + `buildAuthenticationConfiguration.js` once
  API-key authoring moves into `actionForms/AuthenticationForm.vue`.
- `{ ...EMPTY_ROOT_GROUP }` shallow spreads still share the constant's `and` array in
  `views/Flow/FlowStepRow.vue` (local copy) and
  `views/Synchronization/SynchronizationDetailPage.vue` / `syncDraft.js`. Same latent
  mutation bug fixed here for the rule surfaces; left alone to keep this change scoped.
