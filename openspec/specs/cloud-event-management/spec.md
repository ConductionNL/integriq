---
status: implemented
retrofit: true
---

# Cloud Event Management

## Purpose

OpenConnector provides a Cloud Events section in its SPA where administrators
can browse incoming CloudEvents (CloudEvents spec v1.0), inspect their payload,
and view associated call logs. Cloud Events are stored as `event` objects in
the `openconnector` OpenRegister register. This spec covers the observable
browser UI behaviour and the backend CloudEvents routing internals (covered by
PHPUnit/Newman). It is a retrofit spec.

## Requirements

### REQ-CE-UI-001: Cloud Event Management UI

OpenConnector MUST provide a Cloud Events section in its SPA where administrators
can browse, inspect, and manage incoming cloud events and their logs.

#### Scenario: cloud events list page mounts and shows content

- GIVEN an authenticated admin visits the openconnector app
- WHEN they navigate to the Cloud Events section via the sidebar nav or direct URL `/apps/openconnector/cloud-events/events`
- THEN the Cloud Events index page renders inside the main content area with content visible

#### Scenario: add cloud event button opens the creation modal

- GIVEN the Cloud Events index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the cloud event creation form

#### Scenario: cloud event logs sub-page mounts

- GIVEN an authenticated admin
- WHEN they navigate to the Cloud Event logs page at `/apps/openconnector/cloud-events/logs`
- THEN the page mounts and renders the main content area

### REQ-CE-001: CloudEvents inbound routing

The system SHALL receive inbound CloudEvents at the configured receiver
endpoint, validate the CloudEvents envelope (specversion, source, type, id),
persist the event as an `event` object in OpenRegister, and route it to any
matching synchronization or rule pipelines based on `type` and `source` filters.

@e2e exclude backend CloudEvents inbound routing — covered by PHPUnit/Newman, not browser UI

#### Scenario: valid CloudEvents envelope is persisted and routed

- **GIVEN** a valid CloudEvents 1.0 envelope POSTed to the receiver
- **WHEN** the inbound handler processes it
- **THEN** the event is persisted as an `event` object and routed to matching
  pipelines

#### Scenario: envelope missing specversion is rejected

- **GIVEN** an envelope missing the required `specversion` field
- **WHEN** the inbound handler validates it
- **THEN** HTTP 400 is returned with a validation error and nothing is persisted

#### Scenario: matching type invokes the synchronization

- **GIVEN** an event whose `type` matches a configured synchronization trigger
- **WHEN** routing runs
- **THEN** the matching synchronization is invoked with the event payload
