# dead-letter-replay Specification Delta

## ADDED Requirements

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
