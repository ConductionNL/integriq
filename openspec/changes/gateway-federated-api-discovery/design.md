# Design: gateway-federated-api-discovery

Kind: code. Size M. Mostly declarative: a schema, three connector fragments and a page. The code is a small OpenAPI fetch step and a clear refusal while the broker cannot sign SigV4.

## Context at development 92f282bc

- Connector fragment shape: `lib/Settings/register.d/tenderned-connector.json` declares, under `components.objects`, a `source`, hash and target `mapping`s, a `synchronization` with `sourceConfig` (`endpoint`, `resultsPosition`, `idPosition`, `maxPages`) and a weekly `job`.
- AWS SigV4 does not exist. `lib/Service/Adapter/DataInfra/S3Adapter.php:38-55`: the broker's `injectAuth()` injects one templated header and cannot sign, and a broker `authScheme: 'aws-sigv4'` is named as the fix.
- Credentials: `BrokeredCallService` resolves a `credentialRef` under `configuration.authentication` on the real call path (`lib/Service/BrokeredCallService.php:98`).
- API products and their pages: `lib/Settings/register.d/api-product-gateway.json`, `src/manifest.json:1934-1979`.

## D1. Discovery is synchronisation

Each vendor is a source plus a synchronization into `external_api`, run daily by a job. No new engine: pagination, mapping, hashing and deletion guards come from the synchronization engine. Records that disappear at the vendor follow the synchronization's disappearance policy from `records-owned-by-an-external-source`, set to `markEnded` so the inventory shows "no longer seen" instead of silently deleting.

## D2. The three vendors

- Kong: `GET /services` and `GET /routes` on the Admin API, paged by `offset`. A service with its routes becomes one `external_api`; Kong has no OpenAPI per service unless a spec is attached in Kong's Dev Portal, so the description is optional.
- Azure API Management: `GET /subscriptions/{sub}/resourceGroups/{rg}/providers/Microsoft.ApiManagement/service/{name}/apis` with an Entra ID client credentials token (the source's OAuth 2.0 client credentials, already supported), and the export `?format=openapi+json` per API.
- Amazon API Gateway: `GET /restapis` and `GET /v2/apis` signed with SigV4, and `GET /restapis/{id}/stages/{stage}/exports/oas30` per stage.

## D3. SigV4 belongs in the broker

The Amazon source declares `configuration.authentication.credentialRef` with `authScheme: aws-sigv4`, region and service. Computing the signature needs the secret key, and ADR-064 keeps the secret inside OpenRegister's broker, so the broker must sign (the host-locked proxy mode, `CredentialBrokerService::request()`). Integriq does not sign: signing in integriq would need `resolveInjectable()` with an `inject_only` provider, which ADR-064 decision 3 keeps for hosts that cannot be proxied, and Amazon's hosts can.

Until the broker offers `aws-sigv4`, a run of the Amazon synchronization stops before any call and logs "the credential broker cannot sign AWS Signature Version 4 yet", and the inventory page shows the Amazon connector as waiting on OpenRegister. The Kong and Azure connectors do not depend on it.

## D4. OpenAPI fetch as a synchronization step

After the list is synchronized, a second synchronization per vendor fetches each API's export into `external_api.openApiDocument`. It runs with the same job, after the list, and skips APIs whose `version` and `updated` have not changed.

## D5. One inventory page

A manifest page `ApiInventory` (`/inventory`) lists `api_product` and `external_api` together with columns gateway, name, version, base URL, last seen. Row action "Bring behind integriq" opens the import dialog of `gateway-openapi-import-and-publish` with the stored document.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| discovery per vendor | declarative: source, mapping, synchronization and job in a `register.d` fragment | the synchronization engine already does it |
| "no longer seen" | declarative: the synchronization's disappearance policy | reuses records-owned-by-an-external-source |
| counts per gateway on the page | declarative: `x-openregister-aggregations` on `external_api` | a count |
| SigV4 signing | OpenRegister's broker, not integriq | ADR-064: the secret and the signing stay in the broker |

## Seed data

Three dormant fragments (`kong-gateway-discovery.json`, `azure-apim-discovery.json`, `aws-apigateway-discovery.json`) with `isEnabled: false` and empty `credentialRef`, and two example `external_api` objects from a Kong fixture so the page is not empty on a demo install.

## Risks

- Vendor API versions move. Mitigation: each fragment pins the API version it calls (Azure `api-version`, Kong Admin API 3.x) and the mapping test runs against recorded fixtures.
- Large tenants with hundreds of APIs. Mitigation: `maxPages` per run and the unchanged-skip in D4.
