# Design — native data-gathering provider

## Context (verified against the code, not assumed)

Traced at HEAD (`origin/development`, 44fe20a2) before writing:

- **Source fetch.** `SynchronizationService::getAllObjectsFromSource()` →
  `fetchAllPagesOptimized()` → `fetchSinglePageData()` fetches a page via
  `CallService::call()` and parses it: `json_decode()` first, then a fallback to
  `simplexml_load_string()` + a private `xmlToArray()` (XML/RSS), then gzip
  decompression + JSONL line-parsing when signalled, then markdown-list /
  HTML-CSS-selector extraction — the JSON/XML/gzip-JSONL/markdown/HTML paths are
  all present on `development`.
- **Transform.** `MappingService::executeMapping()` applies a Mapping's
  dot-path + Twig field map, `unset`, `cast`, `passThrough`. Twig always returns
  a string (never an array); a bare dot-path whose source field is absent falls
  through to rendering the dot-path string verbatim (the documented literal-leak
  gotcha).
- **Target write.** A Synchronization with `targetType: "register/schema"` and
  `targetId: "<register>/<schema>"` upserts each mapped record into an
  OpenRegister register/schema via the ObjectService. Identity is a
  `synchronization_contract` keyed on `synchronizationId` + `originId`;
  `sourceHash` change-detection (`mapHashObject`) decides create/update/skip. A
  re-run reuses the same target uuids — no duplicates (verified live on ten
  connectors).
- **Cron.** `Cron/JobTask.php` is a Nextcloud `TimedJob` (5-minute tick) that
  delegates to `JobService::run()`. `JobService::scheduleJob()` registers a
  Job's `interval` (seconds) and honours `nextRun` / `scheduleAfter`;
  rate-limited sources push `nextRun` forward off the source's
  `rateLimitReset`. This is OpenConnector's **own** scheduler — no dependency on
  any flow-engine cron.
- **Sources/Syncs/Jobs are OpenRegister objects.** They live in register
  `openconnector`, schemas `source` / `mapping` / `synchronization` /
  `synchronization_contract` / `job` — read straight from OpenRegister by
  `SynchronizationService`.

Conclusion: the answer to the grounding question — *can a Source fetch a REST
endpoint, a Mapping transform it, and a Synchronization upsert into an OR
register on a Job cron today?* — is **yes**, and it is already live-verified for
ten Specter tendrils. This change is positioning + formalization + gap
enumeration, not new engine code.

## The gather contract (the sanctioned Specter-sync replacement)

One Specter `sync_*.py` script becomes one connector bundle of five
OpenRegister objects (register `openconnector`), exactly the shipped
`tenderned.json` shape:

```
Source          type/location/auth + configuration.headers   ── fetch
   │
Mapping (×1–2)  sourceTargetMapping (fields) + optional       ── transform
   │            sourceHashMapping (change-detection subset)
Synchronization sourceId, sourceType, sourceTargetMapping,     ── keyed-upsert
   │            sourceHashMapping, sourceConfig{endpoint,
   │            query, idPosition, resultsPosition, maxPages,
   │            format?}, targetType:"register/schema",
   │            targetId:"<register>/<schema>"
   │
Job             jobClass: SynchronizationAction,               ── cron
                arguments.synchronizationId, interval (s),
                isEnabled
```

Target-register binding: `targetType: "register/schema"` +
`targetId: "<register>/<schema>"` (e.g. `"spectr/tender"`). The target
register/schema must already exist; the connector fragment only seeds the five
`openconnector` objects (registers/schemas are provisioned separately).

Packaging: an ADR-037 `register.d` fragment — a `$comment` + a
`components.objects[]` array where each object carries an
`@self.{register,schema,slug}` triplet — dropped at
`lib/Settings/register.d/<connector>.json`. `InitializeRegister` folds it into
`openconnector_register.json` on `occ app:enable`/upgrade; OpenRegister's
`ImportHandler` materialises each object idempotently by slug (object components
are version-gated: a re-import skips when `incoming version <= existing`, so a
live-fix must bump the object's `version`).

## Capability parity — HAVE vs GAP

"HAVE" = present on `development` today (service cited). "GAP" = not built;
needs its own follow-up change before the dependent tendrils can be native.

| Capability | Specter archetype needing it | HAVE / GAP | Where / how to close |
|---|---|---|---|
| HTTP/REST fetch + JSON parse | every REST tendril (tenderned, ted, dpg, github) | **HAVE** | `CallService::call` + `fetchSinglePageData` (`json_decode`) |
| XML parse (namespace-stripped, `#text`-wrapped leaves) | estonia_rhr eForms, germany | **HAVE** (since 2026-06-20) | `fetchSinglePageData` `simplexml_load_string` + `xmlToArray` |
| RSS/Atom feed | sweden_avropa, blogs_rss | **HAVE** | XML fallback + `resultsPosition: channel.item` (live-verified Sweden) |
| OCDS `releases` envelope | australia_austender, slovenia/romania | **HAVE** | plain JSON + `resultsPosition` (live-verified AusTender) |
| gzip decompress + JSONL bulk file | austria_datagvat, OCP bulk (slovenia/romania/croatia) | **HAVE** (landed 2026-07-15) | oc#97 `bulk-gzip-jsonl-ingestion` (archived) |
| POST-body source + method override | ted_eu, belgium, dutch_laws_bwb | **HAVE** (landed) | oc#94 `post-body-pagination` (archived) |
| Markdown-list + HTML/CSS-selector extract | awesome_selfhosted, openalternative, don_oss_register, wikipedia_comparisons | **HAVE** (landed) | oc#107 `markdown-and-html-source-fetchers` (archived) |
| Query-param page-increment pagination | tenderned (Spring-style `page`/`size`) | **HAVE** (partial) | `CallService::normaliseRequestConfig` |
| Rate-limit / 429 / Retry-After backoff | github (5000/hr), any keyed API | **HAVE** | `CallService` X-RateLimit clamp + per-source `rateLimitReset`/`rateLimitRemaining`; `JobService` pushes `nextRun` off the reset |
| Auth: none / apikey / OAuth2 client_credentials / mTLS | belgium (OAuth2), github (apikey), brp (mTLS) | **HAVE** | `AuthenticationService` + `CallService::getCertificate` + Twig `oauthToken(source)` |
| Broker-held credential (`credentialRef`) | norway_doffin (Azure APIM key) | **HAVE** (landed) | `source-broker-credentials` (archived) |
| Per-record keyed-upsert dedup | every tendril (re-run must not duplicate) | **HAVE** | `synchronization_contract` keyed on `synchronizationId`+`originId`; `sourceHash` (`mapHashObject`) |
| Dead-letter + per-item isolation | any partially-bad page | **HAVE** | `synchronization-engine` REQ-008 + `dead-letter-replay` spec |
| Run status / logs (Specter `source_syncs` equivalent) | operational visibility | **HAVE** | `logs-and-statistics`; `SynchronizationLog` / `SynchronizationContractLog` / job log |
| Self-scheduling cron | every weekly/daily tendril | **HAVE** | `JobService::scheduleJob` + `Cron/JobTask` (`TimedJob`, `interval` s, `nextRun`) |
| Data-driven per-object context merge | github_competitors (`competitorRef`), bwb lawArticle (`lawRef`) | **HAVE** (partial; `useDataAsRequestBody:false` quirk) | `getAllObjectsFromSource` `array_merge($object,$data)` |
| Offset/cursor pagination beyond page 1 (`start`/`rows`, `limit`/`offset`) | germany, france/boamp, many EU portals | **GAP** | page-increment only; needs an offset-mode / `pageOffset` knob in `normaliseRequestConfig`/`getNextPage` |
| 0-vs-1-indexed page offset | tenderned multi-page (silently skips page 2) | **GAP** | `sourceConfig.pageOffset` knob |
| Incremental / since-watermark fetch | high-volume daily feeds | **GAP** | full re-fetch each run; `sourceHash` dedups *writes* but the fetch still pulls everything — needs a persisted watermark cursor |
| CSV / delimited parse | ireland_etenders, canada_canadabuys | **GAP** | no CSV parser (gzip→json→xml all fail on CSV, silently zero) |
| `.zip` multi-member archive iterate | spain_placsp (~83 per-day Atom files) | **GAP** | oc#105 does single-member `.gz`, rejects `.tar`/`.zip` |
| N+1 two-hop (id-list then per-id detail) | italy_anac, saashub, sourceforge | **GAP** | single-fetch sync has no per-row detail-fetch step |
| Wildcard / recursive `resultsPosition` | dutch_laws_bwb lawArticle (nested repeated `hoofdstuk`) | **GAP** | `Adbar\Dot` single fixed dot-path, no glob/wildcard |
| Post-fetch loop/transform (compute an array from two fields) | belgium/latvia `cpvCodes` | **GAP** | Twig returns string only; `cast:array` is the scalar-to-1-element workaround |
| JS-rendered SPA (headless render) | fedramp | **GAP** (likely ETL/out of scope) | no headless renderer; ETL loader remains the workaround |
| Large-file streaming (35 MB+ held fully in memory) | estonia_rhr (35.9 MB/mo XML), OCP `full.jsonl.gz` | **GAP** (risk, not a hard blocker) | whole response buffered by Guzzle/`CallService`; needs streaming decompress/parse |
| Login-walled (national eID) | croatia native (NIAS) | **NOT ADDRESSABLE** | dead tendril — no unattended token shape exists; out of scope |

Each GAP row is a candidate follow-up change, sized like oc#97 / oc#94 / oc#107
(one engine capability, additive inside the existing fetch/paginate path, its
own connector(s) landed after). This change does not build any of them; it
governs how a connector that needs one behaves in the meantime (ship disabled,
documented).

## The OpenConnector-vs-leaf-provider split

Both write to OpenRegister, so the line has to be explicit or they overlap.

| | OpenConnector data gathering (this) | OpenRegister leaf data-provider |
|---|---|---|
| **Question answered** | "keep OR populated with the whole external dataset, refreshed on a schedule" | "give me the current state of THIS one object, on demand, at read time" |
| **Trigger** | OpenConnector Job cron (`interval`, `nextRun`) | a read of a specific object / sub-resource |
| **Cardinality** | many records per run (bulk) | one object's live augmentation |
| **Persistence** | real objects in a target register/schema magic table; keyed-upsert dedup | none (live projection / sub-resource) or a note tied to one object |
| **Mechanism** | Source + Mapping + Synchronization + Job | `IntegrationProvider` / `ObjectSourceProvider` (`integration-leaf-foundation`, `object-source-providers`) |
| **Freshness** | as fresh as the last cron run | always live |
| **Examples** | TenderNed weekly full-feed → `spectr/tender`; NC App Store → `spectr/marketplaceApp` | BRP person lookup for this case; xWiki page search; a CalDAV VTODO projected read-only as an `ActionItem` |

The one legitimate overlap — **transport-only sources**: a leaf provider may
reference an OpenConnector `source` purely as its HTTP transport for a single
live call (the BRP/KvK/xWiki seed sources — `configuredVia: openconnector`,
resolved through `SourceMapper`). Such a source carries **no Synchronization and
no Job**. That is what distinguishes it from a bulk-ingestion source (this
change), which always carries a Synchronization + a Job. Rule of thumb: *a
Source with a Synchronization+Job is a gatherer; a Source without one is
transport for a leaf.* The two never fight over the same object because a
gatherer writes new rows into a register/schema on a schedule, while a leaf
augments/serves one already-addressed object at read time.

## The flow-reaction seam (referenced, not built)

Gathered rows land as OpenRegister objects; OpenRegister already emits
object-created / object-updated events on write. Downstream **flows and agents
react** to those events through the flow-engine's Nextcloud-native trigger set
(or#2068) — object-created/updated triggers — with no coupling back into the
gather layer. That is why data gathering needs **no** flow-engine dependency:
the schedule is OpenConnector's own cron; the flow-engine is a *consumer* of the
objects, downstream, already wired via events.

Forward hook (deferred, do not build here): the on-demand direction — a flow or
agent asking OpenConnector to fetch *now* — already has a natural entry point in
the existing `POST /apps/openconnector/api/synchronizations/{id}/run`. When
or#2068's trigger set / or#2070's execution tooling want a "kick a sync on
demand" node, that endpoint is the hook-in point. This change names it and stops
there.

## Migration waves

| Wave | Contents | Action |
|---|---|---|
| **0 — live-verified** | tenderned, nextcloud_marketplace, dpg_registry, latvia_iub, germany_bund, boamp_france, australia_austender, greece_diavgeia, sweden_avropa (RSS), austria_datagvat (gzip/JSONL) | Formalize as `register.d` fragments; keyless feeds `isEnabled: true` |
| **1 — unblocked by landed capabilities** | ted_eu (multi-page via post-body pagination), slovenia_ocds / romania_seap / croatia OCP-mirror (gzip/JSONL), awesome_selfhosted / openalternative / don_oss_register / wikipedia_comparisons (markdown/HTML), estonia_rhr (XML, pending a watched large-file run) | Land as fragments; enable + live-verify each; estonia stays disabled until the memory-safeguard run |
| **2 — blocked on a NEW capability** | ireland_etenders / canada_canadabuys (CSV), spain_placsp (`.zip`), italy_anac (N+1), germany/france + EU portals multi-page (offset pagination), remaining `*_tenders` (poland, portugal, uk_tenders, slovakia, lithuania, hungary, czech, finland, switzerland, norway…) | Each GAP → its own follow-up change; then land the connector. Ship the draft disabled until then |
| **3 — credential/broker-gated (parked)** | belgium (OAuth2), github_competitors / github_releases (apikey), norway_doffin (broker), g2 / builtwith / wappalyzer / saashub (commercial keys) | Land as fragments, `isEnabled: false`, `<PLACEHOLDER>` / broker `credentialRef`; unparked by their own change when an operator supplies the secret |
| **OUT OF SCOPE — stay Python** | analytics & scoring (`sync_adoption_metrics.py`, relevance/classification, `run_sync.py` orchestration); dead tendrils (croatia native NIAS login-wall, dead legacy APIs); JS-SPA-only (fedramp) until a headless path exists | Not ingestion — stay scheduled Python jobs (earlier routing decision) |

## Why no flow-engine dependency

The coordination boundary is deliberate. or#2067 (nodes), or#2068 (trigger set,
incl. cron-flow-triggers), or#2070 (execution tooling), or#2071 (MCP), hermiq#35
build the OpenRegister flow-engine runtime. **This change touches none of them.**
Data gathering runs on OpenConnector's pre-existing `JobTask`/`JobService` cron,
which predates and is independent of the flow engine. Flows react to the objects
gathering writes via OR events — a one-way, already-wired seam. Building a
cron-*flow*-trigger, a flow node, or any flow-engine internal here would both
duplicate or#2068 and wrongly couple gathering to a runtime it does not need.

## Risks

- **Scraping fragility.** HTML/CSS-selector and markdown-list connectors
  (oc#107) break when a page's structure changes. Mitigate: keep them on the
  same keyed-upsert path (a failed fetch yields zero *new* rows, not corruption),
  surface failures in the run log, and treat these as lower-trust than API feeds.
- **Rate limits.** Keyed APIs (github 5000/hr, commercial catalogues) can 429.
  `CallService` already clamps X-RateLimit headers and `JobService` pushes
  `nextRun` off the reset — but a misconfigured `interval` on a keyed source can
  still burn quota. Mitigate: conservative default `interval`; broker-held keys;
  parked-until-configured.
- **The dead-tendril reality.** Not every Specter script can be native. Some
  sources are login-walled (croatia NIAS), API-dead (dpg old endpoint, digital
  public goods), or JS-SPA-only (fedramp). IMPORT.md records these honestly. This
  change does not pretend otherwise: a connector that cannot yield ships
  **disabled** with a `$comment`, never enabled-but-silently-zero.
- **Large-file memory.** 35 MB+ XML/JSONL bodies are buffered whole
  (estonia_rhr, OCP `full.jsonl.gz`). No streaming yet. Mitigate: those
  connectors ship disabled pending a watched run and/or a streaming follow-up;
  smaller year-slice endpoints (e.g. austria `2010.jsonl.gz`) are the
  live-verification path.
- **Schema drift on the target.** IMPORT.md's live tests repeatedly hit stale
  target schemas (`tender`, `marketplaceApp`) where a mapped field had no column
  to land in. Mitigate: re-import the target register (version-gated) before
  enabling a connector against a schema no prior wave verified live; verify a
  persisted object (not the run's own in-memory response) for
  `objectNameField`-configured properties.
- **Re-import version gate.** A live-fixed connector object silently no-ops on
  re-import unless its `version` is bumped. Mitigate: every committed fix bumps
  the object's `version` (documented in tasks).
