---
kind: code
depends_on: []
---

## Why

`src/modals/Rule/EditRule.vue` — the rule-condition/action editor modal, integriq's
most complex Vue form — has two real, verified defects that the app's otherwise mature
a11y/i18n discipline (every other `<NcSelect>` in the app carries `input-label` or
`aria-label-combobox`; `npm run test:l10n` passes clean at 854 keys / 36 locales) missed:

1. **Two hardcoded, untranslated English literals ship as user-visible button text**:
   `Cancel` (`EditRule.vue:599`) and `Save` (`EditRule.vue:619`) in the modal's footer
   actions are plain text nodes, not `{{ t('integriq', 'Cancel') }}` /
   `{{ t('integriq', 'Save') }}`. Every other modal in this app wraps these same two
   words in `t()` — e.g. `RuleDetailPage.vue:54` (`{{ saving ? t('integriq',
   'Saving…') : t('integriq', 'Save') }}`), `AddEndpointRule.vue:41`
   (`{{ t('integriq', 'Save') }}`). Because `tests/l10n/check-l10n.js` only checks
   that keys **used via `t()`** exist in `en.json`/locale files, a literal that's never
   wrapped in `t()` is invisible to that tooling — a Dutch (or any non-English) user gets
   these two words in English while the rest of the modal is translated, in the single
   busiest editor in the app.
2. **Two icon-only `<NcButton>` instances have no accessible name.** The "remove"
   buttons for extend-input / extend-external-input list rows
   (`EditRule.vue:277-284` and `EditRule.vue:311-319`) render only a `TrashCanOutline`
   icon inside the `#icon` slot with no button text and no `aria-label` — a screen-reader
   user hears "button" with no indication of what it does or which row it removes. This
   is the same WCAG 2.1 AA 4.1.2 (Name, Role, Value) failure ADR-004's modal/input-label
   gates target, just on `NcButton` rather than `NcSelect`; every other icon-only
   `NcButton` checked in this repo (`MappingRulesEditor.vue:76-99` drag-handle/edit/
   delete buttons) correctly carries `:aria-label="t('integriq', '...')"`, so this
   is a real, local regression pattern, not house style.

## What Changes

- Wrap `Cancel` (`EditRule.vue:599`) and `Save` (`EditRule.vue:619`) in
  `{{ t('integriq', 'Cancel') }}` / `{{ t('integriq', 'Save') }}`, matching
  the existing i18n keys already present in `l10n/en.json` and translated across all 36
  locales (both strings are already used elsewhere in this app, so no new locale
  translation work is needed — `npm run test:l10n` will pick up the now-used keys against
  the existing translations).
- Add `:aria-label="t('integriq', 'Remove property')"` to the two icon-only
  `remove-action` `NcButton`s at `EditRule.vue:277` and `EditRule.vue:313` — following
  the app's existing `Remove <noun>` convention (`l10n/en.json` already has
  `"Remove condition"`, `"Remove group"`, `"Remove row"`); both removed rows are
  `extend_input`/`extend_external_input` "Property" list items, so `Remove property` is
  a new key added the same way.

## Impact

- **`src/modals/Rule/EditRule.vue`** — 2 literal→`t()` wraps, 2 `aria-label` additions.
  No behavior change, no new dependency, no schema/manifest change.
- **`l10n/*.json`** — `Cancel`/`Save` keys already exist and are translated (no new
  translation work); `Remove property` is a new key and MUST be added to `l10n/en.json`
  and translated (or run through `npm run test:l10n:write` / the app's translation
  workflow) across all 36 required locales so `npm run test:l10n` stays green.
