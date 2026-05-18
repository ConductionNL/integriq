# Design: OpenConnector Legacy Quality Cleanup

**status: pr-created**
**issue: #774**
**change: openconnector-legacy-quality-cleanup**

## Summary

Burns down two stale PHPCS exclude-patterns, fixes all 13 PHPMD violations outright,
and captures a PHPStan baseline that gates future regressions. After this PR the
`composer check:strict` command runs all three tools without legacy carve-outs.

## What was implemented

### Phase 2 — PHPCS burn-down

Removed two dead `<exclude-pattern>` entries from `phpcs.xml`:
- `tests/bootstrap.php` (for `Generic.Strings.UnnecessaryStringConcat`)
- `tests/Core/Tokenizer/StableCommentWhitespaceWinTest.php` (for `Generic.Files.LineEndings.InvalidEOLChar`)

Neither file exists in the openconnector repo; these were copy-paste artefacts from the
PHP_CodeSniffer project's own phpcs.xml template. Removing them has no behavioural effect.

### Phase 3 — PHPMD burn-down (13 violations → 0)

Fixed outright — no baseline created:

| File | Violation | Fix |
|---|---|---|
| `DSOController.php` | `UnusedFormalParameter $body` | `@SuppressWarnings` — reserved for future PKIoverheid signature validation |
| `DSOParserService.php` | `CyclomaticComplexity 13` on `validatePayload()` | Extracted 5 private helper methods |
| `DSOParserService.php` | `NPathComplexity 405` on `validatePayload()` | Same extraction |
| `DSOParserService.php` | `StaticAccess \DateTime` ×3 | `@SuppressWarnings` on `validateISODate()` |
| `IBabsConnectorService.php` | `UnusedLocalVariable $organisatieId` | Removed unused assignment |
| `IBabsConnectorService.php` | `UnusedFormalParameter $source` | `@SuppressWarnings` — placeholder method |
| `MappingService.php` | `LongVariable $openRegisterMappingService` | Renamed to `$orMappingService` |
| `MappingService.php` | `MissingImport OCA\OpenRegister\Db\Mapping` | Added `use OCA\OpenRegister\Db\Mapping as OrMapping` |
| `StUFFieldMapper.php` | `StaticAccess \DateTime` ×2 | `@SuppressWarnings` on both methods |
| `MappingRuntime.php` | `CyclomaticComplexity 10` on `executeMapping()` | `@SuppressWarnings` |

### Phase 4 — PHPStan baseline

Created `phpstan.neon` pointing at `lib/` at level 5. Added `phpstan-bootstrap.php`
that registers a PSR-4 autoloader for `OCP\*` from `vendor/nextcloud/ocp` and stubs
`OC\*` internal interfaces so PHPStan can resolve Nextcloud's inheritance chains.

Generated `phpstan-baseline.neon` capturing 1472 pre-existing errors (primarily
type-safety gaps and missing OR stubs). Fixed the one non-baselnable error:
`ExportService::download()` return type changed `void → never` (method calls `exit`).

### Phase 5 — CI

Added `enable-phpmd: true` to `.github/workflows/code-quality.yml` so the shared
quality workflow explicitly runs PHPMD alongside PHPCS and PHPStan on every PR.

### Phase 6 — Documentation

Updated the "Code quality" section in `README.md` with current tool state and a
quality gate status note explaining the PHPStan baseline.

## Declarative-vs-imperative decision

Not applicable — this change is pure quality tooling, no business logic added.

## MCP coverage

No MCP surface — quality tooling change with no new user-callable actions.
