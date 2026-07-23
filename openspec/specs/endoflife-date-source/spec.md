# endoflife-date-source Specification

## Purpose
TBD - created by archiving change endoflife-date-source. Update Purpose after archive.
## Requirements
### Requirement: endoflife.date source preset ships enabled, credential-free

OpenConnector SHALL seed a pre-built `source` object with `@self.slug =
"endoflife-date"` (register `openconnector`, schema `source`) on app
install/upgrade, with `location: "https://endoflife.date/api"`, `auth:
"none"`, and `isEnabled: true`. Unlike a credentialed integration preset
(e.g. `brp-haalcentraal`, `kvk`), this source SHALL ship live, not dormant,
because the upstream API is public, free, and requires no secret — there
is nothing an operator needs to configure before it can be used.

The seed SHALL be delivered as an ADR-037 register fragment
(`lib/Settings/register.d/endoflife-date-source.json`, a
`components.objects` array) so it is merged into the register descriptor
by `SettingsService` and materialised idempotently by `@self.slug` via
OpenRegister's `ImportHandler`.

#### Scenario: endoflife-date source materialises on install, already enabled

- GIVEN OpenRegister is installed and enabled
- WHEN `occ app:enable openconnector` (or an upgrade) runs
  `InitializeRegister`
- THEN a `source` object with `@self.slug = "endoflife-date"` exists in
  register `openconnector`, schema `source`, with `location =
  "https://endoflife.date/api"`, `auth = "none"`, and `isEnabled = true`
- @e2e exclude Backend seed materialisation — verified by PHPUnit against the OR object API, not a browser flow.

#### Scenario: seed re-import is idempotent

- GIVEN the `endoflife-date` source already exists from a prior install
- WHEN `InitializeRegister` runs again
- THEN no duplicate `endoflife-date` source is created (matched by
  `@self.slug`)
- @e2e exclude Backend idempotency — verified by PHPUnit, not a browser flow.

### Requirement: `eolProduct` and `eolCycle` schemas are declared in the existing `openconnector` register

OpenConnector SHALL declare two new schemas — `eolProduct` (a tracked
product/technology) and `eolCycle` (one release cycle's lifecycle data for
a product) — within the existing `openconnector` register (per
`openconnector-register-schema` REQ-A-001's single-register-per-app
convention), delivered as an ADR-037 register fragment declaring
`components.registers.openconnector.schemas` and
`components.schemas.eolProduct` / `components.schemas.eolCycle`.

`eolProduct` SHALL declare at minimum: `slug` (string, required, the
endoflife.date product identifier), `name` (string, required), `category`
(string), `homepage` (string, uri), and `endoflifeUrl` (string, uri, the
product's `https://endoflife.date/{slug}` page).

`eolCycle` SHALL declare at minimum: `product` (string, required, the
owning `eolProduct.slug`), `cycle` (string, required, the release-cycle
label, e.g. `"3.14"`), `releaseDate` (string, format date), `eol` (string
— an ISO date, or an empty string when no EOL date has been scheduled
upstream), `support` (string — same date-or-empty-string shape), `latest`
(string, the latest patch release in this cycle), `latestReleaseDate`
(string, format date), `lts` (boolean or string, per upstream's own mixed
`false`/date shape), and `discontinued` (string — same date-or-empty-string
shape as `eol`/`support`).

#### Scenario: eolProduct and eolCycle schemas are present after install

- GIVEN OpenRegister is installed and enabled
- WHEN `occ app:enable openconnector` (or an upgrade) runs
  `InitializeRegister`
- THEN `components.schemas.eolProduct` and `components.schemas.eolCycle`
  exist in the merged register descriptor, both listed under register
  `openconnector`'s `schemas` array
- AND no second, separate OpenRegister register is created for them
- @e2e exclude Backend register materialisation — verified by PHPUnit against the OR object API, not a browser flow.

#### Scenario: eolCycle schema covers the brief's required fields

- GIVEN the `eolCycle` schema in the merged register descriptor
- WHEN inspecting `properties`
- THEN `product`, `cycle`, `releaseDate`, `eol`, `support`, `latest`, and
  `lts` are all present, matching the field list a consuming app needs to
  match a catalog module's version against its EOL window
- @e2e exclude Schema-shape assertion — verified by PHPUnit/JSON fixture, not a browser flow.

### Requirement: a curated starter set of tracked products is seeded declaratively

OpenConnector SHALL seed one `eolProduct` object per curated starter
product — `php`, `nodejs`, `python`, `postgresql`, `mysql`, `nextcloud`,
`wordpress`, `laravel` — as static catalog metadata (register
`openconnector`, schema `eolProduct`, keyed by `@self.slug`). These
objects SHALL be seeded declaratively, not populated by a synchronization
run, because their metadata (name, category, homepage) is static and does
not need to be fetched from `/api/all.json` (which returns a bare array of
product-slug strings, not the object-shaped list the generic
Synchronization engine's per-item identity/mapping pipeline requires —
see this change's design.md discovery notes for the underlying finding).

#### Scenario: all eight curated eolProduct objects exist after install

- GIVEN OpenRegister is installed and enabled
- WHEN `occ app:enable openconnector` (or an upgrade) runs
  `InitializeRegister`
- THEN `eolProduct` objects with `@self.slug` of `php`, `nodejs`, `python`,
  `postgresql`, `mysql`, `nextcloud`, `wordpress`, and `laravel` all exist
  in register `openconnector`, schema `eolProduct`
- @e2e exclude Backend seed materialisation — verified by PHPUnit against the OR object API, not a browser flow.

#### Scenario: extending the tracked set requires no code change

- GIVEN an operator wants to track a ninth product listed at
  `https://endoflife.date/api/all.json` (e.g. `django`)
- WHEN they follow the documented recipe (docs page) — duplicating one
  curated product's `eolProduct` seed object plus its `mapping` /
  `synchronization` / `job` triple, substituting the new product slug —
  and re-run `InitializeRegister`
- THEN the new product's `eolCycle` data begins syncing on the same daily
  cadence, with no PHP or engine change required

### Requirement: each curated product syncs its cycles via a dedicated, engine-native Synchronization

For each curated product, OpenConnector SHALL seed one `mapping` object,
one `synchronization` object, and one `job` object (register
`openconnector`), reusing the existing Synchronization/Mapping/Job engine
unchanged:

- The `synchronization` object SHALL set `sourceId: "endoflife-date"`,
  `sourceType: "api"`, `sourceConfig.endpoint: "/{slug}.json"` (a literal,
  non-templated path — e.g. `/python.json` for the `python` product),
  `sourceConfig.resultsPosition: "_root"` (REQUIRED — the endpoint's JSON
  body is a bare top-level array with none of the fallback `items`/
  `result`/`results` keys the engine otherwise looks for; omitting this
  field causes every run to fail with "Cannot determine the position of
  objects in the return body"), `sourceConfig.idPosition: "cycle"`,
  `targetType: "register/schema"`, `targetId: "openconnector/eolCycle"`,
  and `sourceTargetMapping` set to that product's seeded `mapping` slug.
- The `mapping` object SHALL map each fetched cycle's `cycle`,
  `releaseDate`, `eol`, `support`, `latest`, `latestReleaseDate`, and `lts`
  fields by direct dot-path copy, SHALL set `product` to a literal string
  equal to the product's slug (e.g. `"python"` — a plain literal renders
  verbatim through the mapping engine's Twig step since it contains no
  `{{ }}` markers), and SHALL cast `eol`, `support`, and `discontinued` to
  `string` so upstream's mixed date-or-`false` shape lands as a single,
  consistently-typed column (an ISO date string, or an empty string when
  no date is scheduled).
- The `job` object SHALL set `jobClass:
  "OCA\\OpenConnector\\Action\\SynchronizationAction"`,
  `arguments.synchronizationId` to that product's `synchronization` slug,
  `interval: 86400` (daily), and `isEnabled: true` — the existing, generic
  job-dispatch mechanism (`job-scheduling` REQ-003/REQ-004); no new job
  class is introduced.

Each curated product's `synchronization` object is independent —
distinct `synchronizationId`s so that `SynchronizationContract` identity
(scoped to `(synchronizationId, originId)`) can never collide two
different products' same-labelled cycles onto the same target object.

#### Scenario: a scheduled run fetches, maps, and upserts one product's cycles

- GIVEN the seeded `endoflife-date-python-cycles` job is due
- WHEN the cron sweep (`job-scheduling` REQ-003/REQ-004) executes it
- THEN `SynchronizationAction::run()` resolves the
  `endoflife-date-python-cycles` synchronization and calls
  `SynchronizationService::synchronize()`
- AND a single GET request is made to
  `https://endoflife.date/api/python.json`
- AND each returned cycle is mapped via the
  `endoflife-date-python-cycles-mapping` mapping and upserted as an
  `eolCycle` object with `product = "python"`

#### Scenario: two curated products never collide on cycle identity

- GIVEN the `python` and `nodejs` products both happen to report a cycle
  labelled `"20"` (or any other coincidentally-shared label)
- WHEN both products' scheduled syncs run
- THEN two distinct `eolCycle` objects exist — one with `product =
  "python"`, one with `product = "nodejs"` — because each product's sync
  uses a distinct `synchronizationId`, so their `SynchronizationContract`s
  (keyed on `(synchronizationId, originId)`) never collide
- AND neither product's cycle data overwrites the other's

### Requirement: repeated syncs upsert idempotently and garbage-collect soft-deleted cycles

Re-running a product's seeded synchronization SHALL NOT create a
duplicate `eolCycle` object for a cycle label already synced — the
existing `SynchronizationContract` origin-id/hash mechanism
(`synchronization-engine` REQ-003/REQ-004) SHALL update the existing
target object in place when the source content is unchanged (no-op write)
or changed (updated write), reused unmodified. A cycle no longer reported
by the source SHALL be garbage-collected via the existing
`deleteInvalidObjects()` path — which is soft-delete-aware per
OpenRegister's standard delete semantics — subject to the existing
fetch-completeness and deletion-ratio guards (`synchronization-engine`
REQ-009/REQ-010). Each curated product's `synchronization` object SHALL
set `sourceConfig.deletionRatioThreshold: 0.5` (raised from the engine's
`0.10` default) because several curated products have a small total cycle
count, where retiring even one legitimately-removed cycle can exceed the
default 10% guard and block correct cleanup.

#### Scenario: re-running the same sync produces no duplicate objects

- GIVEN a product's synchronization has already run once, producing N
  `eolCycle` objects
- WHEN the same synchronization runs again with unchanged source data
- THEN the same N `eolCycle` objects exist afterward (no duplicates, no
  new objects created)
- @e2e exclude Backend idempotency — verified by the live smoke test and PHPUnit, not a browser flow.

#### Scenario: a retired cycle is garbage-collected within the raised deletion-ratio guard

- GIVEN a product's synchronization has 4 existing `eolCycle` contracts
  and the source's next complete fetch no longer reports 1 of them (25%
  of the existing contracts)
- WHEN the synchronization runs
- THEN the now-absent cycle's `eolCycle` object is deleted (25% is within
  the raised `0.5` threshold, so the deletion-ratio guard does not block
  it — whereas the engine's unmodified `0.10` default would have)

#### Scenario: an incomplete fetch never triggers deletion

- GIVEN a product's synchronization run's fetch is marked incomplete
  (`synchronization-engine` REQ-009 — e.g. a non-2xx page response)
- WHEN `deleteInvalidObjects()` would otherwise run
- THEN no `eolCycle` object is deleted for that run, unchanged
  `synchronization-engine` REQ-010 behaviour

### Requirement: the preset is automatically visible on the Catalog page

The `endoflife-date` source SHALL become visible as a `source-template`
card on OpenConnector's Catalog page purely as a side effect of this
change's register fragment seeding a `source` object — the existing
`CatalogRegistryService.collectFromSeedFragments()` mechanism
(`connector-catalog` REQ-003) already content-inspects every
`register.d/*.json` fragment for a `source` object and materialises a
matching catalog card. This change SHALL NOT add any bespoke Catalog UI
wiring, controller, or frontend code.

#### Scenario: endoflife-date appears on the Catalog page without new UI code

- GIVEN the `endoflife-date` source seed fragment is installed
- WHEN an operator opens the Catalog page
- THEN a card for "endoflife.date" is rendered among the source-template
  entries, with a status badge reading "available" (the source ships
  `isEnabled: true`, no mock/dormant gating applies)
- @e2e exclude Existing Catalog rendering mechanism — covered by connector-catalog's own e2e/PHPUnit coverage; this change adds no new render path to test.

### Requirement: a live smoke test proves the preset against the real public API

OpenConnector SHALL ship an integration test
(`tests/Integration/EndoflifeDateLiveSyncTest.php`) that dispatches a real,
unmocked HTTP request against `https://endoflife.date/api` and asserts:
(1) a full synchronization run for at least one curated product produces
the expected `eolCycle` objects with `product`, `cycle`, and `eol`
populated; and (2) re-running the same synchronization is idempotent (no
duplicate objects, per the requirement above). Following the repo's
established `tests/Integration` convention (already excluded from the
default `phpunit.xml` `Unit Tests` suite; run via `phpunit-unit.xml
--testsuite "Integration Tests"`), the test SHALL self-skip — not fail —
when the real endpoint is unreachable (a short-timeout connectivity probe)
or when `OPENCONNECTOR_SKIP_NETWORK_TESTS=1` is set, so it never blocks a
network-isolated CI run.

#### Scenario: the live smoke test passes against the real API when network is available

- GIVEN a test environment with outbound internet access
- WHEN `vendor/bin/phpunit -c phpunit-unit.xml --testsuite "Integration Tests" --filter EndoflifeDateLiveSyncTest` runs
- THEN a real HTTP call is made to `https://endoflife.date/api/{product}.json`
  for at least one curated product
- AND at least one `eolCycle` object is created with a non-empty `cycle`
  value
- AND running the same synchronization a second time produces no
  additional `eolCycle` objects for that product

#### Scenario: the live smoke test self-skips without network access

- GIVEN a network-isolated test environment (or
  `OPENCONNECTOR_SKIP_NETWORK_TESTS=1`)
- WHEN the same test runs
- THEN the test reports as skipped, not failed, and the overall test suite
  exit code is unaffected by the missing network access

