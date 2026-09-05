# Tasks — absorb dossiq deliveries: the ADR-041 delivery seam

## Phase 1: The delivery seam (this PR)

- [x] `lib/Event/DeliveryRequestedEvent.php` — provenance + payload + synchronous result slot
      (`setHandled`/`isHandled`, `setResultId`/`getResultId`, `setMatchedSubscriptions`).
- [x] `lib/Event/DeliveryConcludedEvent.php` — terminal outcome envelope (`delivered` /
      `abandoned`, attempts, error, concludedAt), echoing sourceApp + correlationId + subject.
- [x] `lib/EventListener/DeliveryRequestedListener.php` — ingest via
      `EventService::ingestDeliveryRequest()`, write the result slot; leave unhandled on ingest
      failure so the consumer fail-closes.
- [x] `EventService::ingestDeliveryRequest()` — persist the `nl.conduction.delivery.requested`
      CloudEvent with the `data.delivery` provenance block, fan out via `processEvent()`.
- [x] `EventService::dispatchDeliveryConcluded()` — dispatched from `recordDeliverySuccess()` and
      the terminal (`abandoned`) branch of `recordFailure()`, gated to provenance-carrying
      messages; new nullable `IEventDispatcher` constructor dependency.
- [x] Register the listener in `Application::boot()`.
- [x] Unit tests: ingest event shape, delivered dispatch, abandoned dispatch with error,
      no dispatch on non-terminal failure, no dispatch without provenance, listener result-slot
      write-back, listener unhandled-on-failure, foreign-event ignore.

## Phase 2: Intake halves of dossiq's staged extractions — staged

- [ ] **StUF endpoint/credential migration intake.** Blocked on: migration design — dossiq's
      `stufEndpoint` objects hold `vault://` refs resolved via dossiq `IAppConfig`; integriq
      sources resolve through the OpenRegister credential broker. Needs a documented mapping
      (dossiq repair step writes `source` objects `type=stuf-zkn` with broker refs; secrets are
      re-entered or brokered, never copied blind). Tracked jointly with dossiq
      `dossiq-delivers-nothing` phase 2.
- [ ] **Per-callback ZGW notificaties routing.** Blocked on: a design decision — dossiq's
      notificaties fan-out is per-abonnement callback URLs; the seam carries one delivery request,
      while subscriptions are admin-configured. Either the `notificaties` action kind gains
      callback-from-payload support, or dossiq raises one request per callback. Decide before
      dossiq phase 3 lands.
- [ ] **Berichtenbox (MijnOverheid) transport.** Blocked on: commissioning — no production
      transport exists anywhere in the fleet (dossiq ships only a MockAdapter). When built, it is
      an integriq provider quintet (controller + provider seam + sync service + `*_message` schema
      + retry job, the StufZkn/IwmoIjw pattern) addressed by `deliveryKind: 'berichtenbox'`.
- [ ] **DROP/LVBB publication transport.** Blocked on: commissioning — no DROP/LVBB transport
      exists in dossiq to move (its PublicationService was record-only); a real
      bekendmaking-via-DROP delivery is new integriq work, addressed by the existing
      `deliveryKind: 'besluit-publication'` routing on channel `gemeenteblad`.
