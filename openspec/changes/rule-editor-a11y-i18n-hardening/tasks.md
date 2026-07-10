## 1. Fix untranslated literals

- [ ] 1.1 In `src/modals/Rule/EditRule.vue:599`, replace the bare `Cancel` text node with `{{ t('openconnector', 'Cancel') }}`
- [ ] 1.2 In `src/modals/Rule/EditRule.vue:619`, replace the bare `Save` text node with `{{ t('openconnector', 'Save') }}`
- [ ] 1.3 Run `npm run test:l10n` and confirm both keys resolve against the existing `l10n/en.json` entries (no new key needed for these two)

## 2. Fix icon-only remove buttons

- [ ] 2.1 Add `:aria-label="t('openconnector', 'Remove property')"` to the `NcButton` at `src/modals/Rule/EditRule.vue:277` (extend_input remove row)
- [ ] 2.2 Add the same `:aria-label="t('openconnector', 'Remove property')"` to the `NcButton` at `src/modals/Rule/EditRule.vue:313` (extend_external_input remove row)
- [ ] 2.3 Add the new `"Remove property": "Remove property"` key to `l10n/en.json`, then run `npm run test:l10n:write` (or the app's translation workflow) to populate the key across all 36 required locales
- [ ] 2.4 Run `npm run test:l10n` and confirm full parity (no missing/empty keys)

## 3. Verify

- [ ] 3.1 `npm run lint` passes on the touched file
- [ ] 3.2 Manually open the Rule editor modal, select an action type that renders `extend_input` (or `extend_external_input`) rows, and confirm: (a) the remove icon button is now announced with a name by a screen reader / has a visible tooltip via `aria-label`; (b) Cancel/Save render translated text when the browser locale is set to a non-English locale (e.g. `nl`)
- [ ] 3.3 `openspec validate rule-editor-a11y-i18n-hardening --strict`
