## 1. Fix untranslated literals

- [x] 1.1 In `src/modals/Rule/EditRule.vue:599`, replace the bare `Cancel` text node with `{{ t('integriq', 'Cancel') }}`
- [x] 1.2 In `src/modals/Rule/EditRule.vue:619`, replace the bare `Save` text node with `{{ t('integriq', 'Save') }}`
- [x] 1.3 Ran `npm run test:l10n` — both keys already existed in `l10n/en.json` and all 36 locales (no new key needed for these two).

## 2. Fix icon-only remove buttons

- [x] 2.1 Added `:aria-label="t('integriq', 'Remove property')"` to the `NcButton` at `src/modals/Rule/EditRule.vue:277` (extend_input remove row)
- [x] 2.2 Added the same `:aria-label="t('integriq', 'Remove property')"` to the `NcButton` at `src/modals/Rule/EditRule.vue:313` (extend_external_input remove row)
- [x] 2.3 Added `"Remove property": "Remove property"` to `l10n/en.json` (alphabetical position, between "Remove group" and "Remove row"), then hand-translated and added the key to all 36 required-locale `l10n/<locale>.json` files (real translations, not copies of the English source).
- [x] 2.4 Ran `npm run test:l10n` — full parity confirmed, no missing/empty keys.
      NOTE: this same `npm run test:l10n` run also caught 16 PRE-EXISTING missing keys
      introduced earlier in this session by change #1 (`dso-stam-pkioverheid-signature-verification`'s
      new `DsoPkiSettings.vue` admin panel — its English literals were never wrapped
      against `l10n/en.json`). Per house rule ("always fix pre-existing problems in the
      same batch"), those 16 keys were added to `en.json` (via `npm run test:l10n:write`)
      and hand-translated across all 36 required locales in this same pass, so the
      overall `npm run test:l10n` gate is green, not just the 1 key this change owns.

## 3. Verify

- [x] 3.1 `npx eslint src/views/admin/DsoPkiSettings.vue src/views/admin/AdminSettings.vue` — clean (0 errors). `EditRule.vue` itself is under `src/modals/**`, which `eslint.config.js:31` globally excludes from lint by design (legacy modals, only `src/modals/v2/**` is linted) — nothing to run there, consistent with existing repo convention.
- [x] 3.2 Checked live on 26 Nov 2026: sandbox NC 34.0 + MariaDB, OpenRegister main, integriq development built, Chromium via Playwright. `EditRule.vue` was removed in the meantime ("remove the unreachable legacy rule and endpoint modals"), so the check ran on its replacement, the rule detail page (`/apps/integriq/rules/{id}`, `ExtendInputForm.vue` and `ExtendExternalInputForm.vue`). In the accessibility tree, which is what a screen reader announces, an `extend_input` rule with two properties shows two `button "Remove row"`, and an `extend_external_input` rule with three properties shows three. With the user's language set to Dutch they read `Rij verwijderen`, and Save, Cancel and Discard read `Opslaan`, `Annuleren` and `Verwerpen`. No screen reader itself was run; the accessible names are what it reads. The delta spec is reworded to the current surface (REQ-RULEUI-009/010).
- [x] 3.3 `openspec validate rule-editor-a11y-i18n-hardening --strict` → "Change 'rule-editor-a11y-i18n-hardening' is valid".
