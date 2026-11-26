---
kind: code
depends_on: [access-consumer-credentials, gateway-openapi-import-and-publish]
---

# Proposal: access-developer-portal-and-subscriptions

## Summary

An outside developer cannot find integriq's APIs or ask for access without mailing an administrator, and a subscription never ends unless someone revokes it by hand. This change gives developers a portal: a list of the published API products with their documentation, a request for access that follows the existing approval flow, and their own keys to create and replace. Subscribed developers are told when a product they use is deprecated or retired, and a subscription can carry an end date.

## Why

Four rows of integriq's capability matrix, access area, decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `integriq:acc-devportal` | no | build: two competitors yes |
| `integriq:acc-self-service-keys` | no | build: two competitors yes |
| `integriq:acc-change-notice` | partial, built | build: a featureRequest demand row for the missing half |
| `integriq:acc-subscription-expiry` | partial, built | build: a featureRequest demand row plus one competitor yes |

Demand and competitor cells, quoted from the matrix:

- `acc-devportal`: MuleSoft https://docs.mulesoft.com/exchange/to-create-an-asset.md shares API assets in "the Exchange public portal", and consumers "request access" there. WSO2 v4.7.0 `devportal-api.yaml:117` "/apis lists published APIs to developers".
- `acc-self-service-keys`: MuleSoft https://docs.mulesoft.com/exchange/about-my-applications.md "The client ID and client secret credentials are automatically created when the client application is registered", with a "Reset Client Secret" action. WSO2 v4.7.0 `devportal-api.yaml:3081` `/applications/{applicationId}/keys`.
- `acc-change-notice`: featureRequest https://github.com/wso2/api-manager/issues/2928, API consumer notifications. The matrix note: "Callers learn of retirement from response headers only, not from a notice."
- `acc-subscription-expiry`: featureRequest https://github.com/wso2/api-manager/issues/1513. Tyk v5.15.0 `user/session.go:306` "expires sets an end time on a key", enforced by `gateway/mw_key_expired_check.go:20`.

## What integriq already has

- API products and subscriptions: `lib/Settings/register.d/api-product-gateway.json` declares `api_product` (with `visibility` public or private, `status` active or deprecated, `sunsetDate`) and `api_product_subscription` (with `status` pending_approval, active, rejected or revoked, and `revokedAt`).
- `ProductSubscriptionsController` (`appinfo/routes.php:542-545`): subscribe and analytics are admin-only; approve and reject use the approver group check of the HITL approvals.
- At call time `EndpointService::resolveActiveSubscription()` (`lib/Service/EndpointService.php:1197`) finds an `active` subscription and `resolveTierPolicy()` (`:1252`) applies its tier. `buildDeprecationHeaders()` (`:1282`) adds RFC 8594 `Deprecation` and `Sunset` headers for a deprecated product.
- A consumer has a `userId` for the account that created it.
- `openspec/features.overlay.json` lists `developer-portal` as coming soon; no code exists.

## What this change builds

1. A portal page for developers: the public products, each with its description, versions, tiers and published OpenAPI description (from `gateway-openapi-import-and-publish`).
2. Applications: a developer creates a consumer they own, and requests access to a product and tier. The request is an `api_product_subscription` in `pending_approval`, approved by the product's approver group as today.
3. Self-service keys on the developer's own application, through the credential routes of `access-consumer-credentials`, limited to consumers the developer owns.
4. A change notice: when a product is deprecated, given a sunset date or retired, every owner of an active subscription gets a Nextcloud notification and a mail, and the notice is recorded.
5. An end date on a subscription: calls after it are refused with the date named, a reminder goes out fourteen days before, and a daily job marks it expired.

## Out of scope

- Charging for use (`acc-monetise`, deferred).
- A portal for anonymous visitors to request access. Developers sign in with a Nextcloud account, which may be a guest account.
- Publishing the OpenAPI description itself. `gateway-openapi-import-and-publish`.
