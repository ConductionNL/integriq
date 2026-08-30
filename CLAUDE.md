# integriq — l10n tooling

Ported from openregister, which shares the tooling. The app id is
**`integriq`**. Every wrap is:

```js
t('integriq', 'Some user-visible string')
n('integriq', '{count} contract', '{count} contracts', count, { count })
```

Use the scripts for CRUD on `l10n/*.js`. Hand-editing means 37 files kept in sync
by hand, no validation and no consistent formatting. Reading a whole locale file
into context also costs hundreds of tokens per call, and the conversation is
resent every turn.

## Two independent translation sets

| File set | Consumer | Read by |
| --- | --- | --- |
| `l10n/*.js` | **frontend** | `OC.L10N.register` → `t()` / `n()` |
| `l10n/*.json` | **backend** | PHP `IL10N` |

They are **separate catalogues with separate consumers**, not two renderings of
one source. Both are real and both are maintained. Do not assume a change to one
implies the other.

**A `t()` call in `.vue`/`.js` belongs in `en.js`, never in `en.json`.** The gate
used to assert frontend keys against `en.json` — a file no frontend code path
reads — which demanded bookkeeping in the backend catalogue while the one the
browser loads went unaudited. `tests/l10n/check-l10n.js` now targets `en.js`.

There is **no scanner for the backend set**. Auditing `en.json` would mean
walking `lib/` for PHP `$l->t()` calls, not `src/`. Until that exists, `en.json`
is maintained by hand.

## Current state

Re-measure before trusting any of this — run `npm run check:l10n`, which prints
all of it. The numbers below are a snapshot, not a fact about the repo.

**As of 2026-08-13**: `en.js` holds 1281 keys and `src/` uses 846, all 846
present. The missing-key half of the drift is closed and `npm run test:l10n`
passes; **432 of the keys present are unused**, so the unused half is not.

That direction is the one with a trap in it, not just untidiness — see
`clean:l10n` under Gotchas. Some of those 432 are live UI prose nobody has
wrapped in `t()` yet, so they are not dead, merely invisible to the scanner.

The missing half was invisible at runtime, which is exactly why it rotted: a
missing key makes `OC.L10N` fall back to the English source string, which
renders correctly.

Of the 37 locales, `nl` is the only substantially complete one (674 keys, 647
translated); most others sit near 510 keys and predate the current UI. Those
counts are against the old 572-key catalogue, so every locale is now further
behind `en.js` than its own numbers suggest — `npm run test:l10n:parity` reports
roughly 430 missing keys per locale.

## Commands

| You want to… | Run |
| --- | --- |
| Check a key / view its values | `node scripts/l10n-ai.js has\|get\|find` |
| Add, update, remove, rename a key | `node scripts/l10n-ai.js add\|set\|rm\|rename` |
| Audit `en.js` vs `src/` (missing / unused / unwrapped) | `npm run check:l10n` |
| Gate every locale (missing, identical, plural arity) | `npm run test:l10n:parity` |
| Assert `en.js` covers every `t()`/`n()` call (**the CI gate**) | `npm run test:l10n` |
| Extract new keys into `en.js` | `npm run test:l10n:write` |
| Find prose in `.vue` that isn't wrapped yet | `npm run find:unwrapped` |
| Delete keys no source file references | `npm run clean:l10n` (dry-run) |

`test:l10n` is the gate CI runs; `check:l10n` is the richer developer audit (it
adds unused + unwrapped but has no write mode). Both read `en.js` through the
same extractor, so they always agree on what "used" means.

## Hard rules

**Never write a value equal to its key** — in any locale except `en`. This is the
one rule that matters most, because breaking it is invisible.

- **Absent** → `OC.L10N` falls back to the English source. Renders correct text,
  and every tool can still see the key is untranslated, so it stays on the list.
- **`value === key`** → renders the same characters but is indistinguishable from
  finished work, to tooling and to the next maintainer. It is never revisited.

So a legitimate cognate (`ID`, `URL`, `CSV`, `PDF`, `Webhook`, and `Format` /
`Metadata` in many languages) is **omitted**, not written out.
`npm run test:l10n:parity` fails on identical values for this reason; it reports
absent ones separately and less severely.

`en` is the exception: `en` **is** the source language, so `value === key` is
correct there, and that is what `test:l10n:write` emits.

**Plural arrays must match that locale's own `nplurals`.** The count comes from
the locale file's own header, and the *expression* differs between languages that
share a count — Russian, Polish and Czech are all `nplurals=3` with three
mutually incompatible rules. Never copy a plural array from one language to
another. A wrong-length array makes the string render blank at runtime, and it is
the only l10n defect you cannot see by reading the file.

An `n()` call has two source strings but **one** catalogue key — the singular,
whose value is the forms array. The plural source is never its own key.

**Never overwrite an existing real translation.** `l10n-ai.js` refuses without
`--force`; trust the refusal and investigate. Only replace a real value when it
is genuinely wrong, and say why in the commit.

**When adding a string, `en` is required; other locales are optional.** With 37
shipped locales, demanding a hand-written value for each one per string is not
workable and invites exactly the placeholder-shaped filler the first rule
forbids. Add `en`, plus any locale you can genuinely do well, and leave the rest
absent. Use `--locales=` to narrow.

**Commit one language at a time** when translating, so a bad locale can be
reverted alone.

## Gotchas

- **`clean:l10n` needs review before every run.** It removes keys from **all 37**
  locale files, and its current list is 432 keys. Some of those are live UI prose
  that was simply never wrapped in `t()` — deleting those discards translations
  needed the moment someone wraps the string. Dry-run first (`npm run
  clean:l10n`), cross-check against `npm run find:unwrapped`, then remove by hand.
  The npm alias is deliberately the dry run, not `--apply`.
  When checking a key yourself, match the **whole quoted literal**, not a
  substring.
- Locale files are **not** linted, by design. `serializeJs` emits the exact
  on-disk Nextcloud/Transifex layout; `eslint --fix` would rewrite every line to
  tabs and single quotes and diverge from what Transifex regenerates. The eslint
  rules are for source code, not generated translation data.
- **The first write to `en.js` re-sorts it.** `serializeJs` sorts with
  case-insensitive code-unit order, which the other locale files are already in
  but `en.js` is not — it is still in original extraction order. Expect one large
  diff, once.
- `l10n-ai.js rename` does **not** rewrite call sites. Grep `src/` afterwards.
- `l10n-ai.js set` refuses pluralized (array) keys — edit those by hand.
- `find:unwrapped` is deliberately high-recall (60 candidates across 8 files
  today). Expect false positives and audit by hand; do not "fix" it by tightening
  the heuristic until real strings are missed.
- `test:l10n:parity` currently fails: most locales are incomplete. Compare
  against the previous run rather than expecting green.
- `scripts/lib/l10n.js` is **vendored** from openregister — the two apps ship
  separate npm packages, so there is no import path between them. Keep the copies
  in sync; the only intended divergence is `DYNAMIC_KEYS`, which is app-specific.

## Translating a whole locale

Read **`docs/l10n-ui-translation.md`** first. It covers the parts that are not
mechanical: measuring a language's formality register against Nextcloud core
instead of assuming it, why harvested translations from core and sibling apps
must be checked against the call site, and the per-locale conventions already
established.
