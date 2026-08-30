---
kind: config
status: proposed
---

# integriq — native data-gathering provider (Specter ingestion migration)

## Why

The fleet needs one sanctioned way to say "get this / crawl that on a schedule
and keep OpenRegister populated with it". Today that job is done by ~46 live
Specter ingestion tendrils — the `sync_*.py` scripts under
`concurrentie-analyse/scripts/sync/` (TenderNed, TED, Belgium, per-country
procurement portals, GitHub competitor tracking, the Nextcloud marketplace,
laws, blogs/RSS, research feeds, software catalogues). Each is the same shape:
HTTP-fetch a JSON/XML/OCDS/RSS/gzip/HTML endpoint, parse it, map fields, and
upsert the rows into Postgres. They run as a separate Python service on GitHub
Actions cron, outside Nextcloud, writing into their own database — so flows,
agents, dashboards and reports on the OpenRegister side never see this data
natively; it has to be re-imported or queried out-of-band.

Integriq already **is** this capability, just not yet positioned as the
fleet's data-gathering provider. It has Sources (fetch an external
HTTP/REST/file endpoint), Mappings (transform the payload), Synchronizations
(fetch → map → keyed-upsert into a target OpenRegister register/schema) and
Jobs with its own cron (`Cron/JobTask.php` + `JobService::scheduleJob`). A
Source fetching a REST endpoint, a Mapping transforming the payload, and a
Synchronization upserting into an OpenRegister register/schema on a Job cron
schedule **works end-to-end today** — verified live for TenderNed, the
Nextcloud App Store, DPG Registry, Latvia IUB, Germany GovData, France BOAMP,
AusTender, Greece Diavgeia, Sweden Avropa (RSS) and Austria OpenTender
(gzip/JSONL) — ten distinct engine paths, each `found/created` on first run and
`updated/created:0` on re-run (keyed-upsert dedup, no duplicates). The prior art
is real and already partly executed: `spectr/connectors/*.json` are
Integriq-style Source + Mapping + Synchronization + Job bundles authored
directly from the Specter scripts, and `spectr/connectors/IMPORT.md` documents
the live-verified imports source by source.

The engine has also grown, over 2026-06/07, exactly the harder-tendril
capabilities that first blocked this: an XML/RSS fallback
(`fetchSinglePageData()` → `simplexml_load_string()` + `xmlToArray()`, since
2026-06-20), gzip decompression + JSONL bulk-file ingestion (oc#97, archived
2026-07-15), POST-body sources + body-based pagination (oc#94, archived),
markdown-list + HTML/CSS-selector extraction (oc#107, archived), and
broker-held credentials (`source-broker-credentials`, archived). What remains
is (a) to **position** Integriq as the native gather layer with a clear
contract, (b) to **formalize** the already-live connectors as shipped app
fragments so a fresh install self-provisions them, (c) to **draw the boundary**
against OpenRegister's per-object leaf data-providers so the two do not overlap,
and (d) to **enumerate the remaining capability gaps** so the last blocked
tendrils migrate deliberately rather than shipping silently-zero-yielding.

Crucially, this gather layer uses **Integriq's own existing cron**
(`JobTask`/`JobService`), so it needs **no** flow-engine dependency. Flows and
agents merely *react* to the OpenRegister objects gathering writes, through
object-created/updated events that OpenRegister already emits. The
flow-engine's Nextcloud-native trigger set (or#2068), its nodes (or#2067), its
execution tooling (or#2070) and MCP surface (or#2071) are out of scope here and
are not touched or depended on.

## What Changes

- **Establish the gather contract** as the sanctioned replacement for a Specter
  sync script: a Source (fetch) + Mapping (transform) + Synchronization
  (keyed-upsert into an OpenRegister register/schema target) + Job (Integriq
  cron schedule). Specify the per-connector config shape and the
  target-register binding (`targetType: "register/schema"`, `targetId:
  "<register>/<schema>"`), matching the shipped, live-verified `tenderned.json`
  bundle exactly.
- **Formalize the ten live-verified connectors** as ADR-037 `register.d`
  fragments under `lib/Settings/register.d/` (the same packaging the BRP / KvK /
  xWiki seed sources already use), so `occ app:enable`/upgrade folds them into
  the OpenConnector register and OpenRegister's `ImportHandler` materialises them
  idempotently by slug — the connectors become part of the app and self-schedule
  on Integriq's cron, instead of living only as importable files in the
  `spectr/` sibling repo.
- **Draw the boundary** against OpenRegister's per-object leaf data-providers
  (`integration-leaf-foundation`, `object-source-providers`): Integriq =
  scheduled BULK ingestion (pull many records on cron, persist + dedup into a
  register/schema); a leaf provider = per-object LIVE data (notes / sub-resources
  / a read-time projection for THIS object). Both write to OpenRegister; the
  shapes and lifecycles differ. Clarify the one legitimate overlap: an
  Integriq `source` may be used **transport-only** by a leaf provider (a
  single live call, no Synchronization, no Job — the BRP/KvK/xWiki seeds), which
  is distinct from a bulk-ingestion source (which carries a Synchronization + a
  Job).
- **Define the flow-reaction seam** without building it: gathered rows land as
  OpenRegister objects, which emit object-created/updated events; downstream
  flows/agents react via or#2068's trigger set. The on-demand direction — a flow
  or agent asking Integriq to fetch now, via the existing
  `POST /synchronizations/{id}/run` — is named as a forward hook and deferred.
- **Enumerate the capability parity gaps** for the last blocked tendrils
  (offset/cursor pagination beyond page 1, incremental since-watermark fetch, CSV
  parsing, `.zip` multi-member archives, N+1 two-hop detail fetch, wildcard
  `resultsPosition`, post-fetch loop/transform, JS-rendered SPA, large-file
  streaming), each with a HAVE/GAP disposition, and require that a connector
  blocked on a not-yet-built capability ships with its Source and Job **disabled**
  and a documented `$comment` rather than silently yielding zero forever.
- **Sequence the migration** in waves: Wave 0 (formalize the ten live-verified
  connectors), Wave 1 (enable the drafts unblocked by now-landed capabilities),
  Wave 2 (each remaining gap = its own follow-up change, then its connector),
  Wave 3 (credential/broker-gated, parked). The analytics/scoring scripts
  (relevance classification, adoption-metric computation, `run_sync.py`
  orchestration) are explicitly OUT OF SCOPE — they are not ingestion and stay
  scheduled Python jobs, per the earlier routing decision.

## Impact

- **No Integriq engine code changes in this change.** The fetch → map →
  upsert → cron path already exists and is live-verified; this change lands
  configuration (`register.d` fragments) plus the positioning/boundary/parity
  contract. Each remaining capability gap is deferred to its own follow-up change
  (the pattern oc#97 / oc#94 / oc#107 already set).
- **New spec:** `native-data-gathering-provider` (this change) — the gather
  contract, the Integriq-vs-leaf boundary, the flow-reaction seam, and the
  parity-gap governance rule.
- **Cross-references (no edits):** OpenRegister `integration-leaf-foundation` and
  `object-source-providers` (the boundary); or#2068 flow-engine trigger set (the
  reaction seam, referenced only). Integriq `synchronization-engine`,
  `source-management`, `job-scheduling`, `logs-and-statistics`,
  `dead-letter-replay` (the HAVE surface this contract stands on).
- **Fresh install:** ships the Wave-0 connectors provisioned; keyless public
  feeds `isEnabled: true`, credential/broker-gated ones `isEnabled: false`
  (dormant) until an operator supplies the secret — the same convention the
  existing seed sources use.
