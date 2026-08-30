---
kind: code
depends_on: []
---

# openconnector — Digikoppeling transport adapter (WUS + ebMS2 + Grote Berichten)

## Why

**Digikoppeling** is the Dutch government's mandatory standard (Logius) for
machine-to-machine message exchange between public-sector organisations. It is
the *transport* layer that StUF and the ZGW APIs ride on when messages cross an
organisational boundary. OpenConnector already ships a StUF adapter
(`stuf-adapter` spec, `lib/Service/StUF*`) and speaks ZGW, but StUF/ZGW
*content* over a bare Guzzle HTTP call is not Digikoppeling-compliant transport:
there is no WUS WS-Security signing, no ebMS2 reliable asynchronous messaging,
and no Grote Berichten large-payload handling.

This is a genuine, reserved-but-unbuilt gap:

- **ADR-017** (this repo's Information Architecture ADR) explicitly reserves
  `digikoppeling-adapter` as one of its ~15 catalogue adapter specs
  (alongside `stuf-bg-zkn-bg-koppelvlak`, `haalcentraal-…`,
  `digid-eherkenning-auth-adapter`, `peppol-e-invoicing-adapter`, …). It names
  Digikoppeling in the *Adapters* menu description. **No `digikoppeling-adapter`
  spec exists yet** — `openspec/specs/` has `stuf-adapter`, `pdok-adapter`,
  `dso-omgevingsloket`, `data-infra-connectors`, `ibabs-notubiz-connector`,
  `document-cms-connectors`, `saas-productivity-connectors`,
  `endpoint-workspace-connectors`, but nothing for Digikoppeling.
- **Demand**: the requirement clusters this transport underpins are top-tier in
  OpenConnector's assignment — *StUF integration* (136 tenders), *ZGW API
  support* (72), *System integration/coupling* (280), *Common Ground
  architecture* (87). Digikoppeling compliance is a hard procurement gate for
  Dutch municipal/provincial systems, and it is frequently a named,
  disqualifying requirement rather than a nice-to-have.

Without a Digikoppeling adapter, OpenConnector can carry StUF/ZGW payloads only
over non-compliant plain HTTP, which fails the government interoperability
requirement it markets itself against ("Dutch interoperability: ZGW APIs, Common
Ground").

## What Changes

Introduce a `digikoppeling-adapter` capability, delivered per ADR-017 Rule 1 as
a catalogue entry in *Adapters* (no new top-level menu, no per-adapter route)
with a configuration schema. It provides the three Digikoppeling transport
profiles that Logius defines, phased so v1 is deliverable and the async profile
is a named follow-on:

- **WUS profile (synchronous, v1)** — request/response over two-way TLS with
  WS-Security: sign the SOAP envelope with the organisation's PKIoverheid X.509
  certificate and verify the responder's signature. Used for *bevragingen*
  (queries), e.g. a synchronous StUF-BG vraag/antwoord. A Digikoppeling source
  or endpoint selects the WUS profile; the adapter wraps the existing SOAP
  dispatch with the WS-Security signing/verification layer.
- **ebMS2 profile (asynchronous reliable messaging, v1.5)** — OSB/ebXML
  messaging for *meldingen*: reliable delivery (persisted message, retransmit
  until acknowledged), duplicate elimination, and message ordering, driven by a
  partner agreement (CPA-style: partner id, service, action, endpoint,
  certificate). Inbound ebMS2 acknowledgements and message-status are tracked;
  unacknowledged messages are retried by the existing job/cron machinery and
  land on the dead-letter surface (`dead-letter-replay`) after exhausting
  retransmissions.
- **Grote Berichten (large payloads)** — out-of-band transfer: the message
  body carries a reference (URL + checksum) and the payload is fetched/served
  separately, so large documents do not inflate the SOAP/ebMS envelope.

- **PKIoverheid certificate custody via the broker, not plaintext** — the
  signing key and client certificate MUST be supplied through the OpenRegister
  credential broker (the `credentialRef` mechanism), never stored as plaintext
  on the adapter/source config. This aligns with `source-broker-credentials`
  and ADR-007 (source credentials must not be stored plaintext).

## Cross-repo / cross-change relationships (prose)

- **`source-broker-credentials`** (this repo, in-flight) — the `credentialRef`
  contract this adapter reuses for the PKIoverheid certificate + private key.
- **`stuf-adapter`** (this repo) — supplies the StUF *content* (BG/ZKN
  messages) that the WUS/ebMS2 profiles transport; the adapters compose (StUF
  builds the body, Digikoppeling signs and delivers it).
- **`dead-letter-replay`** (this repo) — ebMS2 messages that exhaust
  retransmission land on the dead-letter surface for audited replay.

## Impact

- Affected specs: NEW `digikoppeling-adapter` capability (catalogue entry +
  WUS/ebMS2/Grote-Berichten transport profiles + PKIoverheid cert-via-broker).
  Touches the edges of `stuf-adapter` (content vs transport composition),
  `source-management` (a Digikoppeling source references an adapter profile),
  and `dead-letter-replay` (ebMS2 exhaustion) — described here, not restated
  there.
- Affected code (implementation phase, not this change): a WS-Security
  signing/verification layer over the existing `SOAPService` dispatch; an ebMS2
  messaging service with a persisted outbound message log + retransmission
  driven by cron; a Grote Berichten reference resolver; the adapter catalogue
  registration + configuration schema; PKIoverheid cert resolution through the
  broker; unit tests with signed-envelope fixtures.
- Not affected: the StUF message-building services (unchanged — they produce
  the body), non-Digikoppeling sources, and the CallLog envelope shape.
