---
kind: config
depends_on: []
---

# Proposal: connectors-service-desk-templates

## Summary

An application manager who wants stackiq's application landscape in step with TOPdesk, ServiceNow or GLPI has to build a generic REST source in integriq by hand, because integriq offers no service desk template. stackiq's exchange flows are written and point at "integriq's service desk templates once integriq publishes them". This change publishes them: a dormant template per service desk with the right authentication and the application record endpoint, and a mapping preset per desk that stackiq's set-up action can select.

## Why

The owner-moves pass of 2026-09-28 handed this half to integriq from stackiq `sharing-itsm-exchange`, merged on stackiq `development`. It is `build` by the decision rule.

stackiq `sharing-itsm-exchange`, proposal: "The service desk sources and their credentials: integriq's half. Integriq holds the TOPdesk, ServiceNow or GLPI source and its secret (ADR-064), and its connector catalogue gets the source templates. Stackiq names the source by reference only." Its design D2: the `itsm` connection's `sourceTemplate` names "integriq's service desk templates once integriq publishes them"; D3: "three mapping presets (TOPdesk assets, ServiceNow CMDB CIs, GLPI appliances)".

Row in the stackiq matrix: `share-itsm-integration`, "Exchange application data with the organisation's service management tool." Four competitors rate yes:

- SAP LeanIX: "ServiceNow integration ... to synchronize infrastructure and software asset information" (https://www.leanix.net/hubfs/Legal/Metrics-and-Feature-List-EAM-SAP-LeanIX-v3.1.pdf).
- BlueDolphin: "out-of-the-box integrations with ITSM platforms like TOPdesk, ServiceNow, and JIRA" (https://help.bluedolphin.io/en/articles/11967779-add-an-integration-in-bluedolphin).
- TOPdesk and GLPI are themselves service management tools with application records (https://docs.topdesk.com/en/linking-assets-to-cards.html; GLPI 11.0.9 `src/Appliance.php:105`).

## What integriq already has

- Source templates seeded from `lib/Settings/register.d/*-source.json` and listed by `CatalogRegistryService::collectFromSeedFragments()` (`lib/Service/CatalogRegistryService.php:321`).
- The `sourceTemplate` field on a connection declaration (`lib/Settings/connections.schema.json:156`), offered first when an administrator links a source (`src/dialogs/LinkSourceDialog.vue`).
- `openconnector.source-call` and the fetch page node that stackiq's flows use (`lib/Flow/SourceCallNode.php`, `lib/Flow/SourcePaginateNode.php`).
- The `saas-productivity-connectors` spec, which lists ServiceNow among planned vendors and `itsm` among categories, with no template delivered.
- No TOPdesk, ServiceNow or GLPI template: a search over `lib/`, `register.d` and the catalogue finds none. The open change `connectors-catalogue-expansion` does not name them.

## What this change builds

1. Dormant templates `topdesk`, `servicenow` and `glpi`, each with its authentication, application record endpoint and paging, credential by broker reference.
2. Mapping presets `itsm-topdesk-asset`, `itsm-servicenow-ci` and `itsm-glpi-appliance`, both directions, for stackiq's usage fields.
3. Catalogue entries in the category `Service management`.

## Out of scope

- The flows, the set-up action and the fields on stackiq's usage (stackiq).
- Tickets, changes and incidents. Only application records are exchanged.

## Impact

- New: three seed fragments, six mappings in the seed data, one category override. No PHP.

## Cross-project dependencies

- stackiq's `connections.json` entry `itsm` names these template slugs in `sourceTemplate`, and its set-up action offers the three presets.

## Risks

- A tenant has customised its application record type. The presets are a starting point, and the mapping is editable in integriq like any other.
