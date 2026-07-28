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

- [x] Land `tenderned` connector fragment (`isEnabled: true`); verify
  self-provision on `occ app:enable` — landed at
  `lib/Settings/register.d/tenderned-connector.json`; already present as live
  OpenConnector objects on the shared dev instance (localhost:8080) prior to
  this change (created during authoring/live-verification), confirmed intact
  (Source+Mapping×2+Synchronization+Job, Job `isEnabled:true`,
  `interval:604800`) and re-verified live 2026-07-25: `tender` register/schema
  (spectr id 2444/4369, v1.2.0, matches this repo's schema exactly — no
  drift) went from 0 → 110 real objects on a fresh `/run`
  (`tenderned-to-spectr-tender`), field-mapping spot-checked
  (sourceRef/displayName/title/organisation/procedure/typeOpdracht/isEuropean/
  tenderUrl all correctly populated on a real notice)
- [x] Land `nextcloud_marketplace` connector fragment (`isEnabled: true`) —
  landed at `lib/Settings/register.d/nextcloud-marketplace-connector.json`;
  already present + intact (same as above); `marketplaceApp` schema (id 4386,
  v1.0.0, matches repo exactly) re-verified live: contributed real NC App
  Store rows into the 0 → 136 combined `marketplaceApp` delta on a fresh
  `/run` (`nextcloud-appstore-to-spectr-marketplaceapp`)
- [x] Land `dpg_registry` connector fragment (`isEnabled: true`) — landed at
  `lib/Settings/register.d/dpg-registry-connector.json`; imported fresh this
  change (was not yet present on the shared instance); re-verified live: `/run`
  (`dpg-registry-to-spectr-marketplaceapp`) contributed real DPG rows into the
  same 0 → 136 `marketplaceApp` delta, confirmed via a direct fetch of the
  `aam-digital` record (matches IMPORT.md's own historical spot-check exactly)
- [x] Land `latvia_iub` connector fragment (daily `interval`, `isEnabled:
  true`) — landed at `lib/Settings/register.d/latvia-iub-connector.json`;
  already present + intact on the shared instance (Job `interval:86400`,
  `isEnabled:true`); not re-run live this change (scope: 3+ live-verified,
  chose tenderned/nc_marketplace/dpg_registry) — object+job configuration
  confirmed idempotent (no duplicates) via a repeat `configurations/import`
- [x] Land `germany_bund` connector fragment (`isEnabled: true`) — landed at
  `lib/Settings/register.d/germany-bund-connector.json`; already present +
  intact, not re-run live this change (same scope note as latvia_iub)
- [x] Land `boamp_france` connector fragment (`isEnabled: true`) — landed at
  `lib/Settings/register.d/boamp-france-connector.json`; already present +
  intact, not re-run live this change (same scope note as latvia_iub)
- [x] Land `australia_austender` connector fragment (`isEnabled: true`) —
  landed at `lib/Settings/register.d/australia-austender-connector.json`;
  imported fresh this change (was not yet present), confirmed one Source +
  Mapping + Synchronization + Job created (no duplicates on repeat import),
  not run live this change
- [x] Land `greece_diavgeia` connector fragment (`isEnabled: true`) — landed
  at `lib/Settings/register.d/greece-diavgeia-connector.json`; imported fresh
  this change, confirmed one set created (no duplicates), not run live this
  change
- [x] Land `sweden_avropa` connector fragment (RSS; `isEnabled: true`) —
  landed at `lib/Settings/register.d/sweden-avropa-connector.json`; imported
  fresh this change, confirmed one set created (no duplicates), not run live
  this change
- [x] Land `austria_datagvat` connector fragment (gzip/JSONL; `isEnabled:
  true`) — landed at `lib/Settings/register.d/austria-datagvat-connector.json`;
  imported fresh this change, confirmed one set created (no duplicates), not
  run live this change
- [x] For each: re-verify a real `/run` after import (found/created then
  updated/created:0), confirming the target schema is current (re-import the
  target register version-gated if drift is found) — done for the 3 chosen
  (tenderned/nextcloud_marketplace/dpg_registry) above; both target schemas
  (`tender` v1.2.0, `marketplaceApp` v1.0.0) confirmed current against this
  repo's `spectr/register/spectr_register.json`, no drift found, no re-import
  needed. The remaining 7 are imported+scheduled (Job enabled with a real
  interval) but not individually re-run live in this change — flagged as a
  follow-up live-verify for whoever next touches this instance

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

- [x] `openspec validate native-data-gathering-provider --strict` clean
  (re-confirmed on the Wave-0 landing branch)
- [ ] Cross-check no overlap with or#2067 / or#2068 / or#2070 / or#2071:
  this change adds no flow node, trigger, or runtime
