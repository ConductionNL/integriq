---
kind: code
depends_on: []
---

## Why

The Rules index page opens the generic, schema-generated `CnFormDialog`. Its field list
came from `pages[Rules].config.includeFields` in `src/manifest.json`:
`["name", "description", "action", "timing", "type"]` — five fields, rendered
alphabetically, all as plain text inputs. Creating a rule from the index produced a stub
that had to be finished on a second screen, and three separate schema-driven limitations
made that unfixable declaratively:

1. **`action`, `timing` and `type` are enum-less strings** on the `rule` schema, so
   `resolveWidget()` in nc-vue's `utils/schema.js` falls through to `text`. Three closed
   vocabularies got free-text inputs. `timing` in particular has exactly two valid values
   — `EndpointService::handleRuleProcessing()` compares
   `($ruleData['timing'] ?? 'before') === $timing` against a `$timing` only ever passed
   `'before'` or `'after'` — while the schema's own description advertises four different
   words ("pre-request, post-request, pre-response, post-response") that the engine has
   never understood.
2. **`conditions` is `type: object`**, and `fieldsFromSchema()` drops plain objects that
   carry no `widget` hint. The single most important field on a rule was not on the form
   at all.
3. **The error response lives at `configuration.error.*`.** `fieldsFromSchema()` walks
   only top-level `schema.properties`, so no nested path is reachable through
   `includeFields`/`fieldOverrides` at any setting.

`order` was a fourth casualty: present in `config.columns`, absent from `includeFields`,
so it was listed on the index but not editable anywhere except the detail page.

The pre-manifest modal (`src/modals/Rule/EditRule.vue`, 1919 lines, preserved unwired as
an extraction reference) had all of these. This change brings that field set back as a
wide `form-dialog` slot component, hosting the components the rule detail page already
uses rather than reimplementing them.

Two latent defects surfaced while tracing the type field and are fixed here, because the
new modal writes the same property and would otherwise disagree with the detail page:

- **Rules authored on the detail page were never executed.** `RuleActionConfig.onTypePick()`
  wrote the action type to `configuration.type` only, and
  `RuleDetailPage.onConfigurationUpdate()` stored that blob without touching `draft.type`.
  The pipeline dispatches on the **top-level** `type`
  (`EndpointService.php:2590`, `$ruleType = ($ruleData['type'] ?? '')`), whose `match`
  ends in `default => throw new Exception('Unsupported rule type: ')`.
  `configuration.type` is read only by `RuleService::processCustomRule()`, to sub-dispatch
  rules whose top-level type is `custom`. The component's own docblock asserted the
  opposite. The pre-manifest modal got this right (`type: type || null`), which is why the
  bug only appeared after the detail page replaced it.
- **`action` was unreachable on the detail page.** It is one of only two `required`
  properties on the `rule` schema (`["name", "action"]`), and the page had no editor for
  it, so a rule created there saved without the field endpoints filter on.

## What Changes

- **New** `src/modals/v2/RuleEditorModal.vue` — the wide create/edit surface, wired
  through `pages[Rules].slots["form-dialog"]`. Name, description, a JsonLogic conditions
  editor (visual `RuleConditionGroup` with a raw-JSON toggle), timing/order/action/type,
  and the `configuration.error.*` block shown only when the type is `error`.
  `form-dialog` rather than `form-fields` because CnIndexPage does not forward `size` to
  CnFormDialog, so an inner-content override can never exceed NcDialog's `normal` width —
  the same constraint that put `MappingEditorModal` and `SynchronizationEditorModal` in
  that slot.
- **New** `src/views/Rule/ruleDraft.js` — shared option vocabularies (`ACTION_TYPES`
  moved out of `RuleActionConfig.vue`, plus `TIMING_OPTIONS`, `ACTION_OPTIONS`,
  `DEFAULT_ERROR_CONFIG`), the draft factory, and the conditions round trip
  (`normaliseConditions` / `serializeRuleConditions`). `serializeRuleConditions()` returns
  a bare **object**, deliberately unlike `syncDraft.serializeConditions()` which returns
  an array — the two schemas type the field differently.
- `views/Synchronization/syncDraft.js` re-exports `normaliseConditions` /
  `EMPTY_ROOT_GROUP` from there instead of defining them, retiring the third copy its own
  docblock flagged. Every existing import path keeps working.
- `RuleActionConfig.vue` emits `update:type` alongside `update`, gains a `type` prop as
  the fallback when `configuration.type` is absent (so rules written by the pre-manifest
  modal no longer render an empty action picker), and has its inverted docblock corrected.
- `RuleDetailPage.vue` writes the top-level `type`, gains an `action` select, and swaps
  the free-text Timing input for a two-value select.
- `l10n/en.json` gains the 13 new source strings this adds. Where the pre-manifest modal's
  keys already exist and are translated across all 36 locales — `Error Code`,
  `Error Title`, `Error Message`, `Include JSON Logic results in errors array`,
  `When (conditions)`, `Then (action)`, `Raw JSON`, `Visual builder`, `Format JSON` — they
  are reused rather than re-cased into near-duplicates.

## Non-goals

- The other 14 per-action-type configuration blocks. Their bespoke forms already exist
  under `src/views/Rule/actionForms/` and are hosted by `RuleActionConfig` on the detail
  page; cramming 16 conditional blocks into a dialog is what made the legacy modal
  unmaintainable. The modal's Type helper text points at "Open full editor".
- Implementing a backend handler for the `upload` action type. It is offered by the UI
  (and has an `UploadForm.vue`) but has no `match` arm in **either** pipeline —
  `EndpointService::handleRuleProcessing()` or `SynchronizationService::processRules()` —
  so such a rule throws `Unsupported rule type: upload` when reached. Recorded as
  `UNDISPATCHED_ACTION_TYPES` in `ruleDraft.js` and pinned by a test that fails when the
  arm lands, rather than silently dropped from the picker (which would strand any rule
  already carrying the type).
- Making `src/modals/v2/` lint. `eslint.config.js` carries a `!src/modals/v2/**`
  un-ignore that does not work — `src/modals/**` matches the directory entry too, and
  `eslint src` prunes an ignored directory before the negation can match a file, so all 13
  v2 files (this change's modal included) are silently skipped. Fixing it surfaces ~25
  pre-existing JSDoc/attribute-order warnings in five unrelated v2 modals, which is a
  change of its own rather than a rider on a feature. Recorded in `src/modals/README.md`.
- Clearing the pre-existing `npm run test:l10n` backlog. It already fails with 70 missing
  keys unrelated to rules; this change adds its own 13 and leaves the rest.
- Correcting the `rule` schema's misleading `timing`/`type` descriptions. That requires a
  `1.3.0 → 1.4.0` version bump (guarded by
  `RemainingSecretLeaksTest::testTouchedSchemasHadTheirVersionBumped`) and an
  OpenRegister re-import. Adding `enum: ["before", "after"]` would additionally fail
  validation for existing rows holding anything else.
