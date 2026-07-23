# Design: endoflife-date-source

## Architecture Overview

```
 register.d/endoflife-date-source*.json  (ADR-037 fragments, folded into the
   │                                       register descriptor by
   │                                       SettingsService; occ app:enable /
   │                                       upgrade → InitializeRegister)
   ├─ schemas: eolProduct, eolCycle          (declarative, new register data)
   ├─ 1 source object: endoflife-date        (public, no auth, isEnabled:true)
   ├─ 8 eolProduct objects                   (seeded, static catalog metadata)
   └─ per curated product (×8):
        1 mapping object   "<slug>-cycles-mapping"
        1 synchronization  "<slug>-cycles"    sourceType:api → /{slug}.json
        1 job              "<slug>-cycles-sync"  daily, jobClass:
                                              OCA\OpenConnector\Action\SynchronizationAction

Runtime (nothing new — existing engine only):
  cron (job-scheduling) ──► JobTask::run() ──► JobService::run()
    └─ per due "<slug>-cycles-sync" job ──► JobService::executeJob()
         └─ SynchronizationAction::run({synchronizationId:"<slug>-cycles"})
              └─ SynchronizationService::synchronize()
                   ├─ GET https://endoflife.date/api/{slug}.json  (CallService)
                   ├─ per cycle: getOriginId (idPosition:"cycle") ─► hash ─►
                   │    MappingService::executeMapping("<slug>-cycles-mapping")
                   │    ─► upsert eolCycle object + SynchronizationContract
                   └─ deleteInvalidObjects()  (soft-delete-aware GC, ratio-guarded)

Catalog UI: CatalogRegistryService.collectFromSeedFragments() content-
inspects every register.d/*.json fragment for a `source` object → the
seeded `endoflife-date` source auto-appears as a "source-template" card
with zero new UI code (connector-catalog REQ-003).

softwarecatalog eol-feed-integration (sibling repo, NOT built here):
  reads register `openconnector`, schema `eolCycle` (+ `eolProduct`) via
  the standard OpenRegister object API.
```

## Goals / Non-Goals

**Goals**
- A working, live, credential-free source preset demonstrable on a fresh
  install with zero operator setup.
- Reuse the existing Source/Synchronization/Mapping/Job engine byte-for-byte
  — no new PHP service, controller, cron class, or mapping logic.
- Idempotent, soft-delete-aware upsert across repeated syncs, with correct
  per-product identity isolation (see discovery.md Finding 4).
- API-friendly fetch cadence (one small call per tracked product per day,
  not a firehose crawl of `/api/all.json` plus 460+ per-product calls).
- Automatic Catalog UI visibility via the existing fragment-inspection
  mechanism.

**Non-Goals**
- Auto-discovering and syncing all 460+ endoflife.date products out of the
  box (see discovery.md Recommendation — deliberately deferred).
- Any softwarecatalog-side EOL matching logic.
- Fixing the two pre-existing `synchronization-engine` gaps uncovered
  during discovery (bare-scalar-array items, dead `sourceType: "array"`
  dispatch) — flagged, not fixed, here.

## Decisions

### Decision 1: `eolProduct` is seeded declaratively, not synced

**Why:** `/api/all.json` returns a bare array of strings, which is
structurally incompatible with the generic per-item sync pipeline as it
exists today (discovery.md Finding 1) — syncing it would require new
engine code, which is explicitly out of scope. `eolProduct` metadata
(name, category, homepage) is static and human-known for a curated set, so
there is nothing to "sync" in the first place.
**Alternative considered:** a repair-step/command that calls `/api/all.json`
once and upserts `eolProduct` rows directly via `ObjectService::saveObject`
(bypassing the Synchronization engine entirely, justified under ADR-031's
external-integration exemption, matching how `corporate-card-feed`'s
enrollment endpoint persists newly-discovered OR objects as a side effect
of a provider call). Rejected for **this** change because it adds a new
PHP class for a benefit (full-catalog discovery) that is explicitly out of
scope; recorded as the natural follow-up in discovery.md Next Steps
instead.

### Decision 2: one `Synchronization`/`Mapping`/`Job` triple per tracked product

**Why:** `SynchronizationContract` identity is `(synchronizationId,
originId)` only — no per-product discriminator exists on the schema.
Sharing one `Synchronization` across multiple products (via a templated
endpoint + per-run `data` argument) would let two products' same-labelled
cycles (e.g. two ecosystems both having a `"3.14"` release) silently
overwrite each other's target object (discovery.md Finding 4). Giving each
tracked product its own `Synchronization` (and therefore its own
`synchronizationId`) makes every contract's `(synchronizationId, originId)`
pair collision-proof by construction, with zero new engine code.
**Alternative considered:** `sourceConfig.extraDataConfigs` fan-out from a
single object-shaped product list (discovery.md Finding 6) — viable in
principle, but requires the product list to already be object-shaped,
which circles back to Decision 1's constraint; deferred to the same
full-catalog follow-up.

### Decision 3: curated starter set of 8 products, not all 460+

**Why:** proposal.md Risk 2 / discovery.md Recommendation. 8 static
seed fragments (each ~3 small OR objects) is reviewable and matches the
"mostly configuration/preset" brief; 460+ would mean ~1,400 near-duplicate
seed objects and 460+ daily cron entries for no scope-mandated reason
(softwarecatalog's own change decides which modules it actually needs to
match, and can request additions using the exact same fragment shape).
**Products chosen** (broad, representative of a typical software catalog's
stack, each independently meaningful to verify against): `php`, `nodejs`,
`python`, `postgresql`, `mysql`, `nextcloud`, `wordpress`, `laravel`.

### Decision 4: literal (non-templated) per-product endpoint + mapping

**Why:** since each product gets its own `Synchronization`, there is no
need for `{{ data.productSlug }}` endpoint templating or a `data` argument
threaded through the Job — `sourceConfig.endpoint: "/python.json"` and
`mapping.mapping.product: "python"` (a plain literal string, which the
mapping engine's Twig renderer returns verbatim when it contains no `{{ }}`
markers — `mapping-and-search` REQ-001) are simplest, most inspectable, and
require no engine feature beyond what every other existing `api`
Synchronization already uses.

### Decision 5: `resultsPosition: "_root"` is mandatory, not optional

**Why:** `SynchronizationService::getAllObjectsFromArray()`'s fallback (no
`resultsPosition` configured) only checks the common keys `items` /
`result` / `results` on the decoded body; `/api/{product}.json`'s body is
a bare top-level array with none of those keys, so an unconfigured
Synchronization would throw `"Cannot determine the position of objects in
the return body."` on every run. `resultsPosition: "_root"` is therefore a
required field in every seeded `eolCycle` Synchronization's `sourceConfig`,
not a nice-to-have — captured as its own spec scenario so a future
edit to the fragment cannot silently drop it.

### Decision 6: `eol` / `support` / `discontinued` are cast to `string`

**Why:** endoflife.date's JSON returns these fields as either an ISO date
string or the JSON literal `false` (no scheduled date). The mapping
engine's existing `cast: "string"` directive (`mapping-and-search` REQ-002)
coerces both shapes into a single `eolCycle` column type without any new
cast directive: PHP's `(string) false` is an empty string, so "no
scheduled EOL" round-trips as an empty string rather than a type-mismatched
boolean landing in a `string`-typed OR property. `lts` is left as-is
(boolean `true`/`false`, or occasionally an ISO date per upstream's own
inconsistency) with no cast, since a boolean-or-date mixed type is exactly
what `eolCycle.lts`'s (untyped-scalar) property description documents.

### Decision 7: `deletionRatioThreshold` is raised to `0.5` per product Synchronization

**Why:** the default guard (`synchronization-engine` REQ-010, 0.10) is
tuned for large object sets; several curated products (e.g. a language
with only 3–4 actively-listed cycles) would trip the default guard the
moment a single retired cycle is legitimately removed upstream (1-in-3 =
33% > 10%), silently blocking correct garbage collection every time. Set
per-Synchronization via the existing `sourceConfig.deletionRatioThreshold`
override — no new engine behaviour, just a documented per-preset
configuration choice.

### Decision 8: `eolProduct`/`eolCycle` live in the existing `openconnector` register, not a new register

**Why:** `openconnector-register-schema` REQ-A-001 establishes that the app
MUST declare "exactly one register with slug `openconnector`"; every
existing schema-adding change (`hitl-approval-rule-action`,
`corporate-card-feed`, the LTI schemas, …) adds its schema(s) to that same
register via `components.registers.openconnector.schemas`. Reading the
context brief's "a dedicated register" as *a dedicated pair of schemas*
(not a second, literally-separate OpenRegister register) keeps this change
consistent with that established one-register-per-app convention rather
than introducing a structural first.

## Nextcloud Integration

- Controllers: none new.
- Services: none new — reuses `SynchronizationService`, `MappingService`,
  `JobService`, `CallService` unchanged.
- Cron: none new — reuses the existing `JobTask` sweep and
  `OCA\OpenConnector\Action\SynchronizationAction` job class unchanged.
- Mappers/Entities: none new — `eolProduct`/`eolCycle` via OpenRegister
  `ObjectService`, same as every other OpenConnector-declared schema.
- Events/Hooks: none new.

## Security Considerations

- No credentials of any kind — `auth: "none"`, the API is public and free.
  There is nothing to broker, redact, or leak.
- The seeded `location` is a fixed, admin-controlled string
  (`https://endoflife.date/api` + a literal, non-user-influenceable
  per-fragment suffix like `/python.json`) — no end-user input reaches the
  outbound request URL, so there is no SSRF surface (mirrors the existing
  `source-management` messaging-source precedent).
- `eolProduct`/`eolCycle` carry no PII and no secret-bearing fields —
  export/import (`configuration-export-import`) needs no redaction rule
  for this preset.
- Existing IDOR findings already documented on
  `SynchronizationsController`/`JobsController` (`synchronization-engine`
  REQ-005 Notes, `job-scheduling` REQ-002 Notes) apply unchanged and are
  out of scope for this preset to fix.

## File Structure

```
lib/
  Settings/
    register.d/
      endoflife-date-source.json        # schemas + source + eolProduct seeds
      endoflife-date-source-cycles.json # mapping + synchronization + job
                                         #   seeds, one entry per curated
                                         #   product (kept in its own
                                         #   fragment file for reviewability
                                         #   — see tasks.md for the exact
                                         #   split)
tests/
  Unit/
    Service/
      EndoflifeDateMappingTest.php      # mapping recipe unit tests
  Integration/
    EndoflifeDateLiveSyncTest.php       # live smoke test (self-skipping)
docs/
  administrators/
    sources/
      endoflife-date.md                 # admin-facing docs page
```

## Seed Data

### Schema: `eolProduct`

| Field | php | nodejs | python | postgresql | mysql | nextcloud | wordpress | laravel |
|-------|-----|--------|--------|------------|-------|-----------|-----------|---------|
| slug | php | nodejs | python | postgresql | mysql | nextcloud | wordpress | laravel |
| name | PHP | Node.js | Python | PostgreSQL | MySQL | Nextcloud | WordPress | Laravel |
| category | Language runtime | Language runtime | Language runtime | Database | Database | Platform | CMS | Framework |
| homepage | https://www.php.net | https://nodejs.org | https://www.python.org | https://www.postgresql.org | https://www.mysql.com | https://nextcloud.com | https://wordpress.org | https://laravel.com |
| endoflifeUrl | https://endoflife.date/php | https://endoflife.date/nodejs | https://endoflife.date/python | https://endoflife.date/postgresql | https://endoflife.date/mysql | https://endoflife.date/nextcloud | https://endoflife.date/wordpress | https://endoflife.date/laravel |

**Related items per object:** Files: none. Notes: none. Contacts: none —
these are technology catalog entries, not organisation/contact records.

### Schema: `eolCycle`

Not hand-seeded with example rows — populated live by the first run of
each product's seeded `Synchronization` (that is the point of the preset;
a static seed here would immediately be stale). The live smoke test
(tasks.md) is what proves this population path end to end on install.

### Schema: `source`

| Field | Value |
|-------|-------|
| slug | endoflife-date |
| name | endoflife.date |
| location | https://endoflife.date/api |
| auth | none |
| isEnabled | true |
| configuration.headers.Accept | application/json |

### Schema: `mapping` (×8, one per product — shown for `python`, others identical shape)

| Field | Value |
|-------|-------|
| slug | endoflife-date-python-cycles-mapping |
| name | endoflife.date Python cycles mapping |
| mapping.product | `python` (literal) |
| mapping.cycle | `cycle` (direct copy) |
| mapping.releaseDate | `releaseDate` |
| mapping.eol | `eol` |
| mapping.support | `support` |
| mapping.latest | `latest` |
| mapping.latestReleaseDate | `latestReleaseDate` |
| mapping.lts | `lts` |
| mapping.discontinued | `discontinued` |
| cast.eol / cast.support / cast.discontinued | `string` |
| passThrough | false |

### Schema: `synchronization` (×8, shown for `python`)

| Field | Value |
|-------|-------|
| slug | endoflife-date-python-cycles |
| name | endoflife.date — Python cycles |
| sourceId | endoflife-date |
| sourceType | api |
| sourceConfig.endpoint | /python.json |
| sourceConfig.resultsPosition | _root |
| sourceConfig.idPosition | cycle |
| sourceConfig.deletionRatioThreshold | 0.5 |
| targetType | register/schema |
| targetId | openconnector/eolCycle |
| sourceTargetMapping | endoflife-date-python-cycles-mapping |

### Schema: `job` (×8, shown for `python`)

| Field | Value |
|-------|-------|
| slug | endoflife-date-python-cycles-sync |
| name | endoflife.date — Python cycles sync |
| jobClass | OCA\OpenConnector\Action\SynchronizationAction |
| arguments.synchronizationId | endoflife-date-python-cycles |
| interval | 86400 |
| isEnabled | true |

## Trade-offs

- **Curated 8-product set vs. full 460+ catalog.** Chose curated (Decision
  3) to stay within "mostly configuration/preset" scope and keep the
  fragment reviewable; full-catalog auto-discovery is a clean, separately
  scoped follow-up (a small repair-step/command, per ADR-031's
  external-integration exemption) rather than something this change
  should silently half-do.
- **8 near-duplicate mapping/synchronization/job triples vs. one
  parameterised triple.** Chose duplication (Decision 2) because the
  engine's contract-identity model does not support safe multi-product
  sharing without new code; duplication here is boring, inspectable, and
  correct, which outweighs the DRY concern for a fixed, small N.
- **Declarative `eolProduct` seed vs. a discovery repair-step.** Chose
  declarative (Decision 1) because it needs zero new PHP and the curated
  set's metadata is static; a repair-step only earns its keep once the
  scope grows to "all products," which is explicitly deferred.

## Open Questions

- Does `OCA\OpenRegister\Service\ObjectService::find(id:, register:,
  schema:)` resolve a `synchronization` object by `@self.slug` the same
  way it is relied on to resolve a `source` by slug elsewhere in this
  codebase (proposal.md Risk 3 / discovery.md Finding 5)? Not blocking —
  captured as a live-verification task; the documented fallback is a
  one-line install-time patch if it turns out UUID-only.
