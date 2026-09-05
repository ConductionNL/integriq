# delivery-intake Specification

**Status:** proposed
**Scope:** integriq
**Tier:** V1
**Depends on:** `events-cloudevents` spec (the `event`/`event_subscription`/`event_message`
pipeline this seam rides), Nextcloud `OCP\EventDispatcher\IEventDispatcher`.

## Purpose

The ADR-041 cross-app delivery seam: a sibling Conduction app composes WHAT must be delivered and
raises a typed event; integriq owns HOW it travels by fanning the request out through its
CloudEvents pipeline, and answers with a terminal conclusion the consumer projects onto its own
domain record.

@e2e exclude The seam is a backend-only in-process typed-event exchange with no integriq browser
surface of its own: requests and conclusions surface in the existing Events / DeadLetters pages,
which have their own coverage. The seam behaviours are proven by the PHPUnit suites
(EventServiceDeliverySeamTest, DeliveryRequestedListenerTest) on this side and dossiq's
PublicationServiceTest / DeliveryConcludedListenerTest on the consumer side.

## ADDED Requirements

### Requirement: A delivery request is a typed event with a synchronous result slot

Integriq SHALL expose `OCA\Integriq\Event\DeliveryRequestedEvent` carrying provenance
(`sourceApp`, `subjectRegister`, `subjectSchema`, `subjectId`, `subjectLabel`), a `deliveryKind`,
a `channel`, a caller-composed `payload`, a `correlationId`, and optional `externalReference` /
`userId`. The in-process listener SHALL write the result slot: `setHandled(true)`, the persisted
CloudEvent uuid via `setResultId()`, and the matched-subscription count via
`setMatchedSubscriptions()`. On ingest failure the event SHALL stay unhandled so the consumer
fail-closes.

#### Scenario: A handled request carries the result slot

- **GIVEN** the CloudEvents pipeline persists the request and one subscription matches
- **WHEN** the listener handles a `DeliveryRequestedEvent`
- **THEN** `isHandled()` MUST be true, `getResultId()` MUST be the event uuid, and
  `getMatchedSubscriptions()` MUST be 1

#### Scenario: An ingest failure leaves the request unhandled

- **WHEN** persisting or fanning out the request throws
- **THEN** the event MUST stay unhandled and MUST carry no result id

### Requirement: Delivery requests ride the CloudEvents pipeline unchanged

The listener SHALL persist the request as an `event` object of type
`nl.conduction.delivery.requested` with source `/apps/<sourceApp>/delivery`, the subject id as the
CloudEvents subject, and a `data.delivery` block carrying the full provenance, then fan it out via
`processEvent()`. Routing, retry, backoff, dead-letter, replay and HMAC signing SHALL be the
existing `event_subscription` / `event_message` machinery — no delivery-specific engine, and no
direct call into the legacy synchronization/rule/job runners.

#### Scenario: The persisted event carries provenance

- **WHEN** a request from `dossiq` for channel `gemeenteblad` is ingested
- **THEN** the persisted event MUST have type `nl.conduction.delivery.requested`, source
  `/apps/dossiq/delivery`, and `data.delivery.sourceApp = 'dossiq'` with the correlation id

### Requirement: A provenance-carrying delivery concludes with a typed terminal event

When an `event_message` whose originating event carries a `data.delivery` provenance block reaches
a terminal state, integriq SHALL dispatch `OCA\Integriq\Event\DeliveryConcludedEvent` —
`delivered` from the success path, `abandoned` when the retry budget is spent — echoing
`sourceApp`, `correlationId`, `subjectId` and `channel`, with the attempt count, the last error (or
null) and the terminal timestamp. Ordinary CloudEvent traffic without the provenance block SHALL
never produce a conclusion, a non-terminal failure SHALL not conclude, and a conclusion-dispatch
failure SHALL be logged and swallowed — the message record stays the source of truth.

#### Scenario: Success concludes delivered

- **GIVEN** a pending message whose event data carries `delivery.sourceApp` and `correlationId`
- **WHEN** delivery succeeds
- **THEN** a `DeliveryConcludedEvent` with status `delivered` and the echoed correlation id MUST be
  dispatched

#### Scenario: A spent retry budget concludes abandoned

- **WHEN** a provenance-carrying message fails with no retries remaining
- **THEN** a `DeliveryConcludedEvent` with status `abandoned` and the last error MUST be dispatched

#### Scenario: Ordinary traffic never concludes

- **WHEN** a message without a `data.delivery` provenance block reaches any terminal state
- **THEN** no `DeliveryConcludedEvent` is dispatched
