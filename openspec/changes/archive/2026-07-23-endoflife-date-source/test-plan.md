# Test Plan: endoflife-date-source

## Test Cases

### TC-1: endoflife-date source materialises on install, enabled, no auth
- **spec_ref**: `openspec/changes/endoflife-date-source/specs/endoflife-date-source/spec.md#requirement-endoflifedate-source-preset-ships-enabled-credentialfree`
- **type**: api
- **preconditions**: fresh OpenRegister + OpenConnector install
- **steps**: run `occ app:enable openconnector` (or upgrade); query OR object API for register `openconnector`, schema `source`, slug `endoflife-date`
- **expected result**: object exists with `location = https://endoflife.date/api`, `auth = none`, `isEnabled = true`
- **test command**: PHPUnit (`tests/Unit/Repair` or `tests/Unit/Settings` fixture-load test), not `/test-api` (no bespoke endpoint)

### TC-2: seed re-import is idempotent (source)
- **spec_ref**: same requirement, "seed re-import is idempotent" scenario
- **type**: regression
- **preconditions**: `endoflife-date` source already materialised
- **steps**: re-run `InitializeRegister`
- **expected result**: exactly one `endoflife-date` source object exists, unchanged uuid
- **test command**: PHPUnit

### TC-3: eolProduct and eolCycle schemas declared in the openconnector register
- **spec_ref**: `#requirement-eolproduct-and-eolcycle-schemas-are-declared-in-the-existing-openconnector-register`
- **type**: api
- **preconditions**: merged register descriptor after fragment fold-in
- **steps**: parse the merged descriptor's `components.schemas`
- **expected result**: `eolProduct` and `eolCycle` both present under register `openconnector`'s `schemas` array; no second register created
- **test command**: PHPUnit (JSON fixture assertion)

### TC-4: eolCycle schema covers required field list
- **spec_ref**: same requirement, "eolCycle schema covers the brief's required fields"
- **type**: api
- **preconditions**: merged register descriptor
- **steps**: inspect `components.schemas.eolCycle.properties`
- **expected result**: `product`, `cycle`, `releaseDate`, `eol`, `support`, `latest`, `lts` all present
- **test command**: PHPUnit

### TC-5: all eight curated eolProduct objects exist after install
- **spec_ref**: `#requirement-a-curated-starter-set-of-tracked-products-is-seeded-declaratively`
- **type**: api
- **preconditions**: fresh install
- **steps**: query OR object API for register `openconnector`, schema `eolProduct`
- **expected result**: 8 objects with slugs `php`, `nodejs`, `python`, `postgresql`, `mysql`, `nextcloud`, `wordpress`, `laravel`
- **test command**: PHPUnit

### TC-6: mapping recipe unit tests (per curated product shape)
- **spec_ref**: `#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-enginenative-synchronization`
- **type**: regression
- **preconditions**: none — pure unit test of `MappingService::executeMapping()` against the seeded `endoflife-date-python-cycles-mapping` recipe (and, spot-checked, one other curated product's mapping) with a realistic fixture cycle payload
- **steps**: run `tests/Unit/Service/EndoflifeDateMappingTest.php`
- **expected result**: `product` is the literal slug; `cycle`/`releaseDate`/`latest`/`latestReleaseDate` are copied verbatim; `eol`/`support`/`discontinued` are cast to string (a JSON `false` input becomes an empty string, a date string passes through unchanged); `lts` is passed through uncast
- **test command**: PHPUnit unit test (this change's own new test file)

### TC-7: sourceConfig.resultsPosition omission would break the fetch (documented regression guard)
- **spec_ref**: same requirement, `resultsPosition: "_root"` REQUIRED clause
- **type**: regression
- **preconditions**: seeded synchronization fixture
- **steps**: assert the seeded `synchronization.sourceConfig.resultsPosition` field equals `"_root"` for every curated product's fragment entry
- **expected result**: field present and correct on all 8 — a config-fixture assertion that fails loudly if a future edit drops it, rather than only failing at 3am when the daily cron runs
- **test command**: PHPUnit (JSON fixture assertion)

### TC-8: two curated products never collide on cycle identity
- **spec_ref**: same requirement, "two curated products never collide" scenario
- **type**: regression
- **preconditions**: two curated products' synchronizations both write a cycle with a coincidentally-shared label
- **steps**: run both products' synchronizations against fixture/mock responses sharing a cycle label; inspect resulting `eolCycle` objects
- **expected result**: two distinct `eolCycle` objects, each with the correct `product`, neither overwritten
- **test command**: PHPUnit (exercises the real `SynchronizationService` against a mocked `CallService`/Guzzle handler — the existing engine, not new code)

### TC-9: repeated sync is idempotent (no duplicates)
- **spec_ref**: `#requirement-repeated-syncs-upsert-idempotently-and-garbagecollect-softdeleted-cycles`
- **type**: regression
- **preconditions**: a product's synchronization has already produced N `eolCycle` objects
- **steps**: re-run the same synchronization with unchanged source data
- **expected result**: still exactly N `eolCycle` objects afterward
- **test command**: PHPUnit against mocked responses; ALSO exercised for real in TC-13 (live smoke test)

### TC-10: retired cycle is garbage-collected within the raised deletion-ratio guard
- **spec_ref**: same requirement, "retired cycle is garbage-collected" scenario
- **type**: regression
- **preconditions**: 4 existing `eolCycle` contracts for a product; next fetch omits 1 (25%)
- **steps**: run the synchronization
- **expected result**: the now-absent cycle's object is deleted (25% ≤ the seeded `0.5` threshold)
- **test command**: PHPUnit

### TC-11: an incomplete fetch never triggers deletion
- **spec_ref**: same requirement, "incomplete fetch" scenario
- **type**: regression
- **preconditions**: mocked non-2xx response mid-fetch
- **steps**: run the synchronization
- **expected result**: no `eolCycle` object deleted for that run
- **test command**: PHPUnit — this is pre-existing `synchronization-engine` REQ-010 behaviour; this test only confirms the preset's config does not accidentally disable it

### TC-12: endoflife-date appears on the Catalog page without new UI code
- **spec_ref**: `#requirement-the-preset-is-automatically-visible-on-the-catalog-page`
- **type**: functional
- **preconditions**: `endoflife-date` source seed installed
- **steps**: open the Catalog page as an admin
- **expected result**: a card for "endoflife.date" is visible with an "available" status badge
- **test command**: `/test-functional` (browser) — smoke-level only; the render mechanism itself is `connector-catalog`'s own coverage, not re-tested here

### TC-13: live smoke test against the real public API
- **spec_ref**: `#requirement-a-live-smoke-test-proves-the-preset-against-the-real-public-api`
- **type**: api
- **preconditions**: outbound network access available
- **steps**: `vendor/bin/phpunit -c phpunit-unit.xml --testsuite "Integration Tests" --filter EndoflifeDateLiveSyncTest`
- **expected result**: a real call to `https://endoflife.date/api/{product}.json` succeeds; at least one `eolCycle` object is created with `cycle` populated; a second run produces no duplicates
- **test command**: PHPUnit (`tests/Integration/EndoflifeDateLiveSyncTest.php`, this change's new test file)

### TC-14: live smoke test self-skips without network access
- **spec_ref**: same requirement, "self-skips" scenario
- **type**: regression
- **preconditions**: `OPENCONNECTOR_SKIP_NETWORK_TESTS=1`, or a simulated network-isolated environment
- **steps**: run the same test command as TC-13
- **expected result**: test reports skipped, not failed; suite exit code unaffected
- **test command**: PHPUnit

## Coverage Summary

| Requirement | Covered |
|---|---|
| endoflife.date source preset ships enabled, credential-free | TC-1, TC-2 |
| eolProduct/eolCycle schemas declared in the openconnector register | TC-3, TC-4 |
| curated starter set seeded declaratively | TC-5 |
| each curated product syncs via a dedicated, engine-native Synchronization | TC-6, TC-7, TC-8 |
| repeated syncs upsert idempotently and garbage-collect soft-deleted cycles | TC-9, TC-10, TC-11 |
| preset is automatically visible on the Catalog page | TC-12 |
| live smoke test proves the preset against the real public API | TC-13, TC-14 |

## Out of Scope

- The "extending the tracked set requires no code change" scenario (docs
  recipe) is validated by documentation review, not an automated test — it
  describes an operator workflow, not system behaviour.
- softwarecatalog's consumption of `eolCycle` (matching a catalog module's
  version to its EOL window) is entirely out of this test plan — it is
  covered by softwarecatalog's own `eol-feed-integration` change.
- Persona and accessibility test types are not applicable — this change
  has no new user-facing UI beyond the existing, unmodified Catalog page
  render path (TC-12 is a smoke check, not a full persona/a11y pass).
