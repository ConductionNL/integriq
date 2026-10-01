# Tasks: sources-github-publiccode

Kind: code. Size M. Half for opencatalogi `publiccode-github-harvest`.

## Implementation tasks

### Task 1: GitHub source templates
- **spec_ref**: `openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-github-is-a-source-template-that-holds-only-a-credential-reference-req-ghp-001`
- **files**: `lib/Settings/register.d/github-source.json`, `lib/Service/CatalogRegistryService.php`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN the catalogue is listed THEN `github-api` and `github-raw` show under `Code hosting` and neither carries a secret
- [ ] Implement
- [ ] Test (PHPUnit on the fragment and on `CatalogRegistryService`)

### Task 2: Response decoder
- **spec_ref**: `openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-step-decodes-a-yaml-or-base64-response-req-ghp-002`
- **files**: `lib/Service/ResponseDecoder.php`, `lib/Exception/ResponseDecodeException.php`, `tests/fixtures/publiccode/`
- **acceptance_criteria**:
  - GIVEN a real publiccode.yml v0.2 and v0.4 WHEN decoded as yaml THEN both parse with dates as `Y-m-d` strings
  - GIVEN a malformed file or a `!php/object` tag WHEN decoded THEN a `ResponseDecodeException` names the reason
- [ ] Implement
- [ ] Test (PHPUnit with the fixtures)

### Task 3: `decode` on the source-call step
- **spec_ref**: `openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-file-that-does-not-decode-fails-its-item-req-ghp-003`
- **files**: `lib/Flow/SourceCallNode.php`, `lib/Flow/SourceCallConfigGuard.php`
- **acceptance_criteria**:
  - GIVEN three files, one malformed, and `onError: continue` WHEN the step runs THEN two items carry a body and one carries `_error` kind `decode`
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 4: YAML pages and a spent quota in synchronizations
- **spec_ref**: `openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-spent-quota-suspends-the-run-req-ghp-004`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a page answered 403 with `X-RateLimit-Remaining: 0` WHEN fetched THEN `TooManyRequestsHttpException` carries the reset
  - GIVEN a 403 without rate-limit headers WHEN fetched THEN the page is failed and nothing is suspended
- [ ] Implement
- [ ] Test (PHPUnit; live run against a GitHub code search mock on :8095, recorded in the PR)

### Task 5: Docs
- **spec_ref**: `openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-step-decodes-a-yaml-or-base64-response-req-ghp-002`
- **files**: `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the docs WHEN an administrator connects GitHub THEN the steps name the credential `github-publiccode` and the `decode` option
- [ ] Implement

## Verification
- [ ] `openspec validate sources-github-publiccode --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
- [ ] Live on :8095: templates seeded, code search mock pages by `Link` and suspends on 403, a real publiccode.yml decodes
- [ ] Authenticated crawl against real GitHub (coordinator, needs the PAT)
