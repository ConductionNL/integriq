# Tasks — native data-gathering provider (Specter ingestion migration)

> Proposal only. No OpenConnector engine code changes: the fetch → map → upsert
> → cron path already exists on `development` and is live-verified for ten
> connectors. This change lands configuration (`register.d` fragments) plus the
> positioning / boundary / parity contract. Each capability GAP is a separate
> follow-up change (the oc#97 / oc#94 / oc#107 pattern).

## Contract and boundary (spec)

- [ ] Confirm the gather contract shape against the shipped `tenderned.json`
  bundle (Source + Mapping×2 + Synchronization + Job; `targetType:
  "register/schema"`, `targetId: "spectr/tender"`) and against
  `SynchronizationService` / `JobService` / `Cron/JobTask` at HEAD
- [ ] Confirm the OpenConnector-vs-leaf split against OpenRegister
  `integration-leaf-foundation` and `object-source-providers` (transport-only
  source = no Synchronization + no Job; leaf = per-object live read)
- [ ] Confirm the flow-reaction seam: OpenRegister emits object-created/updated
  events on write; or#2068 trigger set consumes them; no gather-side flow-engine
  dependency

## Wave 0 — formalize the ten live-verified connectors as register.d fragments

- [ ] Land `tenderned` connector fragment (`isEnabled: true`); verify
  self-provision on `occ app:enable`
- [ ] Land `nextcloud_marketplace` connector fragment (`isEnabled: true`)
- [ ] Land `dpg_registry` connector fragment (`isEnabled: true`)
- [ ] Land `latvia_iub` connector fragment (daily `interval`, `isEnabled: true`)
- [ ] Land `germany_bund` connector fragment (`isEnabled: true`)
- [ ] Land `boamp_france` connector fragment (`isEnabled: true`)
- [ ] Land `australia_austender` connector fragment (`isEnabled: true`)
- [ ] Land `greece_diavgeia` connector fragment (`isEnabled: true`)
- [ ] Land `sweden_avropa` connector fragment (RSS; `isEnabled: true`)
- [ ] Land `austria_datagvat` connector fragment (gzip/JSONL; `isEnabled: true`)
- [ ] For each: re-verify a real `/run` after import (found/created then
  updated/created:0), confirming the target schema is current (re-import the
  target register version-gated if drift is found)

## Wave 1 — enable drafts unblocked by now-landed capabilities

- [ ] `ted_eu`: enable multi-page fetch via the landed POST-body pagination
  (oc#94); live-verify past page 1
- [ ] `slovenia_ocds`, `romania_seap`, croatia OCP-mirror: enable via the landed
  gzip/JSONL path (oc#97); live-verify against a small year-slice file first
- [ ] `awesome_selfhosted` (markdown), `openalternative` / `don_oss_register` /
  `wikipedia_comparisons` (HTML/CSS-selector): enable via the landed markdown/HTML
  fetchers (oc#107); live-verify
- [ ] `estonia_rhr` (35.9 MB/mo XML): keep `isEnabled: false`; run one watched
  large-file `/run` before enabling; note the memory risk in its `$comment`

## Wave 2 — one follow-up change per capability GAP, then the connector

- [ ] Open follow-up: offset/cursor pagination beyond page 1 (`start`/`rows`,
  `limit`/`offset`) + a `sourceConfig.pageOffset` knob → unblocks
  germany/france multi-page and many EU portals
- [ ] Open follow-up: CSV / delimited parse → unblocks `ireland_etenders`,
  `canada_canadabuys`
- [ ] Open follow-up: `.zip` multi-member archive iterate → unblocks
  `spain_placsp`
- [ ] Open follow-up: N+1 two-hop (id-list then per-id detail fetch) → unblocks
  `italy_anac`, `saashub`, `sourceforge`
- [ ] Open follow-up: wildcard / recursive `resultsPosition` → unblocks
  `dutch_laws_bwb` lawArticle
- [ ] Open follow-up: incremental / since-watermark fetch cursor → high-volume
  daily feeds
- [ ] Open follow-up (deferred, likely ETL): large-file streaming
  decompress/parse; JS-rendered SPA headless render (`fedramp`)
- [ ] Ship each Wave-2 connector draft `isEnabled: false` with a `$comment`
  naming its gap and follow-up change until that change lands

## Wave 3 — credential/broker-gated connectors (parked)

- [ ] Land `belgium` (OAuth2), `github_competitors` / `github_releases`
  (apikey), `norway_doffin` (broker `credentialRef`), `g2` / `builtwith` /
  `wappalyzer` / `saashub` (commercial keys) as fragments, `isEnabled: false`,
  secrets as `<PLACEHOLDER>` or broker reference; unpark each via its own change

## Out of scope (stay Python)

- [ ] Document that the analytics/scoring scripts (`sync_adoption_metrics.py`,
  relevance/classification, `run_sync.py` orchestration) are NOT ingestion and
  remain scheduled Python jobs (earlier routing decision) — no OpenConnector
  connector for these
- [ ] Document the dead tendrils (croatia native NIAS login-wall; dead legacy
  APIs) as not-addressable, per `spectr/connectors/IMPORT.md`

## Validation

- [ ] `openspec validate native-data-gathering-provider --strict` clean
- [ ] Cross-check no overlap with or#2067 / or#2068 / or#2070 / or#2071:
  this change adds no flow node, trigger, or runtime
