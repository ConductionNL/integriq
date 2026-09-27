# federated-api-inventory Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- gateway-federated-api-discovery

## Purpose

The APIs running on other vendors' gateways are listed next to integriq's own, read on a schedule, and can be brought behind integriq from their description. Row `integriq:gw-federated`.

## ADDED Requirements

### Requirement: APIs on other gateways are listed with integriq's own (REQ-FEDG-001)

Integriq MUST keep an inventory of APIs found on other gateways, each with its gateway, vendor, name, version, base URL, environment and the time it was last seen, and MUST show them on one page together with integriq's own API products, filterable by gateway.

#### Scenario: an architect finds every API in one place
- GIVEN integriq's own products and APIs discovered on Kong and Azure API Management
- WHEN the administrator opens the API inventory and filters on Azure
- THEN only the Azure APIs are listed, each with its version and when it was last seen
- e2e: `tests/e2e/api-inventory.spec.ts`

### Requirement: Each vendor is read on a schedule (REQ-FEDG-002)

Integriq MUST ship dormant discovery connectors for Kong, Azure API Management and Amazon API Gateway that read the vendor's API list and each API's OpenAPI export daily, using the source's brokered credential and, for Amazon, AWS Signature Version 4. An API no longer found MUST be marked no longer seen rather than deleted.

#### Scenario: a retired API stays visible as retired
- GIVEN an API discovered on Kong yesterday
- WHEN today's run no longer finds it
- THEN the inventory still lists it, marked as no longer seen since today
- @e2e exclude scheduled synchronization; covered by synchronization tests against recorded fixtures

### Requirement: A discovered API can be brought behind integriq (REQ-FEDG-003)

For a discovered API with an OpenAPI description, integriq MUST offer to start the OpenAPI import with that description, so its operations become integriq endpoints.

#### Scenario: an Azure API moves behind integriq
- GIVEN a discovered Azure API with a stored OpenAPI description
- WHEN the administrator chooses bring behind integriq on its row
- THEN the import preview opens listing that API's operations
- e2e: `tests/e2e/api-inventory.spec.ts`
