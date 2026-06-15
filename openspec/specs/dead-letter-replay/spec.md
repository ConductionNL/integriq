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
PRESERVE the existing `attempts[]` history, trigger one immediate
`deliverMessage` attempt, and return the updated message. Replay on a message
in `pending`, `delivered`, or `discarded` state SHALL return 409. A replayed
message that fails again follows the standard retry/backoff/abandon machine
unchanged.

#### Scenario: replaying an abandoned message after sink recovery delivers it

- **GIVEN** an `abandoned` message and a sink that is now healthy
- **WHEN** an admin replays it
- **THEN** the message SHALL be persisted with `replayedBy` = the admin's uid
  and re-enter delivery
- **AND** it SHALL reach `status='delivered'` with its prior failure
  `attempts[]` entries still present

#### Scenario: replaying a delivered message is rejected

- **GIVEN** a message with `status='delivered'`
- **WHEN** an admin calls replay on it
- **THEN** the response SHALL be HTTP 409 and the message SHALL be unchanged

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

