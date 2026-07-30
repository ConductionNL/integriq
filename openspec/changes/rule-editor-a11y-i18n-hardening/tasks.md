## 1. Fix untranslated literals

- [x] 1.1 In `src/modals/Rule/EditRule.vue:599`, replace the bare `Cancel` text node with `{{ t('openconnector', 'Cancel') }}`
- [x] 1.2 In `src/modals/Rule/EditRule.vue:619`, replace the bare `Save` text node with `{{ t('openconnector', 'Save') }}`
- [x] 1.3 Ran `npm run test:l10n` — both keys already existed in `l10n/en.json` and all 36 locales (no new key needed for these two).

## 2. Fix icon-only remove buttons

- [x] 2.1 Added `:aria-label="t('openconnector', 'Remove property')"` to the `NcButton` at `src/modals/Rule/EditRule.vue:277` (extend_input remove row)
- [x] 2.2 Added the same `:aria-label="t('openconnector', 'Remove property')"` to the `NcButton` at `src/modals/Rule/EditRule.vue:313` (extend_external_input remove row)
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
- [ ] 3.2 NOT DONE — requires a live browser/Nextcloud instance and a screen reader, out of scope for this isolated worktree (no Docker/instance access permitted for this task). The `aria-label` attributes are present in the markup (verified by direct file inspection) and the `t()`-wrapped Cancel/Save strings resolve against existing translated locale files (verified via `npm run test:l10n`), which together are the mechanical proxy for this manual check.
- [x] 3.3 `openspec validate rule-editor-a11y-i18n-hardening --strict` → "Change 'rule-editor-a11y-i18n-hardening' is valid".
