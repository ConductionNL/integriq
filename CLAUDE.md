# opencatalogi — l10n tooling

This project ships dedicated scripts for every l10n / i18n operation. **Use them.** Do not read or edit `l10n/*.js` files by hand, do not grep them for keys, and do not invent new translation entries with `Edit`/`Write`. Manual edits skip the tools' validation, formatting, and ESLint pass and produce inconsistent files across locales.

`l10n/*.json` is the **backend** (PHP) translation set, maintained through a separate workflow. Never read or modify it. All scripts below operate on `l10n/*.js` only.

The wrap pattern in source code is always:

```js
t('opencatalogi', 'Some user-visible string')
```

Plurals use `n('opencatalogi', 'singular', 'plural', count)`.

---

## Decision tree

| You want to… | Use |
| --- | --- |
| Check whether a key exists / view its value | `l10n-ai.js has` / `get` / `find` |
| Add a new translation key | `l10n-ai.js add` |
| Update one locale's value for an existing key | `l10n-ai.js set` |
| Remove or rename a key everywhere | `l10n-ai.js rm` / `rename` |
| Audit the whole project (missing / unused / unwrapped-by-key) | `npm run check:l10n` |
| Find prose-shaped literals in `.vue` that aren't yet wrapped | `npm run find:unwrapped` |
| Delete keys in `l10n/*.js` that no source file references | `npm run clean:l10n` |

---

## `scripts/l10n-ai.js` — CRUD on `l10n/*.js`

Single-purpose subcommands, line-oriented machine-readable output, exit 0 on success / non-zero on failure. Invoke one subcommand per call so each operation stays cheap. After any write the script runs `eslint --fix` on the touched files.

```bash
node scripts/l10n-ai.js <subcommand> [args...]
```

### Read

```bash
node scripts/l10n-ai.js has <key> [--ignore-case]
node scripts/l10n-ai.js get <key>
node scripts/l10n-ai.js find <substring>          # case-insensitive substring search over keys
node scripts/l10n-ai.js list-locales
```

`has` and `get` exit non-zero (and print `none`) when the key is absent — useful as a guard before `add` vs `set`.

### Write

```bash
# Add a key. Requires --value for EVERY targeted locale (no silent English fallback).
node scripts/l10n-ai.js add <key> \
    --value en="English text" \
    --value nl="Nederlandse tekst" \
    [--locales=en,nl] \
    [--force]   # overwrite if the key already exists

# Update one locale's value for an EXISTING key.
node scripts/l10n-ai.js set <key> --locale=<lang> --value="<text>"

# Remove a key from every locale. Refuses if src/ still references it; pass --force to override.
node scripts/l10n-ai.js rm <key> [--force]

# Rename a key everywhere. Source-file callsites are NOT rewritten — update them yourself.
node scripts/l10n-ai.js rename <old> <new> [--force]
```

Rules and gotchas:

- **Always `add` before `set`.** `set` errors if the key isn't present in the target locale.
- **`add` needs a value per targeted locale.** Run `list-locales` first if you don't know which locales ship. Don't fabricate translations for languages you can't translate to — narrow with `--locales=en` and let the human / translation workflow add the rest.
- **Pluralized keys (array values) cannot be edited via `set`** — the script will refuse and tell you to edit the file by hand. Use this only for plural arrays; do not use it as a workaround for single-string edits.
- **`rm` checks references in `src/`.** Trust the refusal; investigate before passing `--force`.
- **`rename` does not touch callsites.** After renaming, grep `src/` for the old key and update each `t('opencatalogi', '<old>')` to `<new>`.
- **The English key IS the source string.** When adding, the `en` value is conventionally identical to the key.

---

## `scripts/check-l10n.js` — audit (`npm run check:l10n`)

Scans `src/` (`.vue`, `.js`, `.ts`) and compares to `l10n/en.js`. Reports four sections, exits non-zero if any of the first three are non-empty:

1. **MISSING** — strings used via `t('opencatalogi', '...')` that aren't in `en.js`. Fix with `l10n-ai.js add`.
2. **UNUSED** — keys in `en.js` with no matching `t()` call. Fix with `npm run clean:l10n` (or by hand if you intentionally want to keep them).
3. **UNWRAPPED literals matching an l10n key** — string literals in `.vue` templates whose text exactly matches an existing `en.js` key but which aren't wrapped in `t()`. Wrap them.
4. **Unanalyzable t() calls** — calls with dynamic args; informational only, not a failure.

Run this before declaring l10n work done.

---

## `scripts/find-unwrapped.js` — high-recall unwrapped-string detector (`npm run find:unwrapped`)

Complements `check-l10n.js`: where check-l10n's UNWRAPPED section only flags literals that **already exist as keys**, this script flags **any literal that looks like prose** — even strings that have never been translated. Heuristic, expect false positives, audit each hit by hand.

```bash
npm run find:unwrapped                                  # template only (default)
npm run find:unwrapped -- --include-script              # also scan <script> blocks (noisier)
npm run find:unwrapped -- --json                        # machine-readable output
npm run find:unwrapped -- --min-length=4                # raise the prose-shape threshold
npm run find:unwrapped -- src/views/Foo.vue             # restrict to a path
```

(Pass flags after `--` so npm forwards them to the script instead of consuming them itself.)

What it inspects in `<template>`:

- **Text nodes** between tags (`>Some text<`).
- **String literals inside `{{ ... }}` interpolations** (e.g. `{{ x ? 'are' : 'is' }}`).
- **Static attribute values** on a curated list of prose-bearing attrs (`label`, `title`, `placeholder`, `aria-label`, `name`, `text`, `tooltip`, `error-message`, `empty-content-name`, `submit-button-text`, … see `PROSE_ATTRS` in the script).
- **Bound attribute expressions** (`:title="..."`, `v-tooltip="..."`) — both single literals and embedded literals inside ternaries / function calls.

What it filters out (so you don't have to):

- Anything inside `t('opencatalogi', ...)`.
- URLs, hex colors, CSS dimensions, file paths, Vue directive names, function-call literals.
- Code tokens (`true`, `asc`, `primary`, `flex`, …), single lowercase identifiers, snake_case, CamelCase identifiers.
- Bracketed property access (`obj['title']`), equality comparisons (`x === 'markdown'`), and arguments to `*Store` method calls (`objectStore.foo('bar')`).
- `NcSelect` / `NcSelectTags` / `NcSelectUsers` `label` attrs (vue-select option-key prop, not display text).
- Slot-prop pattern `name="name"` / `label="label"` (literal value equals attr name → almost always a slot/prop reference).

Workflow:

1. Run the script, get a list of `file:line:col\t[kind]\t"value"` candidates.
2. For each real prose hit, wrap it with `t('opencatalogi', '...')` in source.
3. Add the new key with `l10n-ai.js add`.
4. Re-run `npm run check:l10n` to confirm zero MISSING / UNWRAPPED.

Exit code: 0 if no candidates, 1 otherwise (so CI can gate).

---

## `scripts/clean-l10n.js` — remove unused keys (`npm run clean:l10n`)

Removes keys that exist in `l10n/*.js` but are not referenced by any `t('opencatalogi', '...')` call in `src/`. Removed from **every** locale `.js` file (English and translations) so they stay aligned.

```bash
node scripts/clean-l10n.js          # dry-run, prints what would be removed
node scripts/clean-l10n.js --apply  # actually remove (this is what `npm run clean:l10n` does)
```

Important constraints:

- **Does NOT add missing keys.** Adding a key without a translation would let `t()` return English-as-Dutch silently instead of falling back to the source string. Add missing keys via `l10n-ai.js add`, never by editing files.
- Safe **only** because frontend `l10n/*.js` is independent of backend `l10n/*.json`. Do not adapt this script for projects that keep `.js` and `.json` in sync.
- After running, `npm run check:l10n` should report zero UNUSED.

---

## End-to-end recipe: introducing a new user-visible string

1. Write the markup with the wrap in place: `<NcButton :title="t('opencatalogi', 'Save changes')">…</NcButton>`.
2. Register the key:
   ```bash
   node scripts/l10n-ai.js add "Save changes" --value en="Save changes" --locales=en
   ```
   (Add `--value nl="Wijzigingen opslaan"` when you have the translation.)
3. `npm run check:l10n` — confirm no MISSING.
4. `npm run find:unwrapped` — confirm no related candidates remain.

## End-to-end recipe: cleaning up after a refactor

1. `npm run find:unwrapped` — wrap any prose that lost its `t()` during the refactor.
2. `npm run check:l10n` — see what's MISSING (add via `l10n-ai.js add`) and what's UNUSED.
3. `npm run clean:l10n` — drop the unused keys.
4. `npm run check:l10n` — must be clean.
