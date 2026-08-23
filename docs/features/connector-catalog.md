# Connector Catalog

The Catalog page (`/catalog` in the Integriq app) is a browsable card grid of every integration capability the app ships — connector adapters, seeded source templates, and importable configuration templates. It is the onboarding surface for operators: instead of reading code or calling the API, you can discover what exists, see whether it is active, and enable or instantiate it in one click.

## What the catalog lists

Every card corresponds to real, already-shipped code — no entry is invented:

| Kind | Examples | Where it comes from |
|---|---|---|
| Adapter | Azure Virtual Desktop, SharePoint Online, Microsoft 365, S3, PDOK, Digikoppeling, Berichtenbox, DSO | OpenRegister's `IntegrationRegistry` (registered category adapters) plus a static descriptor list for the built-in adapters not yet registered there |
| Source template | BRP HaalCentraal, KvK Handelsregister, xWiki, OpenCorporates, SMS/WhatsApp channels | The `lib/Settings/register.d/*-source.json` seed fragments |
| Configuration template | (importable configuration documents) | Exported configuration files (see below) |

Catalog entries are materialised as `catalog_item` OpenRegister objects by the `MaterializeCatalogItems` repair step, which runs on every `occ upgrade` / app enable and upserts by a stable `kind:slug` key — re-runs never duplicate. A newly registered `IntegrationRegistry` provider appears automatically on the next run, with no frontend change.

## Status badges and the two dormancy mechanisms

Each card carries a live status badge:

- **available** — the item is usable right now. Note that seeded sources in *mock mode* count as available: they are reachable and return realistic canned data until you configure real credentials.
- **dormant** — the item is present but inactive.

Two distinct mechanisms control dormancy, and the detail dialog dispatches accordingly:

- **flag-gated** (e.g. PDOK behind `pdok.feature_flag`, Berichtenbox behind `logius.berichtenbox.feature_flag`): the "Enable" action flips the app-config flag.
- **mock-seeded** (e.g. the xWiki source seeded with `isEnabled: false`): the "Instantiate" action creates or enables the Source object from its seed template.

The detail dialog re-checks the live status when it opens (`GET /api/catalog/items/{id}/status`), so a stale card never offers an action that has already been taken.

## Authorization

Enable/Instantiate is gated twice, per ADR-023:

1. **Action layer** — the `catalog.instantiate` action in the admin-configurable action matrix (Settings → Integriq → Action authorization), seeded admin-only.
2. **Data layer** — the underlying Source write still passes through OpenRegister's admin-only lock on the `source` schema, independent of the action matrix.

## Configuration export and import

The Catalog page header hosts the configuration import/export actions:

- **Export configuration** — pick a configuration group and download it as a slug-referenced JSON (OAS) document. Credentials (`apikey`, `secret`, `username`, `password`, JWT and authorization headers) are always stripped from the export.
- **Import configuration** — upload an exported document. The import is previewed first: you see what will be **created**, what will be **updated** (matched by slug), any **slug collisions**, and any **unresolved references** (slugs that do not exist in this environment — these block confirmation until you explicitly acknowledge them, because the referencing entity would import broken). Nothing is written until you confirm.

After a confirmed import, the summary lists every imported source under **credentials need re-entry** — exports always strip credentials, so open each imported source and re-enter them before use.

Both actions are gated by the `configuration.export` / `configuration.import` actions in the same matrix, seeded admin-only.

## API

| Endpoint | Purpose |
|---|---|
| `GET /api/catalog/items/{id}/status` | Live status re-check for one catalog item |
| `POST /api/catalog/items/{id}/instantiate` | Enable (flag-gated) or instantiate (source-backed) an item |
| `POST /api/configurations/{id}/export` | Download the redacted configuration document |
| `POST /api/configurations/import/preview` | Non-mutating import preview |
| `POST /api/configurations/import` | Confirmed import (`confirmed: true` required, HTTP 400 otherwise) |

Catalog listing itself uses OpenRegister's generic object API (`GET /apps/openregister/api/objects/openconnector/catalog_item`) — there is no bespoke list endpoint.
