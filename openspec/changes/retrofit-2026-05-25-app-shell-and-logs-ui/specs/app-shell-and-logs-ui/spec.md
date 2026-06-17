---
retrofit: true
---

# app-shell-and-logs-ui

The Vue application shell glue and the log viewer: the root component's permission
resolution and translate adapter, the modal-host event bus that mounts v2 modals on
demand, and the generic log index page (call logs / endpoint logs / job logs / sync
logs). This spec describes the observed behaviour of the already-shipping components.

## Requirements

### REQ-SHELLUI-001: App-shell permission resolution and translate adapter

The root component SHALL resolve the current user's effective permission list —
injecting an `admin` marker when Nextcloud reports the user as an admin (since the
boolean admin flag is not present in the permissions array) — and SHALL provide a
translate adapter that closes over the app id for the design-system components.

#### Scenario: Admin marker injection
- WHEN the current user is a Nextcloud admin
- THEN `permissions` returns the base permission array with `'admin'` appended

#### Scenario: Translate adapter
- WHEN a design-system component calls the injected translate function with a key
- THEN `translateForApp` returns the openconnector-scoped translation (or the key on miss)

Notes: `App.vue` (2).

### REQ-SHELLUI-002: Modal-host event bus

The modal host SHALL subscribe to the modal event bus on mount and unsubscribe on
destroy, and open/close the test-mapping and add-endpoint-rule v2 modals in response
to bus events, carrying the event payload (mapping / endpoint) into the modal state.

#### Scenario: Opening a modal via the bus
- WHEN an `open test mapping` / `open add endpoint rule` event fires
- THEN `openTestMapping` / `openAddEndpointRule` sets the corresponding modal open with its payload

#### Scenario: Bus lifecycle
- WHEN the host mounts it subscribes; WHEN it is destroyed `beforeDestroy` unsubscribes

Notes: `ModalHost.vue` (6).

### REQ-SHELLUI-003: Log index viewer

The log index page SHALL select a per-log-type configuration (call/endpoint/job/sync),
expose title/description, render rows + total from the configured fetcher, paginate
(page/size), refresh on mount and on filter changes, define the log table columns, and
open a per-row detail modal.

#### Scenario: Loading logs for a type
- WHEN the page mounts for a given `logType`
- THEN `config` resolves the type's fetcher and `refresh` loads `rows`/`total`

#### Scenario: Pagination
- WHEN the user changes page or page size
- THEN `onPageChanged` / `onPageSizeChanged` update state and re-fetch via `refresh`

#### Scenario: Row detail
- WHEN the user opens a log row
- THEN `openDetail` stores the row and opens the configured detail modal

Notes: `LogIndex.vue` (12).
