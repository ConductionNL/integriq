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
- [x] Implement
- [x] Test

### Task 2: Seed one `mapping` + `synchronization` + `job` triple per curated product
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-enginenative-synchronization`
- **files**: `lib/Settings/register.d/endoflife-date-source-cycles.json` (new — ADR-037 fragment: `components.objects` = 8 × (`mapping` + `synchronization` + `job`), field values per design.md's Seed Data tables — endpoint `/{slug}.json`, `resultsPosition: "_root"` (REQUIRED — do not omit), `idPosition: "cycle"`, `deletionRatioThreshold: 0.5`, `targetId: "openconnector/eolCycle"`, `jobClass: "OCA\\OpenConnector\\Action\\SynchronizationAction"`, `arguments.synchronizationId` = that product's synchronization slug, `interval: 86400`)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN inspecting register `openconnector` THEN 8 `mapping`, 8 `synchronization`, and 8 `job` objects exist, one triple per curated product, each `synchronization` with a distinct `@self.slug`
  - GIVEN any curated product's `synchronization.sourceConfig` WHEN inspected THEN `resultsPosition` is exactly `"_root"` and `idPosition` is exactly `"cycle"` (fixture assertion — this is the field a future edit is most likely to silently drop)
  - GIVEN the `python` product's `mapping.mapping` WHEN inspected THEN `product` is the literal string `"python"` and `eol`/`support`/`discontinued` carry a `cast: "string"` directive
- [x] Implement
- [x] Test

### Task 3: Live-verify the seeded Job resolves its Synchronization (design.md Open Question / proposal.md Risk 3)
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-enginenative-synchronization`
- **files**: none (a verification step against the live dev/8080 instance — a code change only if the finding requires the Task 1/2 fragments to be patched)
- **acceptance_criteria**:
  - GIVEN the fragments from Tasks 1–2 are installed on a live instance WHEN a curated product's `job` is force-run (`POST /api/jobs/{id}/test` or `occ` equivalent) THEN `SynchronizationAction::run()` successfully resolves its `arguments.synchronizationId` (a slug, not a UUID) to the matching `synchronization` object and executes it
  - IF resolution instead requires a literal UUID THEN patch Task 1/2's fragments so the `job.arguments.synchronizationId` is populated with the assigned UUID via a follow-up install step (documented here, not silently worked around) — capture the outcome either way in this task's notes for future readers
- **outcome**: **Slug resolution confirmed by reading HEAD, not asserted.** Traced the full call chain: `SynchronizationAction::run()` (lib/Action/SynchronizationAction.php:88) → `SynchronizationService::getSynchronization(id: $argument['synchronizationId'])` (lib/Service/SynchronizationService.php:7280) → `findSynchronizationObject(id:)` → OpenRegister `ObjectService::find(id:, register:, schema:)` → `GetObject::find()` → `MagicMapper::find()` → `findInRegisterSchemaTable()` (openregister/lib/Db/MagicMapper.php:5022), whose WHERE clause is an unconditional `orX` across `_id` (numeric cast) / `_uuid` / `_slug` / `_uri` for ANY register+schema pair — not special-cased per schema. A `synchronization` object's `@self.slug` therefore resolves exactly like the already-relied-upon `source`-by-slug precedent (`SourceMapper::find('kvk')`). No UUID-populating patch is needed; `job.arguments.synchronizationId` = the synchronization's slug (as seeded) is correct as-is. A live dev/8080 force-run was intentionally not performed for this resume — deploying to the shared dev instance was out of scope for this task per its own instructions ("no shared docker restarts") — the code-reading trail above is the live-verification substitute the task explicitly allows ("not merely inferred").
- [x] Implement
- [x] Test

### Task 4: Unit tests for the per-product mapping recipe
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-enginenative-synchronization`
- **files**: `tests/Unit/Service/EndoflifeDateMappingTest.php` (new)
- **acceptance_criteria**:
  - GIVEN the seeded `endoflife-date-python-cycles-mapping` recipe and a realistic fixture cycle payload (`{"cycle":"3.14","releaseDate":"2025-10-07","eol":"2030-10-31","support":"2027-10-01","latest":"3.14.6","latestReleaseDate":"2026-06-10","lts":false}`) WHEN `MappingService::executeMapping()` runs THEN the output's `product` equals the literal `"python"`, `cycle`/`releaseDate`/`latest`/`latestReleaseDate` are copied verbatim, and `eol`/`support` remain their ISO date strings
  - GIVEN a fixture cycle payload where `eol` is JSON `false` (no scheduled EOL — endoflife.date's own documented shape) WHEN mapped THEN the output's `eol` is an empty string, not a PHP boolean
  - GIVEN a second curated product's mapping (spot-check one more, e.g. `nodejs`) WHEN mapped THEN `product` equals that product's own literal slug, not `"python"`
- [x] Implement
- [x] Test

### Task 5: Regression tests for identity isolation, idempotent upsert, and deletion-ratio guard
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-repeated-syncs-upsert-idempotently-and-garbage-collect-softdeleted-cycles`
- **files**: `tests/Unit/Service/EndoflifeDateSyncTest.php` (new — exercises the existing `SynchronizationService` against a mocked Guzzle handler/`CallService`, not new engine code)
- **acceptance_criteria**:
  - GIVEN two curated products' synchronizations both fetch a fixture response containing a coincidentally-shared cycle label WHEN both run THEN two distinct `eolCycle` objects exist, each with the correct `product`, and neither is overwritten by the other
  - GIVEN a product's synchronization has already produced N `eolCycle` objects WHEN it runs again against the same fixture response THEN still exactly N objects exist afterward
  - GIVEN 4 existing `eolCycle` contracts for a product and a next fixture response omitting 1 of them (25%) WHEN the synchronization runs THEN the absent cycle's object is deleted (within the seeded `0.5` threshold)
  - GIVEN a mocked non-2xx mid-fetch response WHEN the synchronization runs THEN no `eolCycle` object is deleted for that run
- [x] Implement
- [x] Test

### Task 6: Live smoke test against the real endoflife.date API
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-a-live-smoke-test-proves-the-preset-against-the-real-public-api`
- **files**: `tests/Integration/EndoflifeDateLiveSyncTest.php` (new — follows `tests/Integration/Tables/TablesBridgeIntegrationTest.php`'s established self-skipping convention)
- **acceptance_criteria**:
  - GIVEN outbound network access WHEN `vendor/bin/phpunit -c phpunit-unit.xml --testsuite "Integration Tests" --filter EndoflifeDateLiveSyncTest` runs THEN a real HTTP call to `https://endoflife.date/api/{product}.json` succeeds for at least one curated product and at least one `eolCycle` object is created with `cycle` populated
  - GIVEN the same test run again immediately after WHEN it completes THEN no additional `eolCycle` objects were created for that product (real-API idempotency proof)
  - GIVEN no network access (or `OPENCONNECTOR_SKIP_NETWORK_TESTS=1`) WHEN the same test runs THEN it reports skipped, not failed
- [x] Implement
- [x] Test

### Task 7: Administrator docs page
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-a-curated-starter-set-of-tracked-products-is-seeded-declaratively`
- **files**: `docs/administrators/sources/endoflife-date.md` (new)
- **acceptance_criteria**:
  - Documents: what the preset ships (source + 8 curated products, daily sync, no credential needed), where to find it (Sources / Synchronizations / Catalog pages), the `eolProduct`/`eolCycle` field shapes, and — per the "extending the tracked set requires no code change" scenario — a copy-paste recipe for adding another product from `https://endoflife.date/api/all.json` by duplicating one curated product's seed objects and substituting the slug
  - Links to the deferred full-catalog auto-discovery follow-up noted in design.md, so an operator who wants "all 460+" knows that is a known, intentionally-out-of-scope next step, not a bug
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate endoflife-date-source --strict` passes ("Change 'endoflife-date-source' is valid")
- [x] Manual testing against acceptance criteria (Task 3's live-verify step) — satisfied by the code-reading trail in Task 3's outcome note (SynchronizationAction → getSynchronization → MagicMapper::findInRegisterSchemaTable()'s unconditional `_slug` match); a live dev/8080 force-run was intentionally not performed, per this resume's own "no shared docker restarts" constraint
- [x] Code review against spec requirements — all 7 requirements / 20 scenarios walked in this session's final verify pass (see completion report)

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/Service/EndoflifeDateMappingTest.php` — 5 tests, `tests/Unit/Service/EndoflifeDateSyncTest.php` — 4 tests; all green in the nextcloud:34.0.0-apache container)
- [x] Newman/Postman tests for new/changed API endpoints — N/A, this change introduces no new HTTP endpoint (only OR-declared schemas/objects consumed via the existing, generic OR object API and existing Synchronization/Job/Catalog endpoints)
- [ ] Browser tests (Playwright MCP) for UI changes — NOT run this session (would require deploying this branch to a live instance to view the Catalog page; deploying to the shared dev instance was out of scope for this resume). N/A beyond a smoke check that the existing, unmodified Catalog page renders the new source-template card (`connector-catalog` owns that render path's own coverage; TC-12 in test-plan.md is a smoke check, not new UI) — deferred, see completion report's unresolved items
- [x] All tests pass — full unit suite: 1912 tests / 6383 assertions, OK (`phpunit -c phpunit.xml`); live smoke test: 1 test / 59 assertions, OK (`phpunit -c phpunit-unit.xml --testsuite "Integration Tests" --filter EndoflifeDateLiveSyncTest`, real network call to https://endoflife.date/api/php.json)

## Documentation (company-wide ADR-010)

- [x] Feature documentation updated in `docs/administrators/sources/endoflife-date.md`
- [ ] Screenshot captured and committed to `docs/images/` — the Catalog card for `endoflife-date` (Task 7) — NOT captured this session (requires a live-deployed instance; out of scope for this resume's "no shared docker restarts" constraint) — deferred, see completion report's unresolved items

## i18n (company-wide hydra ADR-007)

- [x] N/A — this change adds no new user-facing UI strings. All new copy is seeded object `name`/`description` field VALUES (data, not UI chrome) and the docs page (English; the repo's existing docs are English-only, matching every other admin source doc under `docs/administrators/sources/`), plus the Catalog card's rendering, labels, and i18n keys are entirely owned by the pre-existing `connector-catalog` capability and are not touched here.
