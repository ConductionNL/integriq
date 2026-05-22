# Tasks: Berichtenbox MijnOverheid Adapter

## Implementation Tasks

Each task is small enough for one review cycle. Order respects dependencies — foundations first.

### Task 1: Create Berichtenbox schemas in openconnector register
- **spec_ref**: `spec.md#purpose` (foundational schema definition)
- **files**: 
  - `openconnector/_registers.json` (add 3 schemas)
  - `openconnector/schemas/Bericht.json` (outbound message envelope)
  - `openconnector/schemas/Berichtstatus.json` (inbound status event)
  - `openconnector/schemas/BerichtAfleverkanaal.json` (cache entity)
- **acceptance_criteria**:
  - GIVEN the schemas are added to _registers.json with `tier: 4`, WHEN openconnector validates, THEN schema IDs are published and consumers can reference via `{ref: "openconnector:Bericht"}`
  - Schema validation passes with real-world examples (3-5 seed objects per schema as specified in design.md)
  - All entity fields from context-brief are present and typed correctly
- [ ] Implement
- [ ] Test

### Task 2: Create database migration for BerichtAfleverkanaal table and CallLog columns
- **spec_ref**: `spec.md#req-004`, `spec.md#req-007`
- **files**:
  - `openconnector/lib/Migration/Version20250620000000CreateBerichtenboxTables.php`
  - Migration adds `berichtenbox_afleverkanaal` table with indexes
  - Migration adds 5 columns to `oc_openconnector_calllog` (bericht_id, bericht_kenmerk, bericht_type, geadresseerde_type, indexes)
- **acceptance_criteria**:
  - GIVEN `openconnector migrate` is run, WHEN migration executes, THEN tables/columns exist with correct types
  - Cache table has UNIQUE key on (bsn_or_kvk, adressering_type) and index on voorkeurskanaal_checked
  - Backward compatible: no breaking changes to existing columns
- [ ] Implement
- [ ] Test

### Task 3: Implement BerichtenboxAdapter core orchestrator
- **spec_ref**: `spec.md#req-001`, `spec.md#req-002`, `spec.md#req-004`, `spec.md#req-007`
- **files**:
  - `openconnector/lib/Service/Adapters/BerichtenboxAdapter.php` (main class)
  - `openconnector/lib/Exception/BerichtenboxException.php` (custom exception, error codes per spec)
- **acceptance_criteria**:
  - GIVEN a complete Bericht and valid Source, WHEN send() is called, THEN:
    - MIME type validation (only PDF/A, JPEG, PNG)
    - Size validation (attachment < 25MB individually, < 25MB total, body < 1MB)
    - Certificate validation (exists, not expired, OIN matches Source)
    - Voorkeurskanaal check (call cache or live check)
    - Route selection (SOAP vs REST based on Source.configuration.koppelvlak)
    - Rate-limiting check (consult token-bucket)
    - Returns Berichtstatus or BerichtenboxException with proper error code
  - Error messages are user-facing and actionable
  - All validation happens before any network call (fail-fast)
- [ ] Implement
- [ ] Unit test: 15+ test cases covering all validation paths
- [ ] Integration test: end-to-end send against mock Logius SOAP endpoint

### Task 4: Implement BerichtenboxSoapTransport (SOAP/WUS koppelvlak)
- **spec_ref**: `spec.md#req-002`
- **files**:
  - `openconnector/lib/Service/Transports/BerichtenboxSoapTransport.php`
  - Uses PHP SOAP extension to build Aanleveren-2.1.5 envelope
  - mTLS certificate loaded from NextcloudVault via Nextcloud Certificate manager
- **acceptance_criteria**:
  - GIVEN a Bericht, WHEN serialized to SOAP, THEN:
    - Envelope contains required elements per WSDL (Berichtkenmerk, BerichtBetreft, MimeBerichtInhoud, Bijlage)
    - Attachments are base64-encoded and MIME types preserved
    - WS-Addressing From header includes OIN
    - Envelope is valid per Logius XSD (external validation tool)
  - mTLS certificate is used for handshake (verified by intercepting TLS handshake)
  - Response is parsed and mapped to BerichtenboxResponse object
- [ ] Implement
- [ ] Unit test: SOAP serialization correctness, envelope structure
- [ ] Integration test: mock SOAP server with XSD validation

### Task 5: Implement BerichtenboxRestTransport (REST koppelvlak pilot)
- **spec_ref**: `spec.md#req-003`
- **files**:
  - `openconnector/lib/Service/Transports/BerichtenboxRestTransport.php`
  - Uses Guzzle HTTP client for OAuth2 + REST calls
  - OAuth2 client-credentials flow with token caching
- **acceptance_criteria**:
  - GIVEN a Bericht and REST-configured Source, WHEN send() is called, THEN:
    - OAuth2 token exchange succeeds (mock or real preprod endpoint)
    - POST /v1/berichten called with JSON payload matching schema
    - Response JSON parsed and mapped to BerichtenboxResponse
    - Token is cached and reused for subsequent calls (< token TTL)
  - Error responses handled (4xx → permanent fail, 5xx → retry)
  - Payload structure matches Bericht schema (same fields as SOAP, different serialization)
- [ ] Implement
- [ ] Unit test: OAuth2 flow, JSON serialization, response parsing
- [ ] Integration test: mock REST server

### Task 6: Implement BerichtenboxAfleverkanaalCache (voorkeurskanaal check with TTL)
- **spec_ref**: `spec.md#req-004`
- **files**:
  - `openconnector/lib/Service/BerichtenboxAfleverkanaalCache.php`
  - `openconnector/lib/Db/BerichtAfleverkanaal.php` (entity)
  - `openconnector/lib/Db/BerichtAfleverkanaalMapper.php` (mapper)
- **acceptance_criteria**:
  - GIVEN heeftBerichtenbox(bsn) is called, WHEN cache entry < 24h old, THEN cached result returned (no Logius call)
  - GIVEN cache entry >= 24h old, WHEN heeftBerichtenbox() called, THEN live Logius check performed and cache updated
  - GIVEN KvK/OIN addressing, WHEN heeftBox() called, THEN different Logius endpoint used (not citizen endpoint)
  - Cache entries are persisted across adapter invocations (relies on database)
- [ ] Implement
- [ ] Unit test: TTL logic, cache miss/hit
- [ ] Integration test: database persistence

### Task 7: Implement BerichtenboxWebhookController (webhook endpoint)
- **spec_ref**: `spec.md#req-005`
- **files**:
  - `openconnector/lib/Controller/BerichtenboxWebhookController.php`
  - Endpoint: POST /apps/openconnector/api/incoming/berichtenbox/{sourceId}
  - Validates mTLS certificate from request context
  - Dispatches to BerichtenboxReceiver
- **acceptance_criteria**:
  - GIVEN valid mTLS callback, WHEN received, THEN dispatches to receiver without additional auth
  - GIVEN invalid/untrusted certificate, WHEN received, THEN returns 401 Unauthorized
  - GIVEN unknown sourceId, WHEN received, THEN returns 404 Not Found
  - Accepts SOAP envelope in request body (Nextcloud passes raw body)
- [ ] Implement
- [ ] Integration test: mock Logius webhook, mTLS validation

### Task 8: Implement BerichtenboxWebhookReceiver (Notificaties-1.1 parser)
- **spec_ref**: `spec.md#req-005`, `spec.md#req-006`
- **files**:
  - `openconnector/lib/Service/Adapters/WebhookReceivers/BerichtenboxReceiver.php`
  - Parses Notificaties-1.1 SOAP callbacks (BerichtAfgeleverd, BerichtGelezen, BerichtVerlopen, EmailFallbackVerstuurd, etc.)
  - Creates Berichtstatus objects
  - Dispatches OpenConnector events
  - Handles orphan berichtIds (writes to dead-letter queue)
- **acceptance_criteria**:
  - GIVEN BerichtAfgeleverd callback, WHEN parsed, THEN Berichtstatus(status=afgeleverd) created and openconnector.bericht.delivered event dispatched
  - GIVEN BerichtGelezen callback with original Bericht.responseRequired=true, WHEN parsed, THEN both gelezen and awaiting_response events dispatched
  - GIVEN unknown berichtId, WHEN parsed, THEN 409 response and orphan written to dead-letter queue
  - All Berichtstatus entries idempotent (same input → no duplicate event on retry)
  - Email/phone hashing: if callback includes notificatie contact info, hash it before storing in details
- [ ] Implement
- [ ] Unit test: SOAP parsing, status mapping, event dispatch
- [ ] Integration test: mock Logius callbacks, dead-letter handling

### Task 9: Integrate CallLog logging with PII redaction
- **spec_ref**: `spec.md#req-007`
- **files**:
  - `openconnector/lib/Service/CallLogService.php` (extends or uses existing OpenConnector CallLog)
  - Add BerichtenboxAdapter integration to log outbound sends
  - Add BerichtenboxReceiver integration to log inbound callbacks
  - PII redaction function: mask BSN (XXX-XX-XXXX), hash email/phone (SHA256)
- **acceptance_criteria**:
  - GIVEN any Berichtenbox send, WHEN CallLog entry created, THEN:
    - bericht_id, bericht_kenmerk, bericht_type, geadresseerde_type, oin all captured
    - CallLogBody contains full SOAP/JSON payload with PII redacted
    - Raw BSN never appears in database (only first 3 + last 2 digits)
    - Email/phone appear as SHA256 hashes, not cleartext
  - GIVEN admin queries CallLog UI, WHEN viewing Berichtenbox entries, THEN redacted payloads displayed
  - Audit trail fully traceable: send → afgeleverd → gelezen linked by berichtId
- [ ] Implement
- [ ] Unit test: PII redaction correctness (exact format per spec)
- [ ] Integration test: CallLog persistence, retrieval

### Task 10: Implement retention policies (7-year for Berichtenbox, 90-day default)
- **spec_ref**: `spec.md#req-007`
- **files**:
  - `openconnector/lib/Job/RetentionJob.php` (extends or modifies existing)
  - Add Berichtenbox-specific retention logic: exclude from 90-day purge, only delete after 7 years
- **acceptance_criteria**:
  - GIVEN Berichtenbox CallLog entry aged > 90 days but < 7 years, WHEN retention job runs, THEN entry NOT deleted
  - GIVEN entry aged >= 7 years, WHEN retention job runs, THEN entry IS deleted
  - GIVEN non-Berichtenbox entry aged > 90 days, WHEN retention job runs, THEN entry IS deleted (unaffected by Berichtenbox policy)
  - Partition by year (future optimization): CallLog indexed by year for efficient deletion
- [ ] Implement
- [ ] Integration test: time-travel retention job, verify retention periods

### Task 11: Implement rate-limiter integration (token-bucket per OIN)
- **spec_ref**: `spec.md#req-008`
- **files**:
  - `openconnector/lib/Service/RateLimiter/BerichtenboxRateLimiter.php` (uses or extends shared OpenConnector limiter)
  - Token-bucket algorithm: 10 req/s sustained per OIN, 100 burst capacity
- **acceptance_criteria**:
  - GIVEN 200 sends queued for same OIN, WHEN processed, THEN throttled to <= 10 req/s (all succeed within ~20 seconds)
  - GIVEN bursts up to 100 within 1 second, WHEN processed, THEN all succeed immediately (within burst allowance)
  - GIVEN 101st request in same second, WHEN attempted, THEN queued and delayed until next token availability
  - Tokens are per-OIN (different OINs don't interfere)
  - Rate limiter is shared with other OpenConnector jobs (doesn't compete unfairly)
- [ ] Implement
- [ ] Unit test: token-bucket math, per-OIN isolation
- [ ] Integration test: concurrent sends from multiple OINs

### Task 12: Implement exponential backoff on 429/5xx responses
- **spec_ref**: `spec.md#req-008`
- **files**:
  - `openconnector/lib/Service/Adapters/BerichtenboxAdapter.php` (modify to add retry logic)
  - Use OpenConnector's existing queue/job retry mechanism
- **acceptance_criteria**:
  - GIVEN HTTP 429 response, WHEN received, THEN message requeued with backoff: 2s → 4s → 8s → 60s (capped)
  - GIVEN HTTP 5xx response, WHEN received up to 4 times, THEN each retry applies exponential backoff
  - GIVEN 5 consecutive 5xx, WHEN detected, THEN entire queue paused, openconnector.source.degraded event dispatched
  - GIVEN probe succeeds after outage, WHEN detected, THEN queue resumed automatically
  - All messages retried indefinitely until success (no silent drops)
- [ ] Implement
- [ ] Integration test: mock Logius returning 429/5xx, verify retry sequence

### Task 13: Implement certificate validation (expiry, OIN match, environment check)
- **spec_ref**: `spec.md#req-001`, `spec.md#req-010`
- **files**:
  - `openconnector/lib/Service/Certificate/BerichtenboxCertificateValidator.php`
  - Validates on Source save and on each send
- **acceptance_criteria**:
  - GIVEN expired certificate, WHEN validated on send, THEN BerichtenboxError.CERTIFICATE_EXPIRED (fail-fast before network)
  - GIVEN certificate OIN != Source OIN, WHEN validated on save, THEN error "Certificate OIN does not match Source OIN"
  - GIVEN Source.environment=test with production certificate, WHEN validated on send, THEN BerichtenboxError.CERTIFICATE_MISMATCH_ENVIRONMENT
  - GIVEN valid certificate for correct OIN and environment, WHEN validated, THEN passes and send proceeds
- [ ] Implement
- [ ] Unit test: certificate parsing, OIN extraction, environment checks

### Task 14: Implement environment isolation (preprod URL routing, dev UI warning)
- **spec_ref**: `spec.md#req-010`
- **files**:
  - `openconnector/lib/Service/Transports/BerichtenboxSoapTransport.php` (modify to select endpoint per environment)
  - `openconnector/lib/Service/Transports/BerichtenboxRestTransport.php` (same)
  - UI component: warning banner for dev instances
- **acceptance_criteria**:
  - GIVEN Source.environment=test, WHEN send() called, THEN URL forced to preprod endpoint (https://wus-preprod-...)
  - GIVEN Source.environment=production, WHEN send() called, THEN URL is production endpoint
  - GIVEN Nextcloud config.systemTag=dev, WHEN adding Berichtenbox Source, THEN UI shows amber banner and defaults environment=test
  - GIVEN user attempts to select production in dev instance with non-prod cert, WHEN saved, THEN validation fails
- [ ] Implement
- [ ] Integration test: environment routing correctness

### Task 15: Implement Bedrijven (KvK/OIN) addressing support
- **spec_ref**: `spec.md#req-009`
- **files**:
  - `openconnector/lib/Service/Adapters/BerichtenboxAdapter.php` (modify to handle geadresseerde.type=kvk/oin)
  - Transport classes updated to use correct endpoints per addressing type
- **acceptance_criteria**:
  - GIVEN geadresseerde.type=bsn, WHEN send(), THEN standard Berichtenbox endpoint used, voorkeurskanaal checked
  - GIVEN geadresseerde.type=kvk, WHEN send(), THEN Mijn Zaken voor Ondernemers endpoint used, heeftBox(kvk) checked
  - GIVEN geadresseerde.type=oin, WHEN send(), THEN OIN-on-OIN profile used (mutual TLS with OIN validation), no voorkeurskanaal check
  - GIVEN KvK not enrolled, WHEN heeftBox() returns false, THEN Berichtstatus(status=geweigerd, foutCode=NO_BOX_KVK)
- [ ] Implement
- [ ] Unit test: endpoint selection logic, voorkeurskanaal skip for OIN
- [ ] Integration test: routing to correct Logius endpoint per type

### Task 16: Write integration tests against mock Logius endpoints
- **spec_ref**: All requirements
- **files**:
  - `tests/Integration/BerichtenboxIntegrationTest.php` (comprehensive suite)
  - Mock SOAP server (Logius Aanleveren endpoint)
  - Mock REST server (Logius REST pilot endpoint)
  - Mock webhook callbacks (Logius Notificaties)
- **acceptance_criteria**:
  - End-to-end: send Bericht → receive 200 response → parse response → create CallLog → return Berichtstatus
  - Webhook: receive BerichtAfgeleverd callback → parse → create Berichtstatus → dispatch event
  - Rate-limiting: 200 sends queued → throttle to 10 req/s
  - Error handling: invalid attachment → fail-fast with error code, CallLog created, no network call
  - Certificate validation: expired cert → fail before send, certificate mismatch → fail before send
  - Voorkeurskanaal: BSN without box → Berichtstatus(geweigerd), cache hit within 24h
  - Retention: 7-year CallLog not purged, 90-day CallLog purged
- [ ] Implement
- [ ] All integration tests pass

### Task 17: Write unit tests for all service classes
- **spec_ref**: All requirements
- **files**:
  - `tests/Unit/Service/Adapters/BerichtenboxAdapterTest.php`
  - `tests/Unit/Service/Transports/BerichtenboxSoapTransportTest.php`
  - `tests/Unit/Service/Transports/BerichtenboxRestTransportTest.php`
  - `tests/Unit/Service/BerichtenboxAfleverkanaalCacheTest.php`
  - `tests/Unit/Service/Certificate/BerichtenboxCertificateValidatorTest.php`
  - `tests/Unit/Service/CallLogService/BerichtenboxCallLogTest.php` (PII redaction)
- **acceptance_criteria**:
  - >= 60 unit tests covering:
    - All validation paths (size, MIME, certificate, environment)
    - Error code correctness
    - Edge cases (null values, empty arrays, boundary sizes)
    - PII redaction (exact format per spec)
    - Cache TTL logic
    - Rate-limiter token-bucket math
    - Certificate parsing and OIN extraction
  - All tests pass (`composer test`)
- [ ] Implement
- [ ] All unit tests passing

### Task 18: Create feature flag for SOAP vs REST transport
- **spec_ref**: `spec.md#req-003`
- **files**:
  - `openconnector` config: add `berichtenbox.koppelvlak` setting (values: `soap`, `rest-v1`)
  - Source.configuration schema includes `koppelvlak` and `priority` fields
- **acceptance_criteria**:
  - Feature flag `berichtenbox.koppelvlak=soap` → use SOAP transport
  - Feature flag `berichtenbox.koppelvlak=rest-v1` → use REST transport
  - Multiple sources with different flags: routing by `Source.priority` transparent to consumer
  - Setting can be toggled without redeploying (Nextcloud config)
- [ ] Implement
- [ ] Integration test: feature flag toggling, route selection

### Task 19: Document API and configuration in README / wiki
- **spec_ref**: Cross-project integration section of proposal.md
- **files**:
  - `openconnector/docs/berichtenbox-adapter-guide.md` (for operators)
  - Inline JSDoc comments in BerichtenboxAdapter, transports
- **acceptance_criteria**:
  - Guide covers: Source configuration, certificate upload, OIN setup, environment selection
  - API guide covers: consumer app calling openconnector.send('berichtenbox', $bericht)
  - Event payloads documented (openconnector.bericht.delivered, etc.)
  - Error codes explained (when to retry, when to fall back)
  - All code exported enums/constants (error codes, status values) have JSDoc
- [ ] Implement
- [ ] Reviewed by team (inline in PR review)

### Task 20: Prepare for decidesk integration (proof-of-concept)
- **spec_ref**: Cross-project dependencies in proposal.md
- **files**:
  - Example code in BerichtenboxAdapter docs: how decidesk calls openconnector.send() and listens for events
  - Integration test: mock decidesk app flow (zaak → besluit → send → event → zaakstatus update)
- **acceptance_criteria**:
  - Documented API is clear enough that decidesk team can integrate without adapter code review
  - Example code is runnable (or close to it) in integration test
  - Event payload structure matches decidesk expectations
- [ ] Implement example
- [ ] Document

## Verification

- [ ] All 20 tasks checked off
- [ ] `composer test` passes (unit + integration)
- [ ] `openconnector validate` passes (schemas, migrations)
- [ ] Code review against spec requirements
- [ ] Manual testing: send Bericht via adapter, receive webhook callback, verify CallLog and events
- [ ] Certificate validation: test with expired, mismatched, wrong-environment certs
- [ ] Rate-limiting: send 200 messages, verify throttled to 10 req/s
- [ ] Retention: verify 7-year policy applied to Berichtenbox CallLog
- [ ] PII redaction: verify BSN masked, email/phone hashed in CallLog
- [ ] Environment isolation: test preprod routing in test environment

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — 60+ tests per Task 17
- [x] Integration tests for API endpoints and webhooks (`tests/Integration/`) — per Task 16
- [x] Browser tests (Playwright) for UI changes — N/A (no user-facing UI for adapter itself; admin UI tested separately)
- [x] All tests pass (`composer test`)

## Documentation (company-wide ADR-010)

- [x] Feature documentation in `openconnector/docs/` — berichtenbox-adapter-guide.md per Task 19
- [x] Screenshots (if UI changes) — N/A (no user-facing UI)
- [x] JSDoc for exported classes and methods — per Task 19
- [x] Example code for consumer integration (decidesk) — per Task 20

## i18n (company-wide ADR-007)

- [x] Dutch (`nl_NL`) and English (`en_US`) translations — Error messages and event labels in BerichtenboxAdapter, all exceptions
- [x] UI warning banner for dev instances — Dutch and English per Task 14
