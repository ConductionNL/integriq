---
status: implemented
retrofit: true
---

# Source Management

## Purpose

OpenConnector provides a Sources section in its SPA where administrators can
browse, create, edit, and test external source connections (APIs, databases,
registers). A Source is the foundational entity that describes how to connect to
an external system — its URL, authentication type, headers, and rate-limit
configuration. This spec covers the **observable browser UI behaviour** of the
Sources section plus the backend call/rate-limit internals (covered by PHPUnit
and Newman). It is a retrofit spec: the code already exists.

## Requirements

### REQ-SRC-UI-001: Source Management UI

OpenConnector provides a Sources section in its SPA where administrators can
browse, create, edit, delete, and test source connections.

#### Scenario: sources list page mounts and shows content

- GIVEN an authenticated admin visits the openconnector app
- WHEN they navigate to the Sources section via the sidebar nav or direct URL `/apps/openconnector/sources`
- THEN the Sources index page renders inside the main content area with content visible

#### Scenario: add source button opens the creation modal

- GIVEN the Sources index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the source creation form fields

#### Scenario: source logs sub-page mounts

- GIVEN an authenticated admin
- WHEN they navigate to the Source logs page at `/apps/openconnector/sources/logs`
- THEN the page mounts and renders the main content area

### REQ-SRC-001: External HTTP source call

@e2e exclude backend CallService HTTP dispatch — covered by PHPUnit/Newman, not browser UI

The system SHALL dispatch HTTP calls to external sources via `CallService`,
applying source-configured authentication (bearer, basic, API key, JWT),
headers, rate-limit tracking, and response normalisation. All call records are
persisted as `call_log` objects in OpenRegister.

**Scenarios:**

1. **GIVEN** a source with bearer auth configured **WHEN** a call is dispatched
   **THEN** the `Authorization: Bearer <token>` header is included.

2. **GIVEN** a source whose rate-limit remaining reaches zero **WHEN** a call
   is attempted **THEN** a 429 exception is raised and the call is not dispatched.

3. **GIVEN** a dispatched call **WHEN** the response is received **THEN** a
   `call_log` record is persisted with the source id, status code, and duration.

### REQ-SRC-002: Source connection test

@e2e exclude backend source-test endpoint — covered by PHPUnit/Newman, not browser UI

The system SHALL provide a `POST /api/sources/{id}/test` endpoint that
dispatches a single test call to the source and returns the response body and
status code without persisting a log record.

**Scenarios:**

1. **GIVEN** a source with a reachable URL **WHEN** the test endpoint is called
   **THEN** the response contains the upstream status code and body.

2. **GIVEN** a source with an unreachable URL **WHEN** the test endpoint is called
   **THEN** a descriptive error response is returned rather than a 500 crash.
