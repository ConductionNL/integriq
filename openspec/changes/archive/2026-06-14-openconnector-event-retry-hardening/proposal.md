---
kind: feature
depends_on: []
---

# openconnector — event-delivery retry hardening

## Why

OpenConnector's pitch includes "send and receive cloud events", but the shipped
retry semantics are broken in four compounding ways — all documented as
observed-but-flagged in the `events-cloudevents` retrofit spec (REQ-002 Notes):

1. **`retryCount` is never incremented.** `EventService::processRetries()` reads
   the field to enforce the `maxRetries` cap but no code path ever writes it.
   A message that fails delivery is re-attempted indefinitely.
2. **Failed messages are never retried at all.** `deliverMessage()` persists
   `status='failed'` on a non-2xx/exception, but `processRetries()` scans only
   `status='pending'` — so the messages that most need a retry are exactly the
   ones the sweep skips.
3. **There is no terminal state transition.** The `event_message` schema itself
   documents the intended lifecycle (`pending, delivered, failed, abandoned`)
   and says `nextAttempt` is "null when terminal state reached" — but no code
   ever sets `abandoned`, and `lastAttempt`/`nextAttempt` are never written.
   The data model anticipates backoff scheduling; the service ignores it.
4. **Nothing invokes the sweep.** `processRetries()` has zero callers — no
   background job, no controller, nothing. Even the broken sweep never runs.

The net effect: a sink outage produces messages that either retry forever (if
left `pending`) or silently die on first failure (once marked `failed`), and the
already-shipped `delivery-retries-exhausted` notification rule (threshold on
`retryCount >= 5`, from change `openconnector-notifications`) can never fire
because `retryCount` is frozen at 0. For an event bus, correct at-least-once
delivery with bounded retries and a terminal dead state is table stakes
(CloudEvents subscriptions, Stripe/GitHub webhook semantics, every ESB).

## What Changes

Make `EventService` honor the lifecycle its own schema declares:

- **`deliverMessage()` failure path**: increment `retryCount`, write
  `lastAttempt` (ISO 8601), compute `nextAttempt` from an exponential backoff
  schedule (honouring a sink `Retry-After` header when larger), and transition
  to the terminal `abandoned` state (with `nextAttempt = null`) once
  `retryCount` reaches the cap.
- **`processRetries()` sweep**: scan `failed` messages (plus stale `pending`
  push messages that never got a first attempt) whose `nextAttempt <= now` and
  `retryCount < maxRetries`; never touch `delivered` or `abandoned` messages.
- **Attempt audit trail**: append a compact entry (timestamp, statusCode/error)
  to an `attempts[]` array on the message per delivery attempt, so operators
  (and the dead-letter UI in `openconnector-dead-letter-replay`) can see *why*
  a message died, not just that it did.
- **Retry sweep background job**: a NC `TimedJob` (interval 5 minutes) that
  invokes `processRetries()` — registered via `info.xml` `<background-jobs>`
  (NOT `IRegistrationContext`, which has no job registration method — see the
  fleet-wide invalid `registerJob` bug class).
- **Schema delta**: add `attempts` to the `event_message` schema in
  `lib/Settings/openconnector_register.json`; tighten the `status` description
  to the now-enforced enum.

## Capabilities

### Modified Capabilities
- `events-cloudevents`: REQ-002 (push delivery + retry sweep) is rewritten from
  observed-broken behaviour to working retry semantics: bounded exponential
  backoff, terminal `abandoned` state, `Retry-After` respect, attempt audit
  trail, and a scheduled sweep that actually runs.

## Impact

- **Code**: `lib/Service/EventService.php` (`deliverMessage`, `processRetries`),
  new `lib/Cron/EventRetryJob.php`, `appinfo/info.xml` (background-jobs entry).
- **Schema**: `lib/Settings/openconnector_register.json` — `event_message`
  gains `attempts`; no breaking field changes (`retryCount`, `lastAttempt`,
  `nextAttempt`, `status` already exist).
- **Downstream unlocks**:
  - The `delivery-retries-exhausted` notification rule
    (`openconnector-notifications`) becomes live — `retryCount` finally moves.
  - `openconnector-dead-letter-replay` (separate change, depends on this one)
    gets a real `abandoned` population to list and replay.
- **Behaviour change**: messages stop retrying forever. Deployments relying on
  infinite retry (none known — the sweep never ran) would see messages go
  `abandoned` after the cap; the dead-letter change provides the replay path.

## Out of scope

- Dead-letter inspection/replay UI and REST surface —
  `openconnector-dead-letter-replay`.
- Outbound payload signing — `openconnector-webhook-signing`.
- Fixing the REQ-005 IDOR / CSRF flags on `EventsController` (tracked by the
  security-review flags in the retrofit spec; orthogonal to retry semantics).
- Pull-style subscriptions (REQ-003) — pull consumers manage their own cursor;
  retry semantics apply to push delivery only.
