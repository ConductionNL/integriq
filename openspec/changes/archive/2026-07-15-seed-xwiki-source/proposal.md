---
kind: config
status: proposed
---

## Why

OpenRegister ships a complete xWiki integration leaf — `XwikiProvider`,
`ExternalIntegrationRouter`, `XwikiLinkService`, `XwikiLinksController` — that
routes every xWiki call **through an OpenConnector source named `xwiki`**
(`XwikiProvider::SOURCE_ID = 'xwiki'`, `configuredVia: openconnector`). The
router resolves that source via the OpenRegister-backed `SourceMapper`
adapter (`OCA\OpenConnector\Db\SourceMapper::find('xwiki')`, register
`openconnector`, schema `source`).

**That source does not exist.** With zero `source` objects seeded, every OR
xWiki call — including the free-text page search pipelinq needs — returns
`503 openconnector-source-missing`. The integration is dead-on-arrival not
because of a code gap but because the connection row was never seeded.

This blocks moving pipelinq's bespoke `xwiki_direct_url` + XML-proxy
(`OCA\Pipelinq\Service\XWikiService`) onto the canonical OR/OpenConnector
path: OR can't resolve a base URL it has no source for.

## What Changes

- **Seed a pre-built `xwiki` source** as an OpenRegister object
  (register `openconnector`, schema `source`, `@self.slug = "xwiki"`) carrying:
  - `location` — the xWiki REST base URL (placeholder `http://xwiki:8080/xwiki`,
    matching the dev-stack default; operators repoint it),
  - `auth: "none"` by default (xWiki public read), with a `configuration.headers`
    `Accept: application/json` so the REST API returns JSON not XML,
  - `isEnabled: false` so a fresh install ships the connection **dormant** —
    the OR provider degrades gracefully (empty + log) until an operator sets the
    real URL/credentials and enables it.
- Ship it as an **ADR-037 register fragment** at
  `lib/Settings/register.d/xwiki-source.json` (a `components.objects` array),
  so `InitializeRegister` folds it into `openconnector_register.json` on
  `occ app:enable`/upgrade and OpenRegister's `ImportHandler` materialises it
  idempotently by `@self.slug`. No edit to the base descriptor, no concurrent-build
  conflicts.

This is **kind: config** — a declarative seed fragment. No PHP changes in
OpenConnector: the `SourceMapper` adapter, `CallService`, and the OR router
already do all the work; they were just missing the row.

## Capabilities

### Modified Capabilities
- `source-management`: gains a requirement that OpenConnector seeds a pre-built,
  dormant `xwiki` source on install so the OpenRegister xWiki integration leaf
  resolves a base URL out of the box.

## Impact

- **Config:** `lib/Settings/register.d/xwiki-source.json` (new fragment).
- **Behaviour:** after `occ app:enable openconnector` (or upgrade), a `source`
  object with slug `xwiki` exists; OR's `XwikiLinkService` resolves it and
  returns empty results (source dormant) rather than `source-missing`, until an
  operator points it at a live xWiki and enables it.
- **Consumers:** OpenRegister `XwikiProvider`/`XwikiLinkService` (already coded);
  pipelinq (future) re-points `XWikiService` at OR's search endpoint.
- **Secrets:** none — `auth: none`, placeholder URL only.
