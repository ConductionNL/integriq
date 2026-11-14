# Design — absorb-dossiq-deliveries

## Landing zone: the CloudEvents pipeline, not a new engine

The integriq audit ranked four landing zones for a sibling app's delivery: a Flow node, an
`event_subscription` action, a bespoke provider quintet, and raw `CallService`. The seam lands on
the **event pipeline** because the requesting context is a backend transition handler (no user
session for the sibling-push controllers, no admin-authored flow at the dispatch site), and because
the pipeline already owns exactly the semantics a delivery needs: per-subscription retry policy,
exponential backoff, dead-letter + replay UI, HMAC signing, and status bookkeeping on
`event_message`. A flow can still do the actual transport — `action.kind = 'flow'` on the matching
subscription — so the seam composes with the wave-3 direction instead of competing with it.

The legacy runners (`SynchronizationService`, `RuleService`, `JobService`, `FlowRunnerService`) are
never called directly by the seam; they are reachable only as subscription actions that already
existed.

## The provenance gate

`ingestDeliveryRequest()` writes the request's provenance into the event's `data.delivery` block.
`createEventMessage()` embeds the event's serialization in `event_message.payload`, so the terminal
hooks (`recordDeliverySuccess`, terminal `recordFailure`) can read
`payload.data.delivery.{sourceApp,correlationId}` without a second lookup. That block is the gate:
present → dispatch `DeliveryConcludedEvent`; absent → ordinary CloudEvent traffic, no conclusion.
`recordConfigurationError` does not conclude — a config error is operator-fixable and replayable,
not terminal.

## Result-slot honesty

`setMatchedSubscriptions()` exists so "accepted" and "will actually travel" are distinguishable.
Zero matches means the instance has no route for this delivery — the consumer records `unrouted`
and an operator configures a subscription; nothing pretends to deliver. This is the
fail-closed-refusal shape the fleet ruling requires.

## Constructor compatibility

`IEventDispatcher` joins the constructor as a nullable, defaulted final parameter — the same
pattern `ExecutionTraceService` used — so every pre-existing positional test instantiation keeps
working and DI supplies the real dispatcher in production. A null dispatcher simply skips
conclusions (unit-test contexts); the listener half is unaffected.

## Replay semantics

`replayMessage()` can revive an abandoned message. If the replay succeeds, a second conclusion
(`delivered`) is dispatched and supersedes the earlier `abandoned` at the consumer — consumers
MUST project last-terminal-state-wins (dossiq's listener does). This is deliberate: the message
record and the consumer's projection converge without a tombstone protocol.
