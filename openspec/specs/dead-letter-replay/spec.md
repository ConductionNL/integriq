# dead-letter-replay Specification

## Purpose
TBD - created by archiving change openconnector-dead-letter-replay. Update Purpose after archive.
## Requirements
### Requirement: Dead-letter listing with filters and pagination (REQ-DLR-001)

The system SHALL expose an admin-only, CSRF-protected endpoint
`GET /api/events/dead-letter` listing `event_message` objects whose `status`
is in the requested status set (default `failed,abandoned`; `discarded` only
when explicitly requested). The endpoint MUST support `subscriptionId`,
`from`/`to` (on `lastAttempt`), and `status` filters, plus `limit` (default
50) and `offset` (default 0), and MUST return for each row at least: message
UUID, event type, subscription id + sink, `status`, `retryCount`,
`lastAttempt`, `nextAttempt`. The endpoint MUST NOT carry `@NoAdminRequired`
or `@NoCSRFRequired`.

#### Scenario: default listing returns failed and abandoned messages only

- **GIVEN** messages in states `pending`, `delivered`, `failed`, `abandoned`,
  and `discarded`
- **WHEN** an admin calls `GET /api/events/dead-letter`
- **THEN** the response SHALL contain only the `failed` and `abandoned`
  messages

#### Scenario: filtering by subscription narrows the list

- **GIVEN** abandoned messages for subscriptions `S1` and `S2`
- **WHEN** an admin calls `GET /api/events/dead-letter?subscriptionId=S1`
- **THEN** only `S1` messages SHALL be returned

#### Scenario: a non-admin user is rejected

- **GIVEN** an authenticated non-admin user
- **WHEN** they call any `/api/events/dead-letter*` endpoint
- **THEN** the request SHALL be rejected by NC's admin requirement (no
  `@NoAdminRequired` on these methods)

### Requirement: Dead-letter message inspection (REQ-DLR-002)

`GET /api/events/dead-letter/{id}` SHALL return the full message detail:
the CloudEvent payload, the complete `attempts[]` history in chronological
order, replay/discard audit fields when present, and the resolved
subscription context (`sink`, `protocol`, subscription `status`). It SHALL
return 404 when the message does not exist.

#### Scenario: the detail view explains why a message died

- **GIVEN** an `abandoned` message whose `attempts[]` records four HTTP 503
  entries and one timeout
- **WHEN** an admin fetches its detail
- **THEN** the response SHALL include all five attempt entries with their
  timestamps and `statusCode`/`error` values, the payload, and the
  subscription's sink URL

### Requirement: Audited replay returning the message to the delivery machine (REQ-DLR-003)

`POST /api/events/dead-letter/{id}/replay` SHALL, for a message in `failed` or
`abandoned` state: set `status='pending'`, `retryCount=0`, `nextAttempt=now`,
stamp `replayedBy` (the acting admin's uid) and `replayedAt` (ISO 8601),
PRESERVE the existing `attempts[]` history, trigger one immediate delivery attempt using the message's
resolved `action.kind` (`events-cloudevents` REQ-008) — `deliverMessage` for `webhook` (the default,
unchanged from before this change), `SynchronizationService::synchronize` for `synchronization`,
`JobService::executeJob` for `job` — and return the updated message. Replay on a message
in `pending`, `delivered`, or `discarded` state SHALL return 409. A replayed
message that fails again follows the standard retry/backoff/abandon machine unchanged (`events-cloudevents`
REQ-002/REQ-009).

#### Scenario: replaying an abandoned message after sink recovery delivers it

- **GIVEN** an `abandoned` message (`action.kind` absent ⇒ webhook) and a sink that is now healthy
- **WHEN** an admin replays it
- **THEN** the message SHALL be persisted with `replayedBy` = the admin's uid
  and re-enter delivery via `deliverMessage`
- **AND** it SHALL reach `status='delivered'` with its prior failure
  `attempts[]` entries still present

#### Scenario: replaying a delivered message is rejected

- **GIVEN** a message with `status='delivered'`
- **WHEN** an admin calls replay on it
- **THEN** the response SHALL be HTTP 409 and the message SHALL be unchanged

#### Scenario: replaying an abandoned synchronization-action message re-runs the synchronization, not an HTTP call

- **GIVEN** an `abandoned` message whose subscription has `action = {kind: 'synchronization',
  synchronizationId: '<uuid>'}`
- **WHEN** an admin replays it
- **THEN** `SynchronizationService::synchronize` SHALL be invoked with the resolved synchronization
- **AND** no HTTP request SHALL be made to any `sink`
- **AND** on success the message SHALL be persisted `status='delivered'` with `replayedBy` set

### Requirement: Audited discard as a distinct terminal state (REQ-DLR-004)

`POST /api/events/dead-letter/{id}/discard` SHALL, for a message in `failed`
or `abandoned` state: set `status='discarded'`, `nextAttempt=null`, stamp
`discardedBy`/`discardedAt`, and return the updated message. Discarded
messages MUST be excluded from retry sweeps and from the default dead-letter
listing, MUST remain retrievable via the status filter and the detail
endpoint, and MUST NOT be hard-deleted by the discard verb. Discard on
`pending`/`delivered`/`discarded` messages SHALL return 409.

#### Scenario: a discarded message never delivers and shows its decider

- **GIVEN** an `abandoned` message discarded by admin `alice`
- **WHEN** subsequent retry sweeps run AND the message detail is fetched with
  `status=discarded`
- **THEN** no delivery SHALL be attempted
- **AND** the detail SHALL show `discardedBy='alice'` and `discardedAt`

### Requirement: Bulk replay and discard with per-item outcomes (REQ-DLR-005)

Bulk replay and discard MUST accept an explicit, capped id set and report
per-item outcomes. `POST /api/events/dead-letter/replay` and
`POST /api/events/dead-letter/discard`
SHALL accept `{ids: string[]}` with at most 100 UUIDs, apply the corresponding
single-message semantics (REQ-DLR-003 / REQ-DLR-004) per id, and return a
per-id result map (`ok` | error reason, e.g. `not-found`, `invalid-state`).
A partial failure MUST NOT abort the remaining ids. Requests with more than
100 ids SHALL be rejected with 400. The endpoints MUST NOT accept a
filter-predicate form ("replay everything matching X").

#### Scenario: bulk replay reports mixed outcomes

- **GIVEN** ids `[A (abandoned), B (delivered), C (nonexistent)]`
- **WHEN** an admin posts them to bulk replay
- **THEN** the response SHALL report `A: ok`, `B: invalid-state`,
  `C: not-found`
- **AND** message `A` SHALL be replayed despite B and C failing

### Requirement: Dead-letter UI in the Events section (REQ-DLR-006)

The app's Events section SHALL contain an "Event deliveries" sub-view
rendering the dead-letter listing with status badges and the
subscription/status/time filters, a per-message detail modal (its own file
under `src/modals/`) showing the payload and the attempt timeline, and
Replay / Discard actions (per row and via bulk selection) each guarded by a
confirmation step. Empty state, loading state, and per-action success/error
feedback MUST be present.

#### Scenario: operator inspects and replays a dead letter from the UI

- **GIVEN** an admin on the Event deliveries view with one abandoned message
- **WHEN** they open the message's detail modal and confirm "Replay"
- **THEN** the modal SHALL show the attempt timeline before the action
- **AND** the list SHALL reflect the message leaving the dead-letter set after
  a successful redelivery

#### Scenario: bulk discard requires confirmation

- **GIVEN** an admin who selected three abandoned messages
- **WHEN** they trigger the bulk Discard action
- **THEN** a confirmation step SHALL be shown before any request is sent
- **AND** on confirm the three rows SHALL disappear from the default listing

#### Scenario: empty dead-letter queue shows an empty state

- **GIVEN** no failed or abandoned messages exist
- **WHEN** an admin opens the Event deliveries view
- **THEN** an empty state SHALL be rendered instead of an empty table

### Requirement: Dead-letter listing and detail MUST surface action kind and Nextcloud-event provenance (REQ-DLR-013)

Dead-letter listing and detail responses MUST include, per message, the resolved `action.kind`
(defaulting to `webhook` when the subscription has no `action`). The listing endpoint (`GET
/api/events/dead-letter`) and detail endpoint (`GET /api/events/dead-letter/{id}`, REQ-DLR-001/REQ-DLR-002)
MUST both carry this field, and, when the message's
`event.source` begins with `/nextcloud/` (the source prefix `nextcloud-event-triggers`
REQ-001–REQ-004 producers use — `/nextcloud/files`, `/nextcloud/calendar`, `/nextcloud/tables`,
`/nextcloud/forms`), a provenance marker identifying it as Nextcloud-native. `event.source` is the correct
discriminator here rather than `event.type`: the pre-existing OR-object producer already uses a
`com.nextcloud.openregister.object.*` type prefix (REQ-004 in `events-cloudevents`), so a type-prefix-based
`com.nextcloud.` check would incorrectly also match OR-object events — `source` values do not collide
(`/objects/<type>` for OR events vs `/nextcloud/<domain>` for the new producers). The "Event deliveries" UI
(REQ-DLR-006) MUST render an action-kind badge (webhook/synchronization/job) per row and MUST offer a filter
to narrow the list to Nextcloud-native events using this `source`-based marker.

#### Scenario: the dead-letter list shows the action kind per row

- **GIVEN** three dead-lettered messages with `action.kind` of `webhook`, `synchronization`, and `job`
  respectively
- **WHEN** an admin opens the Event deliveries view
- **THEN** each row SHALL display a badge matching its own `action.kind`

#### Scenario: an admin filters the dead-letter list to Nextcloud-native events only

- **GIVEN** a mix of messages whose underlying `event.source` is `/nextcloud/files` and messages whose
  `event.source` is `/objects/person` (an OR-object event, `type = 'com.nextcloud.openregister.object.created'`)
- **WHEN** an admin applies the "Nextcloud event" provenance filter
- **THEN** only messages whose `event.source` begins with `/nextcloud/` SHALL remain in the list
- **AND** the OR-object messages (despite their `com.nextcloud.` type prefix) SHALL NOT be included
### Requirement: Sync-item dead-letter listing with filters and pagination (REQ-DLR-007)

The system SHALL expose an admin-only, CSRF-protected endpoint
`GET /api/sync-dead-letter` listing `sync_item_dead_letter` objects. The
endpoint MUST support `synchronizationId`, `from`/`to` (on `created`), and
`status` (default `failed`) filters, plus `limit` (default 50) and `offset`
(default 0), and MUST return for each row at least: entry UUID,
synchronization id + name, `originId`, `status`, `retryCount`, `created`,
and a truncated `error` preview. The endpoint MUST NOT carry
`@NoAdminRequired` or `@NoCSRFRequired`.

#### Scenario: default listing returns failed items only

- **GIVEN** `sync_item_dead_letter` entries in states `failed`, `replayed`,
  and `discarded`
- **WHEN** an admin calls `GET /api/sync-dead-letter`
- **THEN** the response SHALL contain only the `failed` entries

#### Scenario: filtering by synchronization narrows the list

- **GIVEN** failed entries for synchronizations `S1` and `S2`
- **WHEN** an admin calls `GET /api/sync-dead-letter?synchronizationId=S1`
- **THEN** only `S1` entries SHALL be returned

#### Scenario: a non-admin user is rejected

- **GIVEN** an authenticated non-admin user
- **WHEN** they call any `/api/sync-dead-letter*` endpoint
- **THEN** the request SHALL be rejected by NC's admin requirement

### Requirement: Sync-item dead-letter inspection (REQ-DLR-008)

`GET /api/sync-dead-letter/{id}` SHALL return the full entry detail: the
`payload` (raw source object at failure time), `error`, the complete
`attempts[]` history in chronological order, `phase`, replay/discard audit
fields when present, and the resolved synchronization context (name,
source, target). It SHALL return 404 when the entry does not exist.

#### Scenario: the detail view explains why an item died

- **GIVEN** a `failed` entry whose `error` records a mapping exception
  message
- **WHEN** an admin fetches its detail
- **THEN** the response SHALL include the `payload`, `error`, `phase`, and
  the synchronization's name and source/target identifiers

### Requirement: Audited manual replay of a dead-lettered sync item (REQ-DLR-009)

`POST /api/sync-dead-letter/{id}/replay` SHALL, for an entry in `failed`
state: re-invoke `processSynchronizationObject()` for the entry's `payload`
against its synchronization; on success, set `status='replayed'`, stamp
`replayedBy` (the acting admin's uid) and `replayedAt` (ISO 8601), and
PRESERVE the existing `attempts[]` history; on renewed failure, append a new
`attempts[]` entry, increment `retryCount`, and leave `status='failed'`.
Replay on an entry in `replayed` or `discarded` state SHALL return 409.
Unlike event-message replay (REQ-DLR-003), a sync-item replay is a
synchronous, immediate re-attempt only — there is no automatic re-entry into
a scheduled backoff state, per `synchronization-engine` REQ-008.

#### Scenario: replaying a failed item after a mapping fix succeeds

- **GIVEN** a `failed` entry whose original mapping bug has since been
  corrected
- **WHEN** an admin replays it
- **THEN** the entry SHALL be persisted with `status='replayed'` and
  `replayedBy` = the admin's uid
- **AND** the corresponding `synchronization_contract` SHALL be
  created/updated as if the item had succeeded on first processing

#### Scenario: replaying an already-replayed entry is rejected

- **GIVEN** an entry with `status='replayed'`
- **WHEN** an admin calls replay on it again
- **THEN** the response SHALL be HTTP 409 and the entry SHALL be unchanged

#### Scenario: a repeat failure on replay is recorded without abandoning

- **GIVEN** a `failed` entry whose underlying data is still invalid
- **WHEN** an admin replays it
- **THEN** `status` SHALL remain `failed`
- **AND** `retryCount` SHALL increment and a new `attempts[]` entry SHALL be
  appended

### Requirement: Audited discard of a dead-lettered sync item (REQ-DLR-010)

`POST /api/sync-dead-letter/{id}/discard` SHALL, for an entry in `failed`
state: set `status='discarded'`, stamp `discardedBy`/`discardedAt`, and
return the updated entry. Discarded entries MUST be excluded from the
default listing, MUST remain retrievable via the status filter and the
detail endpoint, and MUST NOT be hard-deleted. Discard on a `replayed` or
already-`discarded` entry SHALL return 409.

#### Scenario: a discarded item is excluded from the default listing

- **GIVEN** a `failed` entry discarded by admin `alice`
- **WHEN** the default dead-letter listing is fetched
- **THEN** the discarded entry SHALL NOT appear
- **AND** its detail SHALL show `discardedBy='alice'` and `discardedAt`

### Requirement: Bulk replay and discard for sync-item dead letters (REQ-DLR-011)

Bulk replay and discard MUST accept an explicit, capped id set and report
per-item outcomes. `POST /api/sync-dead-letter/replay` and
`POST /api/sync-dead-letter/discard` SHALL accept `{ids: string[]}` with at
most 100 UUIDs, apply the corresponding single-entry semantics (REQ-DLR-009 /
REQ-DLR-010) per id, and return a per-id result map (`ok` | error reason,
e.g. `not-found`, `invalid-state`). A partial failure MUST NOT abort the
remaining ids. Requests with more than 100 ids SHALL be rejected with 400.

#### Scenario: bulk replay reports mixed outcomes

- **GIVEN** ids `[A (failed), B (discarded), C (nonexistent)]`
- **WHEN** an admin posts them to bulk replay
- **THEN** the response SHALL report `A: ok`, `B: invalid-state`,
  `C: not-found`
- **AND** entry `A` SHALL be replayed despite B and C failing

### Requirement: Sync-item dead-letter UI in the Synchronizations section (REQ-DLR-012)

The app's Synchronizations section SHALL contain a "Sync dead letters"
sub-view rendering the dead-letter listing with status badges and the
synchronization/status/time filters, a per-entry detail modal (its own file
under `src/modals/`) showing the payload and the attempt timeline, and
Replay / Discard actions (per row and via bulk selection) each guarded by a
confirmation step. Empty state, loading state, and per-action success/error
feedback MUST be present. This reuses the same UI pattern already shipped
for `event_message` dead letters (REQ-DLR-006).

#### Scenario: operator inspects and replays a dead-lettered sync item

- **GIVEN** an admin on the Sync dead letters view with one failed entry
- **WHEN** they open the entry's detail modal and confirm "Replay"
- **THEN** the modal SHALL show the payload and prior attempts before the
  action
- **AND** the list SHALL reflect the entry leaving the dead-letter set after
  a successful replay

#### Scenario: empty sync dead-letter queue shows an empty state

- **GIVEN** no failed sync-item entries exist
- **WHEN** an admin opens the Sync dead letters view
- **THEN** an empty state SHALL be rendered instead of an empty table

