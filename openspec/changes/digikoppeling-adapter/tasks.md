# Tasks — Digikoppeling transport adapter

> Phasing: catalogue entry + WUS synchronous profile first (v1), then ebMS2
> reliable messaging (v1.5), with Grote Berichten and PKIoverheid-via-broker
> spanning both. StUF/ZGW body-building services are reused unchanged.

## v1 — catalogue entry + WUS synchronous profile

- [ ] Register a `digikoppeling` adapter catalogue entry per ADR-017 Rule 1 (card in *Adapters* + configuration schema: `profile` (wus|ebms2), partner `oin`/service/action, endpoint URL, `certificateRef` (broker credentialRef), reliable-messaging params); no top-level menu, no `/beheer` route
- [ ] Implement the WUS profile: wrap the existing `SOAPService` synchronous dispatch with a WS-Security 1.1 X.509 layer — sign the outgoing SOAP envelope with the organisation's PKIoverheid key and verify the responder's signature; two-way TLS using the client certificate
- [ ] Compose with StUF: a WUS Digikoppeling source sends a StUF-BG body produced by the existing `StUFXMLBuilder`/`StUFBGService` unchanged (adapter signs + delivers, does not build the body)
- [ ] Resolve the PKIoverheid client certificate + private key through the credential broker via `certificateRef` (reuse the `source-broker-credentials` `credentialRef` contract); fail closed with an actionable config error if key material for in-process signing is unavailable — never fall back to a plaintext on-disk key
- [ ] Unit tests: signed-envelope fixture (valid signature over the SOAP body), responder-signature verification (accept valid / reject tampered), TLS client-cert selection, cert-via-broker resolution + fail-closed when absent

## v1.5 — ebMS2 reliable asynchronous messaging

- [ ] Implement an ebMS2 outbound messaging service: persist each message with its ebMS2 `MessageId`/`ConversationId`, deliver over 2-way TLS + WS-Security, track acknowledgements
- [ ] Reliable delivery: retransmit unacknowledged messages via the existing `Cron/JobTask` machinery up to the configured retry budget; enforce message ordering per conversation and duplicate elimination keyed on `MessageId`/`RefToMessageId`
- [ ] Inbound ebMS2: accept incoming messages/acknowledgements, emit acks, and expose message status
- [ ] Dead-letter: an ebMS2 message that exhausts its retransmission budget lands on the `dead-letter-replay` surface for audited operator replay (do not build a parallel bus)
- [ ] Unit tests: retransmission on missing ack, dedup on repeated `MessageId`, ordering per conversation, exhaustion → dead-letter

## Grote Berichten (spans both profiles)

- [ ] Implement Grote Berichten out-of-band transfer: carry a payload reference (URL + checksum) in the message and fetch/serve the large payload separately; verify the checksum on retrieval
- [ ] Unit tests: reference round-trip, checksum mismatch rejected

## Close-out

- [ ] Run `composer check:strict` and fix anything it flags in touched files

Acceptance criteria (plain bullets — verified by /opsx-verify):

- Digikoppeling appears as an *Adapters* catalogue entry with a configuration schema; it adds no top-level menu and no `/beheer` route (ADR-017 Rule 1)
- A WUS Digikoppeling source sends a StUF-BG *bevraging* whose SOAP envelope is WS-Security-signed with the PKIoverheid key and whose responder signature is verified; a tampered response is rejected
- The PKIoverheid private key is resolved through the credential broker and never appears in adapter/source config, exports, or logs; an absent key fails closed with a config error
- (v1.5) An ebMS2 *melding* is delivered reliably: retransmitted until acknowledged, ordered per conversation, de-duplicated, and moved to the dead-letter surface after exhausting retransmissions
- A Grote Berichten payload is transferred out-of-band by reference with checksum verification, without inflating the message envelope
