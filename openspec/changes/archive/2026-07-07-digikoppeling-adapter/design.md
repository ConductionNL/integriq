# Design — Digikoppeling transport adapter

## Context

Digikoppeling (Logius) defines three transport profiles over which Dutch
government systems exchange messages:

| Profile | Sync? | Use | Security |
|---|---|---|---|
| **WUS** | synchronous | *bevragingen* (queries) | 2-way TLS + WS-Security X.509 message signing |
| **ebMS2** (OSB) | asynchronous | *meldingen* (notifications) | 2-way TLS + WS-Security, reliable messaging (ack, retransmit, ordering, dedup) |
| **Grote Berichten** | either | large payloads | out-of-band transfer, reference + checksum in the message |

OpenConnector today has StUF *content* services and a generic `SOAPService`, but
no WS-Security signing, no ebMS2 reliable-messaging state machine, and no Grote
Berichten handling. StUF-over-plain-HTTP is not Digikoppeling-compliant.

## Decisions

### D1 — Adapter is transport; StUF/ZGW remain content (composition, not merge)
The Digikoppeling adapter signs and delivers an envelope; it does not build
StUF/ZGW bodies. `StUFXMLBuilder`/`StUFBGService` produce the message body
unchanged; the adapter wraps it. This keeps `stuf-adapter` and
`digikoppeling-adapter` orthogonal (StUF-BG can be sent WUS *or* ebMS2; a ZGW
notificatie can be sent ebMS2) and honours ADR-002 (mapping/rule engine stays
app-local) and the ADR-017 catalogue model.

### D2 — Catalogue entry per ADR-017 Rule 1 (no per-adapter menu)
Digikoppeling ships as a card in *Adapters* + a configuration schema
(profile, partner id/service/action, endpoint, certificate reference, reliable-
messaging parameters). No top-level menu, no `/beheer/digikoppeling` route.
A *Verbinding* (source/endpoint) selects the Digikoppeling adapter + profile.

### D3 — Phasing: WUS first, ebMS2 second, GB alongside
WUS is a bounded wrap of the existing synchronous SOAP dispatch with a
WS-Security signing/verification layer — deliverable in v1 and immediately
unlocks synchronous StUF-BG *bevragingen* (the largest StUF demand). ebMS2 is a
stateful reliable-messaging engine (persisted outbound message, ack tracking,
retransmission, ordering, dedup) — larger, delivered as v1.5. Grote Berichten
is a reference resolver usable by both. The spec marks ebMS2 reliability
requirements clearly so a WUS-only v1 is a valid, compliant partial delivery.

### D4 — Reliable messaging reuses existing cron + dead-letter, not a new bus
ebMS2 retransmission is driven by the existing job/cron machinery
(`Cron/JobTask`), and an ebMS2 message that exhausts its retransmission budget
lands on the `dead-letter-replay` surface for audited operator replay — rather
than inventing a parallel delivery bus. Message ordering + duplicate
elimination are enforced by the persisted message log keyed on the ebMS2
`MessageId`/`RefToMessageId` + conversation id.

### D5 — PKIoverheid keys via the credential broker, never plaintext (ADR-007)
The client certificate + private signing key are resolved through the
OpenRegister credential broker via a `credentialRef` (the mechanism
`source-broker-credentials` introduces), so the private key never lives in
adapter/source config, exports, or logs. WS-Security signing needs the key
material in-process; where the broker cannot yet supply raw key material for
in-process signing, the adapter MUST fail closed with an actionable config
error rather than fall back to a plaintext key on disk.

## Standards references

- Digikoppeling Koppelvlakstandaard WUS, ebMS2, en Grote Berichten (Logius).
- WS-Security 1.1 (X.509 Token Profile) for WUS message signing.
- PKIoverheid certificate hierarchy for the signing/TLS certificates.

## Non-goals

- Not the Digikoppeling REST-API profile in v1 (OAuth/mTLS REST is a later
  additive profile; WUS + ebMS2 cover the mandated SOAP transports first).
- Not building StUF/ZGW message content (owned by `stuf-adapter` / ZGW work).
- Not a CPA-authoring UI in v1 (partner config is entered as adapter
  configuration; a CPA import/authoring tool can follow).
