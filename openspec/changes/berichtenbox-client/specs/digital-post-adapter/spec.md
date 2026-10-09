## RENAMED Requirements

- FROM: `### Requirement: Signing material is resolved by reference, never passed by value (REQ-DPA-004)`
- TO: `### Requirement: The transport certificate is held encrypted and named by reference (REQ-DPA-004)`

## MODIFIED Requirements

### Requirement: The transport certificate is held encrypted and named by reference (REQ-DPA-004)

The Berichtenbox binding MUST NOT sign letters or SOAP requests, because the Logius interface does not accept a signature (Technische Aansluithandleiding MijnOverheid Berichtenbox 1.6.4, sections 3.2 and 6.2). It MUST authenticate by two-way TLS with a PKIoverheid certificate. The certificate and key for the WUS leg MUST be stored encrypted at rest through integriq's mTLS transport and named in the source by `certificateRef`. They MUST NOT appear in plain source configuration, an app-config string, a log line, an API response, or a plain method argument. A certificate that is missing, unreadable, expired or locked by a wrong passphrase MUST fail the send closed with that reason.

#### Scenario: A send names a certificate reference, not a key
- GIVEN a live Berichtenbox source with a `certificateRef` to an encrypted certificate
- WHEN a subscription check runs
- THEN the TLS client certificate is resolved through the mTLS transport
- AND no certificate or key appears in the call arguments, the log or the source API response
- @e2e exclude backend credential path; covered by PHPUnit with a recording logger and a test certificate

#### Scenario: An expired certificate fails closed
- GIVEN a live Berichtenbox source whose certificate has expired
- WHEN a letter is sent
- THEN the send is refused with a reason naming the expired certificate
- AND nothing is posted to the ebMS adapter or to Logius
- @e2e exclude backend credential path; covered by PHPUnit with an expired test certificate

#### Scenario: Nothing is signed
- GIVEN a live Berichtenbox source
- WHEN a letter and a subscription check are sent to the fake provider
- THEN neither payload carries an XML signature or a WS-Security header
- @e2e exclude wire format; covered by PHPUnit against the recorded fake requests

### Requirement: One Berichtenbox code path, built on the client that ships (REQ-DPA-006)

The `berichtenbox` binding MUST be implemented over the existing `lib/Adapters/Berichtenbox/BerichtenboxClient.php` contract, its mock, its refusing binding and one live binding. Integriq MUST NOT carry a second, parallel Berichtenbox client. The contract MUST NOT keep operations the Logius interface does not have: no webhook verification, no OAuth. The catalog descriptor `adapter:berichtenbox` MUST name the Logius product (MijnOverheid Berichtenbox), the interfaces (Digikoppeling ebMS and WUS) and what a connection needs (a Logius aansluiting, a PKIoverheid certificate with the sender's OIN, an ebMS adapter). It MUST NOT name OAuth credentials or a "BBK 1.7".

#### Scenario: The binding uses the shipped client
- GIVEN the digital post provider registry resolves `berichtenbox`
- WHEN the binding sends
- THEN it calls the existing `BerichtenboxClient` contract
- AND no second Berichtenbox client class exists in the repo
- @e2e exclude structural rule; covered by PHPUnit and review

#### Scenario: The catalog entry names the product and what it needs
- GIVEN an operator reads the connector catalog
- WHEN they open the Berichtenbox descriptor
- THEN it names MijnOverheid Berichtenbox, Digikoppeling ebMS and WUS, the PKIoverheid certificate and the ebMS adapter
- AND it does not mention OAuth or BBK 1.7
- e2e: `tests/e2e/digital-post-source.spec.ts`

## ADDED Requirements

### Requirement: The live binding speaks the interface Logius publishes (REQ-DPA-008)

With `logius.berichtenbox.feature_flag` set and a complete source, the Berichtenbox client MUST resolve to `BerichtenboxClientHttp`. It MUST deliver letters as a `GLOBE-R-BV-Request` batch through the operator's Digikoppeling ebMS adapter, over the adapter's REST API, with the `cpaId`, party ids, service and action from the source configuration. It MUST check subscriptions with WUS `ValidateAbonnementen` over integriq's mTLS transport. It MUST collect `GLOBE-R-BV-Result` messages and transport events from the adapter. A flagged source that lacks the adapter URL, the CPA values, the BerichtType mapping or the certificate MUST stay on the refusing binding and MUST name each missing value.

#### Scenario: The flag and a complete source select the live binding
- GIVEN the flag is `1` and the source has every required value
- WHEN the client is resolved
- THEN it is `BerichtenboxClientHttp`, and a send is not marked simulated
- @e2e exclude dependency-injection binding; covered by PHPUnit

#### Scenario: A flagged source without an adapter refuses and says why
- GIVEN the flag is `1` and the source has no `adapterUrl`
- WHEN an operator sends a letter
- THEN the send is refused with a message naming the ebMS adapter
- AND no letter reaches `sent`
- e2e: `tests/e2e/digital-post-source.spec.ts`

#### Scenario: A letter goes out as one batch with one letter
- GIVEN a live source pointed at the fake provider
- WHEN a letter is sent
- THEN the fake records one `POST messages` with action `GLOBE-R-BV-Request`
- AND its payload validates against the vendored `GLOBEBatchRequest.xsd` with one `Bericht`
- AND the `digitalPostMessage` stores `batchId`, `berichtId` as `providerReference`, and the adapter's message id
- @e2e exclude backend transport; covered by PHPUnit and the live proof against the fake

### Requirement: The subscription is checked before every send (REQ-DPA-009)

Before a letter is offered, the binding MUST ask `ValidateAbonnementen` for the recipient's BSN and the letter's BerichtType. When `isBerichtSturen` is `false`, the send MUST be refused with code `not_subscribed` and nothing MUST be sent. A WUS fault MUST refuse the send with the fault text. A subscription answer MUST NOT be used for a batch created more than 7 days later.

#### Scenario: A citizen who is not subscribed is refused before sending
- GIVEN the fake answers `isBerichtSturen = false` for the recipient
- WHEN dossiq sends a besluit to that BSN
- THEN the event carries refusal code `not_subscribed`
- AND the fake records no `POST messages`
- @e2e exclude cross-app typed event; covered by PHPUnit and the live proof against the fake

#### Scenario: A WUS fault is a refusal, not a send
- GIVEN the fake answers `ValidateAbonnementen` with an `ApplicationFault`
- WHEN a letter is sent
- THEN the send is refused with the fault text
- AND nothing is posted to the adapter
- @e2e exclude failure path; covered by PHPUnit

### Requirement: The letter is built to the official schema and its limits (REQ-DPA-010)

The binding MUST build every letter to the vendored Logius XSD and MUST validate it before sending. It MUST refuse, naming the field and the limit, a subject over 50 characters, a text over 4000 characters, a reference over 25 characters, more than two attachments, attachments over 500 kB together before base64, an attachment that is not a PDF, or a category with no BerichtType mapping. It MUST NOT truncate any field in silence. It MUST write line breaks as `\r\n` and give every URL a space before and after.

#### Scenario: A subject that is too long is refused, not cut
- GIVEN a letter whose subject has 51 characters
- WHEN it is sent through a live source
- THEN the send is refused with a message naming `Onderwerp` and the limit of 50
- AND the fake records no `POST messages`
- @e2e exclude backend validation; covered by PHPUnit

#### Scenario: Attachments over 500 kB are refused
- GIVEN a letter with two PDFs of 300 kB each
- WHEN it is sent through a live source
- THEN the send is refused with a message naming the 500 kB limit
- @e2e exclude backend validation; covered by PHPUnit

#### Scenario: A category with no BerichtType is refused
- GIVEN a live source whose `berichtTypes` has no entry for `case-update`
- WHEN a case update is sent
- THEN the send is refused with a message naming the category
- @e2e exclude backend validation; covered by PHPUnit

### Requirement: Logius results decide the status, and a Berichtenbox letter is never read (REQ-DPA-011)

The status job MUST collect results and transport events once per live Berichtenbox source, as the digital post account. A result `Verwerkt` MUST move the letter to `delivered`. Every other result code MUST move it to `failed` with the code and the stage in `lastError`. A transport event `FAILED` or `EXPIRED` MUST move it to `failed`. A transport `DELIVERED` MUST leave it `sent`. A Berichtenbox letter MUST NOT be set to `read`. Each change MUST dispatch `DigitalPostDeliveredEvent`. A result MUST be marked processed at the adapter only after the letter is saved.

#### Scenario: A processed letter becomes delivered
- GIVEN a letter in `sent` and a fake result `Verwerkt` for its `berichtId`
- WHEN the status job runs with no user session
- THEN the letter is `delivered`, written by the digital post account
- AND `DigitalPostDeliveredEvent` was dispatched with the letter id
- @e2e exclude background job; covered by PHPUnit and the live proof against the fake

#### Scenario: A refused letter becomes failed with the Logius code
- GIVEN a letter in `sent` and a fake result `NietActiefOfGeabonneerd`
- WHEN the status job runs
- THEN the letter is `failed` and `lastError` names `NietActiefOfGeabonneerd`
- @e2e exclude background job; covered by PHPUnit

#### Scenario: No read status is invented
- GIVEN a delivered Berichtenbox letter
- WHEN the status job runs again
- THEN the letter stays `delivered`
- @e2e exclude background job; covered by PHPUnit

#### Scenario: A result is not lost when saving fails
- GIVEN a fake result `Verwerkt` and a letter save that fails
- WHEN the status job runs
- THEN the result is not marked processed at the adapter
- AND the next run applies it
- @e2e exclude failure path; covered by PHPUnit

### Requirement: A live Berichtenbox source has no inbound post (REQ-DPA-012)

The Berichtenbox has no direction from citizen to organisation. `pollInbound()` on a live Berichtenbox source MUST return no items and MUST NOT call Logius or the adapter for inbound post. The mock's `inboundFixture` MUST stay reachable on a mock source only.

#### Scenario: The inbound job finds nothing on a live source
- GIVEN a live Berichtenbox source with an `inboundFixture` in its configuration
- WHEN the inbound job runs
- THEN no `IntakeDocumentReceivedEvent` is dispatched
- @e2e exclude background job; covered by PHPUnit

### Requirement: Tests and live proofs run against a fake built from the official files (REQ-DPA-013)

The repo MUST vendor the Logius XSD package and example messages with their source URL, download date and sha256. A test MUST fail when a vendored file changes without that record changing. The fake MUST validate every letter against the vendored XSD and answer with results that validate against the vendored response XSD. It MUST answer `ValidateAbonnementen` only to a client that presents a certificate from its test CA. Every proof run against the fake MUST say it ran against the fake.

#### Scenario: A letter the official schema rejects is rejected by the fake
- GIVEN a letter with `SoortGebruiker` other than `Burger` injected into the payload
- WHEN it reaches the fake
- THEN the fake answers `XmlValidatieTegenXsdValtNegatiefUit` for it
- @e2e exclude test harness; covered by the fake's own tests

#### Scenario: A changed vendored file is caught
- GIVEN a vendored XSD whose sha256 differs from its `SOURCE.md` entry
- WHEN the unit suite runs
- THEN a test fails naming the file
- @e2e exclude test harness; covered by PHPUnit

#### Scenario: No client certificate, no answer
- GIVEN a WUS call to the fake without a client certificate
- WHEN it connects
- THEN the TLS handshake is refused
- @e2e exclude test harness; covered by the fake's own tests

### Requirement: Opt-out and category rules run first and do not change (REQ-DPA-014)

The opt-out list and its category rules MUST run before the subscription check, exactly as they do for every digital post provider. The subscription check MUST NOT replace, relax or record into the opt-out list. A refusal for an opt-out MUST keep code `opted-out`, and a refusal for a missing subscription MUST use `not_subscribed`, so the sending app can tell them apart.

#### Scenario: An opted-out case update is refused before the subscription check
- GIVEN a recipient opted out at instance scope
- WHEN a case update is sent through a live Berichtenbox source
- THEN the send is refused with `opted-out`
- AND the fake records no `ValidateAbonnementen` call
- @e2e exclude cross-app typed event; covered by PHPUnit

#### Scenario: A besluit overrides the opt-out and still needs a subscription
- GIVEN a recipient opted out at instance scope and not subscribed in the fake
- WHEN a besluit is sent through a live Berichtenbox source
- THEN the send is refused with `not_subscribed`
- AND the opt-out log records the override as it does today
- @e2e exclude cross-app typed event; covered by PHPUnit
