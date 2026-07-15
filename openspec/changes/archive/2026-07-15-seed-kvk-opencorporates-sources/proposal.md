---
kind: config
status: proposed
---

## Why

OpenRegister ships company-lookup integration leaves — `KvkProvider` and
`OpenCorporatesProvider` — that route every upstream call **through an
OpenConnector source** (`KvkProvider::SOURCE_ID = 'kvk'`,
`OpenCorporatesProvider::SOURCE_ID = 'opencorporates'`,
`configuredVia: openconnector`). The `ExternalIntegrationRouter` resolves
those sources via the OpenRegister-backed `SourceMapper` adapter
(`OCA\OpenConnector\Db\SourceMapper::find('kvk')` /
`::find('opencorporates')`, register `openconnector`, schema `source`).

**Those sources do not exist.** With zero matching `source` objects seeded,
every OR KvK / OpenCorporates call returns `503
openconnector-source-missing`. The leaves are dead-on-arrival not because
of a code gap but because the connection rows were never seeded.

This blocks centralising pipelinq's bespoke `KvkApiClient` /
`OpenCorporatesApiClient` onto the canonical OR/OpenConnector path (ADR-022):
OR can't resolve base URLs it has no sources for.

## What Changes

- **Seed a pre-built `kvk` source** and a pre-built `opencorporates` source
  as OpenRegister objects (register `openconnector`, schema `source`),
  carrying:
  - `location` — the production REST base URL
    (`https://api.kvk.nl/api/v2` for KvK,
    `https://api.opencorporates.com/v0.4` for OpenCorporates),
  - `auth: "apikey"` — both APIs are API-key authenticated (KvK via an
    `apikey` request header, OpenCorporates via an `api_token` query
    parameter); the placeholder ships **without** a key,
  - a `configuration.headers` `Accept: application/json`,
  - `isEnabled: false` so a fresh install ships the connections **dormant** —
    the OR providers degrade gracefully (`{unavailable, cause}`) until an
    operator sets the API key and enables them.
- Ship them as **ADR-037 register fragments** at
  `lib/Settings/register.d/kvk-source.json` and
  `lib/Settings/register.d/opencorporates-source.json` (each a
  `components.objects` array), so `InitializeRegister` folds them into
  `openconnector_register.json` on `occ app:enable`/upgrade and
  OpenRegister's `ImportHandler` materialises them idempotently by
  `@self.slug`. No edit to the base descriptor, no concurrent-build
  conflicts.

This is **kind: config** — declarative seed fragments. No PHP changes in
OpenConnector: the `SourceMapper` adapter and `CallService` already do all
the work; they were just missing the rows.

## Capabilities

### Modified Capabilities
- `source-management`: gains a requirement that OpenConnector seeds pre-built,
  dormant `kvk` and `opencorporates` sources on install so the OpenRegister
  company-lookup integration leaves resolve base URLs out of the box.

## Impact

- **Config:** `lib/Settings/register.d/kvk-source.json` and
  `lib/Settings/register.d/opencorporates-source.json` (new fragments).
- **Behaviour:** after `occ app:enable openconnector` (or upgrade), `source`
  objects with slugs `kvk` and `opencorporates` exist; OR's company-lookup
  endpoints resolve them and return a degraded `{unavailable,
  cause: 'upstream-service-down'}` (dormant placeholder, no key) rather than
  `openconnector-source-missing`, until an operator sets the API key and
  enables them.
- **Consumers:** OpenRegister `KvkProvider` / `OpenCorporatesProvider`
  (coded in the paired OR change); pipelinq (future) re-points
  `KvkApiClient` / `OpenCorporatesApiClient` at OR's lookup endpoints.
- **Secrets:** none — placeholder URLs + `auth: apikey` without a key.
