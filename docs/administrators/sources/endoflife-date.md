# endoflife.date source preset

## What this ships

A ready-to-run, credential-free source preset for the public
[endoflife.date](https://endoflife.date/api) lifecycle API:

- One **source** object (`endoflife-date`, `https://endoflife.date/api`,
  `auth: none`) — ships **enabled**, not dormant. There is nothing to
  configure before it starts working: the API is public and free.
- Two new schemas in the existing `openconnector` register:
  - `eolProduct` — a tracked product/technology (`slug`, `name`,
    `category`, `homepage`, `endoflifeUrl`).
  - `eolCycle` — one release-cycle's lifecycle data for a product
    (`product`, `cycle`, `releaseDate`, `eol`, `support`, `latest`,
    `latestReleaseDate`, `lts`, `discontinued`).
- Eight curated `eolProduct` objects, seeded declaratively: `php`,
  `nodejs`, `python`, `postgresql`, `mysql`, `nextcloud`, `wordpress`,
  `laravel`.
- One `mapping` + `synchronization` + `job` triple per curated product,
  each pulling that product's cycle data from
  `https://endoflife.date/api/{slug}.json` on a **daily** schedule
  (86400s interval) via the existing, generic Synchronization/Mapping/Job
  engine — no new PHP code.

## Where to find it

- **Sources** page: a source named "endoflife.date", already enabled.
- **Synchronizations** page: 8 synchronizations named
  `endoflife.date — <Product> cycles`, one per curated product.
- **Jobs** page: 8 jobs named `endoflife.date — <Product> cycles sync`,
  each running daily via `OCA\Integriq\Action\SynchronizationAction`.
- **Catalog** page: an "endoflife.date" card appears automatically among
  the source-template entries (no bespoke UI code — this is a side effect
  of the seed fragment declaring a `source` object, picked up by the
  existing `CatalogRegistryService.collectFromSeedFragments()`
  mechanism).

## Field shapes

### `eolProduct`

| Field          | Type          | Notes                                             |
|----------------|---------------|----------------------------------------------------|
| `slug`         | string, req'd | The endoflife.date product identifier (path segment used at `/api/{slug}.json`) |
| `name`         | string, req'd | Human-readable name                               |
| `category`     | string        | e.g. "Language runtime", "Database", "Platform", "CMS", "Framework" |
| `homepage`     | string (uri)  | Product's official homepage                       |
| `endoflifeUrl` | string (uri)  | `https://endoflife.date/{slug}`                    |

### `eolCycle`

| Field               | Type                 | Notes                                                                 |
|---------------------|----------------------|------------------------------------------------------------------------|
| `product`           | string, req'd        | Owning `eolProduct.slug`, set as a literal by the product's mapping   |
| `cycle`             | string, req'd        | Release-cycle label (e.g. `"3.14"`) — the sync origin id              |
| `releaseDate`       | string (date)        | First release date of this cycle                                      |
| `eol`               | string                | ISO end-of-life date, or `""` when none is scheduled upstream         |
| `support`           | string                | ISO end-of-active-support date, or `""`                               |
| `latest`            | string                | Latest patch release within this cycle                                |
| `latestReleaseDate` | string (date)         | Release date of `latest`                                              |
| `lts`               | boolean or string     | Long Term Support flag — upstream sometimes reports an ISO date instead of `true`/`false`; left uncast |
| `discontinued`      | string                | ISO discontinuation date, or `""` — not reported for every product/cycle |

`eol`/`support`/`discontinued` are cast to `string` by each product's
mapping because endoflife.date reports these fields as either an ISO date
string or the JSON literal `false` (no scheduled date) — casting collapses
both shapes into one consistently-typed column (`false` → `""`).

`eolCycle` is **never hand-seeded** — it is populated live by each curated
product's daily Synchronization. A static seed here would be immediately
stale.

## Extending the tracked set (no code change required)

To track a ninth product (e.g. `django`) from the 460+ listed at
[`https://endoflife.date/api/all.json`](https://endoflife.date/api/all.json):

1. Duplicate one curated product's `eolProduct` seed object in
   `lib/Settings/register.d/endoflife-date-source.json`, substituting the
   new product's `slug`/`name`/`category`/`homepage`/`endoflifeUrl`.
2. Duplicate that same product's `mapping` + `synchronization` + `job`
   triple in `lib/Settings/register.d/endoflife-date-source-cycles.json`,
   substituting the slug everywhere it appears (object slugs, the
   `sourceConfig.endpoint`, the mapping's literal `product` value, and the
   job's `arguments.synchronizationId`).
3. Keep `sourceConfig.resultsPosition: "_root"` — **required**, because
   `/api/{slug}.json` returns a bare top-level array with none of the
   `items`/`result`/`results` keys the engine otherwise falls back to.
   Omitting it fails every run with "Cannot determine the position of
   objects in the return body."
4. Re-run `InitializeRegister` (`occ app:enable integriq` or an
   upgrade). The new product's `eolCycle` data begins syncing on the same
   daily cadence — no PHP or engine change required.

Give every product its **own** `Synchronization` (its own
`synchronizationId`). `SynchronizationContract` identity is scoped to
`(synchronizationId, originId)` only — if two products shared one
Synchronization, two ecosystems reporting the same cycle label (e.g. both
having a `"3.14"` release) would silently overwrite each other's target
object.

## Not shipped out of the box

Auto-discovering and syncing **all 460+** endoflife.date products is a
known, intentionally out-of-scope follow-up, not a bug — `/api/all.json`
returns a bare array of product-slug strings, which is structurally
incompatible with the generic per-item Synchronization pipeline (it
requires each fetched item to already be array-shaped). Full-catalog
auto-discovery would need a small, separately-scoped repair-step/command
(per ADR-031's external-integration exemption) and is a natural follow-up
once this preset is live — not part of this preset.
