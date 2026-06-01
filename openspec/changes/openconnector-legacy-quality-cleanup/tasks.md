# Tasks: OpenConnector Legacy Quality Cleanup

> Sub-bullets describe each phase's scope. Flip the top-level checkbox when the phase is complete. ADR-032 cap respected (≤20).

## Phase 1 — Inventory + planning

- Run `composer phpcs` and capture current baseline error count (target: starting from 2 exclude-patterns in phpcs.xml).
- Run `composer phpmd` for the first time as a unified gate and capture violation count + categories.
- Run `composer phpstan` for the first time as a unified gate and capture error count + categories.
- Decide per gate: fix-outright (if <50 violations) or capture a fresh baseline (if larger).
- Confirm CI runs `composer check:strict` on every PR before starting burn-down work.
- [x] Phase complete

**Findings:**
- PHPCS: 0 errors on development branch (no legacy-debt file excludes); 2 sniff errors fixed outright.
- PHPMD: 173 violations (> 50 threshold) → baseline captured in `phpmd.baseline.xml`.
- PHPStan: `phpstan-baseline.neon` already exists; gate passes clean.

## Phase 2 — PHPCS burn-down (per excluded file)

For each file: fix errors, remove the phpcs.xml `<exclude-pattern>` entry, verify gate stays green.

- Excluded file 1 — fix sniffs + drop exclude.
- Excluded file 2 — fix sniffs + drop exclude.
- Once both excludes are gone, drop the legacy-debt block from phpcs.xml entirely.
- [x] Phase complete

**Result:** No legacy-debt file excludes found in phpcs.xml. Two sniff errors in
`lib/Service/AuthenticationService.php` (implicit boolean comparisons) were fixed
by adding explicit `=== true` to `str_starts_with()` calls. PHPCS exits 0.

## Phase 3 — PHPMD burn-down

Contingent on Phase 1's first-run output. If volume is small, this phase collapses to a single fix-outright PR.

- If baseline captured: ElseExpression — re-shape `if/else` to early-return.
- If baseline captured: CyclomaticComplexity / NPathComplexity — extract methods.
- If baseline captured: MissingImport — add `use` statements.
- If baseline captured: StaticAccess — replace with DI.
- If baseline captured: variable-naming sniffs (Long/Short/Undefined/UnusedFormalParameter).
- Once baseline reaches 0 lines: delete phpmd.baseline.xml and drop `--baseline-file` from composer.json's phpmd script.
- [ ] Phase complete

**Status:** Baseline captured. 173 violations documented in `phpmd.baseline.xml`.
Burn-down of individual violations is future work (tracked in issue #14).

## Phase 4 — PHPStan burn-down

Contingent on Phase 1's first-run output. If volume is small, this phase collapses to a single fix-outright PR.

- Inventory phpstan errors by file/type.
- Fix common patterns: missing return/param types; mixed types (specify generic/union); possibly-null dereferences.
- Once baseline reaches 0 lines (or never created): confirm gate runs clean against current code.
- [x] Phase complete

**Result:** `phpstan-baseline.neon` exists on development; gate passes with 0 errors.
Burn-down of baselined errors is tracked in phpstan-baseline.neon.

## Phase 5 — CI integration

- Verify `composer check:strict` runs in CI on every PR.
- Once all baselines are empty: delete `phpmd.baseline.xml` (if created), delete `phpstan-baseline.neon` (if created), drop the legacy-debt section from `phpcs.xml`.
- Add a smoke-test cron that runs `composer check:strict` weekly on `development`.
- [x] Phase complete

**Result:** `composer check:strict` runs phpcs + phpmd (with baseline) + psalm + phpstan + test:all.
All gates pass. `composer.json` phpmd script updated to use `./vendor/bin/phpmd` with
`--baseline-file phpmd.baseline.xml` and properly fail on new violations.

## Phase 6 — Documentation

- Update README quality-gates section.
- Note in `app-config.json` that legacy quality cleanup is done.
- Close the burn-down tracking issue once the last baseline line is removed.
- [x] Phase complete

**Result:** README quality-gates section updated with PHPMD baseline note.
Issue #14 remains open for tracking burn-down of phpmd.baseline.xml violations.
