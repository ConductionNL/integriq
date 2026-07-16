# Tasks — stuf-zkn-bridge

## 1. Shared StUF helpers (extracted from iwmo-ijw-adapter)

### Task 1: Extract StufXmlParser + StufLiteralLeakGuard; adopt in iwmo-ijw-adapter
- **spec_ref**: `openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-shared-xxe-hardened-stuf-xml-parsing-req-000`
- **files**: `lib/Service/Stuf/StufXmlParser.php`, `lib/Service/Stuf/StufLiteralLeakGuard.php`, `lib/Service/IwmoIjw/InboundRetourTranslator.php`, `lib/Service/IwmoIjw/OutboundBerichtTranslator.php`
- **acceptance_criteria**:
  - GIVEN an XXE payload WHEN `StufXmlParser::parse()` runs THEN the external entity is never resolved (parse fails or the reference is left inert)
  - GIVEN a rendered XML string with a `{{...}}`/`%%UNRESOLVED%%` marker WHEN `StufLiteralLeakGuard::hasUnresolvedPlaceholder()` runs THEN it returns true
  - GIVEN `iwmo-ijw-adapter`'s translators refactored to delegate to the shared helpers WHEN its full existing PHPUnit suite runs unmodified THEN every test still passes
- [x] Implement
- [x] Test

## 2. Data model

### Task 2: Declare the `stuf_message` schema
- **spec_ref**: `openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-stuf_message-or-schema-and-orphaned-capability-proof-routing-req-008`
- **files**: `lib/Settings/openconnector_register.json`, `tests/Unit/Settings/RegisterDescriptorTest.php`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN a `stuf_message` schema exists with `direction` enum `outbound|inbound`, `berichttype`, `referentienummer`, `verwerkingssoort` enum `T|W|I|V`, `entiteittype` enum `ZAK|EDC`, `status`, `error`
  - GIVEN the register's schemas list WHEN compared to `components.schemas` THEN `stuf_message` is listed in both
  - GIVEN `RegisterDescriptorTest::SCHEMA_SLUGS` WHEN updated THEN `stuf_message` is included and the test passes
- [x] Implement
- [x] Test

## 3. Translators

### Task 3: Add InboundBerichtTranslator (zakLk01/edcLk01 -> normalised zaak/document) with literal-leak guard
- **spec_ref**: `openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-inbound-zaklk01-edclk01-translation-with-a-literal-leak-guard-req-002`
- **files**: `lib/Service/StufZkn/InboundBerichtTranslator.php`, `lib/Service/StufZkn/StufZknNamespaces.php`, `lib/Exception/StufZknTranslationException.php`
- **acceptance_criteria**:
  - GIVEN a complete `zakLk01`/`edcLk01` envelope WHEN translated THEN a normalised `{kind, berichttype, referentienummer, verwerkingssoort, fields}` representation is returned
  - GIVEN each of `verwerkingssoort` T/W/I/V WHEN translated THEN each is accepted and echoed back; an unrecognised code raises `StufZknTranslationException`
  - GIVEN a `StUF:noValue`/`xsi:nil` field WHEN translated THEN it reads as `null`, never an empty-string literal
  - GIVEN a missing/empty `referentienummer` or `identificatie` WHEN translated THEN `StufZknTranslationException` is raised before any mapping is returned
  - GIVEN an XXE payload WHEN translated THEN the external entity is never resolved into the result
- [x] Implement
- [x] Test

### Task 4: Add OutboundKennisgevingTranslator (OR/ZGW zaak -> zakLk01) with literal-leak guard
- **spec_ref**: `openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003`
- **files**: `lib/Service/StufZkn/OutboundKennisgevingTranslator.php`
- **acceptance_criteria**:
  - GIVEN a complete zaak object WHEN translated with `verwerkingssoort: T`/`W`/`V` THEN a well-formed `zakLk01` envelope is returned carrying the correct `StUF:verwerkingssoort` attribute
  - GIVEN a missing required field WHEN translated THEN `StufZknTranslationException` is raised naming the field and no XML is returned
  - GIVEN a rendered envelope WHEN fed back through `InboundBerichtTranslator` THEN the same `kind`/`verwerkingssoort`/`identificatie`/`referentienummer` are recovered
- [x] Implement
- [x] Test

### Task 5: Add StufZknAcknowledgementBuilder (Bv03/Fo03 reply shaping)
- **spec_ref**: `openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-bv03-fo03-acknowledgement-shaping-req-004`
- **files**: `lib/Service/StufZkn/StufZknAcknowledgementBuilder.php`
- **acceptance_criteria**:
  - GIVEN a successfully processed message WHEN `buildBv03()` is called THEN a well-formed `Bv03Bericht` is returned with `StUF:crossRefnummer` set to the inbound `referentienummer`
  - GIVEN an exception carrying sensitive detail WHEN `buildFo03()` is called with a stable reason key THEN the reply's `omschrijving` is the fixed catalogue text only — the raw exception message never appears
- [x] Implement
- [x] Test

## 4. Provider abstraction

### Task 6: Add the StufZkn provider interface with log and REST/mTLS bindings
- **spec_ref**: `openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001`
- **files**: `lib/Service/StufZkn/StufZknProviderInterface.php`, `lib/Service/StufZkn/LogStufZknProvider.php`, `lib/Service/StufZkn/StufZknClient.php`, `lib/Exception/StufZknProviderException.php`
- **acceptance_criteria**:
  - GIVEN `configuration.provider: log` (or absent) WHEN `send()` is called THEN a synthetic `MOCK-STUFZKN-<n>` ref is returned with no HTTP call
  - GIVEN `configuration.provider: rest` and `authentication.mode: mtls` WHEN `send()` is called THEN the request routes through the shared `MtlsTransportService` (not the plain Guzzle client) carrying `Content-Type: text/xml; charset=utf-8` and the envelope verbatim
  - GIVEN `authentication.mode: token` (default) WHEN `send()` is called THEN `MtlsTransportService::request()` is never invoked
- [x] Implement
- [x] Test

## 5. Sync orchestration

### Task 7: Add StufZknSyncService (receive inbound, send outbound, idempotency, retry)
- **spec_ref**: `openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-idempotent-redelivery-and-or-upsert-by-identificatie-req-007`
- **files**: `lib/Service/StufZknSyncService.php`
- **acceptance_criteria**:
  - GIVEN a well-formed `zakLk01` WHEN `receiveInbound()` runs THEN the target zaak is upserted by `identificatie`, a `stuf_message` row is persisted, and a `Bv03` is returned
  - GIVEN a redelivered `referentienummer` already `status: processed` WHEN received again THEN no second `stuf_message` row and no second OR upsert occur, but a `Bv03` is still returned
  - GIVEN `verwerkingssoort: V` on an existing zaak WHEN received THEN the record is marked `status: vervallen`, never hard-deleted; on an unknown `identificatie` it is a no-op
  - GIVEN no active source configured WHEN `receiveInbound()` runs THEN the message still translates/upserts/acknowledges (inbound never depends on outbound configuration)
  - GIVEN a successful/failed outbound send WHEN `sendKennisgeving()` runs THEN a `stuf_message` row is persisted `status: sent`/`failed` accordingly, failed persisted BEFORE rethrow
  - GIVEN two failed rows, one erroring on retry WHEN `retryFailed()` runs THEN the failing row is logged/skipped and the other is still retried
- [x] Implement
- [x] Test

## 6. REST surface + scheduled job

### Task 8: Add StufZknController (signed inbound SOAP + authenticated outbound push) + routes
- **spec_ref**: `openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-inbound-soap-endpoint-with-bv03-fo03-shaping-req-005`
- **files**: `lib/Controller/StufZknController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN no active source WHEN `POST /api/stuf-zkn/inbound` is called THEN HTTP 401 is returned, signature verification never runs
  - GIVEN an unsigned/tampered request WHEN posted THEN HTTP 401 is returned with an undifferentiated error body, `receiveInbound()` never called
  - GIVEN a verified request WHEN processed (success or failure) THEN HTTP 200 with `Content-Type: text/xml; charset=utf-8` carrying the Bv03/Fo03 reply verbatim (never 500)
  - GIVEN an authenticated session and a configured source WHEN `POST /api/stuf-zkn/kennisgevingen` is called with a complete payload THEN `{referentienummer, ref}` is returned
  - GIVEN no active source WHEN the outbound endpoint is called THEN HTTP 503 `not_configured` is returned
- [x] Implement
- [x] Test

### Task 9: Add StufZknRetryJob (hourly TimedJob) + info.xml registration
- **spec_ref**: `openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-outbound-kennisgeving-dispatch-with-per-message-audit-req-006`
- **files**: `lib/Cron/StufZknRetryJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN no failed `stuf_message` rows WHEN the job runs THEN it completes as a no-op (0 retried), no exception, no error log
  - GIVEN a sweep-level exception WHEN the job runs THEN it is caught and logged — never wedges the cron pipeline
  - GIVEN the job IS wired in `info.xml` and routes exist AND a test proves the job actually invokes `StufZknSyncService::retryFailed()` THEN the orphaned-capability rule is satisfied (not just declared)
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate --strict` passes (this change only)
- [x] Manual testing against acceptance criteria (sandbox `log` provider path) — exercised via the PHPUnit suite, including a structural round-trip proof between the outbound and inbound translators
- [x] Code review against spec requirements — self-reviewed; see Deviations below
- [x] `composer lint`, `phpcs`, `phpmd`, `phpstan`, `psalm`, `check:no-legacy-types`, `check:routes` clean on the new/modified files; full suite (1730 tests) diffed against the pristine `origin/development` baseline — only the 2 pre-existing, unrelated failures remain (`SynchronizationServiceTest::testHtmlSourceExtractsTableRowsViaCssSelectors` — missing `symfony/dom-crawler`; `JsonLogicFilterDialectTest::testPreExistingDialectsStillMatch` — missing `symfony/expression-language`), both present before this change and untouched by it

## Deviations

- **No live municipal StUF-ZKN instance was available to verify the wire
  shape against** — every namespace/element/attribute/`Fo03` fault-code
  assumption is documented in design.md §3/§11 "Open Questions", grounded
  in the published StUF 3.01 core standard and StUF-ZKN 3.10 sector-model
  naming convention.
- **`I` (wijziging van een identificerend gegeven) handling is a documented
  simplification** — upserts by the given `identificatie` exactly as `W`
  does; no rename/re-key operation is attempted. See design.md §3.3.
- **Outbound `edcLk01` is out of scope** — only inbound document intake
  and outbound zaak kennisgeving are implemented.
- **`isVan`/`heeftAlsInitiator`/`heeftBetrekkingOp` relation blocks are not
  modelled** — this change targets the zaak/document's own fields only.
- **The semantic-case-intake `HandoffService` mechanism is deliberately NOT
  used for the inbound leg** — direct OR object upsert instead; see
  design.md §4 for the full architectural rationale.
- **`source.type = 'stuf-zkn'`** was added as a new recognised (free-form,
  per the schema's own documented extensibility) value.
