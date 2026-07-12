# peppol-access-point-connector Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- peppol-access-point-connector

## Purpose

OpenConnector connects to the Peppol network through a certified Access Point
(AP) so sibling apps can look up Peppol participants, transmit UBL documents,
and receive inbound documents without embedding an AS4/Peppol client. The
connector is an NL-infrastructure adapter in the same family as
`digikoppeling-adapter`: it is delivered as a source/adapter configuration
(ADR-017), resolves AP credentials through the OpenRegister credential broker
(ADR-007), and integrates via CloudEvents (`events-cloudevents`) and the
inbound `webhook_signature` rule (`webhook-signing`) — not a parallel
framework. Per ADR-022 the Peppol integration lives here; leaf apps (e.g.
shillinq) consume it via events and its REST surface.

## ADDED Requirements

### Requirement: Peppol participant / SMP lookup endpoint (REQ-001)

OpenConnector MUST expose `GET /api/peppol/participants/{peppolId}` that
resolves a Peppol participant identifier (`scheme:identifier`, e.g.
`0192:1234567890`) against the configured AP's SMP/directory and returns
`{exists: bool, supportedDocTypes: string[]}`. The lookup MUST be performed
through the selected `PeppolAccessPointProviderInterface` binding (REQ-002), so
a `log`/sandbox source answers from canned data and a `rest` source answers
from the live AP. A malformed `peppolId` (not `scheme:identifier`) MUST return
HTTP 400 with a descriptive error, never a 500. When the AP is unreachable the
endpoint MUST return a descriptive error response, not a 500 crash. The endpoint
is the production binding for shillinq's
`PeppolTransmissionAdapterInterface::lookupParticipant`.

#### Scenario: a registered participant returns its supported document types

- GIVEN a Peppol source and a participant `0192:1234567890` registered at the AP
- WHEN `GET /api/peppol/participants/0192:1234567890` is called
- THEN the response SHALL be `{exists: true, supportedDocTypes: [...]}` listing
  at least `ubl-invoice-2.1`
- @e2e exclude backend SMP lookup via provider — covered by PHPUnit/Newman, no browser UI

#### Scenario: an unregistered participant returns exists=false

- GIVEN a participant id not present in the AP directory
- WHEN the lookup endpoint is called
- THEN the response SHALL be `{exists: false, supportedDocTypes: []}`
- @e2e exclude backend SMP lookup — covered by PHPUnit

#### Scenario: a malformed participant id is rejected with 400

- GIVEN a request for `GET /api/peppol/participants/not-a-peppol-id`
- WHEN the endpoint runs
- THEN the response SHALL be HTTP 400 with a descriptive error, not a 500
- @e2e exclude backend input validation — covered by PHPUnit

### Requirement: Access-point provider abstraction with log and generic-REST bindings (REQ-002)

The connector MUST define a `PeppolAccessPointProviderInterface`
(`lib/Service/Peppol/`) with at minimum `lookupParticipant(peppolId)` and
`submitDocument(recipientPeppolId, documentType, payload)` returning an AP
transmission id. A source's `configuration.provider` selects the binding:

- `log` — `LogPeppolAccessPointProvider`, a sandbox binding that performs no
  real network call, answers lookups from `configuration.mockParticipants`, and
  returns a synthetic `MOCK-PEPPOL-<n>` transmission id from `submitDocument`.
  It MUST NOT read any secret. It is the default for dev/CI and mirrors the
  `source-management` mock-mode convention.
- `rest` — `RestPeppolAccessPointProvider`, a generic REST AP binding driven by
  `configuration.baseUrl` and `authentication.credentialRef`; every outbound
  call MUST go through the credential broker (`BrokeredCallService`) so the AP
  API key is injected at call time and never stored in the source config.

The provider abstraction MUST be the single seam through which lookup and
transmission occur, so a new AP vendor is added by implementing the interface,
not by editing the transmission service.

#### Scenario: the log provider transmits without a network call or secret

- GIVEN a Peppol source with `configuration.provider: log`
- WHEN a document is submitted
- THEN a synthetic `MOCK-PEPPOL-<n>` transmission id SHALL be returned with no
  outbound HTTP call and no credential read
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the rest provider brokers its API key

- GIVEN a Peppol source with `configuration.provider: rest`,
  `configuration.baseUrl`, and `authentication.credentialRef`
- WHEN a document is submitted
- THEN the outbound call SHALL be dispatched through the credential broker with
  the AP API key injected at call time
- AND the API key SHALL NOT appear in the source configuration, exports, or logs
- @e2e exclude backend credential brokering — covered by PHPUnit

### Requirement: Event-driven outbound transmission with status lifecycle (REQ-003)

The connector MUST consume `nl.conduction.peppol.outbound.requested` events
(payload `{sourceApp, objectType, objectUri, recipientPeppolId, documentType,
payloadFileUri}`). On each event it MUST create a `peppol_transmission` OR
object with `status='queued'`, resolve the UBL payload from `payloadFileUri`,
and submit it via the selected provider (REQ-002). On a successful AP hand-off
it MUST record the AP `transmissionId` and move the transmission to `sent`. A
submission that throws MUST move the transmission to `failed` and follow the
existing retry machinery; a transmission that exhausts its retry budget MUST be
moved to the dead-letter surface (`dead-letter-replay`) for audited replay,
never silently dropped. Consuming the event MUST be idempotent per
`objectUri`+`documentType` so a redelivered request does not double-transmit.

#### Scenario: an outbound-requested event queues and transmits a document

- GIVEN a Peppol source and an emitted `nl.conduction.peppol.outbound.requested`
  for `recipientPeppolId=0192:1234567890`, `documentType=ubl-invoice-2.1`
- WHEN the connector consumes the event
- THEN a `peppol_transmission` SHALL be persisted `status='queued'` then `sent`
  carrying the AP `transmissionId`
- @e2e exclude backend event consumer + transmission — covered by PHPUnit

#### Scenario: an exhausted transmission is dead-lettered, not dropped

- GIVEN an outbound transmission whose provider submission keeps failing
- WHEN the retry budget is exhausted
- THEN the transmission SHALL land on the dead-letter surface for audited replay
- AND its status SHALL be `failed`
- @e2e exclude backend retry/dead-letter — covered by PHPUnit

#### Scenario: a redelivered request does not double-transmit

- GIVEN an `nl.conduction.peppol.outbound.requested` already transmitted for
  a given `objectUri`+`documentType`
- WHEN the same event is delivered again
- THEN no second AP submission SHALL occur (idempotent)
- @e2e exclude backend idempotency — covered by PHPUnit

### Requirement: Delivery-status CloudEvents on every state change (REQ-004)

On every `peppol_transmission` state change the connector MUST emit a
`nl.conduction.peppol.delivery.status` CloudEvent with payload `{objectUri,
transmissionId, status, timestamp, detail}` where `status` is one of
`queued|sent|delivered|rejected|failed`. Status changes originate both from the
outbound path (REQ-003: `queued`→`sent`/`failed`) and from inbound AP callbacks
(REQ-005: `delivered`/`rejected`). Events MUST be produced through the existing
`EventService` fan-out so any subscriber (e.g. shillinq) receives them via the
standard subscription/delivery machinery.

#### Scenario: each transition emits exactly one delivery-status event

- GIVEN a transmission that goes `queued` → `sent` → `delivered`
- WHEN the three transitions occur
- THEN exactly three `nl.conduction.peppol.delivery.status` events SHALL be
  emitted, each carrying the same `transmissionId` and the new `status`
- @e2e exclude backend event emission — covered by PHPUnit

#### Scenario: a rejection carries a detail message

- GIVEN an AP callback reporting a rejected transmission with a reason
- WHEN the status event is emitted
- THEN its payload SHALL have `status='rejected'` and a non-empty `detail`
- @e2e exclude backend event emission — covered by PHPUnit

### Requirement: Inbound receive webhook that republishes AP callbacks as events (REQ-005)

The connector MUST expose `POST /api/peppol/inbound` that accepts AP delivery
callbacks and inbound-document notifications. The endpoint MUST be protected by
the existing `webhook_signature` rule (HMAC over the raw body, constant-time
compare, timestamp tolerance) so an unsigned or tampered callback is rejected
with HTTP 401 before any side effect. A verified delivery callback for a known
`transmissionId` MUST update the corresponding `peppol_transmission` status
(`delivered`|`rejected`) and thereby emit a `nl.conduction.peppol.delivery.status`
event (REQ-004). A verified inbound-document notification MUST be republished as
a CloudEvent (`nl.conduction.peppol.inbound.received`, payload carrying the
sender participant id, document type, and a payload reference) so consuming apps
can fetch and process it. A callback for an unknown `transmissionId` MUST be
recorded and MUST NOT 500.

#### Scenario: a signed delivery callback advances the transmission

- GIVEN a `peppol_transmission` in `sent` state and a correctly signed AP
  callback reporting delivered for its `transmissionId`
- WHEN `POST /api/peppol/inbound` receives it
- THEN the transmission SHALL become `delivered` and a delivery-status event
  SHALL be emitted
- @e2e exclude backend inbound webhook — covered by PHPUnit/Newman

#### Scenario: an unsigned callback is rejected before any side effect

- GIVEN an inbound callback whose signature is missing or does not verify
- WHEN it arrives at the inbound endpoint
- THEN the response SHALL be HTTP 401
- AND no transmission SHALL change status and no event SHALL be emitted
- @e2e exclude backend signature gate — covered by PHPUnit

#### Scenario: an inbound document is republished as a CloudEvent

- GIVEN a signed inbound-document notification from sender `0192:9999999999`
- WHEN it is received
- THEN a `nl.conduction.peppol.inbound.received` CloudEvent SHALL be emitted
  carrying the sender id, document type, and a payload reference
- @e2e exclude backend republish — covered by PHPUnit

### Requirement: AP credentials brokered, never plaintext (REQ-006)

The AP API key / client secret MUST be resolved through the OpenRegister
credential broker via `authentication.credentialRef` and MUST NOT be stored as
plaintext in source configuration, exports, logs, or error messages (ADR-007).
When required key material cannot be supplied for the `rest` provider, the
connector MUST fail closed with an actionable configuration error and MUST NOT
fall back to a plaintext key. The `log` provider needs no secret and MUST remain
usable with none configured.

#### Scenario: the AP key is brokered and never appears in config or logs

- GIVEN a `rest` Peppol source configured with `authentication.credentialRef`
- WHEN a lookup or transmission is dispatched
- THEN the AP key SHALL be resolved through the credential broker
- AND the key SHALL NOT appear in source config, exports, logs, or errors
- @e2e exclude backend credential brokering — covered by PHPUnit

#### Scenario: absent key material fails closed with no plaintext fallback

- GIVEN a `rest` Peppol source whose `credentialRef` cannot supply the key
- WHEN a transmission is attempted
- THEN it SHALL fail with an actionable config error
- AND no plaintext-key fallback SHALL be used
- @e2e exclude backend credential brokering — covered by PHPUnit

## Non-Functional Requirements

- **Performance:** participant lookups SHOULD be cacheable per `peppolId` for
  the source's configured TTL; a lookup MUST time out and return a descriptive
  error rather than hang the request.
- **Accessibility:** any AP-source configuration UI reuses existing
  `source-management` components (no new bespoke controls).
- **Internationalization:** Dutch and English MUST be supported for all new
  user-facing strings (English source keys) (hydra ADR-007).

## Acceptance Criteria

- [ ] `GET /api/peppol/participants/{peppolId}` returns `{exists, supportedDocTypes}`
- [ ] `log` provider drives lookup + transmit with no network call or secret
- [ ] `nl.conduction.peppol.outbound.requested` is consumed and transmitted idempotently
- [ ] `nl.conduction.peppol.delivery.status` is emitted on every state change
- [ ] `POST /api/peppol/inbound` verifies the signature and republishes events
- [ ] AP credentials are resolved via `credentialRef`; none appear in config/logs

## Notes

- Reuses `events-cloudevents` (emission + subscription), `webhook-signing`
  (`webhook_signature` rule for inbound), `digikoppeling-adapter` patterns
  (ADR-017 catalogue entry, ADR-007 credential broker, dead-letter surface),
  and `source-management` mock-mode.
- Cross-app contract owner: this spec. shillinq's
  `PeppolTransmissionAdapterInterface` production binding targets REQ-001/REQ-003
  and observes REQ-004.
