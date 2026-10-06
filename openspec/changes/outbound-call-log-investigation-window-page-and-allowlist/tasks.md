# Tasks: outbound-call-log-investigation-window-page-and-allowlist

Kind: code. Size S. Woo row 13.23, decision D5. Wave 1. The second part of
`outbound-call-log-investigation-window`; task numbers continue from it. Build after the first part has merged on
`development`: the window endpoints and the `bodyCaptured` field this part
shows come from there.

The tests named below fail on `development` today: no allowlist constant and
no window dialog exist.

## Implementation tasks

### Task 5: The code allowlist
- **spec_ref**: `openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-code-may-keep-bodies-only-for-listed-public-data-callers-req-ocd-010`
- **files**: `lib/Outbound/Call/BodyCapturePolicy.php` (`LOG_BODY_ALLOWLIST`), `lib/Service/CallService.php` (`normaliseConfig()` honours `logBody` only for a listed caller, passed explicitly)
- **acceptance_criteria**:
  - GIVEN the SLO adapter WHEN it passes `logBody` THEN the response body is stored
  - GIVEN any other class under `lib/` that passes `logBody` WHEN the suite runs THEN the scan test fails naming it
- [ ] Implement
- [ ] Test: PHPUnit `CallServiceBodyCaptureTest::testOnlyListedCallersPassLogBody` (scans `lib/` for `'logBody'` and compares against the allowlist)

### Task 6: The source page
- **spec_ref**: `openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011`
- **files**: the source detail page, a dialog in its own file under `src/dialogs/` (hours, reason), `l10n/en.json`, `l10n/nl.json`, `docs/features/outbound-call-log.md`
- **acceptance_criteria**:
  - GIVEN a source without a window WHEN an administrator opens one THEN the page shows "Bodies are stored until {time}" and a button to stop early
  - GIVEN a record in the call log WHEN it was not captured THEN the detail says "No body stored: no investigation window was open"
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/outbound-call-log.spec.ts` (open a window, see a captured record, stop early)

## Verification

The building agent follows `openspec/woo-build-rules.md`:

- [ ] Own clone, `git checkout --no-track -b <branch> origin/development`, `TMPDIR` a sibling outside the clone.
- [ ] PHPUnit judged by the `Tests:` line, or with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`.
- [ ] CI runs the gates on the full tree; the coverage guard needs a test for every added statement.
- [ ] `openspec validate outbound-call-log-investigation-window-page-and-allowlist --type change --strict` passes.
- [ ] One PR, `--base development`; merge `development` in, never rebase; no `Co-Authored-By` trailer.
- [ ] Done means merged on `development` with CI green. Row 13.23 is `production` only once a store release carries it.
