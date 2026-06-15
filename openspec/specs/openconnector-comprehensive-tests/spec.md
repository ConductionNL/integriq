# openconnector-comprehensive-tests Specification

## Purpose
TBD - created by archiving change openconnector-comprehensive-tests. Update Purpose after archive.
## Requirements
### Requirement: ObjectServiceMockBuilder helper MUST exist

The `tests/Helpers/ObjectServiceMockBuilder.php` file MUST exist and MUST provide a
static factory method that returns a pre-configured `MockObject` for `ObjectService` with
sensible defaults for `find`, `findAll`, `saveObject`, and `deleteObject`.

#### Scenario: helper used in SourceServiceTest

- GIVEN `ObjectServiceMockBuilder::make($this)` is called inside a PHPUnit test case
- WHEN `SourceService::getSource('00000000-0000-0000-0000-000000000001')` is invoked
- THEN the mock's `find` method is called with `('openconnector', 'source', '...')` and the test passes without additional mock setup

### Requirement: Every chain-C service class MUST have a corresponding PHPUnit test file

Every service introduced or rewritten by chain C MUST have a matching test file under `tests/Unit/Service/` covering at least one happy path and one error path per public method.

Concretely this covers `CallService`, `EndpointService`, `EventService`, `JobService`, `MappingService`, `RuleService`, `SourceService`, `SynchronizationService`, and `LegacyToRegisterMigrator`.

#### Scenario: SourceServiceTest covers getSource happy path

- GIVEN `SourceService` is constructed with a mocked `ObjectService`
- WHEN `getSource('00000000-0000-0000-0000-000000000001')` is called
- THEN `$objectService->find(id: '00000000-0000-0000-0000-000000000001', register: 'openconnector', schema: 'source')` is called exactly once (verified via `Mock::expects(once())->method('find')->with(id: ..., register: 'openconnector', schema: 'source')`) and the returned object is forwarded to the caller

#### Scenario: SourceServiceTest covers getSource not-found path

- GIVEN `ObjectService::find` is mocked to throw `DoesNotExistException`
- WHEN `getSource('00000000-0000-0000-0000-000000000001')` is called
- THEN `DoesNotExistException` is re-thrown (not swallowed or wrapped)

#### Scenario: JobServiceTest covers createJob happy path

- GIVEN `JobService` is constructed with a mocked `ObjectService`
- WHEN `createJob(['name' => 'test-job', 'interval' => '0 * * * *'])` is called
- THEN `$objectService->saveObject(object: $payload, register: 'openconnector', schema: 'job')` is called exactly once with the correct register/schema/payload (verified via `Mock::expects(once())->method('saveObject')->with(object: $payload, register: 'openconnector', schema: 'job')`)

### Requirement: Every write-side DTO MUST have a PHPUnit test file

Every write-side DTO introduced by chain C MUST have a test file under `tests/Unit/Dto/` verifying both required-field validation and round-trip serialisation.

Concretely the 15 DTOs are `SourceDto`, `EndpointDto`, `ConsumerDto`, `MappingDto`, `JobDto`, `RuleDto`, `SynchronizationDto`, `SynchronizationContractDto`, `CallLogDto`, `JobLogDto`, `SynchronizationLogDto`, `SynchronizationContractLogDto`, `CloudEventDto`, `EventMessageDto`, `EventSubscriptionDto`. Each test MUST verify: (a) `fromArray([])` throws `\InvalidArgumentException` when required fields are missing, and (b) a round-trip `fromArray($data)->toArray()` equals `$data` with no metadata fields injected.

#### Scenario: SourceDtoTest rejects missing name

- GIVEN `SourceDto::fromArray([])` is called with an empty array
- WHEN the call executes
- THEN `\InvalidArgumentException` is thrown with a message identifying the missing `name` field

#### Scenario: SourceDtoTest round-trip serialisation

- GIVEN `SourceDto::fromArray(['name' => 'test', 'type' => 'api'])` is called
- WHEN `->toArray()` is called on the result
- THEN the returned array equals `['name' => 'test', 'type' => 'api']` and contains no `id`, `uuid`, `created`, `updated`, or `owner` keys

### Requirement: CI MUST enforce 80% line and 70% branch coverage as merge-blocking gates

The `tests.yml` workflow MUST run `composer test:coverage` followed by a gate script that
reads `coverage/clover.xml` and exits non-zero if line coverage is below 80% or branch
coverage is below 70%.

#### Scenario: coverage gate blocks a PR below 80% line coverage

- GIVEN the clover report reports 75% line coverage
- WHEN the `phpunit` CI job runs the gate script
- THEN the job exits non-zero and the PR merge is blocked

#### Scenario: coverage gate passes at exactly 80% line coverage

- GIVEN the clover report reports exactly 80% line coverage and 70% branch coverage
- WHEN the CI gate script runs
- THEN the script exits 0 and the job succeeds

### Requirement: Newman collection MUST exist and cover all REST endpoints

The file `tests/postman/openconnector.postman_collection.json` MUST exist as a valid
Postman Collection v2.1 JSON. It MUST contain at least one happy-path request and one
error-path request for every controller method registered in `appinfo/routes.php`.

#### Scenario: Newman collection runs without failures against a seeded dev container

- GIVEN the dev container is running with seed data from `openconnector_seed_data.json`
- WHEN `newman run tests/postman/openconnector.postman_collection.json --environment tests/postman/openconnector.postman_environment.json` is executed
- THEN Newman exits 0 with 0 failures

#### Scenario: Newman asserts 401 for unauthenticated GET /api/sources

- GIVEN no auth credentials are set in the request
- WHEN `GET /index.php/apps/openconnector/api/sources` is called
- THEN the response status is 401

#### Scenario: Newman asserts 403 for non-admin POST /api/sources

- GIVEN a valid non-admin session credential is used
- WHEN `POST /index.php/apps/openconnector/api/sources` is called with a valid body
- THEN the response status is 403

### Requirement: `npm run test:newman` script MUST exist in package.json

The `package.json` scripts section MUST include a `test:newman` entry that runs the Newman
collection against the `openconnector.postman_environment.json` environment.

#### Scenario: test:newman script is present

- GIVEN `package.json` is parsed
- WHEN the `scripts.test:newman` key is read
- THEN the value is a non-empty string that calls `newman run` with the collection and environment file paths

### Requirement: Newman MUST capture p95 response time baselines

The Newman run MUST produce response-time measurements. The `tests/postman/baselines.json`
file MUST record the p95 baseline per endpoint after the first successful CI run. Subsequent
CI runs MUST fail if any endpoint's p95 regresses by more than 50% vs its recorded baseline.

#### Scenario: p95 regression detected

- GIVEN `baselines.json` records `GET /api/sources` p95 as 120ms
- WHEN a new code change causes `GET /api/sources` p95 to reach 200ms (67% regression)
- THEN the CI Newman job exits non-zero and identifies the regressed endpoint

### Requirement: Playwright regression project MUST cover all 10 resource pages

The `playwright.config.ts` MUST include a `regression` project (in addition to the
existing `chromium` and `docs-capture` projects) that runs all `tests/e2e/*.spec.ts`
except `docs-screenshots.spec.ts`. Each of the 10 resource page spec files MUST verify:
page loads, list renders, create succeeds, edit succeeds, delete succeeds, and
search/filter returns expected results.

#### Scenario: sources.spec.ts create flow

- GIVEN the dev container is running with at least one seed Source in OR storage
- WHEN the Playwright test navigates to `/index.php/apps/openconnector/sources` and clicks "Add Source"
- THEN a form opens, the test fills name and type, submits, and the new Source appears in the list

#### Scenario: sources.spec.ts delete flow

- GIVEN a Source created in a prior test step exists in the list
- WHEN the Playwright test clicks the delete action on that Source and confirms
- THEN the Source disappears from the list and a success toast is shown

#### Scenario: endpoints.spec.ts page loads

- GIVEN the dev container is running
- WHEN the Playwright test navigates to `/index.php/apps/openconnector/endpoints`
- THEN the page renders without console errors and the list or empty-state is visible

### Requirement: `playwright.config.ts` MUST set workers to 4 for the regression project

The `regression` project in `playwright.config.ts` MUST be configured with at least 4
parallel workers to keep E2E wall time under 10 minutes.

#### Scenario: workers setting is respected

- GIVEN `playwright.config.ts` sets `workers: 4`
- WHEN `npx playwright test --project regression` is invoked on a machine with ≥4 CPUs
- THEN Playwright launches 4 worker processes simultaneously

### Requirement: Playwright MUST include a migration round-trip spec

The file `tests/e2e/migration-round-trip.spec.ts` MUST exist and MUST verify: install
with legacy data present → run `occ openconnector:migrate-storage` → all 10 resource pages
still show the same data counts as before migration.

#### Scenario: migration round-trip preserves source count

- GIVEN 3 Source objects exist in `oc_openconnector_sources` before migration
- WHEN `occ openconnector:migrate-storage` is run and `storage_migrated` is set to `true`
- THEN the Sources list page shows 3 sources and each retains its original name and type

### Requirement: CI MUST upload Playwright failure artifacts

The `tests.yml` workflow MUST include an `actions/upload-artifact` step that uploads
`tests/e2e/playwright-report/**` and `tests/e2e/test-results/**` when the Playwright
job fails.

#### Scenario: artifacts uploaded on failure

- GIVEN the Playwright job fails on `sources.spec.ts`
- WHEN the `upload-artifact` step runs
- THEN screenshots and traces for the failed test are accessible as a downloadable CI artifact

### Requirement: LegacyToRegisterMigratorTest MUST verify chain-B migration paths

`tests/Unit/Service/LegacyToRegisterMigratorTest.php` MUST verify the four branching
scenarios for `Synchronization.sourceId` (integer-PK, register-schema slug pair, UUID
passthrough, unrecognised format) using a mocked `IDBConnection`.

#### Scenario: migrator resolves integer-PK sourceId

- GIVEN `LegacyToRegisterMigrator` is constructed with a mocked `IDBConnection` and a source row with `id=42` and `uuid='00000000-0000-0000-0000-000000000042'`
- WHEN `migrateAll(dryRun=false, entitySlug='synchronization', batchSize=1000)` is called with a synchronization row having `source_id='42'`
- THEN the resulting OR object's `sourceId` field equals `'00000000-0000-0000-0000-000000000042'`

#### Scenario: migrator passes UUID sourceId through unchanged

- GIVEN a synchronization row has `source_id='a1b2c3d4-e5f6-7890-abcd-ef1234567890'`
- WHEN `migrateAll` processes the row
- THEN the OR object's `sourceId` equals `'a1b2c3d4-e5f6-7890-abcd-ef1234567890'` unchanged

