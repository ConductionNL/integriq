---
status: implemented
retrofit: true
---

# Consumer and Webhook Management

## Purpose

OpenConnector exposes two related UI sections — **Consumers** and **Webhooks** —
that allow administrators to configure inbound access policies. A Consumer
describes an external system that is permitted to call OpenConnector's endpoints,
including its authorization type and allowed domains. Webhooks share the same
underlying schema (`consumer`) and surface but are presented separately for
clarity. This spec covers the observable browser UI behaviour and the backend
authentication enforcement (covered by PHPUnit/Newman). It is a retrofit spec.

## Requirements

### REQ-CON-UI-001: Consumer Management UI

OpenConnector provides a Consumers section in its SPA where administrators can
browse, create, edit, and delete consumer configurations.

#### Scenario: consumers list page mounts and shows content

- GIVEN an authenticated admin visits the openconnector app
- WHEN they navigate to the Consumers section via the sidebar nav or direct URL `/apps/openconnector/consumers`
- THEN the Consumers index page renders inside the main content area with content visible

#### Scenario: add consumer button opens the creation modal

- GIVEN the Consumers index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the consumer creation form

### REQ-WBHK-UI-001: Webhook Management UI

OpenConnector provides a Webhooks section in its SPA where administrators can
browse, create, edit, and delete webhook consumers.

#### Scenario: webhooks list page mounts and shows content

- GIVEN an authenticated admin visits the openconnector app
- WHEN they navigate to the Webhooks section via the sidebar nav or direct URL `/apps/openconnector/webhooks`
- THEN the Webhooks index page renders inside the main content area with content visible

#### Scenario: add webhook button opens the creation modal

- GIVEN the Webhooks index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the webhook/consumer creation form

### REQ-CON-001: Consumer authentication enforcement

@e2e exclude backend consumer auth enforcement — covered by PHPUnit/Newman, not browser UI

The system SHALL enforce consumer-level authentication on inbound calls to
OpenConnector endpoints by resolving the `consumer` record associated with the
request and checking that the caller's credentials match the configured
`authorizationType` (none, apiKey, jwt, basic, oauth2). Requests failing
consumer auth SHALL receive HTTP 401.

**Scenarios:**

1. **GIVEN** a consumer with `authorizationType: apiKey` **WHEN** a request
   arrives without a matching API key header **THEN** the response is HTTP 401.

2. **GIVEN** a consumer with `authorizationType: none` **WHEN** any request
   arrives on a matched endpoint **THEN** auth passes regardless of headers.

3. **GIVEN** a consumer with allowed `domains` configured **WHEN** a request
   originates from an unlisted domain **THEN** the response is HTTP 403.
