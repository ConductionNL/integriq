# Tasks: OpenConnector Legacy Quality Cleanup

## Phase 1 — Inventory + planning

- [ ] Run `composer phpcs` and capture current baseline error count
      (target: starting from 2 exclude-patterns in phpcs.xml)
- [ ] Run `composer phpmd` for the first time as a unified gate
      and capture violation count + categories
- [ ] Run `composer phpstan` for the first time as a unified gate
      and capture error count + categories
- [ ] Decide per gate: fix-outright (if <50 violations) or capture
      a fresh baseline (if larger)
- [ ] Confirm CI runs `composer check:strict` on every PR before
      starting burn-down work

## Phase 2 — PHPCS burn-down (per excluded file)

For each file: fix errors, remove the phpcs.xml `<exclude-pattern>`
entry, verify gate stays green.

- [ ] Excluded file 1 — fix sniffs + drop exclude
- [ ] Excluded file 2 — fix sniffs + drop exclude
- [ ] Once both excludes are gone, drop the legacy-debt block from
      phpcs.xml entirely

## Phase 3 — PHPMD burn-down

Contingent on Phase 1's first-run output. If volume is small, this
phase collapses to a single fix-outright PR.

- [ ] If baseline captured: ElseExpression — re-shape `if/else` to
      early-return
- [ ] If baseline captured: CyclomaticComplexity / NPathComplexity —
      extract methods
- [ ] If baseline captured: MissingImport — add `use` statements
- [ ] If baseline captured: StaticAccess — replace with DI
- [ ] If baseline captured: variable-naming sniffs (Long/Short/
      Undefined/UnusedFormalParameter)
- [ ] Once baseline reaches 0 lines: delete phpmd.baseline.xml and
      drop `--baseline-file` from composer.json's phpmd script

## Phase 4 — PHPStan burn-down

Contingent on Phase 1's first-run output. If volume is small, this
phase collapses to a single fix-outright PR.

- [ ] Inventory phpstan errors by file/type
- [ ] Common patterns to fix:
  - [ ] Missing return-type / param-type declarations
  - [ ] Mixed types (specify generic / union)
  - [ ] Possibly-null dereferences
- [ ] Once baseline reaches 0 lines (or never created): confirm
      gate runs clean against current code

## Phase 5 — CI integration

- [ ] Verify `composer check:strict` runs in CI on every PR
- [ ] Once all baselines are empty:
  - [ ] Delete `phpmd.baseline.xml` (if it was created)
  - [ ] Delete `phpstan-baseline.neon` (if it was created)
  - [ ] Drop the legacy-debt section from `phpcs.xml`
- [ ] Add a smoke-test cron that runs `composer check:strict`
      weekly on `development`

## Phase 6 — Documentation

- [ ] Update README quality-gates section
- [ ] Note in `app-config.json` that legacy quality cleanup is done
- [ ] Close the burn-down tracking issue once the last baseline
      line is removed
