---
kind: config
depends_on: []
---

# Proposal: endoflife-date-source

## Summary

Ship a first-class, ready-to-run **source preset for the public
[endoflife.date](https://endoflife.date/api) API** in OpenConnector: a
seeded `source` (base URL `https://endoflife.date/api`, no auth), a
dedicated register carrying two new schemas — `eolProduct` (a tracked
product/technology) and `eolCycle` (one release-cycle's lifecycle data for a
product) — a curated set of pre-built `Synchronization` + `Mapping` + `Job`
objects that pull each tracked product's cycle data on a daily schedule, and
a live smoke test proving the whole chain against the real, free, public
API. This is a **configuration/preset** change: it reuses OpenConnector's
existing Source → Synchronization → SynchronizationContract engine
(`synchronization-engine`), the Mapping engine (`mapping-and-search`), and
the Job/cron machinery (`job-scheduling`) end to end — no new PHP sync,
mapping, or scheduling code is introduced.

## Motivation

endoflife.date is the canonical open-source EOL/lifecycle dataset — 460+
products, a stable public JSON API, no authentication, no cost — making it
an ideal first "batteries-included" data source preset and one that is
fully live-testable in CI/dev without any credential. Per the established
Conduction pattern (mirrored by the already-shipped `brp-haalcentraal`,
`kvk`/`opencorporates`, and messaging-channel source seeds), integration
transports and their scheduled data pulls live in OpenConnector; leaf apps
consume the resulting OpenRegister objects. The softwarecatalog app's
`eol-feed-integration` change (sibling repo, out of scope here) needs a
queryable `eolCycle` register to match a catalog module's version against
its upstream EOL/support window — today no such data exists anywhere in the
platform.

## Capabilities

- `endoflife-date-source` — new capability (this spec). Built entirely on
  top of the existing `source-management`, `synchronization-engine`,
  `mapping-and-search`, `job-scheduling`, and `connector-catalog`
  capabilities, none of which are modified by this change.

## Affected Projects

- [ ] Project: `openconnector` — new `eolProduct` + `eolCycle` schemas
  (register `openconnector`), a seeded `endoflife-date` `source`, seeded
  `mapping` + `synchronization` + `job` objects per curated tracked
  product (daily scheduled pull via the existing `SynchronizationAction`
  job class), a live smoke-test integration test, and an administrator docs
  page. All shipped as ADR-037 `register.d/*.json` fragments — no shared
  file is edited, no new PHP class is added.

## Scope

### In Scope

- `eolProduct` and `eolCycle` OpenRegister schemas, declared in a dedicated
  register.d fragment (register `openconnector`).
- One seeded `source` object (`endoflife-date`, `location:
  https://endoflife.date/api`, `auth: none`) — public, free, no secret, so
  unlike the credentialed presets (BRP/KvK/messaging) it ships **enabled**
  out of the box, not dormant.
- A curated starter set of tracked products (e.g. `php`, `nodejs`,
  `python`, `postgresql`, `mysql`, `nextcloud`, `wordpress`, `laravel`),
  each seeded as: one `eolProduct` object (static catalog metadata), one
  `mapping` object (source cycle shape → `eolCycle` shape, carrying the
  product slug as a literal), one `synchronization` object
  (`sourceType: api`, endpoint `/{product}.json`, target
  `openconnector/eolCycle`), and one `job` object (daily cadence,
  `jobClass: OCA\OpenConnector\Action\SynchronizationAction`) — reusing the
  generic synchronization/job dispatch engine unchanged.
- A documented, repeatable recipe for adding any further product from the
  460+ listed at `/api/all.json` by duplicating the same fragment shape —
  no code change required to add a product.
- Idempotent upsert across repeated syncs via the existing
  `SynchronizationContract` origin-id/hash mechanism (`cycle` as the
  per-product origin id), and soft-delete-aware garbage collection of
  cycles no longer reported by the source, reusing the existing deletion
  ratio guard unchanged.
- API-friendly fetch strategy: one call per tracked product per scheduled
  run (`/api/{product}.json`, an array of real JSON objects — directly
  compatible with the engine's per-item identity/mapping pipeline), not a
  blind full-catalog crawl.
- A live smoke test that calls the real `https://endoflife.date/api` over
  the network and asserts a full sync → `eolCycle` objects → re-run
  idempotency chain, following the repo's established self-skipping
  `tests/Integration` convention.
- Unit tests for the per-product mapping recipe.
- An administrator docs page describing the preset and how to extend it.
- Automatic Catalog UI visibility: because the fragment seeds a `source`
  object, OpenConnector's existing `CatalogRegistryService` picks it up as
  a `source-template` catalog card with zero additional UI code
  (`connector-catalog` REQ-003).

### Out of Scope

- Any softwarecatalog-side matching logic (module version → EOL cycle) —
  that lives entirely in softwarecatalog's own `eol-feed-integration`
  change, which only *consumes* the `eolCycle` register this change
  produces.
- Other lifecycle/vulnerability feeds (OSV, NVD) — a separate preset each.
- Syncing the full 460+ product catalog out of the box — the curated
  starter set plus the documented extension recipe covers the "canonical,
  live-testable" requirement without forcing every install to run 460+
  daily API calls; see design.md's Open Questions for the discovery-side
  finding that motivates this scope cut.
- Any UI beyond what OpenConnector already renders for Sources /
  Synchronizations / Jobs / the Catalog page.
- A dynamic "browse all 460+ endoflife.date products and one-click-add"
  UI flow — a natural follow-up once this preset proves the pattern, but
  it needs new `CatalogController` code and is not needed to satisfy this
  change's scope.

## Approach

Everything is declared through OpenConnector's existing declarative
schema/seed mechanism (ADR-037 `register.d/*.json` fragments,
folded into the register descriptor by `SettingsService` and materialised
idempotently by `@self.slug` via OpenRegister's `ImportHandler`). At
runtime, nothing new executes: `SynchronizationService::synchronize()`
fetches each tracked product's `/api/{product}.json`, applies the seeded
`Mapping`, and writes `eolCycle` objects through the same
Source/Synchronization/SynchronizationContract triad every other
OpenConnector connector uses; `JobService`/`JobTask` sweep the seeded `Job`
objects on their configured interval via the existing, generic
`SynchronizationAction` job class. Full mechanics, the schema field
tables, and the discovery-side finding that shaped the curated-set
decision are in design.md.

## New Dependencies

None. Reuses the existing Source, Synchronization, Mapping, and Job
machinery (`synchronization-engine`, `mapping-and-search`, `job-scheduling`
specs) and the existing Catalog materialisation
(`connector-catalog` REQ-003).

## Impact

- New: `lib/Settings/register.d/endoflife-date-source.json` (schemas +
  source + mapping/synchronization/job seed objects — may be split across
  more than one fragment file for reviewability; see tasks.md),
  `tests/Unit/Service/EndoflifeDateMappingTest.php`,
  `tests/Integration/EndoflifeDateLiveSyncTest.php`, and
  `docs/administrators/sources/endoflife-date.md`. No edits to
  `lib/Settings/openconnector_register.json`, `appinfo/info.xml`, or any
  other shared file.

## Cross-Project Dependencies

- softwarecatalog's `eol-feed-integration` change (sibling repo) will
  consume the `eolCycle` register this change produces (register
  `openconnector`, schemas `eolProduct`/`eolCycle`) — a read-only,
  documented contract; no code in that app is built or modified here.

## Risks

### Risk 1: `/api/all.json` cannot be synced directly through the generic engine

**Severity:** Medium — **Mitigation:** `/api/all.json` returns a bare JSON
array of product-slug strings, not objects; OpenConnector's
`SynchronizationService::getOriginId()`/`processSynchronizationObject()`
require each fetched item to already be array-shaped (type-hinted
`array $object`), so a raw string item is structurally incompatible with
the generic per-item sync pipeline as it exists in HEAD today (verified by
reading `SynchronizationService.php`, not assumed). Rather than add new
engine code to box scalar array items (out of scope per this change's
"reuse, don't extend the engine" mandate), the design sidesteps the
incompatibility entirely: `eolProduct` entries are seeded declaratively
(their metadata is static, human-known catalog data, not something that
needs live discovery), and only the genuinely dynamic part — each
product's cycle list — is synced, from `/api/{product}.json`, which *is*
a plain array of real JSON objects and works with the engine unmodified.
See design.md Open Questions for the full analysis and the deferred
follow-up (a small, explicitly-scoped repair-step/command) if full
460+-product auto-discovery is wanted later.

### Risk 2: One `Synchronization`/`Mapping`/`Job` triple per tracked product does not scale to all 460+ products as static seed fragments

**Severity:** Low — **Mitigation:** deliberately scoped to a curated
starter set (this change) with a documented, copy-paste-simple extension
recipe (docs page + tasks.md). Bulk/dynamic onboarding of the remaining
catalog is an explicit, separate follow-up (Risk 1's deferred repair-step
option), not silently promised by this change.

### Risk 3: the seeded `Job`'s `arguments.synchronizationId` references the `Synchronization` by slug, not by the UUID OpenRegister assigns at import time

**Severity:** Low — **Mitigation:** `OCA\OpenConnector\Action\SynchronizationAction::run()`
resolves `synchronizationId` via `SynchronizationService::getSynchronization()`
→ `OCA\OpenRegister\Service\ObjectService::find(id:, register:, schema:)` —
the same call shape already relied on elsewhere in this codebase for
slug-based lookups (e.g. `SourceMapper::find('kvk')`, established by the
already-merged `seed-kvk-opencorporates-sources` change). This change
follows that precedent and flags it as a live-verification task (tasks.md)
rather than asserting it silently; if OR's `find()` does not resolve a
slug for the `synchronization` schema specifically, the fallback is a
one-line install-time patch (a repair step reads back the assigned UUID
and writes it onto the `job.arguments.synchronizationId` field) — captured
as a task, not assumed away.

## Rollback Strategy

Fully additive and declarative. Revert by deleting the new
`register.d/*.json` fragment(s) — the next `InitializeRegister` run stops
re-asserting the `eolProduct`/`eolCycle` schemas, the `endoflife-date`
source, and the seeded mapping/synchronization/job objects (existing,
already-materialised OR objects are left in place per OR's standard
fragment-removal behaviour and can be cleaned up by an operator; no
existing source, synchronization, or job outside this preset is touched).
No PHP code is added, so there is nothing to roll back on the code side.

## Open Questions

None blocking — the curated-set scope cut and the slug-based job
reference are both documented, testable design decisions rather than
open-ended unknowns; see design.md for the full discovery trail.
