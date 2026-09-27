---
kind: code
depends_on: []
---

# Proposal: connectors-catalogue-expansion

## Summary

The Store lists about 35 connectors, a few of them twice, two of them
environment placeholders. A tender asks for ready-made connectors to named
municipal back-office systems, and two competitors offer hundreds. This
change gives the Store a template library it lists without installing, adds
a checked set of municipal back-office templates and a generated set of SaaS
templates from a pinned OpenAPI directory, and stops counting placeholders
and duplicates.

## Why

This change covers three rows. One comes from a sibling matrix.

- `integriq:con-backoffice`, "Connect common municipal back-office systems
  such as PinkRoccade iBurgerzaken, Centric GWS and NedGraphics through
  ready-made connectors", rated no and none. Demand: tender
  https://www.tenderned.nl/aankondigingen/overzicht/226100 (Gemeente Stein).
  The matrix note: "Gemeente Stein requirements 166859 and 186343 list
  Alfresco, CIR, GWS, LBA, iBurgerzaken, Civision Samenleving, iObjecten BAG,
  Cipers, NedGeo, NedGlobe, NedOmgeving, Stratech, Simsuite and
  SmartDocuments." No competitor rates yes.
- `integriq:con-library-size`, "Pick from hundreds of ready-made connectors
  for common business software", rated no. Two competitors rate yes:
  - n8n: "packages/nodes-base/package.json registers 443 node files (from
    :449) and 411 credential types across 308 node folders".
  - mulesoft: "https://anypoint.mulesoft.com/exchange/api/v2/assets?types=extension
    returned 296 public Mule 4 connector and module assets", and
    "https://docs.mulesoft.com/llms.txt indexes 149 connector guides".
  The note: "The Store holds roughly 35 entries, not hundreds, and that count
  is padded: two environment placeholder sources (environment-local-source,
  environment-acceptance-source) become connector cards, and SmartDocuments
  and Xential each appear twice."
- `buildiq:int-saas-connectors`, "Use ready-made connectors for common
  services such as Google Sheets, Salesforce or Slack", rated no and none for
  buildiq with integriq as provider. Three competitors rate yes:
  - budibase: "packages/server/src/integrations/index.ts:29-46 registers
    Google Sheets, Airtable, Firestore, DynamoDB, S3, Elasticsearch and more
    as datasources".
  - mendix: "corpus/mendix.tsv #26380 (2026-04-10): pre-built SAP
    integration connectors; #26373 Marketplace connectors".
  - power-apps: "https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/connections-list
    (2026-09-26): connectors for SharePoint, SQL Server, Office 365,
    Salesforce and more".

## What integriq already has

- The Store. `src/manifest.json` page `Store` at `/store`, a card grid over
  `catalog_item` (ADR-080), materialised by `lib/Repair/MaterializeCatalogItems.php`.
- The registry behind it. `lib/Service/CatalogRegistryService.php:147`
  `collect()` joins three lists: OpenRegister's integration registry (:172),
  six static descriptors (:217), and one entry per `source` object in a
  `lib/Settings/register.d/*.json` fragment (:321).
  `findSeedSourcePayload()` (:447) re-reads a fragment when an administrator
  instantiates a template.
- 27 seeded `source` objects in `register.d` at this sha, two of which are
  `environment-local-source` and `environment-acceptance-source`
  (`environments-and-promotion.json`), and two of which, `smartdocuments` and
  `xential`, also appear as static adapters (:265, :279).
- The standards the back-office systems speak: StUF-BG and StUF-ZKN
  (`openspec/specs/stuf-adapter`), iWMO and iJW (`openspec/specs/iwmo-ijw-adapter`),
  Haal Centraal BRP (`brp-haalcentraal-source.json`), PDOK and BAG
  (`lib/Adapters/Pdok`), CMIS for document systems
  (`openspec/specs/document-cms-connectors`).

## What this change builds

1. A template library, `lib/Settings/connector-templates/`, that the Store
   lists and instantiates but the register import never creates as objects.
2. A municipal back-office set: one template per system the Stein tender
   names that has a published interface, each naming the standard it is
   reached over and the document it was checked against. A system without a
   published interface gets a recorded reason, not a guess.
3. A generated SaaS set from a pinned snapshot of the APIs.guru OpenAPI
   directory, filtered to an allow-list, marked as generated on the card.
4. An honest count: placeholders and environment sources leave the Store,
   and a system with both an adapter and a template shows once.

## Out of scope

- New adapter code for any back-office system. A template configures a
  standard integriq already speaks.
- buildiq's half of `buildiq:int-saas-connectors`: its matrix note says the
  path "breaks at rendering anyway" because the endpoint binding in
  `ConnectorSourcePicker.vue` is not wired at runtime. That is buildiq's.
- Live testing of every generated template. A generated template is a
  starting point with base URL and auth scheme, and its card says so.
