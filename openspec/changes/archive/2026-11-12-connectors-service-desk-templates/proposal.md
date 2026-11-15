---
kind: config
depends_on: []
---

# Proposal: connectors-service-desk-templates

## Summary

stackiq becomes the organisation's CMDB at application level (applications, their components and relations, licences and contracts), and Rotterdam keeps that landscape in step with TOPdesk or ServiceNow both ways. Integriq holds the outside half: a dormant source per service desk with its authentication held by the credential broker, mapping presets in both directions that say per field which side owns it, synchronizations stackiq's flows key on, and two small mock servers that replay each desk's API so the exchange can be tested without a tenant.

## Why

The owner-moves pass of 2026-09-28 handed this half to integriq from stackiq `sharing-itsm-exchange`. On 2026-10-01 Ruben widened it for the Rotterdam programme:

- systems: TOPdesk and ServiceNow, plus GLPI as first specified;
- two-way mapping presets for applications, relations between them, and licences and contracts;
- field ownership: every mapped field names its owner, `source` or `stackiq`; the service desk wins for the fields it owns, and a stackiq-only field is never overwritten;
- built on OpenRegister's mapping value object and the flow nodes, not on a PHP client per vendor.

stackiq's widened `sharing-itsm-exchange` (design D2 to D6) consumes every piece below by slug.

Row in the stackiq matrix: `share-itsm-integration`. SAP LeanIX, BlueDolphin, TOPdesk and GLPI rate it yes (sources in the first version of this proposal).

## What integriq already has

- Source templates seeded from `lib/Settings/register.d/*.json`, listed by `CatalogRegistryService`.
- Mappings run by `MappingService`, which executes OpenRegister's `Mapping` value object (`OCA\OpenRegister\Db\Mapping`), and the flow nodes `openconnector.apply-mapping`, `openconnector.source-paginate`, `openconnector.source-call`, `openconnector.contract` and `openconnector.contract-commit`.
- Offset paging (`paginationMode: offset`) and `Link` header paging in the synchronization engine.
- App-side credential injection from the broker (`{"credentialRef": ...}` placeholders under `configuration.authentication`, ADR-064), for hosts that differ per tenant and cannot be host-locked in OpenRegister's provider catalogue.
- No TOPdesk, ServiceNow or GLPI template, no way for a mapping to say who owns a field, and no way for `source-call` to send a mapped object as the whole body.

## What this change builds

1. Dormant sources `topdesk` (Assets API), `servicenow` (Table API) and `glpi`, each with its secret as a broker reference, in the catalogue category `Service management`.
2. A property `ownership` on the mapping schema, and 13 presets that use it: inbound and outbound application presets per desk, inbound relation, licence and contract presets for TOPdesk and ServiceNow, and a spreadsheet preset for stackiq's file import.
3. Ownership enforcement in `openconnector.apply-mapping`: config keys `ownership` (`inbound` or `outbound`) and `exists`. A create keeps every field, an update keeps only the fields the sending side owns.
4. `bodyFrom` on `openconnector.source-call`: send the object at an item path as the JSON body.
5. Dormant synchronizations, one per feed, that stackiq's flows page with and key their contracts on.
6. Mock servers for TOPdesk and ServiceNow under `tests/mocks/`, replaying documented shapes, paging and authentication failures.

## Out of scope

- The flows, the set-up action, the file import and the fields on stackiq's records (stackiq `sharing-itsm-exchange`).
- Tickets, changes, incidents and infrastructure configuration items.
- A run against a real ServiceNow developer instance: Ruben provides one later, listed as an open task.

## Impact

- New: one register fragment (`service-desk-connectors.json`), `lib/Flow/MappingOwnership.php`, fixtures and mocks.
- Changed: `ApplyMappingNode` (two config keys), `SourceCallNode` and `SourceCallConfigGuard` (`bodyFrom`), one category override.
- Without the new config keys every existing flow behaves as before.

## Cross-project dependencies

- stackiq `sharing-itsm-exchange` names the source slugs, preset slugs and synchronization slugs, and passes the tenant address and TOPdesk template id to the presets as `_desk`.

## Risks

- A tenant has its own field ids (TOPdesk) or columns (ServiceNow `u_` columns for the stackiq-owned fields). The presets are a starting point and are edited in integriq like any other mapping.
- The shapes come from TOPdesk's published Assets API specification 1.91.3 and ServiceNow's Table API documentation; they are replayed by mocks, not yet confirmed against a live tenant.
