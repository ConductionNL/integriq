# dead-letter-replay — Delta: action-aware replay and Nextcloud-event provenance

## Purpose

`nextcloud-event-hub` adds a subscription `action.kind` (`webhook`/`synchronization`/`job` — see
`events-cloudevents` REQ-008) so not every dead-lettered `event_message` represents a failed HTTP POST.
Replay MUST re-invoke the SAME kind of action that originally failed (re-running a synchronization, not
POSTing to a sink that was never used), and the dead-letter UI MUST let operators see, at a glance, what
kind of action failed and whether the underlying event came from a Nextcloud-native producer.

## MODIFIED Requirements

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

## ADDED Requirements

### Requirement: Dead-letter listing and detail MUST surface action kind and Nextcloud-event provenance (REQ-DLR-007)

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
