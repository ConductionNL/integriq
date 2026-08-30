# Proposal: openconnector-comprehensive-tests

**kind**: code
**depends_on**: [openconnector-frontend-vue-rewrite]

## Summary

Chains A through D2 completed the migration of OpenConnector from its own database tables
to OpenRegister-backed storage, rewrote all 15 backend services to call `ObjectService`
directly, and replaced the frontend with a manifest-driven `CnAppRoot`/`CnIndexPage` shell.
This final chain (E) closes the testing gap: it introduces PHPUnit unit tests for every
refactored backend class (with 80% line / 70% branch coverage gates as merge-blocking CI
thresholds), a Newman API integration suite covering every REST endpoint, and a full 10-page
Playwright E2E suite that verifies each resource page end-to-end and includes a migration
round-trip smoke test. Chain E is the confidence gate that asserts the entire A→D2 migration
actually works as a system.

## Motivation

The A→D2 chains made deep changes — 15 mappers deleted, 31 files removed from `lib/Db`,
services rewritten to call OR directly, frontend rebuilt from scratch — but shipped with
minimal automated test coverage. The existing `tests/Unit/` directory contains five service
tests that predate the refactor. The Newman collection referenced in chain C's spec does not
yet exist. The Playwright suite is a single documentation-screenshot capture spec with no
assertions. Without test infrastructure, regressions in the A→D2 refactor cannot be
detected automatically, the coverage gate in `composer test:coverage` has no meaningful
denominator, and the CI pipeline cannot block a bad PR from merging.

Chain E is needed now because: (1) the services and frontend are stable enough to write
against, (2) further product changes (new features, new integrations) will compound the
regression risk if the test floor is not established first, and (3) the prior chains' own
test plans (B's 56 TCs, C's 30 TCs, D2's 16 TCs) explicitly deferred full E2E coverage
here.

## Affected Projects

- [x] Project: `openconnector` — new PHPUnit tests under `tests/Unit/`, new Newman
  collection under `tests/postman/`, new Playwright specs under `tests/e2e/`, updated
  `playwright.config.ts`, new GitHub Actions workflows under `.github/workflows/`

## Scope

### In Scope

- PHPUnit unit tests for every service/class touched by chains B and C (≥11 service test
  files, 15 DTO test files, supporting test helpers)
- CI coverage gates: 80% line, 70% branch enforced as merge-blocking; 100% as aspirational
  documented goal
- `--coverage-clover` report emitted by CI and posted as PR comment via codecov or similar
- Newman collection at `tests/postman/openconnector.postman_collection.json` covering all
  REST endpoints (happy path + one error path per endpoint); environment file with
  dev-container defaults
- `npm run test:newman` script
- Playwright E2E: 10 per-resource spec files (sources, endpoints, consumers, mappings,
  cloud-events, syncs, sync-contracts, rules, import, dashboard) each covering: page loads,
  list shows seed data, create, edit, delete, search/filter
- One Playwright migration round-trip spec: install with legacy data → run
  `occ openconnector:migrate-storage` → verify all pages still show the same data
- `playwright.config.ts` updated to `workers: 4` for parallel execution
- GitHub Actions workflows: `phpunit-coverage.yml`, `newman.yml`, `playwright.yml`
  (or merged into one `tests.yml`) with merge-blocking gates
- Test helper: `tests/Helpers/ObjectServiceMockBuilder.php` for consistent `ObjectService`
  mocking across all unit tests

### Out of Scope

- Adding new business logic (chain E is verification, not new features)
- Performance load-testing beyond what the Newman p95 baseline captures
- Accessibility audits beyond what the D2 test plan's TC-016 already established
- Dropping `oc_openconnector_*` legacy tables (chain B cleanup issue B-001, gated on chain
  C shipping)
- DTO auto-generation from chain A schemas (issue C-001)
- ADR-007 follow-up: wiring `EncryptionService` end-to-end (tracked separately)
- MySQL matrix CI (first iteration accepts Postgres only per ADR-009 guidance; MySQL added
  in a follow-up)

## Approach

1. **PHPUnit** — implement `tests/Helpers/ObjectServiceMockBuilder.php` first, then one
   test file per service class in alphabetical order. Each test file uses the mock builder
   and verifies constructor injection, happy path, and at least one error path per public
   method.
2. **Newman** — create a Postman collection JSON manually (no Postman GUI required), keyed
   on the route slugs from `appinfo/routes.php`. Use environment variables for base URL and
   credentials. Add `npm run test:newman` to `package.json`.
3. **Playwright** — create one `{resource}.spec.ts` per resource page following the
   `docs-screenshots.spec.ts` authentication and navigation pattern. Add the migration
   round-trip spec last.
4. **CI** — add GitHub Actions workflows that spin up the dev container (via
   `docker-compose`) and run each test suite against it. Coverage report posted to the PR.

## New Dependencies

- `newman` (npm, devDependency) — CLI runner for Postman collections
- `@vitest/coverage-v8` or similar (npm, devDependency) — if front-end unit test coverage
  is added (currently scoped to Playwright only; PHP coverage via PHPUnit's built-in Xdebug
  driver)
- No new PHP Composer dependencies (PHPUnit already present)

## Impact

- `tests/` — new files under `Unit/`, `postman/`, `e2e/`
- `playwright.config.ts` — `workers: 1` → `workers: 4`; add a `regression` project that
  includes all `*.spec.ts` except `docs-screenshots.spec.ts`
- `package.json` — new `test:newman` script
- `.github/workflows/` — new CI workflow files
- No changes to `lib/`, `src/`, `appinfo/`, or any chain A–D2 files

## Cross-Project Dependencies

- Depends on `openregister` being installed and running in the CI container (seed data from
  chain A's `openconnector_seed_data.json` must be importable).
- The Newman and Playwright suites call the same REST API surface as chains B/C/D1/D2;
  no new API endpoints are introduced.

## Risks

### Risk 1: 100% coverage is aspirational; 80/70 gates may still slip

**Severity:** High — **Mitigation:** Enforce 80% line / 70% branch as the merge-blocking
threshold via `composer coverage:check`. Document 100% as a quarterly tech-debt target in
the repo README. Teams routinely stall at 80–85%; the gap is tracked via the clover report,
not via pipeline blocking.

### Risk 2: Newman CI requires a running Nextcloud container

**Severity:** High — **Mitigation:** GitHub Actions workflow uses `docker-compose` to spin
up `db` + `nextcloud` + `openregister` services before running Newman. The boot recipe is
documented in `.github/workflows/tests.yml`. Workflow caches the container image layers to
reduce cold-start time.

### Risk 3: Playwright E2E is slow (10 specs × full CRUD ≈ 5–10 min wall time)

**Severity:** Medium — **Mitigation:** `workers: 4` in `playwright.config.ts` gives
parallel execution. Path filters on the workflow skip E2E for PRs that touch only `docs/`
or `openspec/` directories.

### Risk 4: ADR-009 dual-platform — tests run against Postgres only in first iteration

**Severity:** Medium — **Mitigation:** Postgres is the stricter platform (strict JSON
types, no implicit type coercion). MySQL matrix is deferred to a follow-up; a GitHub issue
is filed at planning time.

### Risk 5: Playwright test flakiness on async UI updates

**Severity:** Low — **Mitigation:** Use `await expect(locator).toBeVisible()` and
`await expect(locator).toHaveText()` patterns throughout. No `setTimeout` polling allowed.
Retries set to 1 on CI.

## Rollback Strategy

Chain E adds only test files and CI configuration. If the new workflows cause CI pipeline
failures on unrelated PRs (e.g., a transient container start-up failure), the workflows can
be temporarily disabled by removing the workflow files or setting `on: workflow_dispatch`
only. No `lib/`, `src/`, or runtime files are changed. Rollback is a single revert commit.

## Open Questions

- Should the Newman collection use Basic Auth (admin/admin) or the Nextcloud `OCS-APIREQUEST`
  token mechanism? (Prefer Basic Auth for simplicity in CI; OCS token if Basic is blocked.)
- Should coverage be posted via `codecov/codecov-action` (requires a Codecov account) or
  via a lightweight PHP script that comments on the PR? (Prefer the PHP script to avoid
  external service dependency.)
