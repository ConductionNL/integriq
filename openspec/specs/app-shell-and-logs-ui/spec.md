# app-shell-and-logs-ui Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-25-app-shell-and-logs-ui. Update Purpose after archive.
## Requirements
### Requirement: App-shell permission resolution and translate adapter (REQ-SHELLUI-001)

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

### Requirement: Modal-host event bus (REQ-SHELLUI-002)

The modal host SHALL subscribe to the modal event bus on mount and unsubscribe on
destroy, and open/close the test-mapping and add-endpoint-rule v2 modals in response
to bus events, carrying the event payload (mapping / endpoint) into the modal state.

#### Scenario: Opening a modal via the bus
- WHEN an `open test mapping` / `open add endpoint rule` event fires
- THEN `openTestMapping` / `openAddEndpointRule` sets the corresponding modal open with its payload

#### Scenario: Bus lifecycle
- WHEN the host mounts it subscribes; WHEN it is destroyed `beforeDestroy` unsubscribes

Notes: `ModalHost.vue` (6).

### Requirement: Log index viewer (REQ-SHELLUI-003)

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

