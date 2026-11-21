# Tasks: access-developer-portal-and-subscriptions

Kind: code. Size L. Rows `integriq:acc-devportal`, `acc-self-service-keys`, `acc-change-notice`, `acc-subscription-expiry`.

## Implementation tasks

### Task 1: Developer group and the portal product list
- **spec_ref**: `openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-finds-the-published-api-products-req-devp-001`
- **files**: `lib/Controller/PortalController.php`, `appinfo/routes.php`, `src/manifest.json` (page `DeveloperPortal`), admin settings, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a developer in the developer group WHEN they open the portal THEN they see public products and their documentation, and no private product
- [x] Implement (`PortalController::products()` behind `DeveloperPortalAccess`; page `DeveloperPortal` (custom `DeveloperPortalPage`, because a developer holds no register rights and the page reads the portal routes); admin section `DeveloperPortalSettings` with the group's member count; strings in `l10n/en.js`, `nl.js`, and the PHP ones in `en.json`, `nl.json`. A product's published OpenAPI description is linked when it carries `openApiUrl`; publishing it is `gateway-openapi-import-and-publish`)
- [ ] Test (Playwright as a developer and as a user outside the group)
  - Written, not yet run (needs a live instance): `tests/e2e/developer-portal.spec.ts` makes two developers and one account outside the group through the provisioning API, then lists the products and opens the portal page as a developer and as the outsider, each from an empty storage state with its own login.

### Task 2: Applications owned by a developer, and request access
- **spec_ref**: `openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002`
- **files**: `lib/Controller/PortalController.php`, `lib/Service/ProductSubscriptionService.php`, `lib/Settings/integriq_register.json` (consumer `ownerKind`)
- **acceptance_criteria**:
  - GIVEN developer A WHEN they request access with developer B's consumer id THEN the answer is 404 and nothing is written
- [x] Implement (`PortalController` `applications`, `createApplication` and `requestAccess`, checking the developer group and `consumer.userId` with `ownerKind: developer`; `ProductSubscriptionService` is the one subscribe path, so a request opens the product's approval as an administrator's would)
- [ ] Test (PHPUnit on the ownership check; Playwright for create application and request access)
  - PHPUnit done: `PortalControllerTest::testAnotherDevelopersApplicationIsOutOfReach` and the rest of `PortalControllerTest`, `ProductSubscriptionServiceTest`. Left: the Playwright run.
  - Playwright written, not yet run: `tests/e2e/developer-portal.spec.ts` creates an application in the portal page, requests the approval tier of "Zaken API" (202, pending approval, listed under the application and in the approval queue) and has developer A ask with developer B's application (404, B's subscriptions unchanged).

### Task 3: Self-service keys in the portal
- **spec_ref**: `openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003`
- **files**: `lib/Controller/PortalController.php`, the portal application page
- **acceptance_criteria**:
  - GIVEN a developer's application WHEN they generate a key THEN it is shown once, and they can revoke it later without an administrator
- **unblocked**: the credential routes of `access-consumer-credentials` are on development.
- [x] Implement
  - `PortalKeysController` on `GET`/`POST /api/portal/applications/{consumerId}/keys` and `DELETE /api/portal/applications/{consumerId}/keys/{credentialId}`, `#[NoAdminRequired]`, over `ConsumerCredentialList` and `ConsumerCredentials` (the same list, hashing and show-once rule as the administrator's routes). Each route checks the developer group and then ownership, which moved from `PortalController::owns()` to `DeveloperPortalAccess::owns()` so both controllers use one rule. An application that is not the developer's answers 404 and nothing is written. The portal revokes API keys only; a pinned client certificate is the administrator's. Documented in `openapi.json`.
  - `src/views/DeveloperPortal/PortalApplicationKeys.vue` on each application card: the keys by label and prefix with last use, "Generate key" showing the key once, "Revoke". Strings in `l10n/en.js` and `l10n/nl.js`.
- [ ] Test (Playwright; PHPUnit on the ownership wrapper)
  - PHPUnit done: `PortalKeysControllerTest` (shown once and listed without hash, a revoked key no longer matches and the new one does, a pinned certificate is not revoked, another developer's application, an administrator's consumer and an unknown id answer 404 with nothing written, 401 and 403 before anything is read, a bad label and a failed save change nothing) and `DeveloperPortalAccessTest`. Vitest: `tests/vitest/portalApplicationKeys.spec.js`. The Playwright scenario is written in `tests/e2e/developer-portal.spec.ts` and not run (needs a live instance), so the box stays open.

### Task 4: Change notices
- **spec_ref**: `openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004`
- **files**: `lib/Settings/register.d/api-product-gateway.json` (schema `product_change_notice`, `retired` status), `lib/Service/ProductChangeNoticeService.php`, `lib/Service/EndpointService.php`, `src/manifest.json` (notice list on `ApiProductDetail`)
- **acceptance_criteria**:
  - GIVEN a product with two active subscriptions WHEN an administrator deprecates it THEN two notices are written and both owners get a notification
- [x] Implement (`product_change_notice` with a `created` notification over `nc-notification` and `email` to `recipientUserId`; `ProductChangeNoticeListener` on product updates and creates; `retired` answers 410 in `EndpointService`)
- [ ] Test (PHPUnit for notice fan-out and the 410; one notification observed in the Nextcloud notifications list)
  - PHPUnit done: `ProductChangeNoticeServiceTest`, `ProductChangeNoticeListenerTest`, `EndpointServiceTierPolicyTest::testARetiredProductAnswersGone`. Left: observing the notification on a live instance.

### Task 5: Subscription end dates
- **spec_ref**: `openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-subscription-can-end-on-a-date-req-devp-005`
- **files**: `lib/Settings/register.d/api-product-gateway.json` (`expiresAt`, `expired`), `lib/Service/EndpointService.php`, `lib/BackgroundJob/SubscriptionExpiryJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a subscription that expired yesterday WHEN its consumer calls THEN the answer is 403 naming the date
- [x] Implement (`expiresAt`, `expiryReminderSentAt` and status `expired`; `ProductPolicyService::expiredOn()` refuses at request time; `SubscriptionExpiryJob` daily via `SubscriptionExpiryService`)
- [ ] Test (PHPUnit for the check and the job; Newman for the refused call)
  - PHPUnit done: `ProductPolicyServiceTest`, `EndpointServiceTierPolicyTest::testAnEndedSubscriptionIsRefusedNamingItsEndDate`, `SubscriptionExpiryServiceTest`, `SubscriptionExpiryJobTest`. Left: the Newman run against a live instance.
  - Newman written, not yet run: folder "17a — Developer portal: an ended subscription" in `tests/postman/integriq.postman_collection.json` builds an API-key rule, endpoint, consumer, product and a subscription that ended yesterday, expects 403 `subscription_expired` naming the date, and cleans up.

### Task 6: Seed data and documentation
- **spec_ref**: `openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-finds-the-published-api-products-req-devp-001`
- **files**: `lib/Settings/register.d/api-product-gateway.json` (`x-openregister-seed`), `docs/`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the seeded developer opens the portal THEN the two seeded products and their application are there
- [x] Implement (seeds `zaken-api`, `besluiten-api`, `example-developer-app` owned by `developer1`, its subscriptions and one `deprecated` notice; `docs/features/developer-portal.md`)
- [ ] Test (docs walked once against the seed)

## Verification
- [ ] `openspec validate access-developer-portal-and-subscriptions --type change --strict` passes
  - Run on 17 Nov: the only issue is the warning on the Status/Scope header above `## Purpose` in `specs/api-product-gateway/spec.md`, which `--strict` treats as an error; the header follows the app's spec layout, so it is left for the spec pass.
- [ ] Hydra gates `no-admin-idor` and `route-auth` pass on the new routes
- [ ] PHPUnit, Newman and Playwright run, exit codes read
