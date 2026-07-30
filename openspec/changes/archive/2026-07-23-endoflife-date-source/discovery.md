# Discovery: endoflife-date-source

## Question

Can OpenConnector's existing Source → Synchronization → Mapping → Job
engine ingest the public endoflife.date API (`/api/all.json` for the
product list, `/api/{product}.json` for a product's release-cycle data)
**as pure configuration**, with zero new PHP sync/mapping/scheduling code —
and if not, exactly where does the generic engine's assumptions break, and
what is the smallest config-only (or minimally-scoped) design that still
satisfies the brief?

## Approach Taken

- Called the real API to confirm actual response shapes (network access
  available in this environment):
  - `GET https://endoflife.date/api/all.json` → a bare JSON array of
    product-slug strings: `["adonisjs","akeneo-pim","alibaba-ack",...]`
    (verified via `curl`, 200 OK, `content-type: application/json`).
  - `GET https://endoflife.date/api/python.json` → a JSON array of real
    cycle objects: `[{"cycle":"3.14","releaseDate":"2025-10-07","eol":
    "2030-10-31","latest":"3.14.6","latestReleaseDate":"...","lts":false,
    "support":"2027-10-01",...}, ...]` — no `product`/`name` field is
    present on each cycle object; the product identity is implicit in the
    endpoint path only.
  - Checked for a richer v2 API (`/api/v2/products`, `/api/v2/products/
    python`, etc.) that might return product-list entries as objects
    instead of bare strings — all guessed v2 paths 404'd. The stable,
    working API is the v1 shape above, which also matches exactly what
    the context brief itself specifies (`/api/all.json`, `/api/{product}
    .json`).
- Read `lib/Service/SynchronizationService.php` (not just the retrofit
  spec text, which the spec itself warns can drift from HEAD) end to end
  for: `getAllObjectsFromSource()`, `getAllObjectsFromApi()`,
  `getAllObjectsFromArray()`, `getOriginId()`,
  `processSynchronizationObject()`, `fetchExtraDataForObject()` /
  `fetchMultipleExtraData()`.
- Read `lib/Action/SynchronizationAction.php` (the generic `jobClass` used
  by every scheduled Synchronization) and `JobService::executeJob()`
  (`job-scheduling` spec REQ-004) to understand exactly how a seeded `Job`
  object drives a seeded `Synchronization` object.
- Cross-checked the `synchronization-engine` spec's REQ-002 claim that
  `sourceType: "array"` is supported ("SHALL support `array` (static)
  sources directly... objects are read directly from the static array
  source without an HTTP call") against the actual dispatch `switch` in
  `getAllObjectsFromSource()`.

## Findings

1. **`/api/all.json`'s bare string array is structurally incompatible with
   the generic per-item sync pipeline as it exists in HEAD.**
   `SynchronizationService::getOriginId(array $synchronization, array
   $object)` and `processSynchronizationObject(array $synchronization,
   array $object, ...)` both type-hint their per-item parameter as
   `array`. Nowhere in the fetch pipeline (`getAllObjectsFromApi()`,
   `getAllObjectsFromArray()`, `fetchSinglePageData()`) is a scalar array
   item (a raw string) coerced into an array before it reaches these
   calls — the `$data`-merge step
   (`foreach ($objects as &$object) { $object = array_merge($object,
   $data); }`) only runs when a non-empty `$data` argument was supplied to
   `synchronize()`, and even then `array_merge()` itself requires its
   first argument to already be an array. Passing a scheduled job's
   `Synchronization` straight at `/api/all.json` would therefore throw a
   `TypeError` for every fetched item; REQ-008's per-item dead-letter
   guard would catch it (so the sync would not crash outright) but every
   single item would dead-letter and zero `eolProduct` objects would ever
   be written — a silently-broken preset, not a working one.

2. **`sourceType: "array"` is documented in the `synchronization-engine`
   spec (REQ-002) but is not actually dispatched anywhere in HEAD.**
   `getAllObjectsFromSource()`'s `switch ($type)` only has cases for
   `register/schema`, `api`, `database`, `nextcloud-table`, and
   `nextcloud-form` — there is no `case 'array':` and no `default:`
   branch, so a Synchronization configured with `sourceType: "array"`
   silently returns zero objects (`$objects` stays at its initial `[]`).
   This is spec/code drift in an existing, unrelated retrofit spec — out
   of scope for this change to fix — but it rules out a design that
   relies on `sourceType: array` to hand the engine a hand-curated,
   already-object-shaped product list.

3. **`/api/{product}.json` is fully compatible with the engine, unmodified.**
   It returns a plain JSON array of real objects with a stable per-cycle
   key (`cycle`). `sourceType: api` with a literal (non-templated)
   `sourceConfig.endpoint: "/{product}.json"` and `sourceConfig.idPosition:
   "cycle"` works exactly like every other existing `api` Synchronization
   in the codebase — no new engine code, no drift, no dead-lettering.

4. **A single shared Synchronization across multiple products would
   silently corrupt data.** `SynchronizationContract` identity is scoped
   to `(synchronizationId, originId)` only (`synchronization_contract`
   schema has no per-product discriminator field). If one `Synchronization`
   object were reused for many products (e.g. via a templated endpoint and
   a `data: {productSlug}` argument per invocation), two different
   products sharing a cycle label (e.g. two ecosystems both having a
   `"3.14"` release) would collide on the same contract and the second
   product's write would silently overwrite the first's target object.
   `idPosition` is a single, non-templated dotted-path lookup — it cannot
   compose `productSlug + cycle` into a collision-proof key. The only
   collision-safe design available without new engine code is one
   `Synchronization` per product (each with its own `synchronizationId`),
   confirmed by `SynchronizationAction`/`getOriginId()`'s actual code
   shape, not merely inferred.

5. **`SynchronizationAction::run()` resolves its target `Synchronization`
   only by `arguments['synchronizationId']`** — via `SynchronizationService
   ::getSynchronization()` → `OCA\OpenRegister\Service\ObjectService::find
   (id:, register:, schema:)`. This is the identical call shape already
   relied on for slug-based resolution elsewhere in this codebase (e.g.
   `SourceMapper::find('kvk')`, per the already-merged
   `seed-kvk-opencorporates-sources` change) — strong precedent that OR's
   `find()` accepts a slug here too, but not independently re-verified
   against OpenRegister's own `ObjectService` source in this discovery
   (out of this repo). Flagged as a live-verification task rather than a
   blocking unknown.

6. **`fetchExtraDataForObject()`/`extraDataConfigs` genuinely supports
   per-item sub-resource fetch-and-merge** (`staticEndpoint` with
   `{{ originId }}` substitution, `keyToSetExtraData`, `mergeExtraData`) —
   confirmed by reading the full method body. This mechanism is not
   needed for the chosen design (each tracked product already gets its
   own dedicated `api` Synchronization pointed straight at
   `/{product}.json`), but is a viable alternative if a future change
   wants one Synchronization to fan out per-product sub-fetches from an
   object-shaped product list — worth recording for the deferred
   auto-discovery follow-up (see Next Steps).

7. **`tests/Integration/` is already excluded from the default `phpunit.xml`
   test suite** and has an established self-skipping convention for tests
   that need a real external dependency
   (`tests/Integration/Tables/TablesBridgeIntegrationTest.php`, run via
   `phpunit-unit.xml --testsuite "Integration Tests"`). A live smoke test
   against the real, public, no-auth endoflife.date API fits this
   convention directly — no new CI wiring or test-runner convention is
   needed, only a new test class that self-skips on a network/connectivity
   failure rather than a missing credential.

## Recommendation

**Do not attempt to sync `/api/all.json` through the generic engine.**
Instead:

- Seed `eolProduct` objects **declaratively** (static catalog metadata for
  a curated starter set of tracked products) — there is nothing dynamic
  to discover for this part; it does not need the sync engine at all.
- Sync `eolCycle` objects **per product**, one dedicated `Synchronization`
  + `Mapping` + `Job` triple per curated product, each with a literal
  (non-templated) `/{product}.json` endpoint — this is 100% compatible
  with the engine as it exists today, reuses the SynchronizationContract
  triad for idempotent upsert and soft-delete-aware garbage collection
  unchanged, and avoids the cross-product identity-collision risk
  (Finding 4).
- Scope the starter set to a small, curated, clearly-documented list
  (e.g. 8 products) rather than promising all 460+ out of the box —
  extending it is a copy-paste of the same fragment shape, documented in
  the docs page, with no code change required.
- Flag Finding 5 (slug-based Job→Synchronization resolution) as an
  explicit live-verification task rather than silently assuming it works.

This is a **Go** on the config-only approach for the scope in Risk 1/2 of
the proposal; it is a documented **scope cut, not a blocker**.

## Risks Uncovered

- Findings 1–2 (the bare-string-array incompatibility and the dead
  `sourceType: "array"` branch) are pre-existing gaps in the
  `synchronization-engine` capability, independent of this change. They
  are not fixed here (out of scope — this change is a preset, not an
  engine change) but are worth a future, separately-scoped
  `synchronization-engine` follow-up: (a) box scalar array items into
  `{value: <item>}` before per-item processing so bare-list sources (not
  just endoflife.date) become usable, and/or (b) either implement the
  documented `sourceType: "array"` dispatch or correct the spec text to
  match HEAD. Recorded here for traceability; not actioned by this
  change's tasks.md.

## Next Steps

Proceed to design.md and specs/ with the curated-set, per-product-sync
design above. A full-catalog auto-discovery capability (seed a
`Synchronization`/`Mapping`/`Job` triple for every product returned by
`/api/all.json`, via a small, explicitly-scoped repair-step/command per
ADR-031's external-integration exemption) is a natural, clearly-bounded
follow-up change once this preset is live — not part of this change's
tasks.md.
