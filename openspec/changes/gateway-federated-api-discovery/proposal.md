---
kind: code
depends_on: [gateway-openapi-import-and-publish]
---

# Proposal: gateway-federated-api-discovery

## Summary

A municipality rarely runs one gateway. Some APIs sit behind Kong, some behind Azure API Management, some behind Amazon API Gateway, and nobody has one list of them. This change lets integriq read the API inventory of those gateways on a schedule and show it next to integriq's own API products, with each API's OpenAPI description where the vendor exposes it.

## Why

Row `integriq:gw-federated` (rated no, built none), gateway area (the core area), decided `build` in the OpenSpec pass of 2026-09-27: two competitors rate yes.

- Changelog https://apim.docs.wso2.com/en/4.6.0/get-started/about-this-release/: WSO2 API Manager 4.6.0 (2025-11-04) added API discovery for federated gateways.
- MuleSoft https://docs.mulesoft.com/exchange/api-scanners.md "API scanners connect external API gateways to Anypoint Platform, enabling you to discover, import" and sync APIs from Amazon API Gateway, Azure API Management and others.
- WSO2 v4.7.0 `carbon-apimgt/components/apimgt/org.wso2.carbon.apimgt.federated.gateway/.../FederatedAPIDiscovery`.

The matrix evidence for integriq: a search for kong, apigee, AWS API Gateway and Azure API Management across `lib/` and `src/` finds nothing; the gateway serves its own endpoints only (`lib/Service/EndpointService.php:1876-2266`).

## What integriq already has

- Connector fragments in `lib/Settings/register.d/` ship a source, a mapping, a synchronization and a job together (for example `tenderned-connector.json`), and the synchronization engine pages, maps and upserts.
- AWS Signature Version 4 signing exists in `lib/Service/Adapter/DataInfra/S3Adapter.php`.
- Source credentials go through OpenRegister's credential broker as `credentialRef` (`migrate-inline-secrets-to-broker`).
- `gateway-openapi-import-and-publish` imports a vendor's OpenAPI document into a source and endpoints.

## What this change builds

1. An `external_api` schema: gateway, vendor, name, version, base URL, stage or environment, the gateway's own id, the OpenAPI description when available, and last seen.
2. Three connector fragments, each dormant until an administrator adds a credential: Kong Admin API (services and routes), Azure API Management (APIs and their exported OpenAPI), Amazon API Gateway (REST and HTTP APIs, with an OpenAPI export per stage).
3. An API inventory page listing integriq's own products and the discovered APIs together, filterable by gateway, with a link to the source gateway.
4. "Bring behind integriq": for a discovered API with an OpenAPI description, start the import of `gateway-openapi-import-and-publish` from it.

## Out of scope

- Managing policies on the other vendors' gateways. The row says "discover and manage"; this change covers discovery and hand-over. Pushing rate limits or keys into Kong or Azure is left for a later change once someone asks for it.
- Apigee, Tyk and WSO2 as sources. The adapter pattern makes them one fragment each when needed.
