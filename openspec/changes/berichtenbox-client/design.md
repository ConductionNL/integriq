# Design: berichtenbox-client

## 1. Sources, and what they say

Every interface detail below comes from one of these. Anything they do not settle is listed as open in section 10 or 12. Nothing is filled in from memory or from the old code.

| # | Source | Version | URL | Read |
|---|---|---|---|---|
| S1 | Technische Aansluithandleiding MijnOverheid Berichtenbox (Logius) | **1.6.4**, the version the page states | https://www.logius.nl/domeinen/interactie/mijnoverheid/documentatie/technische-aansluithandleiding-mijnoverheid-berichtenbox | 2026-10-07 |
| S2 | Same handleiding as PDF | 1.6.3, 24 April 2020 | https://www.logius.nl/sites/default/files/bestanden/website/Technische%20Aansluithandleiding%20MijnOverheid%20Berichtenbox%20v1.6.3.pdf | 2026-10-07 |
| S3 | XSD package MijnOverheid Berichtenbox | file name says 2026 | https://www.logius.nl/sites/default/files/public/bestanden/diensten/MijnOverheid/logius-mijnoverheid-berichtenbox-xsd-2026.zip | 2026-10-07 |
| S4 | Voorbeeldberichten Berichtenbox | none stated | https://www.logius.nl/sites/default/files/website/diensten/mijnoverheid/bestanden/logius-mijnoverheid-voorbeeldberichten-berichtenbox.zip | 2026-10-07 |
| S5 | MijnOverheid Berichtenbox, the five-step aansluitprocedure | page, no version | https://www.logius.nl/onze-dienstverlening/interactie/voorzieningen/mijnoverheid/mijnoverheid-berichtenbox | 2026-10-07 |
| S6 | Aanvraagformulier MijnOverheid Berichtenbox | form | https://www.logius.nl/domeinen/interactie/mijnoverheid/aanvraagformulier-mijnoverheid-berichtenbox | 2026-10-07 |
| S7 | Checklist testen Berichtenbox MijnOverheid | 1.4 | https://www.logius.nl/onze-dienstverlening/interactie/voorzieningen/mijnoverheid/documentatie/checklist-testen-berichtenbox-mijnoverheid | 2026-10-07 |
| S8 | Digikoppeling Koppelvlakstandaard ebMS2 | 3.3.2, 31 May 2024 (current redirect) | https://gitdocumentatie.logius.nl/publicatie/dk/ebms/ | 2026-10-07 |
| S9 | Digikoppeling Koppelvlakstandaard WUS | 3.8.1 (current redirect) | https://gitdocumentatie.logius.nl/publicatie/dk/wus/ | 2026-10-07 |
| S10 | Hoe ontwikkelen we het FBS (Logius) | updated 2 July 2026 | https://www.logius.nl/onze-dienstverlening/interactie/federatief-berichten-stelsel/hoe-ontwikkelen-we-het-fbs | 2026-10-07 |
| S11 | FBS aansluiten (Logius) | page | https://www.logius.nl/domeinen/interactie/federatief-berichten-stelsel/aansluiten | 2026-10-07 |
| S12 | ebms-core, open source ebMS 2.0 adapter (Apache 2.0), `EbMSRestController` | branch 2.20.x | https://github.com/eluinstra/ebms-core/blob/ebms-core-2.20.x/core/src/main/java/nl/clockwork/ebms/api/ebms/EbMSRestController.java | 2026-10-07 |

One difference between S1 and S2 matters here. S2 names two Logius contacts who must approve WUS use for subscription checks. S1 keeps the warning that WUS checks are "in aantallen gelimiteerd en alleen bedoeld voor kleine bevragingen" and drops the names.

### What the code claims, and what the sources say

| Claim in integriq today | File | Sources |
|---|---|---|
| "BBK 1.7 REST API" | `lib/Adapters/Berichtenbox/BerichtenboxClient.php:6`, `lib/Service/CatalogRegistryService.php:324` | No Logius source names a BBK or a version 1.7. The interface is ebMS plus WUS (S1 §1.3). |
| OAuth 2.0 client credentials | `BerichtenboxClient.php:16`, `BerichtenboxClientUnavailable.php:39` | Not in S1 to S9. Authentication is two-way TLS with PKIoverheid (S1 §3.2, §6.2). |
| Signed outbound envelopes | `BerichtenboxClient.php:18`, `:57-62` | "Het is niet mogelijk om de payload van het ebMS bericht digitaal te ondertekenen" (S1 §3.2). "Het is niet mogelijk SOAP berichten te Signen" (S1 §6.2). |
| HMAC-signed webhook in `X-Logius-Signature` | `BerichtenboxClient.php:76-89` | No webhook exists. Results come back as an ebMS message (S1 §5.5). |
| `read` / `gelezen` status | `BerichtenboxProvider.php:234` | "Organisaties krijgen geen inzicht of en wanneer berichten zijn gelezen" (S1 §2). |
| Inbound citizen replies | `BerichtenboxProvider.php:206-222` | "Burgers hebben zelf niet de mogelijkheid om berichten terug te sturen" (S1 §2). |
| `priority` normal or high | `BerichtenboxProvider.php:91-95` | No such element in S3. |
| `checkMailbox(bsn)` answers per BSN | `BerichtenboxClient.php:99` | The check is per BSN **and** per BerichtType (`berichtTypeCode`, S3 WSDL `ValidateAbonnementenAanvraag`). |

## 2. The interface, in short

**Transport.** Digikoppeling. ebMS for `GLOBE-R-A-Request` (AbonnementService) and `GLOBE-R-BV-Request` (BerichtVerwerkService). WUS for `ValidateAbonnementen`. Over internet or Diginetwerk. Two-way TLS 1.2 with a PKIoverheid certificate carrying the OIN (government) or HRN (supplier). The ebMS profile is Reliable Messaging (S1 §3.2). Logius keeps one firewall rule per sender IP (S1 §2.4.1). Logius makes the CPA (S1 §3.4).

**Sending a letter.** One `Berichten` batch per request, 1 to 1000 `Bericht` elements, at most 100 MB with base64 (S1 §5.2, S3 `GLOBEBatchRequest.xsd`). Per letter (S3 `GLOBEBatchRequestTypes_128ch.xsd`):

| Element | Rule |
|---|---|
| `BatchID` | GUID, unique per batch, repeated in every letter |
| `AanmaakDatum` | Zulu time, at most 13 days in the past when offered |
| `BerichtLeverancierID` | the sender's OIN, 20 digits (XSD allows 1 to 25 characters) |
| `BerichtID` | GUID, unique per letter |
| `BerichtType` | 1 to 8 characters, configured and active in the Leveranciersportaal |
| `PublicatieDatum` | optional, at most 13 days after `AanmaakDatum` |
| `EindDatumHandelingsTermijn` | optional, a deadline used for notifications (in the XSD, not in S1) |
| `Onderwerp` | 1 to 50 characters |
| `BerichtTekst` | at most 4000 characters, line break `\r\n`, a URL needs a space before and after |
| `Referentie` | optional, at most 25 characters, shown to the citizen |
| `GebruikerID` | the BSN |
| `SoortGebruiker` | fixed `Burger` |
| `Bijlage` | at most 2, each `Inhoud` (base64), `BijlageType` fixed `Pdf`, `Omschrijving`, `Volgorde` |

Attachments together at most 500 kB before base64. PDF/A-1a or PDF/A-2a, WCAG 2.0 (S1 §5.9). The subscription check must be at most 7 days older than the batch (S1 §5.2).

**The result.** `GLOBE-R-BV-Result` (S3 `GLOBEBatchResponse.xsd`) carries counts per batch and, per letter, `BerichtID`, `BerichtType`, `VerwerkingsCode` and `Stadium`. The ten codes are `Verwerkt`, `TechnischProbleem`, `NietActiefOfGeabonneerd`, `BerichtTypeNietOndersteund`, `PublicatieDatumLigtTeVerInDeToekomst`, `AanmaakDatumLigtTeVerInHetVerleden`, `BerichtBestaatAl`, `BijlageTeGroot`, `OinInCPAKomtNietOvereenMetOinInBericht`, `XmlValidatieTegenXsdValtNegatiefUit`. A resend after a fix reuses the same `BatchID` and `BerichtID` (S1 §5.6).

**Checking a subscription.** WUS `ValidateAbonnementen` with `berichtleverancierCode`, `berichtTypeCode` and 1 to 250 `Klant` (`Key` = BSN, `Rol` = `Burger`). The answer gives `isBerichtSturen` per BSN, at once (S1 §7, S3 WSDL). Faults are `ApplicationFault` and `DependentServiceFault`. The ebMS AbonnementService answers within four hours, gzipped, in three variants: a BSN list of up to a million, the full set, or mutations since a date (S1 §4).

## 3. The client, and where it plugs in

`DigitalPostService` stays as it is. It still calls `DigitalPostProviderInterface::send()` and `status()` through the registry (`lib/Service/DigitalPost/DigitalPostService.php:351-362`, `:271-309`). Only the Berichtenbox side changes.

```
DigitalPostService
  -> BerichtenboxProvider            (provider seam, unchanged interface)
       -> BerichtenboxClient          (rewritten contract)
            |- BerichtenboxClientMock         (default, simulated)
            |- BerichtenboxClientUnavailable  (flag on, not configured)
            '- BerichtenboxClientHttp         (new, live)
                 |- ebMS leg: operator's Digikoppeling ebMS adapter, over its REST API
                 '- WUS leg: MtlsTransportService, SOAP 1.1, straight to Logius
```

### The rewritten contract

```php
abstract class BerichtenboxClient {
    abstract public function flavour(): string;                       // mock | https | unavailable
    abstract public function checkSubscriptions(array $bsns, string $berichtType, array $config): array; // bsn => bool
    abstract public function deliver(array $letter, array $config): array; // {batchId, berichtId, ebmsMessageId}
    abstract public function collectResults(array $config): array;   // list of {berichtId, verwerkingsCode, stadium, batchId}
    abstract public function transportEvents(array $config): array;  // list of {ebmsMessageId, type}
}
```

`verifyWebhook()` and `checkMailbox()` go. `BerichtenboxSourceAdapter` (`lib/Sources/Berichtenbox/BerichtenboxSourceAdapter.php:123-188`) follows the same contract. REQ-DPA-006 stays: one Berichtenbox code path, no parallel client.

### The ebMS leg goes through an adapter

S1 §1.1 assumes the sender runs "een eigen Digikoppeling gateway". Integriq has the ebMS state machine (`lib/Adapters/Digikoppeling/Ebms2ReliableMessagingService.php`), but its own docblock lists what it lacks: a durable store, a retransmit driver, an inbound receiver with acknowledgements, and the SOAP envelope on the wire (`:20-26`). Building those in PHP is a project of its own.

So the live client hands the ebMS leg to an adapter the operator runs, and speaks its REST API. The reference is ebms-core (S12). Its REST controller has `POST messages`, `GET messages/unprocessed`, `GET messages/{messageId}`, `GET messages/{messageId}/status`, `GET events/unprocessed` and `POST events/{messageId}` (process). A send names `cpaId`, `fromPartyId`, `toPartyId`, `service`, `action`, `conversationId` and the data source. Event types are `RECEIVED`, `DELIVERED`, `FAILED` and `EXPIRED`.

The adapter holds the PKIoverheid key for the ebMS leg, the CPA, the retry schedule and the acknowledgements. Integriq never sees ebMS on the wire.

The source configuration gains: `adapterUrl`, `adapterAuth` (how integriq authenticates to the adapter), `cpaId`, `fromPartyId`, `toPartyId`, `service`, `berichtTypes` (category to BerichtType code), `wusEndpoint`. `certificateRef` and `senderOin` stay. Which values go in `cpaId`, `toPartyId` and `service` is in the CPA Logius makes; see open question Q2.

### The WUS leg goes straight to Logius

`ValidateAbonnementen` is one synchronous SOAP 1.1 call (`BasicHttpBinding`, S3 WSDL). It is not signed. Integriq sends it through `MtlsTransportService` (`lib/Service/Mtls/MtlsTransportService.php:82`), which already builds Guzzle TLS options from a client certificate and removes the temporary files afterwards. The SOAP action is `http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/2009/01/IBerichtenboxValidatieService/ValidateAbonnementen` (S3). The endpoint is configured, because the WSDL carries an internal RDW host (`rdw64825.ot.tld:9002`), not a Logius address.

## 4. Certificates

**There is no signing material to resolve.** Neither leg signs (S1 §3.2, §6.2). The old blocker, `CredentialBrokerService::issueSigningMaterial` in OpenRegister, does not apply to Berichtenbox. Digikoppeling WUS for other services still needs it.

**The ebMS leg.** The adapter holds the certificate and key in its own keystore. Integriq stores nothing for it.

**The WUS leg.** Integriq needs the same PKIoverheid certificate and key, for TLS only. Recommended: the existing mTLS transport. The source's `authentication` block holds the PEM and key encrypted with Nextcloud's `ICrypto` (`lib/Service/Mtls/MtlsConfigResolver.php:115-170`). The resolver checks shape, expiry and passphrase, and the key is never logged or returned by the API. `certificateRef` then names that encrypted block. REQ-DPA-004 is rewritten to say exactly this: by reference, encrypted at rest, never in plain configuration, never in a log, never as a plain method argument. See decision D3.

**Expiry.** A PKIoverheid certificate expires. The setup check from #2574 gains one line: days until the WUS certificate expires, warning from 30 days. Logius itself replaces the GLOBE endpoint certificates on 10 and 24 November 2026 (S5); the adapter's trust store must take the new ones.

## 5. Sending one letter

1. `DigitalPostService` asks the opt-out list and composes the body, as today (`DigitalPostService.php:142-200`). Unchanged.
2. `BerichtenboxProvider::send()` maps the letter's category to a `BerichtType` through `berichtTypes`. No mapping means a refusal naming the category.
3. The provider asks `checkSubscriptions([bsn], berichtType)`. `false` is a refusal with code `not_subscribed`, before anything is sent. A WUS fault is a refusal with the fault text. The check is never cached longer than 7 days (S1 §5.2); the first version does not cache at all (D4).
4. The provider builds one batch with one letter. It checks the batch against the vendored XSD and the size rules in section 2. A failure is a refusal naming the field and the limit. Nothing is truncated in silence.
5. `deliver()` posts it to the adapter. `batchId`, `berichtId` and the adapter's message id are stored on the `digitalPostMessage` (`providerReference` = `berichtId`, new fields `batchId` and `transportMessageId`). The letter is `sent`.

**Text rules the provider applies.**
- `Onderwerp` over 50 characters is a refusal. The subject comes from the sending app, so the sending app fixes it.
- `\n` becomes `\r\n`.
- The unsubscribe line from `OutboundGate::compose()` stays. A URL gets a space before and after.
- `Referentie` carries the case reference when it fits in 25 characters, and is left out when it does not.
- `GebruikerID` is the BSN. It is never logged; the log shows the hashed key the opt-out list uses.

**Retries.** The adapter retries the ebMS leg under the CPA's schedule (S1 §3.3). Integriq does not retry a send on top of it. A `FAILED` or `EXPIRED` transport event moves the letter to `failed` with `lastError` "not delivered to Logius". A resend is a new send of the same letter with the same `batchId` and `berichtId`, which S1 §5.6 prescribes. There is no retry endpoint for digital post today (idp-live run 5), and this change does not add one (D6).

## 6. Results and statuses

The status job (`lib/BackgroundJob/DigitalPostStatusJob.php`) already polls letters in `sent`, as the digital post account. For Berichtenbox it calls `collectResults()` and `transportEvents()` once per source, not once per letter. It matches by `berichtId` and `transportMessageId`. Each result is marked processed at the adapter only after the letter is saved.

| What arrives | digitalPostMessage | lastError |
|---|---|---|
| transport `DELIVERED` (Logius acknowledged) | stays `sent` | |
| transport `FAILED` or `EXPIRED` | `failed` | not delivered to Logius |
| result `Verwerkt` | `delivered` | |
| result `NietActiefOfGeabonneerd` | `failed` | the code; dossiq can fall back to paper |
| result `BerichtBestaatAl` | `failed` | the code; S1 does not say whether the earlier letter was stored (Q9) |
| any other result code | `failed` | the code and the `Stadium` |
| nothing within the result window | stays `sent`, setup check warns | |

`read` is never set for a Berichtenbox letter. `DigitalPostDeliveredEvent` fires on each change, as today. How long to wait for a result is not in S1 for the BerichtVerwerkService (D7 and Q5).

## 7. Opt-out and category rules do not change

The opt-out list and its category rules run first, unchanged (`opt-out-before-send`, `opt-out-per-purpose`). A besluit still overrides an instance opt-out, a case update still respects it. The Berichtenbox subscription is a second, separate fact held by Logius. A citizen who never subscribed to the sender cannot get the letter in the Berichtenbox, whatever the category. That refusal is `not_subscribed`, distinct from `opted-out`, so dossiq can tell "send it on paper" from "do not send".

## 8. The fake provider

The fake stands in for Logius and the adapter in PHPUnit, Playwright and live proofs. It is built from the official files, not from our idea of them.

- **Vendored contract.** S3 and S4 are copied under `tests/fixtures/berichtenbox/logius/` with a `SOURCE.md` naming the URL, the download date and a sha256 per file. A test fails when a file changes without `SOURCE.md` changing.
- **The adapter face.** A small Python service (same pattern as the idp-live fake Postex) answers the ebms-core REST subset from section 3: `POST messages`, `GET events/unprocessed`, `GET messages/unprocessed`, `GET messages/{id}`, `POST events/{id}`, `POST messages/{id}`. It records every request.
- **The Logius face.** For every `GLOBE-R-BV-Request` it receives, it validates the payload against the vendored XSD with lxml. Invalid gives `XmlValidatieTegenXsdValtNegatiefUit`, the same code Logius would give. It then answers a `GLOBE-R-BV-Result` that validates against `GLOBEBatchResponse.xsd`.
- **The WUS face.** `ValidateAbonnementen` per the vendored WSDL, behind TLS that demands a client certificate from a test CA. No client certificate, no answer.
- **Behaviour by test BSN.** Fixed BSNs give subscribed, not subscribed, `BerichtTypeNietOndersteund`, `BijlageTeGroot`, `BerichtBestaatAl`, a transport `EXPIRED`, and no result at all. A `fail` file gives a 503, as in idp-live.
- **The rules S1 states that the XSD does not.** 500 kB of attachments, 13 days for dates, 7 days for the subscription check, OIN in the letter equal to the OIN in the client certificate.

A stronger proof is available later: two ebms-core instances, one as us and one as a fake GLOBE, so the ebMS wire itself is exercised (D5).

## 9. The aansluitprocedure: what Ruben has to request

From S5, S6 and S7. Each step is done by the sending organisation, or by Conduction when it connects as intermediary (D1).

1. **Eligibility.** The sending organisation has a public task and may use BSNs (S5 step 1). A municipality qualifies. Conduction does not send in its own name.
2. **Choose the route.** Own connection, or through an intermediary. An ICT supplier can be the intermediary for several organisations, with a Verwerkersovereenkomst per client (S1 §2.3, S5).
3. **Identity.** An OIN (or a subOIN in the OIN register) for the organisation, or the intermediary's OIN or HRN (S6). A PKIoverheid server certificate with that number.
4. **Agreements.** Logius' Algemene Voorwaarden, the preproductie and productie Voorwaarden MijnOverheid, and Logius' standard Verwerkersovereenkomst (S1 §2.3).
5. **The application form (S6).** Contacts (general, technical, financial, communication). Connection type, internet or Diginetwerk. Outgoing and incoming IP addresses for preproductie and for productie. Expected letters per month. Start date, at most six months ahead.
6. **Preproductie.** Logius makes the CPAs (S1 §3.4). Access to the Leveranciersportaal (separate form, S5) to create the BerichtTypes and the organisation profile. At least two test DigiD accounts with test BSNs, one subscribed and one not, on `https://preprod.mijn.overheid.nl` (S7).
7. **Test and report.** Run checklist S7 version 1.4 (profile, BerichtTypes, both subscription services, batches to subscribed and unsubscribed users, results, display, PDF/A). Send the test report to Logius.
8. **Productie.** Network path, connection, an optional limited production run (S5 step 4). Preproductie sends no e-mail notifications, so the notification is first seen there (S7).

Costs are not on S5. Ruben asks Logius (Q8).

## 10. What cannot be proven without Logius test credentials

- That Logius accepts our certificate, our IP address and our CPA. The fake accepts whatever its test CA signs.
- The CPA values: party ids, roles, service name, endpoints, retry schedule, and whether the acknowledgement is synchronous or asynchronous.
- Which ebMS profile version the Berichtenbox runs. S1 says "Digikoppeling 1.0", the current ebMS2 standard is 3.3.2 (S8).
- How fast a `GLOBE-R-BV-Result` arrives. S1 gives four hours only for the AbonnementService.
- How Logius treats a letter that passes the XSD but breaks a rule in S1 only, such as 41 characters of `Omschrijving` (see Q3).
- That our PDFs pass Logius' PDF/A check.
- That a letter shows up in a real Berichtenbox and the citizen gets the e-mail.
- How many WUS checks Logius allows ("gelimiteerd", S1 §4) before it refuses.

Every live proof before preproductie is therefore a proof against the fake, and says so.

## 11. Risks

- **The ebMS adapter is a new moving part an operator must run.** Mitigation: the setup check reports the adapter's reachability, and a missing adapter refuses the send with a named reason, as `BerichtenboxClientUnavailable` does now.
- **Logius replaces GLOBE.** FBS with BBO replaces GLOBE. The migration was suspended in June 2026 and will not happen in 2026; no new date is set (S10). BBO's connection documentation is not published (S11). The client keeps all GLOBE knowledge in `BerichtenboxClientHttp`, so a BBO client can be added as another binding later.
- **The XSD package is inconsistent with itself.** `GLOBEBatchRequest.xsd` imports `GLOBEBatchRequestTypes.xsd`, the package ships `GLOBEBatchRequestTypes_128ch.xsd`. The vendored copy keeps the file names and a catalog maps the import (Q3).
- **WUS volume.** One subscription check per letter is fine for case work and wrong for a bulk mailing. Bulk use needs the ebMS AbonnementService (D4).

## 12. Decisions (approved by Ruben 2026-10-07)

Ruben took the recommended option in each, on integriq#2578.

- **D1. Who connects to Logius.** Each customer organisation connects with its own OIN and certificate. Conduction delivers the software. Connecting as intermediary is a separate decision, once a second customer asks.
- **D2. How the ebMS leg is built.** Through an ebMS adapter the operator runs, over its REST API, with ebms-core as the reference.
- **D3. Where the WUS certificate lives.** In integriq's existing mTLS transport, encrypted at rest with `ICrypto`, named by `certificateRef`.
- **D4. How the subscription is checked.** WUS `ValidateAbonnementen` once per letter, with no cache.
- **D5. How strong the pre-Logius proof is.** The XSD-validating fake from section 8.
- **D6. Resending a failed letter.** No resend in this change. A failed letter stays failed.
- **D7. When a letter with no result counts as lost.** The setup check warns after 24 hours. The status is never changed by guessing.
- **D8. Berichtenbox voor bedrijven.** Out of scope. This interface sends to citizens only.

### Questions only Logius can answer

- **Q1.** Which ebMS profile and Digikoppeling version does the Berichtenbox CPA demand today?
- **Q2.** The CPA values for a Berichtenbox sender: service, party ids, roles, endpoints, retry schedule, synchronous or asynchronous acknowledgement.
- **Q3.** Which document wins: S1 says `Omschrijving` at most 40 characters, the XSD allows 128. And should the request import the `_128ch` types file?
- **Q4.** May a sender use WUS `ValidateAbonnementen` once per letter, and at what volume does Logius want the ebMS AbonnementService instead?
- **Q5.** How long can a `GLOBE-R-BV-Result` take?
- **Q6.** What `EindDatumHandelingsTermijn` triggers. It is in the XSD and not in S1.
- **Q7.** Does a new connection made now on GLOBE have to move to BBO later, and with how much notice?
- **Q8.** What a connection costs, for an organisation and for an intermediary.
- **Q9.** Does `BerichtBestaatAl` on a resend mean the first letter reached the citizen?
