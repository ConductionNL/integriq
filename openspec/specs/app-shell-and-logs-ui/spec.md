---
status: done
---

# app-shell-and-logs-ui Specification

## Purpose
Provides the OpenConnector application shell and log viewer UI. The root component resolves the current user's effective permissions (injecting an admin marker when applicable) and supplies an app-scoped translate adapter, a modal host opens test-mapping and add-endpoint-rule dialogs in response to a shared event bus, and a unified log index page lists, paginates, and drills into call, endpoint, job, and sync logs.

@e2e exclude Vue component-internal method/computed behaviour (permissions admin-marker, translateForApp adapter, modal-bus subscribe/unsubscribe, LogIndex config/refresh/pagination/openDetail) reverse-engineered from App.vue/ModalHost.vue/LogIndex.vue — unit-level (vitest), not browser-observable; the app shell render + log sub-page renders are covered by manifest-pages e2e

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

### Requirement: Log pages render via the shared declarative logs-page component (REQ-SHELLUI-003)

Every log route MUST be declared in the manifest as a `"type": "logs"` page resolved by the shared `CnLogsPage` component.

Specifically, `SourceLogs`, `EndpointLogs`, `JobLogs`, `SynchronizationLogs`,
and `CloudEventLogs` MUST each be declared in the manifest
(`src/manifest.json` / `src/manifest.d/*.json`) with a `{ register, schema }`
config, resolved by `@conduction/nextcloud-vue`'s shared `CnLogsPage`
component. OpenConnector MUST NOT ship its own bespoke log-index Vue component
or per-`logType` pinia store wiring for this purpose — that responsibility
belongs to the shared nc-vue component per ADR-036.

#### Scenario: All five log routes resolve through the manifest, not a bespoke wrapper

- **GIVEN** the manifest declares `SourceLogs`, `EndpointLogs`, `JobLogs`,
  `SynchronizationLogs`, and `CloudEventLogs` as `"type": "logs"` pages
- **WHEN** a user navigates to any of these five routes
- **THEN** the page renders via `CnLogsPage` reading directly from the
  declared OR `{register, schema}` — no openconnector-owned wrapper component
  is in the render path

#### Scenario: No dead per-logType wrapper code ships in the repo

- **GIVEN** the fleet's dead/stub-code review (`hydra-gate-stub-scan`)
- **WHEN** it scans `src/views/` for components not referenced by any manifest
  entry
- **THEN** it finds no orphaned log-index wrapper component

Notes: `src/views/wrappers/LogIndex.vue` was deleted (confirmed orphaned — zero
manifest `"component": "LogIndex"` references, zero importers outside its own
file, and its two referenced store members (`sourceStore.refreshSourceLogs` /
`sourceStore.sourceLogs`) were never actually defined in `src/store/store.js`
— the wrapper referenced undefined store members and would have thrown at
runtime had it ever been reachable). No store cleanup was needed since those
members never existed.

