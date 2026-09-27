# api-product-gateway Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- access-developer-portal-and-subscriptions

## Purpose

Developers find the published APIs, ask for access and manage their own keys, and they hear about changes before their calls break. Rows `integriq:acc-devportal`, `acc-self-service-keys`, `acc-change-notice` and `acc-subscription-expiry`.

## ADDED Requirements

### Requirement: A developer finds the published API products (REQ-DEVP-001)

Integriq MUST offer a portal page to members of the configured developer group. It MUST list every product whose visibility is public, with its description, versions, tiers and published OpenAPI description, and MUST NOT list a private product.

#### Scenario: a developer browses the portal
- GIVEN a developer in the group `integriq-developers`
- WHEN they open the developer portal
- THEN they see the public product "Zaken API" with its tiers and documentation, and no private product
- e2e: `tests/e2e/developer-portal.spec.ts`

### Requirement: A developer requests access for their own application (REQ-DEVP-002)

Integriq MUST let a developer create an application, which is a consumer they own, and request access to a product and tier for it. The request MUST open the product's existing approval. Every portal action MUST refuse a consumer the developer does not own and write nothing.

#### Scenario: a request waits for the approver
- GIVEN a developer with an application
- WHEN they request access to "Zaken API" on the basic tier
- THEN a subscription is pending approval, and the product's approver group sees the request
- e2e: `tests/e2e/developer-portal.spec.ts`

#### Scenario: another developer's application is out of reach
- GIVEN developer A and developer B's application
- WHEN A requests access using B's application id
- THEN the answer is 404 and no subscription is written
- @e2e exclude covered by PHPUnit on the ownership check

### Requirement: A developer manages the keys of their own application (REQ-DEVP-003)

Integriq MUST let a developer generate, list and revoke the keys of an application they own, with each new key shown once, without an administrator.

#### Scenario: a developer replaces a leaked key
- GIVEN a developer whose key leaked
- WHEN they generate a new key in the portal, switch their system to it and revoke the old one
- THEN calls with the new key pass and calls with the old key get 401
- e2e: `tests/e2e/developer-portal.spec.ts`

### Requirement: Subscribers are told when a product they use changes (REQ-DEVP-004)

When an administrator deprecates a product, sets or moves its sunset date, publishes a new version or retires it, integriq MUST write one change notice per active subscription and MUST notify the subscription's owner by Nextcloud notification and mail. A retired product MUST answer 410 to its subscribers.

#### Scenario: a deprecation reaches the developer before the sunset
- GIVEN "Besluiten API" with an active subscription owned by a developer
- WHEN an administrator marks it deprecated with sunset date 2027-01-01
- THEN the developer gets a notification naming the product and the date, and the product page lists the notice as sent
- e2e: `tests/e2e/product-change-notice.spec.ts`

### Requirement: A subscription can end on a date (REQ-DEVP-005)

Integriq MUST let an administrator set an end date on a subscription. After that date calls through the subscription MUST be refused with 403 naming the date. Integriq MUST remind the owner fourteen days before, and MUST mark the subscription expired.

#### Scenario: a pilot subscription stops on its end date
- GIVEN a subscription ending 2026-12-31
- WHEN its consumer calls on 2027-01-02
- THEN the answer is 403 and names 2026-12-31, and the subscription shows as expired
- @e2e exclude request-time check and a daily job; covered by PHPUnit and Newman
