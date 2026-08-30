---
status: done
---

# app-shell-and-logs-ui Specification

## Purpose
Provides the Integriq application shell and log viewer UI. The root component resolves the current user's effective permissions (injecting an admin marker when applicable) and supplies an app-scoped translate adapter, a modal host opens test-mapping, add-endpoint-rule and run/test dialogs in response to a shared event bus, and a unified log index page lists, paginates, and drills into call, endpoint, job, and sync logs.

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
- THEN `translateForApp` returns the integriq-scoped translation (or the key on miss)

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
component. Integriq MUST NOT ship its own bespoke log-index Vue component
or per-`logType` pinia store wiring for this purpose — that responsibility
belongs to the shared nc-vue component per ADR-036.

#### Scenario: All five log routes resolve through the manifest, not a bespoke wrapper

- **GIVEN** the manifest declares `SourceLogs`, `EndpointLogs`, `JobLogs`,
  `SynchronizationLogs`, and `CloudEventLogs` as `"type": "logs"` pages
- **WHEN** a user navigates to any of these five routes
- **THEN** the page renders via `CnLogsPage` reading directly from the
  declared OR `{register, schema}` — no integriq-owned wrapper component
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

### Requirement: Shared run/test modal for row actions (REQ-SHELLUI-004)

The `Run now` and `Test` row actions SHALL open a shared modal rather than
firing the request directly, on both the Synchronizations and the Jobs index,
and that modal SHALL own both the request and the rendering of its result. The
`runSynchronizationHandler`, `testSynchronizationHandler`, `runJobHandler` and
`testJobHandler` handlers SHALL do nothing but emit `open-run-action` on the
modal bus (REQ-SHELLUI-002) with `{ target, mode, item }`; they SHALL NOT call
the backend and SHALL NOT raise a toast.

All entity-specific knowledge — endpoint, request body, option switches, result
shape and log link — SHALL live in per-`(target, mode)` descriptors, so the
modal component itself contains no reference to synchronizations or jobs and a
further runnable entity is added by adding a descriptor.

The modal SHALL gate the request behind an options step: it opens showing the
descriptor's switches with an explanation of each, and issues no request until
the user confirms. This is the only surface through which the `force`,
`forceDeletion` and `forceRun` flags the endpoints accept can be set.

A switch that cannot take effect SHALL be disabled and SHALL explain why rather
than what it would have done.

#### Scenario: the run is gated, not fired on menu click

- **WHEN** the user picks `Run now` on a synchronization row
- **THEN** the modal opens on its options step
- **AND** no request has been sent

#### Scenario: test mode re-routes to the test endpoint

- **GIVEN** the Run modal for a synchronization
- **WHEN** the user turns on `Test mode` and runs
- **THEN** the request goes to `POST /api/synchronizations/{id}/test`, not to
  `/run` with `test: true`
- **AND** so a user authorized for `synchronization.test` but not
  `synchronization.run` is not refused an operation they may perform

#### Scenario: force deletion is withheld where it provably does nothing

- **GIVEN** a synchronization whose `syncMode` is `incremental`
- **WHEN** the Run modal renders
- **THEN** the `Force deletion` switch is disabled, explaining that deletion
  detection is off entirely in incremental mode and that `forceDeletion`
  overrides only the ratio guard, not that block
  (`synchronization-engine` REQ-018)
- **AND** it is absent altogether when `Test mode` is on, a dry run having no
  deletions to guard

#### Scenario: the returned run log is rendered

- **WHEN** a synchronization run returns its log
- **THEN** the modal shows the six `result.objects` counters, the execution
  time, and the contract/contract-log counts
- **AND** `result.contracts` / `result.logs` are rendered as counts with a link
  to the filtered logs page, never as inline detail — the engine returns them
  as uuid references, not embedded objects

#### Scenario: a guarded cleanup pass is explained, not hidden behind "deleted: 0"

- GIVEN a run whose deletion-ratio guard tripped
- WHEN the result renders
- THEN it states that nothing was deleted and why, with the candidate count,
  the computed share and the threshold, ahead of the counters — where
  `deleted: 0` beside a large `found` would otherwise read as a clean no-op
- AND it offers a one-click re-run with `forceDeletion` pre-set, which still
  passes through the options step rather than firing straight away
- AND that offer is withheld for the `incremental_mode` and `fetch_incomplete`
  guards, which `forceDeletion` cannot override

#### Scenario: a job that did not run is not reported as having run

- **GIVEN** a job that is not yet due, run with force off
- **WHEN** the endpoint answers with literal JSON `null` (`job-scheduling`
  REQ-002)
- **THEN** the modal reports that nothing was executed and why

#### Scenario: the job force-run action does not claim to be a dry run

- **GIVEN** `JobsController::test()` is `run()` with `forceRun` forced on and
  the engine has no dry-run mode for jobs at all
- **WHEN** the Jobs row action and the modal it opens are labelled
- **THEN** neither describes the operation as a test or a dry run
- **AND** the modal states that the job executes for real

Notes: `src/modals/v2/RunActionModal.vue` (the generic shell),
`src/modals/v2/runTargets.js` (the four descriptors),
`src/handlers/actionHandlers.js` (the four emitters). Replaces the toast-only
handlers and the dead pre-manifest `Job/RunJob.vue`, `Job/TestJob.vue`,
`Synchronization/RunSynchronization.vue` and
`Synchronization/TestSynchronization.vue`.

The guard reporting reads `result.objects.deletionGuard`
(`{ guarded, reason, ratio, threshold, candidateCount, totalContracts }`),
which `SynchronizationService` already populates alongside the event it
dispatches and the NC warning it logs. It is null when the cleanup pass never
ran — a dry run skips deletion entirely rather than guarding it — which is not
the same as `guarded: false` and must not be reported as a guarded run.
