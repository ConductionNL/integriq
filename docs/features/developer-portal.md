# Developer portal and subscriptions

## Overview

Outside developers find integriq's published API products in the developer
portal, create their own applications and ask for access without mailing an
administrator. Subscribers hear about a product change before their calls
break, and a subscription can end on a date.

## Who may use the portal

A developer is a Nextcloud account in the developer group. Until an
administrator names another group under **Administration settings → Integriq
→ Who may use the developer portal**, that group is `integriq-developers`.
Guest accounts from the Guests app can be members. The admin settings show how
many accounts are in the group, so a group left empty, which hides the portal
from everyone, is visible there.

## Browsing products

The **Developer portal** page lists every API product whose visibility is
`public` and that is not retired: its name, version, description, tiers
(those that need approval are marked) and, when the product links one, its
API description. A private product is never listed.

## Applications and access

An application is a consumer the developer owns: its `userId` is the developer
and its `ownerKind` is `developer`. A developer sees only their own
applications. **Request access** picks a product and a tier for one
application:

- a tier without an approval gate is active at once;
- a gated tier opens an approval for the tier's approver group, exactly as a
  subscription an administrator creates; the approvers find it on the
  **Approvals** page and on the product page.

Every portal action checks that the application belongs to the developer
before it reads or writes anything. Another developer's application, or a
product that is not public, answers `404` and nothing is written.

## Change notices

When an administrator deprecates a product, sets or moves its sunset date,
publishes a new version (a new product row with the same `productSlug`) or
retires it, integriq writes one **change notice** per active subscription,
addressed to the subscription's owner: the application's account, else the
user who requested the subscription. Each notice sends a Nextcloud
notification and a mail naming the product and, where there is one, the date.
The **Change notices** section of the product page lists who was told what and
when.

A retired product answers `410 Gone` to its subscribers.

## Subscription end dates

A subscription can carry `expiresAt`. From that moment its calls are refused
with `403`, naming the date (`error: subscription_expired`). A daily job marks
ended subscriptions `expired` and, fourteen days before the end, writes one
`subscription-expiring` notice for the owner.

## Seed data

A fresh install carries two public products, **Zaken API** (active, two tiers)
and **Besluiten API** (deprecated, sunset 2027-01-01), the application
`example-developer-app` owned by the account `developer1` with a subscription
to Zaken API that ends 2026-12-31, and one deprecation notice for Besluiten
API. Create the account `developer1` and add it to the developer group to walk
through the portal.

## API

| Method | Path | Who |
| --- | --- | --- |
| GET | `/api/portal/products` | developer group |
| GET | `/api/portal/applications` | developer group, own applications |
| POST | `/api/portal/applications` | developer group |
| POST | `/api/portal/applications/{consumerId}/subscriptions` | developer group, own application |
| GET, PUT | `/api/admin/developer-portal` | administrators |

Keys for an application (generate, list, revoke) arrive with the consumer
credentials routes; see the open item in the change's `tasks.md`.
