# Tasks: endoflife-date-source

## Implementation Tasks

### Task 1: Declare `eolProduct`/`eolCycle` schemas, seed the `endoflife-date` source, and seed the 8 curated `eolProduct` objects
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-endoflifedate-source-preset-ships-enabled-credentialfree`, `#requirement-eolproduct-and-eolcycle-schemas-are-declared-in-the-existing-openconnector-register`, `#requirement-a-curated-starter-set-of-tracked-products-is-seeded-declaratively`
- **files**: `lib/Settings/register.d/endoflife-date-source.json` (new — ADR-037 fragment: `components.registers.openconnector.schemas` += `eolProduct`, `eolCycle`; `components.schemas.eolProduct`; `components.schemas.eolCycle`; `components.objects` = 1 `source` + 8 `eolProduct` objects per design.md's Seed Data tables)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN `occ app:enable openconnector` runs THEN `source` slug `endoflife-date` exists with `isEnabled: true`, `auth: none`
  - GIVEN the same install WHEN inspecting the merged register descriptor THEN `eolProduct` and `eolCycle` are both declared under register `openconnector`, and `eolCycle.properties` includes `product`, `cycle`, `releaseDate`, `eol`, `support`, `latest`, `lts`
  - GIVEN the same install WHEN querying register `openconnector`, schema `eolProduct` THEN all 8 curated slugs (`php`, `nodejs`, `python`, `postgresql`, `mysql`, `nextcloud`, `wordpress`, `laravel`) exist
  - GIVEN `InitializeRegister` runs a second time THEN no duplicate `source` or `eolProduct` objects are created (matched by `@self.slug`)
- [ ] Implement
- [ ] Test

### Task 2: Seed one `mapping` + `synchronization` + `job` triple per curated product
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-enginenative-synchronization`
- **files**: `lib/Settings/register.d/endoflife-date-source-cycles.json` (new — ADR-037 fragment: `components.objects` = 8 × (`mapping` + `synchronization` + `job`), field values per design.md's Seed Data tables — endpoint `/{slug}.json`, `resultsPosition: "_root"` (REQUIRED — do not omit), `idPosition: "cycle"`, `deletionRatioThreshold: 0.5`, `targetId: "openconnector/eolCycle"`, `jobClass: "OCA\\OpenConnector\\Action\\SynchronizationAction"`, `arguments.synchronizationId` = that product's synchronization slug, `interval: 86400`)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN inspecting register `openconnector` THEN 8 `mapping`, 8 `synchronization`, and 8 `job` objects exist, one triple per curated product, each `synchronization` with a distinct `@self.slug`
  - GIVEN any curated product's `synchronization.sourceConfig` WHEN inspected THEN `resultsPosition` is exactly `"_root"` and `idPosition` is exactly `"cycle"` (fixture assertion — this is the field a future edit is most likely to silently drop)
  - GIVEN the `python` product's `mapping.mapping` WHEN inspected THEN `product` is the literal string `"python"` and `eol`/`support`/`discontinued` carry a `cast: "string"` directive
- [ ] Implement
- [ ] Test

### Task 3: Live-verify the seeded Job resolves its Synchronization (design.md Open Question / proposal.md Risk 3)
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-enginenative-synchronization`
- **files**: none (a verification step against the live dev/8080 instance — a code change only if the finding requires the Task 1/2 fragments to be patched)
- **acceptance_criteria**:
  - GIVEN the fragments from Tasks 1–2 are installed on a live instance WHEN a curated product's `job` is force-run (`POST /api/jobs/{id}/test` or `occ` equivalent) THEN `SynchronizationAction::run()` successfully resolves its `arguments.synchronizationId` (a slug, not a UUID) to the matching `synchronization` object and executes it
  - IF resolution instead requires a literal UUID THEN patch Task 1/2's fragments so the `job.arguments.synchronizationId` is populated with the assigned UUID via a follow-up install step (documented here, not silently worked around) — capture the outcome either way in this task's notes for future readers
- [ ] Implement
- [ ] Test

### Task 4: Unit tests for the per-product mapping recipe
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-enginenative-synchronization`
- **files**: `tests/Unit/Service/EndoflifeDateMappingTest.php` (new)
- **acceptance_criteria**:
  - GIVEN the seeded `endoflife-date-python-cycles-mapping` recipe and a realistic fixture cycle payload (`{"cycle":"3.14","releaseDate":"2025-10-07","eol":"2030-10-31","support":"2027-10-01","latest":"3.14.6","latestReleaseDate":"2026-06-10","lts":false}`) WHEN `MappingService::executeMapping()` runs THEN the output's `product` equals the literal `"python"`, `cycle`/`releaseDate`/`latest`/`latestReleaseDate` are copied verbatim, and `eol`/`support` remain their ISO date strings
  - GIVEN a fixture cycle payload where `eol` is JSON `false` (no scheduled EOL — endoflife.date's own documented shape) WHEN mapped THEN the output's `eol` is an empty string, not a PHP boolean
  - GIVEN a second curated product's mapping (spot-check one more, e.g. `nodejs`) WHEN mapped THEN `product` equals that product's own literal slug, not `"python"`
- [ ] Implement
- [ ] Test

### Task 5: Regression tests for identity isolation, idempotent upsert, and deletion-ratio guard
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-repeated-syncs-upsert-idempotently-and-garbage-collect-softdeleted-cycles`
- **files**: `tests/Unit/Service/EndoflifeDateSyncTest.php` (new — exercises the existing `SynchronizationService` against a mocked Guzzle handler/`CallService`, not new engine code)
- **acceptance_criteria**:
  - GIVEN two curated products' synchronizations both fetch a fixture response containing a coincidentally-shared cycle label WHEN both run THEN two distinct `eolCycle` objects exist, each with the correct `product`, and neither is overwritten by the other
  - GIVEN a product's synchronization has already produced N `eolCycle` objects WHEN it runs again against the same fixture response THEN still exactly N objects exist afterward
  - GIVEN 4 existing `eolCycle` contracts for a product and a next fixture response omitting 1 of them (25%) WHEN the synchronization runs THEN the absent cycle's object is deleted (within the seeded `0.5` threshold)
  - GIVEN a mocked non-2xx mid-fetch response WHEN the synchronization runs THEN no `eolCycle` object is deleted for that run
- [ ] Implement
- [ ] Test

### Task 6: Live smoke test against the real endoflife.date API
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-a-live-smoke-test-proves-the-preset-against-the-real-public-api`
- **files**: `tests/Integration/EndoflifeDateLiveSyncTest.php` (new — follows `tests/Integration/Tables/TablesBridgeIntegrationTest.php`'s established self-skipping convention)
- **acceptance_criteria**:
  - GIVEN outbound network access WHEN `vendor/bin/phpunit -c phpunit-unit.xml --testsuite "Integration Tests" --filter EndoflifeDateLiveSyncTest` runs THEN a real HTTP call to `https://endoflife.date/api/{product}.json` succeeds for at least one curated product and at least one `eolCycle` object is created with `cycle` populated
  - GIVEN the same test run again immediately after WHEN it completes THEN no additional `eolCycle` objects were created for that product (real-API idempotency proof)
  - GIVEN no network access (or `OPENCONNECTOR_SKIP_NETWORK_TESTS=1`) WHEN the same test runs THEN it reports skipped, not failed
- [ ] Implement
- [ ] Test

### Task 7: Administrator docs page
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-a-curated-starter-set-of-tracked-products-is-seeded-declaratively`
- **files**: `docs/administrators/sources/endoflife-date.md` (new)
- **acceptance_criteria**:
  - Documents: what the preset ships (source + 8 curated products, daily sync, no credential needed), where to find it (Sources / Synchronizations / Catalog pages), the `eolProduct`/`eolCycle` field shapes, and — per the "extending the tracked set requires no code change" scenario — a copy-paste recipe for adding another product from `https://endoflife.date/api/all.json` by duplicating one curated product's seed objects and substituting the slug
  - Links to the deferred full-catalog auto-discovery follow-up noted in design.md, so an operator who wants "all 460+" knows that is a known, intentionally-out-of-scope next step, not a bug
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate --change endoflife-date-source` passes
- [ ] Manual testing against acceptance criteria (Task 3's live-verify step)
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/Service/EndoflifeDateMappingTest.php`, `tests/Unit/Service/EndoflifeDateSyncTest.php`)
- [ ] Newman/Postman tests for new/changed API endpoints — N/A, this change introduces no new HTTP endpoint (only OR-declared schemas/objects consumed via the existing, generic OR object API and existing Synchronization/Job/Catalog endpoints)
- [ ] Browser tests (Playwright MCP) for UI changes — N/A beyond a smoke check that the existing, unmodified Catalog page renders the new source-template card (`connector-catalog` owns that render path's own coverage; TC-12 in test-plan.md is a smoke check, not new UI)
- [ ] All tests pass (`composer test`, `vendor/bin/phpunit -c phpunit-unit.xml --testsuite "Integration Tests" --filter EndoflifeDateLiveSyncTest` for the live smoke test)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/administrators/sources/endoflife-date.md`
- [ ] Screenshot captured and committed to `docs/images/` — the Catalog card for `endoflife-date` (Task 7)

## i18n (company-wide hydra ADR-007)

- [ ] N/A — this change adds no new user-facing UI strings. All new copy is seeded object `name`/`description` field VALUES (data, not UI chrome) and the docs page (English; the repo's existing docs are English-only, matching every other admin source doc under `docs/administrators/sources/`), plus the Catalog card's rendering, labels, and i18n keys are entirely owned by the pre-existing `connector-catalog` capability and are not touched here.
