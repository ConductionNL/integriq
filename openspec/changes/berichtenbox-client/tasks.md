# Tasks: berichtenbox-client (integriq)

Ruben approved the decisions on 2026-10-07 (design.md section 12). Tests fail first, through the real path, as the digital post account. Every proof before Logius preproductie runs against the fake and says so.

Archive order: `berichtenbox-digital-post-adapter` archives first. This change modifies and renames requirements in its `digital-post-adapter` spec, and `openspec archive` refuses that until the spec exists.

## 0. Decisions before building

- [x] 0.1 Ruben decided D1 to D8 on 2026-10-07, all as recommended (design.md section 12).
- [ ] 0.2 The questions Q1 to Q9 go to Logius (through the Logius business consultant), with the answers recorded in design.md. Q2 (CPA values) and Q3 (which limit wins) block task 3.

## 1. Vendored contract and the fake

- [ ] 1.1 Vendor the Logius XSD package and the example messages under `tests/fixtures/berichtenbox/logius/`, with `SOURCE.md` (URL, download date, sha256 per file) and an XML catalog that maps the `GLOBEBatchRequestTypes.xsd` import to the shipped `_128ch` file.
  - spec_ref: `specs/digital-post-adapter/spec.md#requirement-tests-and-live-proofs-run-against-a-fake-built-from-the-official-files-req-dpa-013`
  - files: `tests/fixtures/berichtenbox/logius/`, `tests/Unit/Adapters/Berichtenbox/VendoredContractTest.php`
- [ ] 1.2 The fake: the ebms-core REST subset, XSD validation of every letter, results that validate against the response XSD, WUS `ValidateAbonnementen` behind a test CA, fixed test BSNs per outcome, a `fail` file for 503.
  - files: `tests/fake/berichtenbox/fake.py`, its own tests, `tests/fake/berichtenbox/README.md`

## 2. Contract

- [ ] 2.1 Rewrite `BerichtenboxClient` to `checkSubscriptions`, `deliver`, `collectResults`, `transportEvents`. Remove `verifyWebhook` and `checkMailbox`. Update the mock, the refusing binding and `BerichtenboxSourceAdapter`.
  - spec_ref: `specs/digital-post-adapter/spec.md#requirement-one-berichtenbox-code-path-built-on-the-client-that-ships-req-dpa-006`
  - files: `lib/Adapters/Berichtenbox/*.php`, `lib/Sources/Berichtenbox/BerichtenboxSourceAdapter.php`, their tests
- [ ] 2.2 Rewrite the `adapter:berichtenbox` catalog text and `BerichtenboxProvider::getConfigSchema()`: MijnOverheid Berichtenbox, Digikoppeling ebMS and WUS, PKIoverheid, ebMS adapter. Drop OAuth, BBK 1.7 and `priority`. Add `adapterUrl`, `adapterAuth`, `cpaId`, `fromPartyId`, `toPartyId`, `service`, `berichtTypes`, `wusEndpoint`.
  - files: `lib/Service/CatalogRegistryService.php`, `lib/Service/DigitalPost/BerichtenboxProvider.php`, `tests/e2e/digital-post-source.spec.ts`

## 3. Live binding

- [ ] 3.1 `BerichtenboxLetterBuilder`: one batch, one letter, GUIDs, Zulu dates, `\r\n`, spaced URLs, `Referentie` only when it fits, validation against the vendored XSD, refusals that name the field and the limit.
  - spec_ref: `specs/digital-post-adapter/spec.md#requirement-the-letter-is-built-to-the-official-schema-and-its-limits-req-dpa-010`
  - files: `lib/Adapters/Berichtenbox/BerichtenboxLetterBuilder.php`, its test
- [ ] 3.2 WUS subscription check over `MtlsTransportService`: SOAP 1.1 request per the WSDL, `isBerichtSturen` per BSN, faults as refusals.
  - spec_ref: `specs/digital-post-adapter/spec.md#requirement-the-subscription-is-checked-before-every-send-req-dpa-009`
  - files: `lib/Adapters/Berichtenbox/BerichtenboxValidatieClient.php`, its test
- [ ] 3.3 `BerichtenboxClientHttp`: deliver through the adapter REST API, collect results and transport events, mark processed only after save.
  - spec_ref: `specs/digital-post-adapter/spec.md#requirement-the-live-binding-speaks-the-interface-logius-publishes-req-dpa-008`
  - files: `lib/Adapters/Berichtenbox/BerichtenboxClientHttp.php`, its test
- [ ] 3.4 Binding selection in `Application.php`: flag on and complete source gives the live binding; anything missing keeps the refusing binding and names each missing value.
  - files: `lib/AppInfo/Application.php`, `lib/Adapters/Berichtenbox/BerichtenboxClientUnavailable.php`, their tests
- [ ] 3.5 Certificate: `certificateRef` resolves through `MtlsConfigResolver`; expiry, wrong passphrase and missing material fail closed; no key in logs or API responses; setup check warns 30 days before expiry.
  - spec_ref: `specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004`
  - files: `lib/Service/DigitalPost/BerichtenboxProvider.php`, `lib/SetupCheck/DigitalPostAccountCheck.php`, their tests

## 4. Provider and statuses

- [ ] 4.1 `BerichtenboxProvider::send()`: category to BerichtType, subscription check, build, deliver, store `batchId`, `berichtId`, `transportMessageId`; `not_subscribed` distinct from `opted-out`.
  - spec_ref: `specs/digital-post-adapter/spec.md#requirement-opt-out-and-category-rules-run-first-and-do-not-change-req-dpa-014`
  - files: `lib/Service/DigitalPost/BerichtenboxProvider.php`, `lib/Settings/integriq_register.json` (`digitalPostMessage` gains `batchId` and `transportMessageId`, minor version bump), their tests
- [ ] 4.2 Status job: one collect per live source, the mapping table in design.md section 6, never `read`, `DigitalPostDeliveredEvent` per change.
  - spec_ref: `specs/digital-post-adapter/spec.md#requirement-logius-results-decide-the-status-and-a-berichtenbox-letter-is-never-read-req-dpa-011`
  - files: `lib/BackgroundJob/DigitalPostStatusJob.php`, `lib/Service/DigitalPost/DigitalPostService.php`, their tests
- [ ] 4.3 `pollInbound()` returns nothing on a live source.
  - spec_ref: `specs/digital-post-adapter/spec.md#requirement-a-live-berichtenbox-source-has-no-inbound-post-req-dpa-012`
  - files: `lib/Service/DigitalPost/BerichtenboxProvider.php`, its test

## 5. Live proof against the fake

- [ ] 5.1 dossiq to integriq to the fake: a subscribed besluit reaches `delivered`; a not-subscribed citizen is refused with `not_subscribed` and nothing is posted; an opted-out case update is refused before the subscription check; a 51-character subject is refused; a `BijlageTeGroot` result becomes `failed`; an `EXPIRED` transport event becomes `failed`; the status job runs with no session.
- [ ] 5.2 Record every request the fake saw, and confirm every recorded letter validates against the vendored XSD.

## 6. Logius preproductie (after the aansluiting, not in this PR)

- [ ] 6.1 Run checklist "Testen Berichtenbox MijnOverheid" 1.4 against preproductie with two test DigiD accounts.
- [ ] 6.2 Write the test report for Logius.
