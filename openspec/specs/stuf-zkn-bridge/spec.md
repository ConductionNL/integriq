# stuf-zkn-bridge Specification

## Purpose

Integriq gains a StUF-ZKN (StUF-ZKN 3.10, VNG/EGEM) bridge — the
legacy Dutch municipal SOAP/XML case-management message standard a large
share of municipalities still run in their surrounding systems (midoffice,
DMS, belastingen, KCC). Per ADR-022, integriq translates the default
OpenRegister/ZGW object APIs into other standards' shapes; this bridge lets
a municipality adopt procest without first ripping out its StUF estate —
industry research shows StUF-to-ZGW migration commonly costs 25-50% of a
zaaksysteem procurement, so migration tooling that avoids a rip-and-replace
is a material deal-winning capability. Inbound `zakLk01`/`edcLk01`
kennisgevingen upsert an OR/ZGW zaak/document object directly and reply
with a `Bv03`/`Fo03`; outbound OR/ZGW zaak changes translate to a `zakLk01`
kennisgeving dispatched to a subscribed StUF consumer, over the SAME shared
mTLS transport `iwmo-ijw-adapter`/`fsc-connectivity`/`dso-connector-adapter`
already use.

## Requirements
### Requirement: Shared XXE-hardened StUF XML parsing (REQ-000)

The system MUST provide `Service\Stuf\StufXmlParser::parse()`, extracted
from `iwmo-ijw-adapter`'s `InboundRetourTranslator::parseXml()`, shared by
every StUF-family translator that parses externally-delivered XML in this
app. Parsing MUST use `LIBXML_NONET` only, NEVER `LIBXML_NOENT`/
`LIBXML_DTDLOAD`, so external entity expansion stays disabled. The method
MUST never throw — it returns `null` on empty input, malformed XML, or any
libxml error, letting each caller raise its own domain exception.

#### Scenario: an XXE payload is rejected or left unexpanded, never resolved
- GIVEN a raw XML string carrying a `<!ENTITY xxe SYSTEM "file:///etc/passwd">` external entity reference
- WHEN `StufXmlParser::parse()` is called
- THEN the entity SHALL NEVER be expanded — the parse SHALL either fail (return null) or leave the entity reference inert
- @e2e exclude backend XML hardening — covered by PHPUnit

#### Scenario: iwmo-ijw-adapter's own retour parsing is unaffected by the extraction
- GIVEN `IwmoIjw\InboundRetourTranslator` refactored to delegate to `StufXmlParser`
- WHEN its existing PHPUnit suite runs unmodified
- THEN every prior scenario SHALL still pass, including its own XXE hardening and malformed-XML rejection
- @e2e exclude backend refactor-safety — covered by PHPUnit

### Requirement: Shared literal-leak guard (REQ-000)

The system MUST provide `Service\Stuf\StufLiteralLeakGuard::hasUnresolvedPlaceholder()`,
extracted from `iwmo-ijw-adapter`'s `OutboundBerichtTranslator::assertNoUnresolvedPlaceholder()`,
shared by every StUF-family outbound translator's defense-in-depth scan for
a leftover `{{...}}` or literal `%%UNRESOLVED%%` template marker in a
rendered envelope.

#### Scenario: a rendered envelope carrying a leftover marker is detected
- GIVEN a rendered XML string containing `{{identificatie}}` or the literal `%%UNRESOLVED%%`
- WHEN `StufLiteralLeakGuard::hasUnresolvedPlaceholder()` is called
- THEN it SHALL return true, and the calling translator SHALL refuse to send the envelope
- @e2e exclude backend literal-leak guard — covered by PHPUnit

### Requirement: Inbound zakLk01/edcLk01 translation with a literal-leak guard (REQ-002)

The system MUST translate an inbound StUF-ZKN SOAP envelope carrying a
`zakLk01` (zaak) or `edcLk01` (document) kennisgeving into a normalised
representation via `StufZkn\InboundBerichtTranslator::translate()`. The
translator MUST recognise all four StUF 3.01 core `verwerkingssoort`
codes (`T`/`W`/`I`/`V`) on the `object` element, MUST honour StUF's
`StUF:noValue="geenWaarde" xsi:nil="true"` convention (read as `null`,
never an empty-string literal), and MUST raise
`StufZknTranslationException` BEFORE returning any mapping when
`stuurgegevens.referentienummer` or `object`'s `identificatie` is
missing/empty — the translator MUST NEVER guess or synthesise either
value. Parsing MUST be XXE-hardened via the shared `StufXmlParser`.

#### Scenario: a complete zakLk01 toevoeging translates to a normalised zaak representation
- GIVEN a well-formed `zakLk01` SOAP envelope with `verwerkingssoort="T"` and every zaak field populated
- WHEN `InboundBerichtTranslator::translate()` is called
- THEN a normalised `{kind: "zaak", berichttype: "zakLk01", referentienummer, verwerkingssoort: "T", fields: {...}}` array SHALL be returned
- @e2e exclude backend translator — covered by PHPUnit

#### Scenario: a complete edcLk01 translates to a normalised document representation
- GIVEN a well-formed `edcLk01` SOAP envelope carrying `isRelevantVoor.gerelateerde.identificatie`
- WHEN `InboundBerichtTranslator::translate()` is called
- THEN a normalised `{kind: "document", ...}` array SHALL be returned, including the related zaak's `zaakIdentificatie`
- @e2e exclude backend translator — covered by PHPUnit

#### Scenario: each recognised verwerkingssoort (T/W/I/V) is accepted
- GIVEN a `zakLk01` envelope with `verwerkingssoort` set to each of `T`, `W`, `I`, `V` in turn
- WHEN translated
- THEN each SHALL be accepted and echoed back on the normalised result, and an unrecognised code SHALL raise `StufZknTranslationException`
- @e2e exclude backend translator — covered by PHPUnit

#### Scenario: a noValue/nil field is read as null, never an empty-string literal
- GIVEN an object field marked `StUF:noValue="geenWaarde" xsi:nil="true"`
- WHEN translated
- THEN the corresponding normalised field SHALL be `null`, distinct from a present-but-empty value
- @e2e exclude backend translator — covered by PHPUnit

#### Scenario: a missing referentienummer or identificatie never reaches an OR mapping — literal-leak guard
- GIVEN a `zakLk01` envelope missing `stuurgegevens.referentienummer` (or, separately, `object.identificatie`)
- WHEN `InboundBerichtTranslator::translate()` is called
- THEN `StufZknTranslationException` SHALL be raised before any mapping is returned — no guessed correlation reference or business key
- @e2e exclude backend literal-leak guard — covered by PHPUnit

#### Scenario: an XXE payload in an inbound envelope is rejected or left unexpanded
- GIVEN an inbound envelope carrying an external entity reference
- WHEN translated
- THEN the entity SHALL never be resolved into the translation result — proven by the translator either raising `StufZknTranslationException` (malformed after the DOCTYPE-carrying parse) or, if parsed, the result never surfacing external file content
- @e2e exclude backend XML hardening — covered by PHPUnit

### Requirement: Outbound zakLk01 kennisgeving translation with a literal-leak guard (REQ-003)

The system MUST translate an OR/ZGW zaak object plus a `verwerkingssoort`
(`T`/`W`/`V`) into a `zakLk01` StUF-ZKN SOAP envelope via
`StufZkn\OutboundKennisgevingTranslator::translate()`. Any required field
(`identificatie`, `omschrijving`, `zaaktypeCode`, `registratiedatum`,
`startdatum`) that is missing, null, or an empty/whitespace-only string
MUST raise `StufZknTranslationException` BEFORE any XML is built. The
envelope MUST NEVER contain an empty tag for a missing optional field —
absent optional fields MUST render using StUF's `noValue`/`xsi:nil`
convention. The fully rendered envelope MUST also be scanned via the
shared `StufLiteralLeakGuard` as defense in depth.

#### Scenario: a complete zaak create translates to a valid zakLk01 toevoeging
- GIVEN an OR/ZGW zaak object with every required field populated
- WHEN `OutboundKennisgevingTranslator::translate()` is called with `verwerkingssoort: "T"`
- THEN a well-formed `zakLk01` envelope SHALL be returned with `StUF:verwerkingssoort="T"` and every populated field present
- @e2e exclude backend translator — covered by PHPUnit

#### Scenario: a missing required field never reaches the XML — literal-leak guard
- GIVEN an OR/ZGW zaak object missing `zaaktypeCode`
- WHEN `OutboundKennisgevingTranslator::translate()` is called
- THEN `StufZknTranslationException` SHALL be raised before any XML is built
- @e2e exclude backend literal-leak guard — covered by PHPUnit

#### Scenario: the rendered envelope round-trips structurally through the inbound translator
- GIVEN a zaak translated outbound to a `zakLk01` envelope
- WHEN that envelope is fed back through `InboundBerichtTranslator::translate()`
- THEN the same `kind`, `verwerkingssoort`, `identificatie`, and `referentienummer` SHALL be recovered — proving both directions agree on the same wire shape
- @e2e exclude backend round-trip proof — covered by PHPUnit

### Requirement: Bv03/Fo03 acknowledgement shaping (REQ-004)

The system MUST reply to every inbound StUF-ZKN kennisgeving with either a
`Bv03Bericht` (success) or `Fo03Bericht` (fault) via
`StufZkn\StufZknAcknowledgementBuilder`, correlated to the inbound
message's own `referentienummer` via `StUF:crossRefnummer`. `Fo03Bericht`
MUST NEVER include internal exception detail (stack traces, file paths,
raw exception messages) — `omschrijving` MUST come from a small, fixed,
secret-free fault catalogue keyed by a stable reason string, never from a
caught exception's own message text.

#### Scenario: a successfully processed zakLk01 replies with a Bv03
- GIVEN an inbound `zakLk01` that translates and upserts successfully
- WHEN the reply is built
- THEN a well-formed `Bv03Bericht` SHALL be returned carrying `StUF:crossRefnummer` equal to the inbound message's `referentienummer`
- @e2e exclude backend ack shaping — covered by PHPUnit

#### Scenario: an unprocessable message replies with a Fo03 and leaks no internal detail
- GIVEN an inbound message that fails translation or OR upsert with an exception carrying sensitive detail
- WHEN the fault reply is built
- THEN the `Fo03Bericht`'s `omschrijving` SHALL be the fixed catalogue text only — the raw exception message SHALL NEVER appear anywhere in the reply XML
- @e2e exclude backend fault-safety — covered by PHPUnit

### Requirement: Inbound SOAP endpoint with Bv03/Fo03 shaping (REQ-005)

`POST /api/stuf-zkn/inbound` MUST accept a raw StUF-ZKN SOAP envelope,
gated by the same HMAC scheme `IwmoIjwController::inbound()` uses
(`WebhookSignatureService`, `X-OpenConnector-Signature` over the raw
bytes, verified against the active source's
`configuration.webhookSignature`). With no active `type=stuf-zkn` source
configured, the endpoint MUST fail closed with 401 (no secret exists to
verify against). An unsigned/tampered request MUST be rejected 401 BEFORE
any XML is parsed, with an undifferentiated error body that never reveals
which check failed. A verified request MUST always receive a `Bv03`/`Fo03`
XML body reply (never a 500) — `StufZknSyncService::receiveInbound()`
never throws out to the controller.

#### Scenario: no source configured fails the endpoint closed
- GIVEN no active `type=stuf-zkn` source exists
- WHEN a request is posted to the inbound endpoint
- THEN the response SHALL be 401, and signature verification SHALL never even run
- @e2e exclude backend auth gate — covered by PHPUnit

#### Scenario: an unsigned or tampered request is rejected before any processing
- GIVEN an active source with a configured webhook secret
- WHEN a request arrives with a missing or invalid signature
- THEN the response SHALL be 401 with an undifferentiated error body, and `receiveInbound()` SHALL never be called
- @e2e exclude backend auth gate — covered by PHPUnit

#### Scenario: a verified request always receives an XML Bv03/Fo03 reply
- GIVEN a verified signature
- WHEN the request is processed (successfully or not)
- THEN the HTTP response SHALL be 200 with `Content-Type: text/xml; charset=utf-8` carrying the sync service's `Bv03`/`Fo03` reply verbatim
- @e2e exclude backend endpoint shaping — covered by PHPUnit

### Requirement: StufZkn outbound provider abstraction with log and REST bindings (REQ-001)

The system MUST define `StufZkn\StufZknProviderInterface`
(`getProviderId()`, `getConfigSchema()`, `send(sourceConfiguration,
referentienummer, envelopeXml)`), selected at runtime via a
`type=stuf-zkn` source's `configuration.provider` (`log`|`rest`,
defaulting to `log`). `log` (`LogStufZknProvider`) MUST perform no network
call and MUST NOT read any secret. `rest` (`StufZknClient`) MUST dispatch
over the SAME shared `MtlsTransportService`/`MtlsConfigResolver`
(`authentication.mode`: `token`|`mtls`) pattern already proven by
`IStandaardenClient`/`FscDirectoryClient`/`DsoClient` — routing through
mTLS ONLY when `authentication.mode=mtls` is configured, never
implementing a new/bespoke TLS layer.

#### Scenario: the log provider sends nothing over the network and returns a synthetic ref
- GIVEN a source with `configuration.provider: log` (or absent)
- WHEN `send()` is called
- THEN a synthetic `MOCK-STUFZKN-<n>` ref SHALL be returned with no outbound HTTP call
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the REST provider sends the expected content-type and mTLS routing
- GIVEN a source with `configuration.provider: rest`
- WHEN `send()` is dispatched with `authentication.mode=mtls` configured
- THEN the request SHALL route through `MtlsTransportService` (not the plain Guzzle client) carrying `Content-Type: text/xml; charset=utf-8` and the envelope XML verbatim as the raw body
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: token mode never routes through the mTLS transport
- GIVEN a source with `authentication.mode` absent or `token`
- WHEN `send()` is dispatched
- THEN `MtlsTransportService::request()` SHALL never be invoked — token mode is unchanged
- @e2e exclude backend provider binding — covered by PHPUnit

### Requirement: Outbound kennisgeving dispatch with per-message audit (REQ-006)

`POST /api/stuf-zkn/kennisgevingen` MUST be an authenticated NC-session
endpoint (mirrors `IwmoIjwController::createBericht()`) accepting `{zaak,
verwerkingssoort}`, translating and dispatching via the resolved provider,
and persisting a `stuf_message` audit row (`direction: outbound`) for
every attempt regardless of outcome — a failed transport send MUST persist
`status: failed` + the error BEFORE rethrowing, never silently drop the
attempt. A `StufZknRetryJob` (hourly `TimedJob`) MUST re-attempt every
`stuf_message` row with `status: failed` (direction=outbound) through the
currently configured provider, isolated per-row — one row's retry
exception MUST be logged and skipped, never aborting the sweep.

#### Scenario: a successful outbound send persists a sent record
- GIVEN a valid zaak and an active source (log provider)
- WHEN `sendKennisgeving()` is called
- THEN a `stuf_message` row SHALL be persisted with `direction: outbound`, `status: sent`
- @e2e exclude backend per-message audit — covered by PHPUnit

#### Scenario: a failed outbound send persists a failed record and rethrows
- GIVEN an active source whose provider transport fails
- WHEN `sendKennisgeving()` is called
- THEN a `stuf_message` row SHALL be persisted with `status: failed` and the error detail BEFORE `StufZknProviderException` is rethrown
- @e2e exclude backend per-message audit — covered by PHPUnit

#### Scenario: a request with no active source returns not_configured
- GIVEN no active `type=stuf-zkn` source
- WHEN the outbound endpoint is called
- THEN the response SHALL be 503 with `error: "not_configured"`, not an unhandled exception
- @e2e exclude backend not-configured behaviour — covered by PHPUnit

#### Scenario: one failing retry does not abort the sweep
- GIVEN two failed `stuf_message` rows, one of which fails again on retry
- WHEN `StufZknRetryJob::run()` executes
- THEN the other row SHALL still be retried and marked `sent` — the sweep SHALL NOT abort on the first failure
- @e2e exclude backend retry isolation — covered by PHPUnit

### Requirement: Idempotent redelivery and OR upsert-by-identificatie (REQ-007)

A redelivered inbound message (identical `stuurgegevens.referentienummer`, previously fully processed) MUST NOT create a second `stuf_message` audit row and MUST NOT re-apply its OR write — it MUST still receive a fresh `Bv03` acknowledgement. `verwerkingssoort: V` (vervallen) on an `identificatie` with an existing OR record MUST mark it `status: vervallen` rather than deleting it; on an `identificatie` with no existing record it MUST be a no-op (still acknowledged, nothing written).

#### Scenario: a redelivered inbound message is acknowledged without duplicating state
- GIVEN a `zakLk01` already processed once (its `stuf_message` row has `status: processed`)
- WHEN the identical envelope (same `referentienummer`) is received again
- THEN a `Bv03` SHALL still be returned, but no new `stuf_message` row and no new OR upsert SHALL occur
- @e2e exclude backend idempotency — covered by PHPUnit

#### Scenario: vervallen marks an existing zaak vervallen rather than deleting it
- GIVEN an existing OR zaak matching the inbound `identificatie`
- WHEN a `zakLk01` with `verwerkingssoort="V"` is received
- THEN the OR record's `status` SHALL be set to `vervallen`, and no other fields SHALL be wiped
- @e2e exclude backend vervallen handling — covered by PHPUnit

#### Scenario: inbound receipt works even with no active source configured
- GIVEN no active `type=stuf-zkn` source exists
- WHEN a signature-verified `zakLk01` is received (signature verified against a source that DOES exist but is not `isEnabled`, or against a fallback path)
- THEN the message SHALL still translate, upsert into the default target register/schema, and receive a `Bv03` — inbound receipt never depends on outbound configuration
- @e2e exclude backend not-configured resilience — covered by PHPUnit

### Requirement: stuf_message OR schema and orphaned-capability-proof routing (REQ-008)

A `stuf_message` schema MUST be declared in the `openconnector` register
(`direction`, `berichttype`, `referentienummer`, `verwerkingssoort`,
`entiteittype`, `status`, `error`, `syncedAt`) and MUST be present in both
`components.registers.openconnector.schemas[]` and `components.schemas` —
double-checked against the register descriptor's own `RegisterDescriptorTest`,
per the fleet's documented "schemas list can silently drift" lesson. Every
route (`stufZkn#inbound`, `stufZkn#outbound`) and the `StufZknRetryJob`
background job MUST be wired AND covered by a test proving actual
invocation, not merely declared.

#### Scenario: the register descriptor declares stuf_message consistently
- GIVEN `lib/Settings/integriq_register.json`
- WHEN `RegisterDescriptorTest` runs
- THEN `stuf_message` SHALL appear in both the register's `schemas[]` list and `components.schemas`, with a non-empty `properties` block
- @e2e exclude backend descriptor integrity — covered by PHPUnit

#### Scenario: the retry job actually invokes the sync service
- GIVEN `StufZknRetryJob::run()`
- WHEN executed
- THEN `StufZknSyncService::retryFailed()` SHALL be invoked exactly once per run — proving wiring, not just declaration
- @e2e exclude backend orphaned-capability proof — covered by PHPUnit

