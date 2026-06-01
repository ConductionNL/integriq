# Design: OpenConnector Legacy Quality Cleanup

**Status: pr-created**
**Issue:** #14
**Branch:** feature/14/openconnector-legacy-quality-cleanup

## Summary

Inventory and harden openconnector's quality gates. PHPCS is clean (0 errors);
PHPMD was run as a unified gate for the first time and a baseline was captured for
173 violations; PHPStan already has a baseline and passes.

Two PHPCS sniff errors in `lib/Service/AuthenticationService.php` (implicit boolean
comparisons) were fixed outright. A pdepend parser limitation in
`lib/Service/EndpointService.php` (complex `isset()` expression) was simplified.

## Phase 1 — Inventory results

| Gate   | Result             | Action             |
|--------|--------------------|--------------------|
| PHPCS  | 0 errors, warnings only | Already clean; 2 sniff errors fixed |
| PHPMD  | 173 violations     | Baseline captured (`phpmd.baseline.xml`) |
| PHPStan| 0 errors (baseline exists) | Already passing |

## Phase 2 — PHPCS

The `phpcs.xml` has no legacy-debt file excludes on the `development` branch.
Two "implicit true comparisons" errors in `AuthenticationService.php` were fixed
by adding explicit `=== true` comparisons to `str_starts_with()` calls.

## Phase 3 — PHPMD

173 violations (ElseExpression × 81, CyclomaticComplexity × 14, NPathComplexity × 11,
and others). Volume > 50 → baseline captured. `phpmd.baseline.xml` created via
`vendor/bin/phpmd lib/ xml phpmd.xml --generate-baseline`.

The `composer.json` `phpmd` script was updated to use the baseline and fail properly
(previously: `|| echo 'skipping...'` masked failures).

A pdepend parser error in `EndpointService.php` line 361 was fixed by extracting
the `$endpointData['configurations'] ?? []` expression to a variable before `isset()`.

## Phase 4 — PHPStan

`phpstan-baseline.neon` already exists and the gate passes clean. No new work needed.

## Phase 5 — CI integration

`composer check:strict` runs phpcs, phpmd, psalm, phpstan, and test:all. All pass.
The `phpmd` script now runs via `./vendor/bin/phpmd` (not the global binary) and
fails the build when violations exceed the baseline.

## Declarative-vs-imperative decision

Not applicable — this change contains no new Service classes.

## MCP coverage

Not applicable — no API surface changes.
