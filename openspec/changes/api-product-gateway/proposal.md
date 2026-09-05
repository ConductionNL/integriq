---
kind: spec-only
depends_on: []
---

# Proposal: api-product-gateway (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. The API
product gateway was implemented and archived on 2026-07-15
(`archive/2026-07-15-api-product-gateway`, 21/33 tasks checked with
per-task evidence), yet this live copy was resurrected at 0/33: the
openconnector→integriq rename applied to the prose, the evidence notes
stripped, every box reset. The machinery exists at HEAD:
`lib/Controller/ProductSubscriptionsController.php`, the api_product
routes in `appinfo/routes.php`, the schema fragment in
`lib/Settings/register.d/api-product-gateway.json`, gateway enforcement in
`lib/Service/EndpointService.php`, product metrics in
`lib/Observability/IntegriqMetricsProvider.php` (the fleet rename moved it
from `OpenConnectorMetricsProvider.php` — a move, not a gap), and the UI
(manifest pages `ApiProducts` / `ApiProductDetail`,
`src/views/ApiProducts/ApiProductDetail.vue`).

No live `@spec` tags point into this directory (`appinfo/routes.php`
mentions it in a prose comment only, which this retirement keeps valid by
leaving the directory in place).

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| `api_product` schema + tiers, subscription lifecycle (subscribe/approve/reject), over-tier enforcement + deprecation headers in the endpoint runtime, analytics, product metrics, API Products pages + Consumer subscription widget, seed data | **Already shipped and archived**: `archive/2026-07-15-api-product-gateway` (21/33 boxes checked), code at HEAD |
| Residual verification: live schema-import and seed run, Playwright for the API Products pages and Consumer widget, Newman for subscribe/approve/reject/analytics + 429 + deprecation headers, feature docs, screenshot, `nl_NL` catalog entries | Open, and honestly unticked in the archived twin (no live instance in that session; each open box carries its reason). Same shape as `approvals-verification-pack`; pick up in a verification pass, not by resurrecting this change |

## Sequencing

Nothing remains to implement from this change directly. The residual
live-instance verification, docs and l10n belong to a
verification-pack-style follow-up.

## Archival

This directory is retired in place (not moved or renamed) to keep the diff
reviewable and the prose pointer in `appinfo/routes.php` valid; archive it
via the normal flow at the next sweep.
