# Discovery: openconnector-comprehensive-tests

## Question

Can the PHPUnit, Newman, and Playwright test suites all run against the same dev-container
environment that chains A–D2 were built on, with no new infrastructure dependencies beyond
`newman` (npm) and an Xdebug-capable PHP build?

## Approach Taken

1. Reviewed the existing `phpunit.xml` (bootstrap at `tests/bootstrap.php`, single
   `Unit Tests` testsuite pointing at `tests/unit`).
2. Reviewed `composer.json` scripts: `test:unit`, `test:all`, `test:coverage`
   (`--coverage-html` + `--coverage-clover`) already present. `coverage:check` script
   already enforces 75% line coverage but uses a 75% threshold today (chain E raises this
   to 80%).
3. Reviewed `playwright.config.ts`: `workers: 1`, `fullyParallel: false`, two projects
   (`chromium` regression + `docs-capture`). Extending to `workers: 4` and adding per-
   resource spec files is straightforward.
4. Checked `package.json`: `test:e2e` script calls `playwright test` with no project
   filter. No Newman script exists yet.
5. Reviewed the existing `tests/Unit/` directory: 5 pre-chain-A test files remain, none
   targeting the refactored services. The `tests/Http/` directory exists but is empty
   (previously housed legacy Guzzle-based integration tests, removed during chain C).
6. Checked that `newman` is available as an npm package and can run against a local
   Nextcloud instance with Basic Auth.

## Findings

- **PHPUnit coverage** is already wired; only the threshold (75% → 80%) and the clover
  upload step need changing.
- **Newman** is a zero-friction addition: `npm install --save-dev newman`, add a script,
  write the collection JSON. No new infrastructure.
- **Playwright parallelism** is a one-line config change (`workers: 4`). The global-setup
  login-once pattern already handles parallel test isolation correctly (all workers share
  the persisted admin session).
- **CI container boot**: the existing `.github/docker-compose.yml` already defines the
  `nextcloud` + `db` services needed. A `services:` block in the workflow YAML referencing
  that compose file is sufficient.
- **Xdebug**: the dev container ships with Xdebug 3 disabled by default. Enabling it for
  the coverage job requires a one-line `php.ini` override in the workflow (`xdebug.mode=coverage`).
  This is standard Nextcloud CI practice.
- **No MySQL matrix**: `ADR-009` acknowledges Postgres is the stricter platform. First
  iteration runs Postgres only. MySQL matrix is a follow-up GitHub issue.
- **Existing test flakiness**: the `docs-screenshots.spec.ts` uses `waitForTimeout(900ms)`
  polling. New regression specs MUST NOT copy this pattern — use `await expect(...)` with
  an assertion timeout instead.

## Recommendation

Proceed to specs and design. All three test layers are feasible with existing infrastructure.
The only new npm devDependency is `newman`. No new PHP dependencies are needed.

## Risks Uncovered

- The `tests/bootstrap.php` file was not read during this discovery; it may contain
  Nextcloud environment assumptions (e.g., `OC::$server` bootstrapping) that require the
  full Nextcloud container to run PHPUnit. If so, PHPUnit unit tests cannot run in a plain
  `php` process — they must run inside the dev container. This is a CI workflow decision
  (run PHPUnit inside `docker exec nextcloud`) rather than a blocker.
- The Newman collection must be written by hand (no existing Postman workspace). The
  route slugs from `appinfo/routes.php` are the authoritative source; a one-time pass
  through the file is required before authoring the collection.

## Next Steps

Proceed to specs, design, migration (N/A — no schema changes), and tasks.
