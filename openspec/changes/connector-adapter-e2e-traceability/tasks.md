## 1. Annotate stuf-adapter (58 scenarios, zero @e2e today)

- [x] 1.1 Add one `@e2e exclude backend StUF-BG/StUF-ZKN integration — covered by PHPUnit, not browser UI` line under every `#### Scenario:` in `openspec/specs/stuf-adapter/spec.md` (mirror the exact wording/placement pattern used in `openspec/specs/endpoint-runtime/spec.md`). Implemented as ONE exclude line per `### Requirement:` section (16 sections, covering all 58 scenarios beneath each) — this is the literal `endpoint-runtime` pattern (one line per Requirement, before its scenarios), which gate-19 accepts as covering every scenario in that requirement's block.
- [x] 1.2 Confirmed `find src -iname "*stuf*"` returns nothing (no Vue UI exists).

## 2. Annotate ibabs-notubiz-connector (46 scenarios, zero @e2e today)

- [x] 2.1 Added one exclude line per `### Requirement:` section (14 sections, 46 scenarios covered).
- [x] 2.2 Confirmed `find src -iname "*ibabs*" -o -iname "*notubiz*"` returns nothing.

## 3. Annotate the dso-omgevingsloket scenarios not owned by the in-flight signature-verification change

- [x] 3.1 Added one exclude line per `### Requirement:` section EXCEPT `REQ-DSO-050` (13 of 14 sections annotated, 53 scenarios covered).
- [x] 3.2 Left the REQ-DSO-050 scenarios (owned by `dso-stam-pkioverheid-signature-verification`, applied earlier in this same session) unannotated here — verified no `@e2e` line was added under that Requirement's heading.

Content-integrity check performed on all three files: `diff` of every `### Requirement`/`#### Scenario`/bullet line between the pre- and post-edit versions was empty — the transformation only inserted new lines, it did not alter or reorder any existing scenario text.

## 4. Close the STUF auth zero-coverage gap (security-relevant path)

- [x] 4.1 Located `CallService::getCertificate()`/`removeFiles()`/`writeFile()` (`lib/Service/CallService.php:300-432`) and `AuthenticationService::buildWsSecurityHeader()` (`lib/Service/AuthenticationService.php:574-612`, already implemented, spec-tagged `#REQ-STUF-012`).
- [x] 4.2 Added 5 new PHPUnit tests to `tests/Unit/Service/CallServiceTest.php` for REQ-STUF-011: string-form cert write, ssl_key write, escaped-`\n`→real-newline conversion (byte-for-byte), array-form `[pem, password]` cert (password preserved untouched), and combined cert+ssl_key+verify cleanup via `removeFiles()`.
- [x] 4.3 Added `tests/Unit/Service/AuthenticationServiceTest.php` (7 tests) for REQ-STUF-012: header structure/username, `PasswordDigest` verified against an independently hand-recomputed `Base64(SHA1(rawNonce + Created + Password))` (extracted from the method's own output, not a canned truthy check), nonce-randomization-per-call, `PasswordText` plaintext, default-mode behavior, missing-credentials fail-closed, and XML-escaping of the username.
- [x] 4.4 Both implementations MATCH their spec's stated formula/behavior exactly — no discrepancy found, nothing to file as a new gap.
- [x] 4.5 Ran the full PHPUnit unit suite: 392/392 green (was 380 before this change's 12 new tests). Did NOT run the full `composer check:strict` (phpmd/psalm/phpstan) in this pass — out of the isolated-worktree time budget; ran `phpcs --standard=phpcs.xml lib/` instead (0 errors, only pre-existing unrelated `@spec`-tag warnings on files this change did not touch).

## 5. Validate

- [x] 5.1 `openspec validate connector-adapter-e2e-traceability --strict` → "Change 'connector-adapter-e2e-traceability' is valid".
- [x] 5.2 Manually reviewed `check_e2e_coverage.py`'s exclusion logic (read-only, outside this worktree per task scope): it accepts `@e2e exclude <reason>` "in the spec's scenario block or its parent requirement block", and the `endpoint-runtime` file it was modeled on uses exactly the same one-per-Requirement placement — confirms the format used here parses correctly.
