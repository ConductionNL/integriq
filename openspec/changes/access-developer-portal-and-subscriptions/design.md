# Design: access-developer-portal-and-subscriptions

Kind: code. Size L. The `api_product` and `api_product_subscription` schemas, `ProductSubscriptionsController`, `EndpointService`'s subscription resolution, and two new pages.

## Context at development 92f282bc

- Schemas in `lib/Settings/register.d/api-product-gateway.json`: `api_product` (`visibility`, `status`, `sunsetDate`, `endpoints`, `tiers`, `defaultTier`) and `api_product_subscription` (`product`, `consumer`, `tier`, `status`, `approvalRequestId`, `requesterUserId`, `activatedAt`, `revokedAt`).
- `appinfo/routes.php:535-545`: product CRUD goes through OpenRegister's object API; subscribe and analytics are admin-only, approve and reject use the approver group.
- `EndpointService::resolveActiveSubscription()` (`lib/Service/EndpointService.php:1197`) filters on `status` `active`; `buildDeprecationHeaders()` (`:1282`).
- Pages `ApiProducts` (`/products`) and `ApiProductDetail` (`/products/:id`) in `src/manifest.json:1934-1979`, admin-facing.
- The HITL approval notification pattern: `x-openregister-notifications` with a `created` trigger in `lib/Settings/register.d/hitl-approval-rule-action.json:156`.

## D1. Who a developer is

A developer is a Nextcloud account in a group the administrator names in the admin settings (default `integriq-developers`). Guest accounts from the Guests app work. The portal routes are `#[NoAdminRequired]` and check group membership in the body, with the same shape as the approve and reject routes. Anonymous access was rejected: a request for access needs someone to answer to, and a key needs an owner.

## D2. An application is a consumer the developer owns

"Create application" writes a `consumer` with `userId` set to the developer and a new `ownerKind` of `developer` (administrators' consumers read `admin`). Every portal action checks `consumer.userId` equals the current user before it reads or changes anything, which closes the IDOR shape the hydra gate `no-admin-idor` looks for. The developer sees only their own consumers.

## D3. Request access reuses the approval flow

"Request access" calls the existing subscribe path with the developer's consumer and a tier, opening an approval for the product's approver group. The subscribe route stays admin-only; a new `portal#requestAccess` route carries the developer check and calls the same service method. No second approval mechanism.

## D4. Keys through the credential routes

Generate, list and revoke key reuse `access-consumer-credentials` (show once, several keys, last used). The portal wraps them with the ownership check of D2.

## D5. The change notice is an object with a declared notification

A new schema `product_change_notice` (`product`, `subscription`, `recipientUserId`, `kind` one of deprecated, sunset-date-set, retired, new-version, `message`, `sentAt`). When an administrator deprecates a product, sets or moves its sunset date, or marks it retired from the product page, the service writes one notice per active subscription. The notice schema declares `x-openregister-notifications` with a `created` trigger, channels `nc-notification` and `email`, and recipient `{ "kind": "field", "field": "recipientUserId" }`. The notice list on the product page shows who was told what and when.

`status` on `api_product` gains `retired`. A retired product's endpoints answer 410 Gone for subscribers, with the notice's message.

## D6. Subscription end dates

`api_product_subscription` gains `expiresAt` and a status `expired`. `resolveActiveSubscription()` refuses a subscription whose `expiresAt` has passed, and the endpoint answers 403 with the date. A daily background job (ADR-069 conventions) sets `status` to `expired` and writes a `product_change_notice` of kind `subscription-expiring` fourteen days ahead, reusing D5's notification.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| notify subscribers of a change | declarative: `x-openregister-notifications` on `product_change_notice`, `created` trigger | the `created` trigger works today; the notice doubles as the record |
| subscription expiry | imperative: a check in `resolveActiveSubscription()` plus a daily job | request-time refusal cannot be a derived field; the job is scheduled bulk work (ADR-031 exception) |
| retired returns 410 | imperative, in `EndpointService` | request-time behaviour |

## Seed data

- `api_product` `zaken-api` (public, active, two tiers) and `besluiten-api` (public, deprecated, sunset date 2027-01-01).
- A developer consumer `example-developer-app` owned by the seeded user `developer1`, with an active subscription to `zaken-api` expiring 2026-12-31.
- One `product_change_notice` of kind `deprecated` for `besluiten-api`.

## Risks

- A developer group left empty hides the portal from everyone. Mitigation: the admin settings show the group and its member count.
- A notice storm when a popular product is deprecated. Mitigation: one notice per subscription, not per call, and the notification engine batches mail.
