# digikoppeling-adapter Specification

## Purpose
TBD - created by archiving change digikoppeling-adapter. Update Purpose after archive.
## Requirements
### Requirement: Digikoppeling adapter is a catalogue entry, not a menu (REQ-DK-001)

The Digikoppeling adapter MUST be delivered as a catalogue entry in the
*Adapters* section with a configuration schema, per ADR-017 Rule 1. It MUST NOT
add a top-level navigation menu, a per-adapter settings page, or a route under
`/beheer`. The configuration schema MUST capture at least: the transport
`profile` (`wus` | `ebms2`), the partner organisation identifier (OIN),
service and action, the endpoint URL, a `certificateRef` naming the PKIoverheid
credential, and the reliable-messaging parameters for ebMS2.

@e2e exclude adapter catalogue registration + schema — covered by PHPUnit, no dedicated browser journey

#### Scenario: Digikoppeling ships as an Adapters card with a config schema

- **GIVEN** the Digikoppeling adapter is installed
- **WHEN** the *Adapters* catalogue is inspected
- **THEN** it SHALL show a Digikoppeling catalogue entry with a configuration schema
- **AND** no top-level menu item or `/beheer` route SHALL be added for it
- @e2e exclude catalogue registration — covered by PHPUnit

### Requirement: WUS synchronous profile with WS-Security signing (REQ-DK-002)

The WUS profile MUST perform a synchronous request/response over two-way TLS and
MUST sign the outgoing SOAP envelope with the organisation's PKIoverheid X.509
certificate using WS-Security 1.1 (X.509 Token Profile), and MUST verify the
responder's WS-Security signature on the reply. A reply whose signature is
missing or invalid MUST be rejected as a transport error and MUST NOT be treated
as a successful response. The WUS profile MUST transport a StUF/ZGW body
produced by the existing content services without modifying that body.

@e2e exclude backend SOAP + WS-Security signing — covered by PHPUnit with signed-envelope fixtures

#### Scenario: a WUS bevraging is signed and its response verified

- **GIVEN** a WUS Digikoppeling source carrying a StUF-BG vraag body
- **WHEN** the adapter dispatches the request
- **THEN** the outgoing SOAP envelope SHALL carry a valid WS-Security X.509
  signature over the body
- **AND** a correctly signed response SHALL be accepted as a completed call
- @e2e exclude backend signing — covered by PHPUnit

#### Scenario: a tampered or unsigned response is rejected

- **GIVEN** a WUS request whose response signature is missing or does not verify
- **WHEN** the adapter processes the reply
- **THEN** the call SHALL be recorded as a transport error
- **AND** the response body SHALL NOT be treated as a successful answer
- @e2e exclude backend signature verification — covered by PHPUnit

### Requirement: ebMS2 reliable asynchronous messaging (REQ-DK-003)

The ebMS2 profile MUST deliver messages reliably: each outbound message MUST be
persisted with its ebMS2 `MessageId` and `ConversationId`, retransmitted via the
existing job/cron machinery until acknowledged or until the configured retry
budget is exhausted, ordered per conversation, and de-duplicated on receipt
using the ebMS2 `MessageId`/`RefToMessageId`. A message that exhausts its
retransmission budget MUST be moved to the dead-letter surface for audited
replay rather than silently dropped. Inbound ebMS2 messages MUST be acknowledged
and their delivery status MUST be observable.

@e2e exclude backend reliable messaging state machine — covered by PHPUnit

#### Scenario: an unacknowledged message is retransmitted then dead-lettered

- **GIVEN** an ebMS2 message dispatched to a partner that never acknowledges
- **WHEN** the retry budget is exhausted
- **THEN** the message SHALL have been retransmitted up to the configured budget
- **AND** it SHALL land on the dead-letter surface for audited replay
- @e2e exclude backend retransmission — covered by PHPUnit

#### Scenario: a duplicate MessageId is eliminated on receipt

- **GIVEN** an inbound ebMS2 message whose `MessageId` was already processed
- **WHEN** it arrives again
- **THEN** it SHALL be de-duplicated (processed at most once)
- @e2e exclude backend dedup — covered by PHPUnit

### Requirement: Grote Berichten out-of-band large-payload transfer (REQ-DK-004)

Grote Berichten transfers MUST carry the large payload out-of-band: the message
MUST contain a payload reference (URL + checksum) rather than the payload
inline, and the payload MUST be fetched or served separately with its checksum
verified on retrieval. A checksum mismatch MUST be rejected as a transport
error. This mechanism MUST be usable by both the WUS and ebMS2 profiles.

@e2e exclude backend large-payload reference handling — covered by PHPUnit

#### Scenario: a large payload is transferred by reference with checksum

- **GIVEN** a Digikoppeling message exceeding the inline size threshold
- **WHEN** it is sent via Grote Berichten
- **THEN** the message SHALL carry a payload reference (URL + checksum), not the
  inline payload
- **AND** on retrieval a checksum mismatch SHALL be rejected as a transport error
- @e2e exclude backend GB handling — covered by PHPUnit

### Requirement: PKIoverheid keys resolved via the broker, never plaintext (REQ-DK-005)

The PKIoverheid client certificate and private signing key MUST be resolved
through the OpenRegister credential broker via a `certificateRef`, and MUST NOT
be stored as plaintext in adapter or source configuration, exports, logs, or
error messages (ADR-007). When key material required for in-process WS-Security
signing cannot be supplied, the adapter MUST fail closed with an actionable
config error and MUST NOT fall back to a plaintext key on disk.

@e2e exclude backend credential brokering — covered by PHPUnit

#### Scenario: the signing key is brokered and never appears in config or logs

- **GIVEN** a Digikoppeling adapter configured with a `certificateRef`
- **WHEN** a signed WUS or ebMS2 message is dispatched
- **THEN** the private key SHALL be resolved through the credential broker
- **AND** the private key SHALL NOT appear in adapter/source config, exports,
  logs, or error messages
- @e2e exclude backend credential brokering — covered by PHPUnit

#### Scenario: absent key material fails closed, no plaintext fallback

- **GIVEN** a Digikoppeling adapter whose `certificateRef` cannot supply the
  signing key material
- **WHEN** a signed message dispatch is attempted
- **THEN** the dispatch SHALL fail with an actionable config error
- **AND** no plaintext-key-on-disk fallback SHALL be used
- @e2e exclude backend credential brokering — covered by PHPUnit

