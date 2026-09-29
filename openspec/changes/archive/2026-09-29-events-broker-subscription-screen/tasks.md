# Tasks: events-broker-subscription-screen

Kind: code. Matrix row `integriq:evt-broker`. Builds on `event-broker-transport`.

### Task 1: List the broker transports
- **spec_ref**: openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-the-app-lists-the-broker-transports-it-has-req-ebsc-001
- **files**: `lib/Controller/EventsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN four registered transports WHEN an administrator calls `GET /api/events/brokers` THEN four descriptions are returned
  - GIVEN a user without `event.subscriptions` WHEN they call it THEN the answer is 403
- [x] Implement (EventBrokersController::index, route eventBrokers#index; see design "Changed while building")
- [x] Test (tests/Unit/Controller/EventBrokersControllerTest.php: the list reflects the registry with the four real transports; OCSForbiddenException without the action)

### Task 2: Broker as an action kind on the form
- **spec_ref**: openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-the-subscription-form-offers-broker-as-a-delivery-action-req-ebsc-002
- **files**: `src/modals/EventSubscription/SubscriptionActionFields.vue`, `src/modals/EventSubscription/brokerFields.js`
- **acceptance_criteria**:
  - GIVEN the subscription form WHEN the administrator picks "Broker" THEN broker, topic, content mode and ordering key fields appear
  - GIVEN the form WHEN RabbitMQ is picked THEN a routing key field appears
  - GIVEN the form WHEN Kafka is picked THEN the routing key field is hidden and content mode offers structured only
  - GIVEN a filled broker action WHEN the form is saved THEN `action.kind` is `broker` with the chosen fields
- [x] Implement
- [x] Test (tests/vitest/brokerSubscriptionFields.spec.js; tests/e2e/events-broker-subscription.spec.ts written, not run in this lane: no instance)

### Task 3: Connection fields with a credential reference
- **spec_ref**: openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
- **files**: `src/modals/EventSubscription/BrokerConnectionFields.vue`, `src/modals/v2/sourceCredentialRef.js`
- **acceptance_criteria**:
  - GIVEN the broker action WHEN the administrator fills the connection THEN base URL, virtual host for RabbitMQ and a credential picker are shown, and no password field
  - GIVEN a filled connection WHEN the subscription is saved THEN `protocolSettings.broker` holds `baseUrl`, `vhost` and `credentialRef` only
- [x] Implement (plus a plain username field, design D4)
- [x] Test (vitest: no password field, payload equals tests/fixtures/events/broker-subscription-payload.json; BrokerSubscriptionPayloadTest validates it against the merged event_subscription schema)

### Task 4: Resolve the reference at publish
- **spec_ref**: openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
- **files**: `lib/Service/EventService.php`, `lib/Service/BrokeredCallService.php`, `tests/Unit/Service/EventServiceBrokerTest.php`
- **acceptance_criteria**:
  - GIVEN a `credentialRef` WHEN a matching event is dispatched THEN the transport receives the resolved secret
  - GIVEN an unresolvable reference WHEN dispatched THEN a configuration error is recorded and no retry is scheduled
- [x] Implement (lib/Broker/BrokerCredentialResolver.php, BrokeredCallService::resolveCredentialRef())
- [x] Test (tests/Unit/Service/EventServiceBrokerTest.php, 4)

### Task 5: Mask stored broker secrets
- **spec_ref**: openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-stored-broker-secrets-are-masked-on-the-apps-subscription-endpoints-req-ebsc-004
- **files**: `lib/Controller/EventsController.php`, `src/modals/EventSubscription/BrokerConnectionFields.vue`
- **acceptance_criteria**:
  - GIVEN a stored `protocolSettings.broker.password` WHEN subscriptions are listed THEN the value is masked
  - GIVEN a masked value WHEN the form opens THEN it says a password is stored and offers a credential instead
- [x] Implement (masking shipped in #2231 via SubscriptionSecretMasker; the notice in BrokerConnectionFields)
- [x] Test (EventsControllerBrokerSecretRedactionTest from #2231; the notice in brokerSubscriptionFields.spec.js)

## Verification

- `openspec validate events-broker-subscription-screen --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/events-broker-subscription.spec.ts` green against a local instance
