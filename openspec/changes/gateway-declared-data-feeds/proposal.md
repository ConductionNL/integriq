---
kind: code
depends_on: []
---

# Proposal: gateway-declared-data-feeds

## Summary

A controller who wants the ledger in Power BI has to give the BI tool a Nextcloud account, because the only machine surface is OpenRegister's object API. ADR-091 says an app must not serve an API with its own credential check; it declares what it needs and integriq serves it. This change makes integriq read a feed declaration from an app, serve each declared dataset as a read-only, paged endpoint that a BI tool calls with its own key, and pin every key to the one administration it may read.

## Why

The owner-moves pass of 2026-09-28 handed this half to integriq from shillinq `reporting-data-delivery`, merged on shillinq `development`. It is `build` by the decision rule, and its row carries tender demand.

shillinq `reporting-data-delivery`, design D2: "`lib/Settings/feeds.json` declares four datasets over shillinq schemas: `ledger-lines` ..., `accounts`, `periods`, `relations`; each with its fields, a required `administrationId` binding and a page size. Integriq reads the declaration and exposes the endpoints with its credential handling; shillinq ships no public controller." Cross-Project Dependencies: "integriq's endpoint configuration serving them (paging, API keys, and OData if integriq offers it) is listed for its owner."

Row in the shillinq matrix: `rep-bi-feed`, "Feed the financial data to an outside data warehouse or BI tool such as Power BI." Tender demand https://www.tenderned.nl/aankondigingen/overzicht/416109, and five competitors rate yes:

- Exact Online: "Power BI Connector: Krijg direct toegang tot al je data" (https://www.exact.com/nl/producten/boekhouden/features-en-prijzen).
- Moneybird: "Met de nieuwe Rapportage API haal je rapporten ... automatisch op" (https://www.moneybird.nl/changelog/nieuwe-rapportage-api/).
- SnelStart: "Power BI Connector voor SnelStart" (https://www.snelstart.nl/koppelingen).
- Twinfield: Twinfield Analysis over the web services (https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/twinfield-analy-3041240).
- Odoo: `addons/rpc/controllers/json2.py:50`.

ADR-091 decision 4: "An app declares the endpoints it needs; OpenConnector owns them. ... The declaration is the contract, and it is reviewable in the app's repo."

## What integriq already has

- Endpoints as OpenRegister objects, served by `EndpointsController::handlePath` on `/api/endpoint/{_path}` (`appinfo/routes.php:430`) through `EndpointService::handleRequest()`.
- Consumers with API key authentication (`openspec/specs/consumer-management`, REQ-CON-002), source allowlists (REQ-CON-SCOPE-001) and rate limits (REQ-CON-RL-001, REQ-CON-RL-002).
- API products that bundle endpoints for subscription (`openspec/specs/api-product-gateway`, REQ-APG-001, REQ-APG-003).
- Reading a declaration file from every enabled app: `ConnectionRegistryService` reads `lib/Settings/connections.json` (`DECLARATION_FILE`, `lib/Service/ConnectionRegistryService.php:46`; `readDeclaration()`, :311).
- The open change `objecten-api-facade` specifies an objecttype declaration reader (its Task 6, unticked). It is specific to the Objecten API.

## What this change builds

1. A reader for `lib/Settings/feeds.json` in every enabled app, validated against a published JSON Schema, run on install, upgrade and on request.
2. One read-only endpoint per declared dataset over the app's register and schema, with the declared fields only, paging, and an OData-style JSON answer a BI tool can page through.
3. A per-consumer binding of the declared scope parameter (for shillinq `administrationId`), enforced on every request.
4. One API product per declaring app, so an administrator issues a key per BI workspace.

## Out of scope

- Which datasets exist and their fields (shillinq `reporting-data-delivery`).
- Writing through a feed. Feeds are read-only.
- The full OData query language. Paging, field selection and an equality filter on declared fields are served; anything else answers 400.

## Impact

- New: `lib/Service/FeedDeclarationService.php`, `lib/Settings/feeds.schema.json`, a repair step, a feed handler inside `EndpointService`, a Feeds page.
- Changed: `lib/Service/EndpointService.php`, `lib/AppInfo/Application.php`, the consumer form (scope binding).

## Cross-project dependencies

- shillinq `reporting-data-delivery` ships `lib/Settings/feeds.json`. humaniq's `td-bi-feed` row (owned by openregister in humaniq's matrix) can use the same declaration later.

## Risks

- A key that reads every administration. The scope binding is required on a consumer before any feed endpoint answers, and an unbound consumer receives 403.
