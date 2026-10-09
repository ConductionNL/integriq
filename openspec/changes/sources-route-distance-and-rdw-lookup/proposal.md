---
kind: code
depends_on: []
---

# Proposal: sources-route-distance-and-rdw-lookup

## Summary

humaniq asks integriq for two lookups and has no way to ask. An HR officer wants the road distance between an employee's home and work postcodes for the commuting allowance, and the make, first registration date and catalogue value of a company car from its licence plate. integriq has neither source, and a sibling app can only reach integriq's call engine by resolving its classes from the container. This change adds a typed command that calls a connection an app declared and integriq linked, a route distance command that geocodes two postcodes and asks a route planner, and source templates for a route planner and the RDW vehicle register.

## Why

The owner-moves pass of 2026-09-28 handed these halves to integriq from two humaniq changes merged on humaniq `development`. Both are `build` by the decision rule.

- **Route distance.** humaniq `expenses-travel-calculation`, Cross-app dependencies: "integriq: a route-distance source (for example a route planner API) that answers the road distance between two Dutch postcodes, configured by the administrator with its own credentials. humaniq calls it duck-typed through `FleetAppId` and degrades to manual entry." Its design D3: "`RouteDistanceService` asks integriq ... for the road distance between the two postcodes."
- **RDW lookup.** humaniq `people-register-prefill`, Cross-app dependencies: "integriq: a Source for each connection and the call path through `CallService` with the credential held by OpenRegister's credential broker; humaniq never holds the BRP certificate or key." Its design D1 declares the connections `rdw-voertuigen` and `brp-personen`. The BRP source already exists (`2026-07-15-seed-brp-haalcentraal-source`); the RDW source and the call path do not.

Rows in the humaniq matrix:

- `td-commute-allowance`, "Calculate a commuting allowance from the travel distance and apply the tax-free rules." Tender demand: Delft Support E8, "reiskostenberekening via routeplanner of OV-API". AFAS, Visma Raet and HR2day rate yes; Visma "calculates travel distance automatically" (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/2).
- `exp-mileage`, "Log business mileage and have it paid at the tax-free rate." AFAS ("calculates distances with Google Maps", https://help.afas.nl/help/NL/SE/137646.htm), Visma Raet and HR2day rate yes.
- `dm-plate-lookup`, "Fill in a company car's make, first registration date and catalogue value from the vehicle register by entering its licence plate." Visma Raet (release notes 2026-02, "a link with the RDW") and Loket (`GetAdditionalTaxliabilityByLicensePlateNumber`, https://developer.loket.nl/ApiDocs) rate yes; AFAS has it on its roadmap (https://www.afas.nl/roadmap).

## What integriq already has

- The connection registry: every app's `lib/Settings/connections.json` becomes an `app_connection` row whose `source` an administrator links (`lib/Service/ConnectionRegistryService.php`, change `connection-registry`, 36 of 36 tasks).
- `CallService::call()` (`lib/Service/CallService.php:2957`) and `BrokeredCallService` for brokered credentials.
- PDOK Locatieserver geocoding (`lib/Adapters/Pdok/PdokGeocodingClient.php`, routes `/api/pdok/*` at `appinfo/routes.php:516-519`, spec `pdok-adapter`), which turns a postcode into a point.
- The BRP Haal Centraal source template (`lib/Settings/register.d/brp-haalcentraal-source.json`).
- Typed ADR-041 commands with a result slot (`lib/Event/DigitalPostSendRequestedEvent.php`).

## What this change builds

1. `ConnectionCallRequestedEvent`: an app names one of its declared connections and a request; integriq calls the linked source with its credential and returns the status and body.
2. `RouteDistanceRequestedEvent`: two postcodes and a transport mode in, one-way road distance in kilometres and the provider out.
3. Source templates `rdw-open-data` (no credential) and `route-planner-openrouteservice` and `route-planner-tomtom` (API key by broker reference).

## Out of scope

- Mapping RDW or BRP fields onto humaniq's schemas, and the allowance rules (humaniq).
- Public transport fares (humaniq's change excludes them too).
- Moving the BRP client certificate into the credential broker. `BrokeredCallService` refuses a client certificate next to a `credentialRef` (:752-755, "v1 scope"); that is the broker's to lift.

## Impact

- New: two events and their listeners, `lib/Service/RouteDistanceService.php`, three seed fragments.
- Changed: `lib/AppInfo/Application.php`, `lib/Service/CatalogRegistryService.php` (categories).

## Cross-project dependencies

- humaniq `expenses-travel-calculation` and `people-register-prefill` dispatch these events instead of resolving integriq classes. Their `connections.json` keys (`rdw-voertuigen`, `brp-personen`, and a route planner key) are what the call command resolves.

## Risks

- A connection that is declared but not linked. The command answers `not-linked` with the connection's title, which humaniq shows as its 409 message.
