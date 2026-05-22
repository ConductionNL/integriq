# Tasks: digikoppeling-adapter

## Overview

The digikoppeling-adapter is a foundational transport layer that every other Conduction app relies on for overheid-to-overheid messaging. The implementation is organized into 12 major work streams covering schema definitions, certificate management, transport profile implementations, retry scheduling, audit trails, and observability.

All tasks assume OpenConnector is the primary delivery vehicle. Higher-level adapters (StUF, ZGW, Berichtenbox) will depend on the Digikoppeling adapter being complete and stable.

## Tasks

### DK-1. OpenRegister Schema Definitions (M)

**Objective:** Define the six Digikoppeling data schemas in OpenRegister with full field specifications and validation.

- [ ] DK-1.1 Register the `openconnector-digikoppeling` register in OpenRegister if not already present.
  - **Acceptance:** Register appears in OpenRegister's register list; can accept schema definitions.

- [ ] DK-1.2 Define `DigikoppelingEndpoint` schema with fields: `aansluitnummer`, `oin`, `name`, `profile`, `endpointUrl`, `wsdlUrl`, `cpaId`, `serviceNamespace`, `messageTypes`, `pkiCertificateChain`, `validFrom`, `validUntil`, `maxConcurrentRequests`, `requestsPerMinute`, `lastSyncedFromRegistry`.
  - **Acceptance:** Schema registered; field types match design.md; `aansluitnummer` + `oin` is natural key; `profile` enum enforced.

- [ ] DK-1.3 Define `DigikoppelingCertificate` schema with fields: `aansluitnummer`, `oin`, `certificateType`, `pemCertificate`, `pemPrivateKeyRef`, `pemChain`, `validFrom`, `validUntil`, `subjectDN`, `issuerDN`, `serialNumber`, `rotationReminderDays`.
  - **Acceptance:** Schema registered; `pemPrivateKeyRef` is a reference to Nextcloud secret store (never stored in schema itself); `certificateType` enum enforced.

- [ ] DK-1.4 Define `DigikoppelingMessage` schema with fields: `direction`, `profile`, `localEndpointRef`, `remoteEndpointRef`, `messageId`, `conversationId`, `messageType`, `payloadHash`, `payloadFileRef`, `payloadSizeBytes`, `status`, `statusReason`, `attemptCount`, `nextAttemptAt`, `firstAttemptAt`, `lastAttemptAt`, `acknowledgedAt`, `expiresAt`, `signingCertificateRef`, `correlatesTo`, `triggeredByJobRef`.
  - **Acceptance:** Schema registered; `direction` and `status` enums enforced; `payloadFileRef` uses Nextcloud Files abstraction; `correlatesTo` allows self-referencing for request/response pairing.

- [ ] DK-1.5 Define `DigikoppelingRetryPolicy` schema with fields: `profile`, `initialDelaySeconds`, `maxAttempts`, `backoffMultiplier`, `maxDelaySeconds`, `expireAfterHours`. Seed with default Logius values for WUS/OUS/Grote Berichten/Best Effort.
  - **Acceptance:** Schema registered; four seed rows present (one per profile) with values matching design.md defaults.

- [ ] DK-1.6 Define `DigikoppelingAuditEvent` schema with fields: `messageRef`, `eventType`, `actor`, `timestamp`, `details`. Enforce append-only semantics (no UPDATE or DELETE on audit rows).
  - **Acceptance:** Schema registered; audit rows cannot be modified once created; `timestamp` is immutable; `details` JSON accepts arbitrary event-specific context.

- [ ] DK-1.7 Define `DigikoppelingServiceRegistryCache` schema with fields: `aansluitnummer`, `oin`, `organisatienaam`, `services`, `cpaDocuments`, `lastFetchedAt`.
  - **Acceptance:** Schema registered; `aansluitnummer` is natural key; `services` and `cpaDocuments` are JSON arrays; cache can be bulk-upserted by sync job.

### DK-2. PKIoverheid Certificate Management (L)

**Objective:** Implement certificate upload, validation, storage with private-key isolation, and expiry monitoring.

- [ ] DK-2.1 Create a certificate upload form in the OpenConnector admin UI that accepts a PEM-encoded certificate + chain.
  - **Acceptance:** Form appears in admin panel; can select a file; form validates it's not empty.

- [ ] DK-2.2 Implement certificate validation against the bundled PKIoverheid root CA bundle. Reject if chain does not terminate at a trusted root.
  - **Acceptance:** Unit test with a valid PKIoverheid cert chain succeeds; test with an untrusted cert is rejected with clear error message; root bundle file is present in the app.

- [ ] DK-2.3 Extract certificate metadata (`validFrom`, `validUntil`, `subjectDN`, `issuerDN`, `serialNumber`) and persist to `DigikoppelingCertificate` schema.
  - **Acceptance:** Unit test confirms metadata extraction; certificate metadata matches OpenSSL output.

- [ ] DK-2.4 Write the private key to Nextcloud's `IEncryptionService` (or equivalent secret store), NOT to the database. Store only a reference (`pemPrivateKeyRef`) in the schema.
  - **Acceptance:** Private key does not appear in `DigikoppelingCertificate` table; reference is a UUID or secret-store identifier; signing code retrieves the key via reference.

- [ ] DK-2.5 Implement daily certificate-check job that identifies certificates within `rotationReminderDays` of expiry.
  - **Acceptance:** Cron job runs daily; identifies expiring certs; unit test confirms detection logic.

- [ ] DK-2.6 When certificate nears expiry, send a Nextcloud notification to all users in the `digikoppeling_admin` group and add an entry to the OpenConnector dashboard widget.
  - **Acceptance:** Notification is sent; dashboard widget displays expiry countdown; email notification works (if configured).

- [ ] DK-2.7 Implement `occ digikoppeling:update-root-bundle` command to fetch and validate the PKIoverheid root CA bundle from Logius, replace the local bundle, and re-validate stored certificates.
  - **Acceptance:** Command runs without error on a valid bundle; bundle signature is verified; stored certs are re-validated; invalid certs are flagged in output.

### DK-3. WUS Synchronous Transport (L)

**Objective:** Implement SOAP 1.2 WUS profile with WS-Security signing and response verification.

- [ ] DK-3.1 Create `lib/Connectors/DigikoppelingConnector.php` (or similar) with a method `sendWUS(DigikoppelingEndpoint $ep, array $payload, DigikoppelingCertificate $cert): array` that constructs a SOAP 1.2 envelope.
  - **Acceptance:** Class compiles; method signature matches; SPDX header present.

- [ ] DK-3.2 Add WS-Addressing headers to the SOAP envelope: `MessageID`, `To`, `Action`, `From`, `ReplyTo` (per WS-Addressing 1.0 spec).
  - **Acceptance:** Unit test confirms headers are present in the constructed envelope; `MessageID` is a valid UUID.

- [ ] DK-3.3 Implement WS-Security 1.1 signing: sign the SOAP body and Timestamp using the local certificate's private key. Use canonicalization per WS-Security spec.
  - **Acceptance:** Unit test with mocked signing confirms signature is computed; comparison against known signature output (e.g., from a test vector) succeeds; unsigned envelope is rejected.

- [ ] DK-3.4 Send the envelope via HTTPS with PKIoverheid mTLS client certificate (the local signing cert).
  - **Acceptance:** Integration test confirms TLS handshake succeeds; client cert is presented; HTTP request is HTTPS.

- [ ] DK-3.5 Receive the SOAP response and verify its signature against the remote endpoint's certificate chain (stored in `DigikoppelingEndpoint.pkiCertificateChain`).
  - **Acceptance:** Unit test with a valid signed response succeeds; test with an unsigned response fails; test with a response signed by a different cert fails.

- [ ] DK-3.6 Persist a `DigikoppelingMessage` with `status=delivered` and write audit events: `sent`, `signature-verified`, `acknowledged`.
  - **Acceptance:** Message row is created with correct status; three audit events are written in chronological order.

- [ ] DK-3.7 On SOAP fault or transport error, record the message as `status=failed`, write a `wire-error` audit event with the fault code, and return a structured error. Do NOT automatically retry.
  - **Acceptance:** Unit test with a SOAP fault response records the fault code; message status is `failed`; no retry is scheduled.

### DK-4. OUS Asynchronous Transport (L)

**Objective:** Implement One-way Asynchronous Service profile with callback endpoint.

- [ ] DK-4.1 Implement `sendOUS(DigikoppelingEndpoint $ep, array $payload, DigikoppelingCertificate $cert): string` that generates a callback URL on the local Nextcloud instance.
  - **Acceptance:** Method returns a callback URL; URL is HTTPS and unique per message; URL is registered as a listening endpoint.

- [ ] DK-4.2 Set the WS-Addressing `ReplyTo` header to the callback URL in the OUS request envelope.
  - **Acceptance:** Unit test confirms `ReplyTo` is set in the envelope; callback URL matches the generated URL.

- [ ] DK-4.3 Persist the `DigikoppelingMessage` with `status=sent`, `conversationId` set to the WS-Addressing `MessageID`, and write audit events: `created`, `sent`.
  - **Acceptance:** Message row created with `status=sent`; `conversationId` is a valid UUID; both audit events are present.

- [ ] DK-4.4 Return the `conversationId` to the caller so they can track the asynchronous response.
  - **Acceptance:** Return value is the `conversationId`; caller can use it to poll or subscribe to response events.

- [ ] DK-4.5 Implement a callback endpoint (`POST /api/digikoppeling/callback`) that receives inbound OUS SOAP envelopes.
  - **Acceptance:** Endpoint is registered; accepts POST requests; does not require authentication (remote endpoint signs the SOAP envelope instead).

- [ ] DK-4.6 Verify the callback's SOAP signature against the originating endpoint's certificate chain. Look up the outbound message by `RelatesTo` header and update its status to `acknowledged`.
  - **Acceptance:** Unit test with a valid signed callback updates the original message to `status=acknowledged`; unsigned callback is rejected; callback with mismatched signature is rejected.

- [ ] DK-4.7 Persist a new inbound `DigikoppelingMessage` row with `direction=inbound` and `correlatesTo` pointing at the original outbound message.
  - **Acceptance:** Inbound message row created; `correlatesTo` references the original message ID; `direction=inbound`.

- [ ] DK-4.8 Fire a domain event `digikoppeling.message.received` so consuming jobs (procest, zaakafhandelapp) can react to the response.
  - **Acceptance:** Event is emitted; consumers can subscribe to it; event payload includes `conversationId` and `messageType`.

- [ ] DK-4.9 Write audit events: `response-received`, `signature-verified`, `acknowledged`.
  - **Acceptance:** Three audit events are written in order; `signature-verified` includes validation details in JSON `details`.

- [ ] DK-4.10 Return HTTP 200 OK to the remote party.
  - **Acceptance:** Integration test confirms HTTP 200 is returned; response body is empty.

### DK-5. Grote Berichten via ebMS3/AS4 (L)

**Objective:** Implement Grote Berichten profile for large payloads with AS4 binding and guaranteed delivery.

- [ ] DK-5.1 Implement `sendGroteBerichten(DigikoppelingEndpoint $ep, array $payload, DigikoppelingCertificate $cert, string $cpa): bool` that packages the payload as an ebMS3 user message.
  - **Acceptance:** Method detects payloads > 20 MB or `forceGroteBerichten=true` flag; creates ebMS3 user message structure per ebMS3 spec.

- [ ] DK-5.2 Apply AS4 message-level signing using the local PKIoverheid certificate. Signing MUST include the public certificate as an attachment per the AS4 profile.
  - **Acceptance:** Unit test confirms signature is present; signing cert is attached; signature verifies.

- [ ] DK-5.3 Apply message-level encryption to the payload per the CPA's PMode. Use the remote endpoint's public key from `DigikoppelingEndpoint.pkiCertificateChain`.
  - **Acceptance:** Unit test with mocked encryption confirms encrypted payload is created; encryption algorithm matches PMode spec.

- [ ] DK-5.4 Send via AS4 push binding (HTTPS POST) to `DigikoppelingEndpoint.endpointUrl`. Expect an AS4 receipt acknowledgement within the PMode receipt-timeout.
  - **Acceptance:** Integration test confirms POST is sent; receipt is expected; timeout is enforced per PMode.

- [ ] DK-5.5 Persist a `DigikoppelingMessage` with `status=sent` and `conversationId` set to the ebMS3 `MessageID`. Write audit events: `created`, `signed`, `sent`.
  - **Acceptance:** Message row created; `conversationId` matches ebMS3 MessageID; three audit events are written.

- [ ] DK-5.6 On receipt arrival, verify the receipt signature, update the message to `status=delivered`, and write `signature-verified` and `acknowledged` audit events.
  - **Acceptance:** Valid receipt updates message status; invalid or missing receipt is handled per retry policy.

- [ ] DK-5.7 If no receipt arrives within the PMode receipt-timeout, treat as a transient error and schedule a retry per the Grote Berichten retry policy (default: 14 attempts, 24 hours, exponential backoff).
  - **Acceptance:** Unit test confirms retry is scheduled; delay is calculated per exponential backoff formula; `nextAttemptAt` is set correctly.

### DK-6. Best Effort Lightweight Profile (S)

**Objective:** Implement Best Effort fire-and-forget profile with limited retries.

- [ ] DK-6.1 Implement `sendBestEffort(DigikoppelingEndpoint $ep, array $payload, DigikoppelingCertificate $cert): bool` that uses the same envelope format as OUS but does NOT register a callback listener.
  - **Acceptance:** Method sends envelope; does not set up callback; returns bool (success/failure).

- [ ] DK-6.2 Retry per the Best Effort retry policy (default: 3 attempts, 1 hour lifetime). Do not block the caller on confirmation.
  - **Acceptance:** Message is persisted; retries are scheduled; caller returns immediately; retry policy default is applied.

- [ ] DK-6.3 Persist the `DigikoppelingMessage` with `status` progressing from `pending` → `sent` → `delivered` or `expired`. Write appropriate audit events.
  - **Acceptance:** Message status transitions are correct; audit trail is complete.

### DK-7. Retry Scheduler (L)

**Objective:** Implement the background job that retries failed messages according to their profiles' retry policies.

- [ ] DK-7.1 Create a system cron job `DigikoppelingRetryJob` that runs every 5 minutes.
  - **Acceptance:** Cron job is registered in OpenConnector; can be manually triggered for testing.

- [ ] DK-7.2 The job SHALL query `DigikoppelingMessage` with `status IN (failed, pending)` and `nextAttemptAt <= now()`.
  - **Acceptance:** Unit test with mocked messages confirms correct query; only eligible messages are selected.

- [ ] DK-7.3 For each message, check the remote endpoint's concurrency and throughput limits (`maxConcurrentRequests`, `requestsPerMinute`). If a slot is available, re-invoke the send. Otherwise, queue the message and move to the next.
  - **Acceptance:** Scheduler respects limits; unit test with mocked endpoint confirms no more than N concurrent sends; throughput is capped at M requests/minute.

- [ ] DK-7.4 On success (2xx or AS4 receipt), mark the message `status=delivered` (or `acknowledged` for OUS), update `lastAttemptAt`, and write an `acknowledged` audit event.
  - **Acceptance:** Successful retry transitions message status correctly.

- [ ] DK-7.5 On transient error (5xx, timeout, signature-verification failure), reschedule the retry at `now() + delay` where `delay = initialDelaySeconds * backoffMultiplier^attemptCount` capped at `maxDelaySeconds`. Increment `attemptCount` and write a `retry-scheduled` audit event.
  - **Acceptance:** Unit test confirms delay formula is correct; backoff multiplier is applied; cap is enforced; `nextAttemptAt` is set.

- [ ] DK-7.6 On permanent error (4xx other than 429, signature-verification failure on the Nth attempt) or after exhausting `maxAttempts` or exceeding `expireAfterHours`, mark the message `status=expired` and write an `expired` audit event with details.
  - **Acceptance:** Expired message has correct status; audit event includes reason (max attempts, time limit, permanent error code).

- [ ] DK-7.7 Emit a domain event or send a notification to the OpenConnector job owner when a message expires, so they can manually intervene.
  - **Acceptance:** Notification is sent; includes message details and a link to the audit trail.

### DK-8. Audit Trail and Immutability (M)

**Objective:** Implement append-only audit events and audit-trail export.

- [ ] DK-8.1 Ensure `DigikoppelingAuditEvent` enforces append-only semantics: rows cannot be updated or deleted after creation. If using OpenRegister, implement this as a validation rule in the schema's mutation handler.
  - **Acceptance:** Unit test attempts to UPDATE an audit row and is rejected; DELETE is rejected; INSERT succeeds.

- [ ] DK-8.2 Every `DigikoppelingMessage` state transition (created, signed, sent, wire-error, response-received, signature-verified, acknowledged, retry-scheduled, expired, archived) MUST write a `DigikoppelingAuditEvent` row.
  - **Acceptance:** Integration test sends a message through full lifecycle; audit trail has one event per transition; no events are missing.

- [ ] DK-8.3 Each audit event includes `messageRef`, `eventType`, `actor` (Nextcloud user or "system"), `timestamp`, and a JSON `details` blob with event-specific context.
  - **Acceptance:** Unit test confirms all fields are populated; `details` includes HTTP status, SOAP fault code, signature validation result, ebMS3 receipt MessageId as applicable.

- [ ] DK-8.4 Retention is ≥7 years per Archiefwet 1995. Configure as a schema-level setting (default 7 years, configurable to permanent).
  - **Acceptance:** Retention setting is persisted; schema respects it (enforces via database trigger or application-level archival job).

- [ ] DK-8.5 Implement audit-export endpoint `GET /api/digikoppeling/audit/{conversationId}` that returns a JSON document with all messages and events for the conversation in chronological order.
  - **Acceptance:** Endpoint is registered; returns 200 with valid JSON; document includes all messages and events; events are in chronological order.

- [ ] DK-8.6 Sign the audit-export document with the local certificate to prove integrity. Include the signature and the signing certificate (public key) in the response.
  - **Acceptance:** Signature is present; verifies against the local certificate; independent tool can verify the signature.

- [ ] DK-8.7 Include the SHA-256 hash of each original payload (not the payloads themselves) in the audit export for integrity verification.
  - **Acceptance:** Each message in the export includes `payloadHash` (SHA-256); hash can be verified against the original payload file.

### DK-9. Service Registry Sync (M)

**Objective:** Implement automatic Logius service registry sync and endpoint lookup.

- [ ] DK-9.1 Create a system cron job `DigikoppelingServiceRegistrySyncJob` that runs daily.
  - **Acceptance:** Cron job is registered; can be manually triggered; logs execution.

- [ ] DK-9.2 The job fetches the Logius Digikoppeling Service Registry from the configured URL (default: Logius's published HTTPS endpoint). Validate the response is valid JSON.
  - **Acceptance:** Unit test with mocked HTTP response confirms fetch succeeds; invalid JSON is rejected with logged error.

- [ ] DK-9.3 For each aansluiting in the registry, upsert a `DigikoppelingServiceRegistryCache` row with `organisatienaam`, `services`, `cpaDocuments`, and `lastFetchedAt=now()`.
  - **Acceptance:** Unit test confirms upsert creates new rows and updates stale ones; `lastFetchedAt` is current timestamp.

- [ ] DK-9.4 Log a summary of the sync: number of new entries, updated entries, removed entries (organizations no longer in the registry).
  - **Acceptance:** Log entry is written at `info` level; summary includes counts.

- [ ] DK-9.5 Implement a lookup API endpoint `GET /api/digikoppeling/registry-lookup?q=...` that searches the cache by `organisatienaam`, `aansluitnummer`, or `oin` and returns matching results.
  - **Acceptance:** Endpoint returns 200 with matching results; matches on partial name; results include `endpointUrl`, `cpaId`, and remote certificate chain.

- [ ] DK-9.6 In the OpenConnector admin UI for creating a new `DigikoppelingEndpoint`, call the lookup endpoint when the admin types in the "remote organisation" field and pre-fill fields from the cache.
  - **Acceptance:** UI autocomplete works; clicking a result pre-fills `endpointUrl`, `serviceNamespace`, `cpaId`, and the certificate chain.

### DK-10. Payload Handling and Storage (S)

**Objective:** Store payloads in Nextcloud Files (not the database) and link via hash.

- [ ] DK-10.1 When a message is created, compute the SHA-256 hash of the payload and write the payload to Nextcloud Files under `.digikoppeling/payloads/{messageId}`.
  - **Acceptance:** File is written to Files; hash is computed; path matches convention; large payloads (50+ MB) do not bloat the database.

- [ ] DK-10.2 Store a reference to the file (`payloadFileRef`) and the hash (`payloadHash`) in the `DigikoppelingMessage` row, never the payload itself.
  - **Acceptance:** Schema row does not include the payload; `payloadFileRef` is a Nextcloud Files reference; `payloadHash` is a SHA-256 hex string.

- [ ] DK-10.3 When retrieving a message, fetch the payload from Files by reference, verify its hash matches `payloadHash`, and stream it to the caller.
  - **Acceptance:** Unit test confirms fetch and hash verification; mismatched hash is rejected with error.

- [ ] DK-10.4 Implement a cleanup job that removes payload files for messages older than the audit retention period (default 7 years).
  - **Acceptance:** Cleanup job runs periodically; only removes files for messages outside retention window; logs deleted files.

### DK-11. API Endpoints and OpenConnector Integration (M)

**Objective:** Expose REST endpoints and integrate with OpenConnector's job system.

- [ ] DK-11.1 Implement `POST /api/digikoppeling/send` endpoint that accepts a job invocation with `profile`, `endpointRef`, `messageType`, `payloadHash`, `payloadFileRef` and returns the appropriate response (synchronous for WUS, `conversationId` for async).
  - **Acceptance:** Endpoint is registered; accepts POST; returns 200 on success; validates required fields (400 if missing).

- [ ] DK-11.2 Implement `POST /api/digikoppeling/callback` endpoint that receives inbound OUS/AS4 callbacks without authentication (signature verification replaces auth).
  - **Acceptance:** Endpoint is registered; accepts POST; does not require Nextcloud auth; returns 200 on success.

- [ ] DK-11.3 Implement `GET /api/digikoppeling/messages/{id}` endpoint to query a message's current status and audit trail.
  - **Acceptance:** Endpoint returns 200; includes message status, attempt count, next retry time; requires `requireLogin()`.

- [ ] DK-11.4 Integrate with OpenConnector's job system so that a Digikoppeling endpoint appears as a source type and jobs can invoke the send endpoint.
  - **Acceptance:** OpenConnector UI allows selecting a Digikoppeling endpoint as a source; jobs can be created that reference a Digikoppeling endpoint; job completion triggers domain events.

- [ ] DK-11.5 Emit domain events `digikoppeling.message.created`, `digikoppeling.message.sent`, `digikoppeling.message.received` so other apps (procest, zaakafhandelapp) can subscribe.
  - **Acceptance:** Events are emitted at correct points in the message lifecycle; consumers can subscribe and react.

### DK-12. Observability and Testing (M)

**Objective:** Add structured logging, dashboards, and comprehensive tests.

- [ ] DK-12.1 Inject `LoggerInterface` and emit structured log entries for every upstream message send (not per controller call, since APCu cache hits should not generate logs).
  - **Acceptance:** Log entries are written; fields include `messageId`, `profile`, `remoteEndpointRef`, `messageType`, `status`, `attemptCount`, `upstreamLatencyMs`, `httpStatus`, `soapFaultCode`.

- [ ] DK-12.2 Set log levels: `debug` for 2xx responses and cache hits, `warning` for transient errors (429, 5xx), `error` for permanent failures, `critical` for certificate expiry.
  - **Acceptance:** Logs appear at correct levels; can be queried by level via OpenConnector logging interface.

- [ ] DK-12.3 Add a mydash dashboard widget showing:
  - Per-endpoint throughput (requests/minute, concurrent sends)
  - Retry queue (messages pending retry, oldest message age)
  - Certificate expiry countdown (days until rotation reminder)
  - Audit event volume (events created today)
  - Recent failures (last 10 failed messages)
  - **Acceptance:** Widget appears in mydash; updates in real time; links to message details and audit trail.

- [ ] DK-12.4 Write PHPUnit tests covering:
  - WUS send with valid signature (2xx)
  - WUS send with SOAP fault
  - OUS send with callback arrival and signature verification
  - OUS send with callback timeout (retry)
  - Grote Berichten send > 20 MB with AS4 receipt
  - Grote Berichten receipt timeout (retry)
  - Best Effort send with limited retries
  - Retry scheduler respecting endpoint limits
  - Message expiry after maxAttempts
  - Certificate validation (valid chain, untrusted root, expiry)
  - Audit trail immutability
  - Service registry sync and lookup
  - Payload hashing and file storage
  - **Acceptance:** All tests pass; no quality violations (`composer check:strict`).

- [ ] DK-12.5 Create integration test fixtures (`tests/fixtures/digikoppeling/`) with:
  - Valid and invalid PKIoverheid certificate chains
  - Sample WUS SOAP requests and responses
  - Sample OUS callbacks
  - Sample Grote Berichten ebMS3 messages
  - Sample service registry JSON
  - **Acceptance:** Fixture files are present; tests load and use them; fixtures include both success and error cases.

- [ ] DK-12.6 Document the adapter in OpenConnector's admin UI help text with:
  - How to upload a certificate
  - How to configure a Digikoppeling endpoint
  - How to monitor message status and retries
  - How to export an audit trail
  - Emergency escalation contact (e.g., Conduction support)
  - **Acceptance:** Help text is present; clear and actionable for admins.

### DK-13. Quality Gate and Security Review (S)

**Objective:** Ensure the adapter meets security and quality standards before release.

- [ ] DK-13.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) on all new files and resolve any violations.
  - **Acceptance:** Zero violations reported; code style matches OpenConnector conventions.

- [ ] DK-13.2 Invoke `hydra-gate-spdx` to ensure all PHP files have @license and @copyright PHPDoc tags.
  - **Acceptance:** Gate passes; all files have SPDX headers.

- [ ] DK-13.3 Run a security review checking for:
  - No hardcoded secrets or credentials
  - Private key isolation (never stored in schema or logs)
  - Signature verification on all inbound SOAP envelopes
  - Certificate chain validation before use
  - Immutable audit trails (no UPDATE/DELETE on audit rows)
  - Input validation on all endpoints (payload size, certificate size, JSON structure)
  - **Acceptance:** Security review passes; no vulnerabilities flagged.

- [ ] DK-13.4 Performance test the retry scheduler: schedule 1000 pending messages across 10 endpoints, confirm scheduler respects limits and completes in < 1 minute per batch.
  - **Acceptance:** Test runs without timeout; throughput and concurrency limits are enforced; no N+1 queries detected.

- [ ] DK-13.5 Load test: send 100 concurrent WUS requests to a test endpoint, confirm all succeed and complete within 30 seconds.
  - **Acceptance:** Load test passes; no deadlocks or connection pool exhaustion.
