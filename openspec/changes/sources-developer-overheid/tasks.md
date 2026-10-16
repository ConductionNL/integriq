# Tasks: sources-developer-overheid

Kind: mixed under the thin-glue exception (design.md, Mixed-spec rationale). Consumed by opencatalogi `publiccode-developer-overheid-harvest`.

## Implementation Tasks

### Task 1: Source `developer-overheid-oss`
- **spec_ref**: `openspec/changes/sources-developer-overheid/specs/developer-overheid-sources/spec.md#requirement-the-oss-register-is-a-dormant-source-that-holds-only-a-credential-reference-req-dos-001`, `#requirement-the-key-is-sent-as-x-api-key-and-never-stored-or-logged-req-dos-002`
- **files**: `lib/Settings/register.d/developer-overheid-source.json`, `lib/Service/CatalogRegistryService.php`, `tests/Unit/Settings/DeveloperOverheidSourceFragmentTest.php`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN read THEN the source is disabled, located at the register's base URL, and its only key material is the placeholder `{"credentialRef": {"credentialName": "developer-overheid-oss"}}` at `configuration.authentication.apikey`
  - GIVEN the fragment WHEN read THEN `configuration.headers.X-Api-Key` is the template `{{ source.configuration.authentication.apikey }}`
  - GIVEN the catalogue WHEN listed THEN the source is under `Government registers`
- [x] Implement
- [x] Test

### Task 2: Source `gitlab-raw`
- **spec_ref**: `openspec/changes/sources-developer-overheid/specs/developer-overheid-sources/spec.md#requirement-gitlabcom-raw-files-are-a-credential-free-source-req-dos-003`
- **files**: `lib/Settings/register.d/gitlab-source.json`, `lib/Service/CatalogRegistryService.php`, `tests/Unit/Settings/DeveloperOverheidSourceFragmentTest.php`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN read THEN `gitlab-raw` is enabled, located at `https://gitlab.com`, sends `Accept: text/plain` and has no authentication
  - GIVEN the catalogue WHEN listed THEN it is under `Code hosting`
- [x] Implement
- [x] Test

### Task 3: Page count from a single-quoted `Link` and from `Total-Pages`
- **spec_ref**: `openspec/changes/sources-developer-overheid/specs/developer-overheid-sources/spec.md#requirement-a-listing-that-fits-on-one-page-ends-after-one-request-req-dos-004`
- **files**: `lib/Service/SynchronizationService.php`, `tests/Unit/Service/SynchronizationServiceLastPageTest.php`
- **acceptance_criteria**:
  - GIVEN `rel='last'`, `rel="last"` or `rel=last` WHEN parsed THEN the page number is read
  - GIVEN no `Link` count and `Total-Pages: 1` WHEN a listing is fetched THEN page 2 is not requested
  - GIVEN a later page without either header WHEN fetched THEN the count found earlier stays
- [x] Implement
- [x] Test

### Task 4: Mock of the OSS-register
- **spec_ref**: `openspec/changes/sources-developer-overheid/specs/developer-overheid-sources/spec.md#requirement-a-mock-of-the-oss-register-replays-its-shapes-req-dos-005`
- **files**: `tests/mocks/developer-overheid/server.py`, `tests/mocks/README.md`
- **acceptance_criteria**:
  - GIVEN no or a wrong `X-Api-Key` WHEN any route is called THEN 401 with the register's message
  - GIVEN `TOTAL=150` WHEN pages 1 and 2 are requested THEN 100 and 50 records with the paging headers
  - GIVEN the raw routes WHEN called THEN one file is broken YAML, one is a 404 and the rest are valid publiccode.yml files
- [ ] Implement
- [ ] Test

### Task 5: Sources manual
- **spec_ref**: `openspec/changes/sources-developer-overheid/specs/developer-overheid-sources/spec.md#requirement-the-oss-register-is-a-dormant-source-that-holds-only-a-credential-reference-req-dos-001`
- **files**: `docs/features/sources.md`
- **acceptance_criteria**:
  - GIVEN the manual WHEN read THEN it gives the recipe: request the key at https://apis.developer.overheid.nl/apis/key-aanvragen, create a `generic-apikey` credential `developer-overheid-oss` with `openconnector` in its allowed apps, enable the source; and how to point at the test environment
- [ ] Implement

### Task 6: Live check
- **spec_ref**: `openspec/changes/sources-developer-overheid/specs/developer-overheid-sources/spec.md#requirement-the-key-is-sent-as-x-api-key-and-never-stored-or-logged-req-dos-002`
- **files**: none (evidence in the PR description)
- **acceptance_criteria**:
  - GIVEN the mock and a credential holding `YOUR_API_KEY_HERE` WHEN a synchronization lists the repositories THEN one request is sent, it carries the key, and the CallLog shows the header redacted
  - GIVEN a real key WHEN `GET /repositories?publiccode=true&perPage=100` is called through the source THEN it answers 200; if it answers 401, take the OAuth2 path of proposal Risk 1 before merging
- [ ] Verify

## Verification

- All tasks checked off
- `openspec validate` passes (by hand: the CLI here lacks the `conduction` schema)
- Code review against the spec requirements

## Quality checklist

- PHPUnit for the fragments, the catalogue labels and the page count (`tests/Unit/`)
- No new API endpoint, so no Newman change; no UI change, so no browser test
- All tests pass (`composer test`)
- `docs/features/sources.md` updated (ADR-010)
- No new user-facing strings beyond seed names and descriptions (ADR-007)
- No key, token or realistic-looking secret anywhere in the change; placeholders are `YOUR_API_KEY_HERE` and the nil UUID
