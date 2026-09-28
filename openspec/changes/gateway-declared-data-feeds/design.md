# Design: gateway-declared-data-feeds

Kind: code. Size M. Read at integriq development `966d6458` on 2026-09-28.

## Context

- **Declarations.** `ConnectionRegistryService` (`lib/Service/ConnectionRegistryService.php`) reads `lib/Settings/connections.json` from every enabled app (`readDeclaration()`, :311), validates it against `lib/Settings/connections.schema.json`, and syncs rows on install, upgrade and on request (`sync()`, :93; `syncChangedDeclarations()`, :121). That is the pattern for a second declaration file.
- **Serving.** `/api/endpoint/{_path}` (`appinfo/routes.php:430`) reaches `EndpointService::handleRequest(ObjectEntity $endpoint, IRequest, string $path)`. Endpoints are `endpoint` objects; ADR-091 notes the machinery "proposes almost no new code".
- **Callers.** A `consumer` authenticates with an API key (consumer-management REQ-CON-002), is checked against its source allowlist (REQ-CON-SCOPE-001) and its rate limit (REQ-CON-RL-002). An API product bundles endpoints and a consumer subscribes to it (api-product-gateway REQ-APG-001, REQ-APG-003).
- **The first declarer.** shillinq `reporting-data-delivery` D2: datasets `ledger-lines`, `accounts`, `periods`, `relations`, each with fields, a required `administrationId` binding and a page size.

## D1. The declaration file

`lib/Settings/feeds.json`: `{ "feeds": [ { "key", "title", "register", "schema", "fields": [..], "filter": {..}, "scope": { "parameter": "administrationId", "field": "administrationId" }, "pageSize" } ] }`. `lib/Settings/feeds.schema.json` publishes the shape. `FeedDeclarationService` reads, validates and syncs it like connections, and refuses a feed whose schema does not declare every listed field, naming the missing ones.

## D2. One endpoint per dataset, read-only

Each feed becomes an `endpoint` object at `/api/endpoint/feeds/<app>/<key>`, method GET only, marked as generated from the declaration so an administrator cannot edit it out of step. The handler reads the app's register and schema through OpenRegister with the declaration's fixed filter plus the scope, returns only the declared fields, and pages with `$top` (capped at the page size) and `$skip`. The answer is `{ "value": [...], "@odata.nextLink": "..." }`, which Power BI's OData and web connectors both page through. `$select` narrows to declared fields; `$filter` accepts `eq` on declared fields; anything else answers 400 naming what is not supported.

Alternative considered: return OpenRegister's own paging envelope. Rejected: BI tools page by following a next link, and a declared field list is the privacy boundary the app reviewed.

## D3. The scope is bound per consumer

A consumer gets `feedScopes` per app: the value of the declared scope parameter it may read (for shillinq one or more administration ids). The handler applies it as a filter on every request; a request whose `administrationId` differs answers 403, and a consumer with no scope for that app answers 403 on every feed. This check runs after authentication and the source allowlist, before the rate limit, next to REQ-CON-SCOPE-001.

## D4. One product per app

The sync keeps one API product per declaring app (`<app> data feeds`) holding its feed endpoints, so the subscription, approval and per-tier limits of api-product-gateway apply unchanged. A Feeds page lists the declared feeds per app with their endpoint URL, fields and the consumers subscribed.

## Declarative versus imperative

| Behaviour | Path | Rationale |
|---|---|---|
| What a feed exposes | Declarative, the app's `feeds.json` | ADR-091 decision 4. |
| Serving and paging | Imperative handler inside `EndpointService` | Request handling. |
| Who may read which scope | Configuration on the consumer | Credential handling is integriq's (ADR-091 decision 1). |

## Seed data

A consumer "Power BI Gemeente Voorbeeld" bound to the administration of Gemeente Voorbeeld, subscribed to `shillinq data feeds`, with its key by broker reference.

## Risks

- [An app renames a field] the sync refuses the feed with the missing field named, and the old endpoint keeps answering until the declaration is fixed.
- [A large ledger] the page size cap and `$skip` keep each answer bounded; a BI refresh pages.
