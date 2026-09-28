# Design: connectors-service-desk-templates

Kind: config. Size S. Read at integriq development `966d6458` on 2026-09-28.

## Context

- **stackiq's flows.** stackiq `sharing-itsm-exchange` D3: an outbound flow on `usage` create and update maps application name, supplier, version, lifecycle status, business owner, technical owner and BBN level, calls the source, and writes a returned `recordId` into `externalReferences`; a nightly inbound flow pages over the desk's application records and matches on `recordId`, else name and supplier.
- **Templates.** A seed fragment like `lib/Settings/register.d/kvk-source.json` becomes a catalogue source template; `SLUG_CATEGORY_OVERRIDES` (`CatalogRegistryService.php:88`) sets its category.
- **Linking.** `connections.schema.json:156-159` `sourceTemplate`: "Slug of an integriq source template, offered first when an admin links a source".

## D1. Three templates

| Slug | Records | Authentication | Paging |
|---|---|---|---|
| `topdesk` | `/tas/api/assetmgmt/assets` filtered on the application template | operator login and application password (basic) by `credentialRef` | `pageStart`, `pageSize` |
| `servicenow` | `/api/now/table/cmdb_ci_appl` | basic or OAuth client by `credentialRef` | `sysparm_offset`, `sysparm_limit` |
| `glpi` | `/apirest.php/Appliance` | app token and user token by `credentialRef`, session opened per run | `range` |

Each is `isEnabled: false` with the tenant URL left empty.

## D2. Presets both ways

Per desk an outbound preset (usage to record) and an inbound preset (record to `{system, recordId, url, name, supplier}`), named `itsm-<desk>-<asset|ci|appliance>` and `...-inbound`. `system` is the template slug, and `url` is the record's page in the desk, built from the tenant URL and the record id.

## D3. Catalogue

The three slugs map to `Service management`.

## Declarative versus imperative

Seed configuration only.

## Seed data

The three templates and six presets; a recorded answer per desk as test fixtures.

## Risks

- [GLPI 11 moves to its new API] the template carries the API path, and a second template can follow without touching the presets' field names.
