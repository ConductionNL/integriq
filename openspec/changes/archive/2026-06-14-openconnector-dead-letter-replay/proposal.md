---
kind: feature
depends_on: [openconnector-event-retry-hardening]
---

# openconnector — dead-letter queue inspection and replay

## Why

Once `openconnector-event-retry-hardening` lands, event messages that exhaust
their retries transition to a terminal `abandoned` state — and then nothing.
There is no operator surface to answer the three questions every integration
platform (n8n, Camel, Mulesoft, Zapier, every message broker with a DLQ) treats
as table stakes:

1. **What died?** — list failed/abandoned deliveries, filter by subscription
   and time window.
2. **Why did it die?** — inspect the payload, the per-attempt history
   (`attempts[]`), and the subscription/sink it was bound for.
3. **What do I do about it?** — replay (the sink was down, it's back) or
   discard (the message is obsolete/poisonous), individually or in bulk,
   with an audit of who decided what.

Today an operator's only option is raw OR object spelunking. The messages are
already OR-stored objects with all the state needed, so this is a thin,
high-leverage layer: a REST surface + an Events sub-view over existing data —
no new storage, per ADR-022.

This is also the operational answer to the `delivery-retries-exhausted`
notification (change `openconnector-notifications`): that notification tells
ops *that* deliveries are dying; this change gives the notification somewhere
to land — a dead-letter view where the problem can actually be resolved.

## What Changes

- **Dead-letter REST surface** (`EventMessagesController` or extension of the
  events API): list dead-lettered messages (`status IN ('failed','abandoned')`)
  with subscription/time/status filters + pagination; single-message detail
  (payload + attempts + subscription context); replay (single + bulk);
  discard (single + bulk). All endpoints admin-gated with CSRF intact —
  explicitly NOT inheriting the `@NoAdminRequired`/`@NoCSRFRequired` posture
  the retrofit spec flagged as an IDOR on `EventsController`.
- **Replay semantics**: reset the message to `pending` with `retryCount = 0`,
  `nextAttempt = now`, preserving the existing `attempts[]` history and
  stamping `replayedBy`/`replayedAt`; the message re-enters the normal
  delivery state machine (immediate attempt + sweep).
- **Discard semantics**: a second terminal state `discarded` (with
  `discardedBy`/`discardedAt`), excluded from sweeps and from the default
  dead-letter listing.
- **Dead-letter UI**: an "Event deliveries" sub-view in the app's Events
  section — dead-letter list with status badges and filters, a detail modal
  (own file under `src/modals/`, per the modal-isolation gate) showing payload
  + attempt timeline, per-row and bulk Replay/Discard actions with
  confirmation.
- **Schema delta**: `event_message` gains `replayedBy`, `replayedAt`,
  `discardedBy`, `discardedAt`; `status` enum gains `discarded`.

## Capabilities

### New Capabilities
- `dead-letter-replay`: operator-facing dead-letter queue over failed and
  abandoned event deliveries — listing, inspection, audited replay and
  discard, via REST and an Events-section UI.

## Impact

- **Code**: new controller methods + routes (`appinfo/routes.php`), small
  service additions (`EventService::replayMessage`, `discardMessage`), one
  Vue view + one modal + store wiring.
- **Schema**: additive fields on `event_message` in
  `lib/Settings/openconnector_register.json`.
- **Depends on**: `openconnector-event-retry-hardening` (the `abandoned`
  population and `attempts[]` history this surface displays).
- **No new storage**: the DLQ *is* the existing `event_message` collection,
  filtered — per ADR-022.

## Out of scope

- Dead-lettering of failed synchronization items: sync failures are
  per-contract and logged in `synchronization_log`; contracts have no terminal
  failure state to park/replay. A sync-side DLQ needs its own state-model work
  first — follow-up candidate `openconnector-sync-dead-letter`, not bundled
  here (different state machine, different replay semantics).
- Automatic re-replay policies (e.g. "replay all on sink recovery") — manual,
  audited operator action only in this iteration.
- Changes to the retry/backoff machine itself (owned by
  `openconnector-event-retry-hardening`).
