# Design — dead-letter queue inspection and replay

## Context

`openconnector-event-retry-hardening` gives `event_message` a real lifecycle
(`pending → delivered | failed → abandoned`) and a per-attempt audit trail.
This change adds the operator surface on top. The guiding constraint: the DLQ
is a *view plus two verbs* over existing OR objects — no parallel queue table,
no copy-on-death, per ADR-022.

## Decisions

### D1 — The DLQ is a filter, not a place

"Dead-lettered" is defined as `status IN ('failed','abandoned')` (with
`abandoned` the primary population; `failed` rows are shown as "retrying" with
their `nextAttempt`, so operators see trouble *before* exhaustion). Messages
are never moved or duplicated into a queue structure.

**Alternative considered**: a separate `dead_letter` schema populated on
abandonment. Rejected — duplicates state, creates a sync surface between the
copy and the source message, and OR querying over the existing collection is
already cheap.

### D2 — Replay = reset to `pending`, history preserved

`replayMessage` sets `status='pending'`, `retryCount=0`, `nextAttempt=now`,
stamps `replayedBy` (session user uid) + `replayedAt`, and leaves `attempts[]`
intact — then triggers one immediate `deliverMessage`. The message re-enters
the standard machine; replay does NOT get bespoke delivery code. A replayed
message that dies again simply abandons again, with a visibly longer
`attempts[]` showing both campaigns.

**Alternative considered**: clone-and-replay (new message, original kept as a
tombstone). Rejected — doubles the rows an operator must reason about and
breaks the (Event × Subscription) identity the schema documents.

### D3 — Discard is a distinct terminal state, not deletion

`discarded` (+ `discardedBy`/`discardedAt`) rather than deleting the row:
an event bus must be able to show "we received this, matched it, failed to
deliver it, and a named operator decided to drop it" — that is audit-trail
material. Deletion remains available through the existing generic object
lifecycle/retention path; the DLQ verbs never hard-delete. `discarded` rows
are excluded from the default DLQ listing but reachable via the status filter.

### D4 — Admin-gated, CSRF-on, bulk verbs capped

All DLQ endpoints are admin-only (no `@NoAdminRequired`) and keep NC's CSRF
protection (no `@NoCSRFRequired`). The retrofit spec flags the existing
`EventsController` posture as an IDOR + CSRF hazard; the DLQ — which can
re-fire deliveries at arbitrary sinks — must not inherit it. Bulk replay and
bulk discard accept an explicit array of message UUIDs (max 100 per call), not
a server-side "all matching filter" predicate: replaying an unbounded set on a
stale filter is exactly the kind of foot-gun a confirmation modal cannot fix.
The response reports per-UUID success/failure so partial bulk outcomes are
visible.

### D5 — UI lives in the Events section as a sub-view

One "Event deliveries" list view (filters: status, subscription, time window;
columns: event type, subscription/sink, status badge, retryCount,
lastAttempt) + one detail modal in its own file under `src/modals/`
(modal-isolation gate) with payload (pretty-printed JSON), the attempt
timeline, and Replay/Discard buttons. Bulk actions via list selection.
No new top-level nav entry: dead letters are an *Events* operational concern,
and the Events section already exists in the app shell.

### D6 — `failed` rows get Replay too

Replay on a `failed` (still-retrying) message is allowed and simply means
"retry now": same reset semantics. This avoids the operator dance of waiting
for abandonment before being allowed to act during an incident.

## Reuse analysis

| Need | Existing surface | Strategy |
|---|---|---|
| DLQ storage + filtering | `event_message` OR objects | Reused — listing is a filtered query |
| Delivery on replay | `EventService::deliverMessage` + retry machine (retry-hardening change) | Reused unchanged — replay only resets state |
| Attempt diagnostics | `attempts[]` (retry-hardening REQ-006) | Displayed, never recomputed |
| Ops alerting hook | `delivery-retries-exhausted` notification (openconnector-notifications) | Notification deep-links operators to this view |
| Modal/UI conventions | app shell Events section, `src/modals/` pattern | Followed |

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Replaying a poisonous message re-poisons the sink | Replay is deliberate, admin-only, per-message audited; attempts[] shows prior outcomes before the operator clicks |
| Bulk replay storms a recovering sink | 100-UUID cap per call; deliveries beyond the immediate attempt pace through the backoff machine |
| `discarded` rows accumulate | Covered by the existing log/object retention machinery (logs-and-statistics REQ-004); not a new growth class |
| Operators expect a sync-item DLQ here too | Explicit non-goal in proposal; follow-up `openconnector-sync-dead-letter` named |
