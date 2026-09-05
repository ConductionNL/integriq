# Proposal: absorb-dossiq-deliveries

kind: capability — cites **ADR-041** (hydra org-wide: cross-app commands via typed events),
**ADR-013** (event-bus model) and the `events-cloudevents` spec. Coupled to the dossiq change
`dossiq-delivers-nothing`, which ships the requesting half. Train order: this PR merges first — it
defines the event contract dossiq's `class_exists()`-guarded dispatch resolves; dossiq's half fails
closed until then, so no ordering breakage either way.

## Summary

Fleet ruling: **case apps keep no delivery code — integrations belong to integriq.** This change
gives integriq the receiving half of the ADR-041 delivery seam so a sibling app (dossiq first) can
hand over an outbound delivery and get an honest, terminal answer back:

1. **`OCA\Integriq\Event\DeliveryRequestedEvent`** — the typed cross-app command ("deliver this
   payload on my behalf"), carrying provenance (`sourceApp`, subject register/schema/id/label),
   `deliveryKind`, `channel`, a caller-composed payload, a `correlationId`, and a synchronous
   result slot (`isHandled` / `getResultId` / `getMatchedSubscriptions`).
2. **`DeliveryRequestedListener`** — ingests the request into the existing CloudEvents pipeline as
   a `nl.conduction.delivery.requested` event whose `data.delivery` block carries the provenance;
   admin-configured `event_subscription`s route it to a webhook / flow / synchronization /
   notificaties action and inherit retry, backoff, dead-letter, replay and HMAC signing unchanged.
   Zero matched subscriptions is reported honestly so the consumer fail-closes as "unrouted".
3. **`OCA\Integriq\Event\DeliveryConcludedEvent`** — dispatched from the `event_message` state
   machine when a provenance-carrying delivery reaches a terminal state: `delivered` on success,
   `abandoned` when the retry budget is spent. Ordinary CloudEvent traffic (no provenance block)
   never produces one. The consumer projects the outcome onto its own domain record (dossiq: the
   case's publication entry).

No new transport, no new engine: the seam is a thin typed-event skin over `EventService`, and it
deliberately does NOT touch the wave-3 retirement targets (`SynchronizationService`, `RuleService`,
`JobService`, `FlowRunnerService` are not called directly — a flow can still be the *subscription's
action*).

## Why

ADR-041 requires cross-app commands to travel as typed events defined by the target app; integriq
had no such contract (no ADR-041 recipe existed in this repo before this change). Meanwhile every
delivery-shaped surface dossiq carries is either unreachable, mocked, or retry-less, and integriq
already operates the machinery all of them need. The seam lets sibling apps shed transport without
integriq growing bespoke per-app code: one contract, provenance-routed subscriptions.

The sibling-push controllers (`stufZkn#outbound`, `iwmoIjw#createMessage`, ...) stay: they serve
session-carrying frontend calls. The event seam serves backend/flow contexts where a server-side
HTTP call would 401 (the exact phantom ADR-041 documents).

## What

1. `lib/Event/DeliveryRequestedEvent.php` + `lib/Event/DeliveryConcludedEvent.php` (new).
2. `lib/EventListener/DeliveryRequestedListener.php` (new), registered in `Application::boot()`.
3. `EventService::ingestDeliveryRequest()` (new public method): persists the provenance-carrying
   `event` object, fans out via `processEvent()`, returns event + created messages.
4. `EventService` terminal-state hooks: `recordDeliverySuccess()` and the terminal branch of
   `recordFailure()` dispatch `DeliveryConcludedEvent` via a new nullable `IEventDispatcher`
   constructor dependency (nullable + defaulted, same test-compatibility pattern as
   `ExecutionTraceService`). Dispatch failures are logged and swallowed — the message record stays
   the source of truth.
5. Unit tests: `EventServiceDeliverySeamTest` (ingest shape, delivered/abandoned dispatch, no
   dispatch without provenance or on non-terminal failure), `DeliveryRequestedListenerTest`
   (result-slot write-back, unhandled-on-ingest-failure, foreign-event ignore).

## Follow-ups staged in tasks.md

Phase 2 tracks the integriq-side halves of dossiq's staged extractions: StUF endpoint/credential
migration intake, a per-callback notificaties routing decision, and (on commission) real
Berichtenbox / DROP-LVBB transports as provider quintets. Each carries its blocker honestly.

## Non-goals

- A delivery-specific message schema: `event_message` + the CloudEvent `data.delivery` block
  already carry everything the seam needs (the `*_message` quintet pattern stays reserved for
  bespoke wire protocols with their own inbound leg).
- Replay semantics changes: a replayed abandoned message that later succeeds simply dispatches a
  second, superseding `delivered` conclusion — consumers project last-terminal-state-wins.
