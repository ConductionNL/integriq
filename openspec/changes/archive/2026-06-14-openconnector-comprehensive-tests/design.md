# Design: openconnector-comprehensive-tests

## Architecture Overview

Chain E adds three parallel test layers on top of the refactored stack that chains A–D2
produced. None of the three layers modify production code; they only observe it.

```
┌─────────────────────────────────────────────────────────────────────┐
│  GitHub Actions (tests.yml)                                          │
│  ┌──────────────────┐  ┌───────────────────┐  ┌────────────────┐   │
│  │ phpunit-coverage  │  │  newman           │  │  playwright    │   │
│  │ (Docker exec)     │  │  (Docker compose) │  │  (Docker comp.)│   │
│  └──────────┬────────┘  └────────┬──────────┘  └───────┬────────┘  │
│             │                    │                      │            │
│  clover.xml │      collection    │         specs        │            │
│  + PR comment                   │         + traces     │            │
└─────────────────────────────────────────────────────────────────────┘

tests/
  Unit/
    Service/      ← SourceServiceTest, JobServiceTest, … (11 files)
    Dto/          ← 15 DTO test files
    Command/      ← MigrateToOpenRegisterTest (verifies chain B command)
    Helpers/      ← ObjectServiceMockBuilder.php
  postman/
    openconnector.postman_collection.json
    openconnector.postman_environment.json
    baselines.json   ← p95 response time baselines per endpoint
  e2e/
    sources.spec.ts
    endpoints.spec.ts
    consumers.spec.ts
    mappings.spec.ts
    cloud-events.spec.ts
    synchronizations.spec.ts
    sync-contracts.spec.ts
    rules.spec.ts
    import.spec.ts
    dashboard.spec.ts
    migration-round-trip.spec.ts
    docs-screenshots.spec.ts  ← existing, unchanged
    global-setup.ts           ← existing, unchanged
    .auth/                    ← gitignored, written by global-setup
```

## API Design

No new API endpoints. All Newman and Playwright tests target the existing REST surface
from chains B/C. See `contract.md` for the CI gate contract.

## Database Changes

None. Chain E is test-only.

## Nextcloud Integration

- **PHPUnit bootstrap** (`tests/bootstrap.php`): must be run inside `docker exec nextcloud
  php` because services are injected via Nextcloud's DI container. Unit tests that require
  only mocked dependencies MAY run outside the container; tests that call `IAppConfig` or
  `IDBConnection` require the container.
- **Playwright global-setup.ts**: existing pattern — login once, persist session to
  `tests/e2e/.auth/admin.json`. Unchanged.
- **Coverage**: PHPUnit's `--coverage-clover` requires Xdebug 3 with `xdebug.mode=coverage`.
  Enabled via `php.ini` override in the CI workflow only (not in the dev container default).

## Security Considerations

- Newman collection environment file uses placeholder credentials (`"admin"`/`"admin"`)
  which are the dev-container defaults. Real credentials MUST NOT be committed.
- `tests/e2e/.auth/admin.json` is gitignored; it is written at runtime by `global-setup.ts`
  and never committed.
- Newman test assertions include at least one 401 and one 403 case per resource group
  to verify that the existing auth middleware is not accidentally removed by future changes.

## NL Design System

Not applicable (chain E is test code only; no Vue components introduced).

## File Structure

```
tests/
  Helpers/
    ObjectServiceMockBuilder.php
  Unit/
    Command/
      MigrateToOpenRegisterTest.php
    Dto/
      SourceDtoTest.php
      EndpointDtoTest.php
      ConsumerDtoTest.php
      MappingDtoTest.php
      JobDtoTest.php
      RuleDtoTest.php
      SynchronizationDtoTest.php
      SynchronizationContractDtoTest.php
      CallLogDtoTest.php
      JobLogDtoTest.php
      SynchronizationLogDtoTest.php
      SynchronizationContractLogDtoTest.php
      CloudEventDtoTest.php
      EventMessageDtoTest.php
      EventSubscriptionDtoTest.php
    Service/
      CallServiceTest.php
      EndpointServiceTest.php
      EventServiceTest.php
      JobServiceTest.php
      MappingServiceTest.php
      RuleServiceTest.php
      SourceServiceTest.php
      SynchronizationServiceTest.php
      LegacyToRegisterMigratorTest.php
  postman/
    openconnector.postman_collection.json
    openconnector.postman_environment.json
    baselines.json
  e2e/
    sources.spec.ts
    endpoints.spec.ts
    consumers.spec.ts
    mappings.spec.ts
    cloud-events.spec.ts
    synchronizations.spec.ts
    sync-contracts.spec.ts
    rules.spec.ts
    import.spec.ts
    dashboard.spec.ts
    migration-round-trip.spec.ts
.github/
  workflows/
    tests.yml
```

## Decisions

### Decision 1: Three separate CI jobs vs. one monolithic `tests.yml`

**Choice**: three parallel jobs inside one `tests.yml` workflow.

**Rationale**: a single workflow file is easier to maintain and surfaces a unified status
check name on the PR. Parallel jobs (`needs:` not set between them) keep wall time low.
An explicit `needs: [phpunit, newman, playwright]` final step acts as the merge gate.

**Alternative considered**: separate workflow files (`phpunit-coverage.yml`, `newman.yml`,
`playwright.yml`). Rejected because three status checks clutter the PR UI and require
branch protection rules to reference all three.

### Decision 2: Newman over Pest for API integration tests

**Choice**: Newman (Postman CLI).

**Rationale**: chain C's spec already specified Newman. The `tests/Http/` directory name
also implies a Newman home. Newman collections are portable and can be imported into
Postman by contributors who prefer a GUI.

**Alternative considered**: PHPUnit HTTP tests via Guzzle in `tests/Http/`. Rejected
because it would require a running Nextcloud container inside PHPUnit's process model
(complex bootstrap); Newman handles this cleanly with a separate `docker-compose up` step.

### Decision 3: ObjectServiceMockBuilder helper class

**Choice**: a dedicated `tests/Helpers/ObjectServiceMockBuilder.php` that returns a
pre-configured `PHPUnit\Framework\MockObject\MockObject` for `ObjectService`.

**Rationale**: chain C introduced `ObjectService` injection into 8+ services. Without a
shared mock builder, every test file duplicates the same `$this->createMock(ObjectService::class)`
+ method stubbing boilerplate. The helper encapsulates sensible defaults and keeps tests
readable.

**ADR-011 note**: `ObjectService` is the canonical OR abstraction; tests that mock it
directly rather than calling the real OR are correct (unit tests should not cross app
boundaries).

### Decision 4: 80% line / 70% branch as merge-blocking thresholds

**Choice**: enforce these thresholds in CI; document 100% as aspirational.

**Rationale**: the existing `coverage:check` script uses 75%. Chain E raises the line
threshold to 80% to reflect the fact that the new test files have explicit intent to cover
all refactored code paths. 70% branch threshold is realistic for code with many defensive
`if/else` guards against null OR responses. 100% would be a maintenance burden on every
future PR.

### Decision 5: ADR-031 declarative vs. imperative

Chain E introduces imperative test code (PHPUnit, Newman, Playwright). The coverage
thresholds themselves are declarative: they express what the system SHALL guarantee without
prescribing how to achieve it. This is consistent with ADR-031's guidance that CI gate
values (thresholds, baselines) are declarative even when the test code that produces the
measurements is imperative.

## Trade-offs

| Approach | Chosen? | Rationale |
|---|---|---|
| PHPUnit inside dev container only | Yes | Nextcloud DI requires the container for integration-style unit tests; acceptable in CI |
| MySQL matrix in first iteration | No | Deferred; Postgres is stricter, covers the majority risk |
| Codecov external service | No | Avoided external dependency; PHP script posts clover summary as PR comment |
| `workers: 1` (no change to Playwright) | No | `workers: 4` significantly reduces wall time for 11 spec files |
