# stuf-zds-case-client Specification

**Status:** proposed
**Scope:** integriq
**Depends on:** the `stuf-zkn-bridge` spec (the StUF-ZKN source and `stuf_message` schema).

## Purpose

Integriq sends StUF-ZDS case messages on behalf of a sibling app. The calling app decides what a
case is and which zaaktype it maps to; integriq builds the message, sends it to the case system a
StUF-ZKN source names, retries what can be retried, and reports how it ended.

@e2e exclude The client is a backend exchange with an external case system and has no browser
surface; it is proven by the PHPUnit suites (StufZdsCaseClientTest, StufZdsMessageBuilderTest,
StufZdsResponseReaderTest, StufZdsCircuitBreakerTest, StufZdsCaseRequestedListenerTest).

## ADDED Requirements

### Requirement: Case message construction

The client SHALL build zakLk01 creeerZaak, zakLk02 actualiseerZaak, zakLv01 geefZaakdetails,
genereerZaakIdentificatie_Di02 and template-named Du01 vrij berichten as complete SOAP envelopes
with stuurgegevens from the source (zender, ontvanger, gebruiker), a fresh 26-character
referentienummer and a seventeen-digit tijdstipBericht. A creeerZaak without a zaaktype omschrijving,
with documents over the payload limit, or a vrij bericht missing a required field SHALL be refused
before anything is sent.

#### Scenario: A creeerZaak carries the zaaktype the caller mapped

- GIVEN a case and the zaaktype omschrijving `Melding openbare ruimte`
- WHEN the client builds the creeerZaak
- THEN the zakLk01 object carries that zaaktype omschrijving and verwerkingssoort `T`

### Requirement: Secure transport

The client SHALL send only to an `https://` address, to the ZDS service the operation belongs to
(`ontvangAsynchroonUrl`, `beantwoordVraagUrl`, `vrijeBerichtenUrl`, else `baseUrl`), with token,
mTLS or WS-Security authentication. A transport failure SHALL be reported as HTTP status 0 with a
`TIMEOUT` or `NETWORK` fout, never thrown.

#### Scenario: A plain-HTTP address is never sent to

- GIVEN a source whose service address starts with `http://`
- WHEN a case message is sent
- THEN nothing is sent and the result carries the permanent fout `TRANSPORT_NON_HTTPS`

### Requirement: Response reading

The client SHALL read the zaak identificatie from a bevestiging or Du02, the zaak from a zakLa01,
and the code of a fout. The codes StUF067, StUF068 and StUF019 SHALL be transient, all others
permanent. An answer carrying a DOCTYPE SHALL be treated as unreadable.

#### Scenario: A busy case system is retried

- GIVEN a Fo03 with code StUF067
- WHEN the answer is read
- THEN the fout is transient

### Requirement: Circuit breaker and retry

Four consecutive failures against one source SHALL open its circuit for five minutes, during which
requests are answered with the fout `CIRCUIT_OPEN` without sending. A kennisgeving whose attempt
fails transiently SHALL be retried with the same envelope after 5 s, 30 s, 2 min and 10 min; a StUF
fout in the body decides whether a failure is transient, the HTTP status only when there is none.

#### Scenario: Retries stop after the schedule

- GIVEN a case system that answers HTTP 502 every time
- WHEN the client sends a creeerZaak and every retry runs
- THEN the message is attempted five times and its row ends `failed`

### Requirement: Credentials stay encrypted

The WS-Security password SHALL be stored encrypted on the source and decrypted only at send time.
A source naming a WS-Security user without an encrypted password SHALL be refused.

#### Scenario: A user without a password is refused

- GIVEN a source with `authentication.wsse.username` and no `encryptedPassword`
- WHEN a case message is built
- THEN the client refuses it before sending

### Requirement: Every case message is audited

Every case message SHALL have a `stuf_message` row naming its operation, source, asking app,
correlation id, referentienummer and attempts. The envelope SHALL be kept only while the row is
`retrying` and erased when it concludes `sent` or `failed`. The stuf-zkn-bridge retry sweep SHALL
leave these rows alone.

#### Scenario: A concluded row keeps no envelope

- GIVEN a creeerZaak the case system confirms
- WHEN the row concludes
- THEN its status is `sent` and it has no envelope

### Requirement: Case operations

The client SHALL offer creeerZaak (asking for an identificatie first when the source sets
`caseIdentificationStrategy: vooraf`), actualiseerZaak, geefZaakdetails, genereerZaakIdentificatie
and vrijBericht against a StUF-ZKN source in `rest` mode, and refuse a source in `log` mode.

#### Scenario: vooraf allocates the identificatie first

- GIVEN a source with `caseIdentificationStrategy: vooraf`
- WHEN the client sends a creeerZaak
- THEN it first sends genereerZaakIdentificatie and puts the allocated identificatie in the zakLk01

### Requirement: Case messages are a cross-app command

A sibling app SHALL ask for a case message by dispatching `StufZdsCaseRequestedEvent` (source,
operation, arguments, correlation id). The listener SHALL write the client's result into the event,
or a refusal when nothing was sent. When a kennisgeving concludes, integriq SHALL dispatch
`StufZdsCaseConcludedEvent` with the asking app, the correlation id and the result.

#### Scenario: A refusal is never a result

- GIVEN a request for a vrij bericht the source does not register
- WHEN the listener handles it
- THEN the event carries a refusal and no result

### Requirement: Asynchronous confirmations conclude the message

Integriq SHALL receive the asynchronous Bv01/Bv03/Fo01/Fo02/Fo03 a case system sends after
processing a kennisgeving, at `POST /api/stuf-zkn/confirmations` and, for a sibling app that
receives it on its own URL, through `StufZdsConfirmationReceivedEvent`. The caller SHALL
authenticate with the WS-Security UsernameToken of the source the outbound row went to; a source
without one accepts no confirmation. An unknown crossRefnummer SHALL answer exactly like a wrong
password (422), so a referentienummer cannot be probed. A bevestiging SHALL make the row `sent`
and end any pending retry; a foutbericht SHALL make it `failed` with the case system's fout. The
first confirmation of a message SHALL dispatch `StufZdsCaseConcludedEvent` with `confirmation` in
the result. A repeated one is acknowledged but not announced again; a later one with another code
(a foutbericht after a bevestiging, or the reverse) is acknowledged and logged, and SHALL change
neither the row nor what the owning app was told: after the first asynchronous confirmation the
owning app receives no second, contradicting conclusion for that message. The first asynchronous
confirmation itself still overrides what the synchronous answer at send concluded.

#### Scenario: A contradicting later confirmation changes nothing

- GIVEN an outbound row with referentienummer REF-1 that a Bv01 already confirmed
- WHEN the case system posts a Fo01 cross-referencing REF-1
- THEN the answer is a Bv03 (200), the row stays `sent` with `confirmation` Bv01
- AND no second `StufZdsCaseConcludedEvent` is dispatched

#### Scenario: A Bv01 confirms the row

- GIVEN an outbound creeerZaak row with referentienummer REF-1
- WHEN the case system posts a Bv01 with crossRefnummer REF-1 and the source's UsernameToken
- THEN the answer is a Bv03 (200), the row records `confirmation` Bv01 and the zaak identificatie
- AND `StufZdsCaseConcludedEvent` is dispatched with success true

#### Scenario: A Fo01 fails the row

- GIVEN an outbound row with referentienummer REF-1
- WHEN the case system posts a Fo01 with fout StUF064
- THEN the row is `failed` with faultCode StUF064 and the conclusion carries success false

#### Scenario: An unknown crossRefnummer cannot be told from a wrong password

- GIVEN no outbound row with referentienummer REF-404
- WHEN a Bv01 cross-referencing REF-404 arrives
- THEN the answer is the same 422 a wrong password gets, and nothing changes
