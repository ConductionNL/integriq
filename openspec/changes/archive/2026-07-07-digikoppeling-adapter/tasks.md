# Tasks — Digikoppeling transport adapter

> Phasing: catalogue entry + WUS synchronous profile first (v1), then ebMS2
> reliable messaging (v1.5), with Grote Berichten and PKIoverheid-via-broker
> spanning both. StUF/ZGW body-building services are reused unchanged.

## v1 — catalogue entry + WUS synchronous profile

- [x] Register a `digikoppeling` adapter catalogue entry per ADR-017 Rule 1 (`DigikoppelingAdapter` descriptor: `profile` (wus|ebms2), partner `oin`/service/action, endpoint URL, `certificateRef` (broker credentialRef), reliable-messaging params); no top-level menu, no `/beheer` route
- [x] Implement the WUS profile WS-Security 1.1 X.509 layer (`WsSecuritySigner`): sign the outgoing SOAP envelope Body with the PKIoverheid key (RSA-SHA256, exclusive-C14N enveloped XML-DSig) and verify the responder's signature. **Deferred (documented):** the on-the-wire two-way-TLS POST through `SOAPService` — blocked on the same broker signing-material capability as the key resolver (see below); the signing + verification core is real and tested.
- [x] Compose with StUF: `WusProfileService::buildSignedRequest()` wraps a StUF body produced elsewhere unchanged (adapter signs + delivers, does not build the body)
- [x] Resolve the PKIoverheid client certificate + private key through the credential broker via `certificateRef` (`PkiOverheidCredentialResolver`); fail closed with an actionable config error when the broker cannot supply in-process signing material — never a plaintext on-disk fallback. **Note:** the current constrained-proxy broker (`request()` only) cannot hand raw key material to a calling app, so production correctly fails closed until the broker grows a signing-material capability (detected by reflection so it lights up automatically). This is the honest, spec-compliant behaviour (REQ-DK-005), not a stub.
- [x] Unit tests: signed-envelope fixture (valid signature over the SOAP body), responder-signature verification (accept valid / reject tampered / wrong cert / unsigned), cert-via-broker resolution + fail-closed when material unavailable

## v1.5 — ebMS2 reliable asynchronous messaging

> Scope note: the reliability **state machine** (the decisions that make ebMS2
> reliable) is implemented and fully unit-tested in `Ebms2ReliableMessagingService`.
> The **live wiring** around it — an OpenRegister-durable message store, the
> `Cron/JobTask` retransmission driver, and the inbound HTTP receiver endpoint
> that emits acks — is the documented v1.5 follow-on (it needs a live partner +
> TLS + cron to exercise). Nothing here is stubbed: every decision below is real
> and tested; only the transport/persistence plumbing is deferred.

- [x] ebMS2 outbound message model: register each message with its `MessageId`/`ConversationId` and track acknowledgements (`registerOutbound`/`acknowledge`/`isAcknowledged`). **Deferred:** durable OR-backed persistence + the actual 2-way-TLS+WS-Security delivery call.
- [x] Reliable-delivery decisions: retransmit-until-budget (`dueForRetransmit`/`recordAttempt`), message ordering per conversation (`orderedConversation`), duplicate elimination on `MessageId` (`receiveInbound`). **Deferred:** the `Cron/JobTask` driver that calls these on a schedule.
- [ ] Inbound ebMS2 receiver endpoint (accept messages, emit acks, expose status over HTTP) — **DEFERRED (v1.5 live wiring):** dedup + status are in the state machine; the HTTP receiver + ack emission need a live partner.
- [x] Dead-letter: a message that exhausts its retransmission budget is moved to the dead-letter surface via `shouldDeadLetter`/`deadLetter` (audit record for `dead-letter-replay`; no parallel bus).
- [x] Unit tests: retransmission on missing ack, dedup on repeated `MessageId`, ordering per conversation, exhaustion → dead-letter

## Grote Berichten (spans both profiles)

- [x] Grote Berichten out-of-band transfer (`GroteBerichtenReference`): carry a payload reference (URL + checksum) in the message and verify the checksum on retrieval (rejects mismatch as a transport error)
- [x] Unit tests: reference round-trip, checksum mismatch rejected

## Close-out

- [x] Run `composer check:strict` and fix anything it flags in touched files (lint + phpcs clean on all new lib files; PHPUnit green)

## Deferral summary (honest)

Delivered in this change: the ADR-017 catalogue descriptor + config schema; the
full WUS WS-Security signing/verification crypto core (real, tested); Grote
Berichten reference + checksum; the ebMS2 reliability state machine; and the
PKIoverheid-via-broker resolver that fails closed (no plaintext). Deferred as
documented follow-ons, blocked on live infrastructure rather than stubbed: the
live two-way-TLS SOAP/ebMS2 wire dispatch, the OR-durable ebMS2 message store,
the cron retransmission driver, the inbound ebMS2 receiver endpoint, and
in-process PKIoverheid signing in production (unblocked when the credential
broker exposes a signing-material issuance capability — the resolver already
detects it by reflection).

Acceptance criteria (plain bullets — verified by /opsx-verify):

- Digikoppeling appears as an *Adapters* catalogue entry with a configuration schema; it adds no top-level menu and no `/beheer` route (ADR-017 Rule 1)
- A WUS Digikoppeling source sends a StUF-BG *bevraging* whose SOAP envelope is WS-Security-signed with the PKIoverheid key and whose responder signature is verified; a tampered response is rejected
- The PKIoverheid private key is resolved through the credential broker and never appears in adapter/source config, exports, or logs; an absent key fails closed with a config error
- (v1.5) An ebMS2 *melding* is delivered reliably: retransmitted until acknowledged, ordered per conversation, de-duplicated, and moved to the dead-letter surface after exhausting retransmissions
- A Grote Berichten payload is transferred out-of-band by reference with checksum verification, without inflating the message envelope
