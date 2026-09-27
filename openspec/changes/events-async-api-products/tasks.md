# Tasks: events-async-api-products

Kind: code. Matrix row `integriq:evt-async-apis`.

### Task 1: Move the product policy check into its own service
- **spec_ref**: openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
- **files**: `lib/Service/ProductPolicyService.php`, `lib/Service/EndpointService.php`, `tests/Unit/Service/ProductPolicyServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the existing REQ-APG-004 and REQ-APG-005 tests WHEN they run after the move THEN they pass unchanged
  - GIVEN an endpoint in no product WHEN it is called THEN consumer-level limits apply as before
- [ ] Implement
- [ ] Test (existing PHPUnit suites plus a direct test of the new service)

### Task 2: Channels on the product schema and page
- **spec_ref**: openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
- **files**: `lib/Settings/register.d/events-async-api-products.json`, `src/views/ApiProducts/ApiProductDetail.vue`
- **acceptance_criteria**:
  - GIVEN the register is imported WHEN a product is saved with a channel THEN OpenRegister accepts it, and a duplicate channel slug is refused
  - GIVEN the product detail page WHEN an administrator adds a channel THEN it is listed
  - GIVEN a fresh install WHEN the seed runs THEN `woo-publications` 2.0.0 has channel `publications`
- [ ] Implement
- [ ] Test (`node tests/validate-register.js`; Playwright `tests/e2e/events-async-api-products.spec.ts`)

### Task 3: Consumer pull and subscribe routes
- **spec_ref**: openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
- **files**: `lib/Controller/ProductChannelsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN no active product subscription WHEN a consumer pulls THEN 403 `subscription_required`
  - GIVEN a tier budget of 2 WHEN a consumer spends it across an endpoint and a channel THEN the next request is 429
  - GIVEN a deprecated product version WHEN a consumer pulls THEN Deprecation and Sunset headers are present
- [ ] Implement
- [ ] Test (PHPUnit on the controller; Newman requests in `tests/postman/`)

### Task 4: Push deliveries under the product subscription
- **spec_ref**: openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-push-deliveries-follow-the-product-subscription-req-aapi-003
- **files**: `lib/Controller/ProductChannelsController.php`, `lib/Service/EventService.php`, `lib/Settings/register.d/events-async-api-products.json`
- **acceptance_criteria**:
  - GIVEN a revoked product subscription WHEN an event matches THEN no delivery is made
  - GIVEN a spent quota WHEN an event matches THEN the delivery is deferred to the window end
  - GIVEN a sink on a private or metadata address WHEN a consumer registers it THEN 400 and nothing is created
- [ ] Implement
- [ ] Test (PHPUnit on EventService and on the sink check)

### Task 5: AsyncAPI document
- **spec_ref**: openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
- **files**: `lib/Service/AsyncApiDocumentService.php`, `lib/Controller/ProductChannelsController.php`
- **acceptance_criteria**:
  - GIVEN a public product with a channel WHEN the document is requested without a session THEN a valid AsyncAPI 3.0 document is returned
  - GIVEN a private product WHEN it is requested without a subscribed consumer THEN 403
- [ ] Implement
- [ ] Test (PHPUnit validating the output against the AsyncAPI 3.0 JSON schema fixture)

## Verification

- `openspec validate events-async-api-products --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- Newman collection for the channel routes and `tests/e2e/events-async-api-products.spec.ts`
