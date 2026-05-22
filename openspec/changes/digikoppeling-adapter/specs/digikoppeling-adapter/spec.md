---
status: draft
---

# Digikoppeling 2.0 Transport Adapter

## Purpose

The Digikoppeling adapter is a first-class transport layer in OpenConnector that wraps the three Digikoppeling profiles (WUS, OUS, Grote Berichten, Best Effort) behind a unified configuration surface. It manages PKIoverheid certificates, enforces retry policies per the official Logius specification, syncs the service registry for endpoint discovery, and produces audit trails satisfying Archiefwet 1995 retention requirements.

The adapter is payload-agnostic — higher-level adapters (StUF, ZGW, Berichtenbox) compose on top without modification to the Digikoppeling layer.

## Requirements

### Requirement: Six Data Schemas in OpenRegister

The digikoppeling-adapter SHALL introduce six new OpenRegister schemas in the `openconnector-digikoppeling` register:

1. **DigikoppelingEndpoint** — remote party endpoint with profile, certificate chain, message types, throughput limits
2. **DigikoppelingCertificate** — local PKIoverheid cert with private-key isolation, expiry monitoring
3. **DigikoppelingMessage** — message audit hook (sent/received, status, retry state, timestamps)
4. **DigikoppelingRetryPolicy** — per-profile retry config (WUS/OUS/Grote Berichten/Best Effort)
5. **DigikoppelingAuditEvent** — append-only audit trail (never updated/deleted, 7-year retention)
6. **DigikoppelingServiceRegistryCache** — local Logius registry mirror for endpoint discovery

Detailed field specifications in design.md.

#### Scenario: All six schemas are registered and queryable

- GIVEN openconnector is installed with the digikoppeling-adapter
- WHEN the schema registry is queried
- THEN all six schemas SHALL appear as registered entries in the `openconnector-digikoppeling` register
- AND each schema SHALL accept CRUD operations via the OpenRegister API

#### Scenario: DigikoppelingAuditEvent is append-only

- GIVEN a `DigikoppelingMessage` transitions from `pending` to `sent`
- WHEN the adapter writes a `DigikoppelingAuditEvent` with `eventType=sent`
- THEN the event SHALL be persisted as immutable (no UPDATE or DELETE operations on audit rows)
- AND the row SHALL include `actor`, `timestamp`, and a JSON `details` blob

### Requirement: WUS Synchronous SOAP Transport

The adapter SHALL implement the WUS-koppelvlak profile for synchronous SOAP 1.2 request/response with WS-Security message signing.

#### Scenario: WUS request with valid signature succeeds

- GIVEN a configured `DigikoppelingEndpoint` with `profile=WUS` and a local signing `DigikoppelingCertificate`
- WHEN an OpenConnector job invokes the adapter with a SOAP body payload
- THEN the adapter SHALL:
  - Construct a SOAP 1.2 envelope with WS-Addressing headers (`MessageID`, `To`, `Action`, `From`, `ReplyTo`)
  - Sign the body and timestamp per WS-Security 1.1 using the local certificate
  - Send via HTTPS with PKIoverheid mTLS client cert
  - Verify the response signature against the remote endpoint's certificate chain
  - Return the response body to the caller
  - Write a `DigikoppelingMessage` with `status=delivered` and audit events for `sent` and `signature-verified`

#### Scenario: WUS request fails with SOAP fault

- GIVEN a WUS request is sent
- WHEN the remote endpoint returns a SOAP fault
- THEN the adapter SHALL:
  - Record the message as `status=failed` with the fault code in `statusReason`
  - Write a `wire-error` audit event with the raw SOAP fault
  - Return a structured error to the caller
  - NOT automatically retry (WUS is synchronous; retry is caller's responsibility)

### Requirement: OUS Asynchronous Transport with Callback

The adapter SHALL implement One-way Asynchronous Service (OUS) where the response is delivered to a callback endpoint.

#### Scenario: OUS send establishes callback listener

- GIVEN a configured `DigikoppelingEndpoint` with `profile=OUS`
- WHEN the adapter sends an OUS request
- THEN the adapter SHALL:
  - Auto-generate a callback URL on the local Nextcloud instance
  - Set the WS-Addressing `ReplyTo` header to that callback URL
  - Persist the `DigikoppelingMessage` with `status=sent` and `conversationId` equal to the WS-Addressing `MessageID`
  - Return the `conversationId` to the caller for tracking
  - Write audit events for `created` and `sent`

#### Scenario: OUS callback arrives and is routed to original message

- GIVEN an OUS callback SOAP envelope arrives at the local callback endpoint
- WHEN the adapter receives the inbound envelope
- THEN the adapter SHALL:
  - Verify the signature against the originating endpoint's certificate
  - Look up the corresponding outbound message by `RelatesTo` header
  - Update the original message row to `status=acknowledged` with `acknowledgedAt=now()`
  - Persist a new inbound `DigikoppelingMessage` row with `direction=inbound` and `correlatesTo` pointing at the original
  - Fire a domain event `digikoppeling.message.received` for consuming jobs
  - Write audit events for `response-received`, `signature-verified`, and `acknowledged`
  - Return HTTP 200 OK to the remote party (or ebMS3 receipt for AS4)

### Requirement: Grote Berichten via ebMS3/AS4

The adapter SHALL implement the Grote Berichten profile for large payloads (> 20 MB) using ebMS3 AS4 binding.

#### Scenario: Grote Berichten send for payload > 20 MB

- GIVEN a configured `DigikoppelingEndpoint` with `profile=GroteBerichten` and a valid CPA
- WHEN the adapter is invoked with a payload larger than 20 MB OR an explicit `forceGroteBerichten=true` flag
- THEN the adapter SHALL:
  - Package the payload as an ebMS3 user message with the CPA's PMode
  - Apply AS4 message-level signing and encryption using the local PKIoverheid certificate
  - Send via AS4 push binding (HTTPS POST)
  - Expect an AS4 receipt acknowledgement within the PMode receipt-timeout
  - Persist the `DigikoppelingMessage` with `status=sent` and `conversationId` from ebMS3 `MessageID`
  - Write audit events for `created`, `signed`, and `sent`

#### Scenario: Grote Berichten receipt timeout triggers retry

- GIVEN a Grote Berichten message is sent and no AS4 receipt arrives within the PMode receipt-timeout
- WHEN the adapter's retry scheduler runs
- THEN the adapter SHALL:
  - Retry per the `DigikoppelingRetryPolicy` for `GroteBerichten` (default: 14 attempts, exponential backoff, 24 hours)
  - Update `attemptCount` and `nextAttemptAt` on each retry
  - Write `retry-scheduled` audit events
  - After exhausting attempts, mark the message `status=expired` and write an `expired` audit event

### Requirement: Best Effort Lightweight Transport

The adapter SHALL implement Best Effort, a lightweight variant for non-critical messaging.

#### Scenario: Best Effort send with limited retries

- GIVEN a configured `DigikoppelingEndpoint` with `profile=BestEffort`
- WHEN the adapter sends a message
- THEN the adapter SHALL:
  - Use the same envelope format as OUS but without callback registration (fire-and-forget)
  - Retry per the `DigikoppelingRetryPolicy` for `BestEffort` (default: 3 attempts, 1 hour lifetime)
  - Persist the message with `status` progressing to `delivered` or `expired`
  - NOT block the caller on receipt confirmation

### Requirement: PKIoverheid Certificate Management

The adapter SHALL manage PKIoverheid certificates with private-key isolation and expiry monitoring.

#### Scenario: Administrator uploads PKIoverheid certificate

- GIVEN an admin uploads a PKIoverheid certificate via the OpenConnector admin UI
- WHEN the certificate is stored
- THEN the adapter SHALL:
  - Write the private key to Nextcloud's secret store (never to the schema or database)
  - Store the public certificate and chain in `DigikoppelingCertificate` as PEM text
  - Validate the chain against the PKIoverheid root CA bundle
  - Extract and store `validFrom`, `validUntil`, `subjectDN`, `issuerDN`, `serialNumber`
  - Reject the upload if the chain does not terminate at a trusted PKIoverheid root
  - Write an audit event for `created`

#### Scenario: Certificate nears expiry and admin is notified

- GIVEN a stored certificate is within `rotationReminderDays` (default 60) of expiry
- WHEN the daily certificate-check job runs
- THEN the adapter SHALL:
  - Send a Nextcloud notification to all users in the `digikoppeling_admin` group
  - Add an entry to the OpenConnector dashboard widget
  - Once expired, fail any outbound send attempt with a clear error message pointing at the rotation procedure

### Requirement: Retry Policy Enforcement

The adapter SHALL enforce per-profile retry policies and expose them as configurable `DigikoppelingRetryPolicy` rows.

#### Scenario: OUS message fails transient error and is retried

- GIVEN an OUS or Grote Berichten message has `status=failed` after a transient error (network timeout, 5xx, signature-verification failure)
- WHEN the scheduler runs every 5 minutes
- THEN the adapter SHALL:
  - Retry the message per the applicable policy's backoff schedule
  - Increment `attemptCount`
  - Schedule the next attempt at `now() + initialDelaySeconds * backoffMultiplier^attemptCount` capped at `maxDelaySeconds`
  - Write a `retry-scheduled` audit event with the next attempt time

#### Scenario: Message exhausts retries or expires

- GIVEN a message has reached `maxAttempts` OR has exceeded `expireAfterHours`
- WHEN the scheduler evaluates the message
- THEN the adapter SHALL:
  - Mark the message `status=expired`
  - Write an `expired` audit event with details on the reason (max attempts or time limit)
  - Send a notification to the OpenConnector job owner so they can manually intervene

### Requirement: Complete Audit Trail per Message

The adapter SHALL produce a complete, append-only audit trail for every message sufficient to satisfy Archiefwet 1995 and BIO requirements.

#### Scenario: Message lifecycle produces immutable audit trail

- GIVEN a `DigikoppelingMessage` transitions through states: created → signed → sent → response-received → signature-verified → acknowledged
- WHEN each transition occurs
- THEN the adapter SHALL:
  - Write a `DigikoppelingAuditEvent` row with `eventType`, `actor`, `timestamp`, and JSON `details`
  - Never update or delete audit rows
  - Store attempt-specific context (HTTP status, SOAP fault code, signature validation result, ebMS3 receipt MessageId)

#### Scenario: Auditor exports conversation history with integrity proof

- GIVEN an auditor requests the message history for a given `conversationId`
- WHEN the audit-export endpoint is called (`GET /api/digikoppeling/audit/{conversationId}`)
- THEN the adapter SHALL:
  - Return a JSON document containing every message and audit event in chronological order
  - Sign the document with the local certificate to prove integrity
  - Include the SHA-256 hash of the original payloads (not the payloads themselves, which may be archived separately)

### Requirement: Service Registry Sync

The adapter SHALL maintain a local cache of the Logius Digikoppeling Service Registry for endpoint discovery.

#### Scenario: Daily sync fetches and caches Logius registry

- GIVEN the daily service-registry-sync system job runs
- WHEN the job executes
- THEN the adapter SHALL:
  - Fetch the Logius Digikoppeling Service Registry (HTTPS endpoint defined in app config)
  - Upsert every aansluiting into `DigikoppelingServiceRegistryCache` with `lastFetchedAt=now()`
  - Write a summary log of additions, updates, and removals
  - Emit a domain event `digikoppeling.registry.synced`

#### Scenario: Admin configures endpoint via registry lookup

- GIVEN an admin is configuring a new outbound `DigikoppelingEndpoint`
- WHEN they type into the "remote organisation" lookup field
- THEN the adapter SHALL:
  - Query the local `DigikoppelingServiceRegistryCache`
  - Return matching results matched on `organisatienaam`, `aansluitnummer`, or `oin`
  - Pre-fill `endpointUrl`, `serviceNamespace`, `cpaId`, and the remote certificate chain from the cached entry

### Requirement: Payload-Agnostic API for Composition

The adapter SHALL expose a payload-agnostic API so higher-level adapters (StUF, ZGW, Berichtenbox) compose on top without modification.

#### Scenario: StUF adapter sends StUF-ZKN bericht via Digikoppeling

- GIVEN a higher-level adapter (e.g., future `stuf-zkn-adapter`) needs to send a StUF-ZKN bericht
- WHEN it calls the digikoppeling-adapter with:
  ```
  POST /api/digikoppeling/send
  {
    "profile": "WUS" | "OUS" | "GroteBerichten" | "BestEffort",
    "endpointRef": "uuid-of-DigikoppelingEndpoint",
    "messageType": "stuf-zkn:bv03Bericht",
    "payloadHash": "sha256-of-payload",
    "payloadFileRef": "files://admin/digikoppeling/payloads/{messageId}"
  }
  ```
- THEN the adapter SHALL:
  - Wrap the raw payload in the appropriate envelope (SOAP for WUS/OUS, ebMS3 for Grote Berichten)
  - NOT inspect or validate the payload body
  - Set the `messageType` URI on the persisted `DigikoppelingMessage` for audit filtering
  - Return either the synchronous response (WUS) or the `conversationId` (OUS/Grote Berichten/Best Effort)

### Requirement: Per-Endpoint Throughput Limits

The adapter SHALL respect per-endpoint throughput limits to avoid rate-limiting or blocking by remote parties.

#### Scenario: Scheduler respects concurrency and throughput limits

- GIVEN a `DigikoppelingEndpoint` has `maxConcurrentRequests=5` and `requestsPerMinute=60`
- WHEN the retry scheduler picks up pending messages for that endpoint
- THEN the adapter SHALL:
  - Never issue more than 5 concurrent requests to that endpoint
  - Track request volume per endpoint per minute
  - Queue surplus messages in `status=pending` until a slot opens
  - Emit structured logs for throttling events

### Requirement: PKIoverheid Root CA Bundle Updates

The adapter SHALL ship with the PKIoverheid root CA bundle and provide a mechanism to update it without redeploying the app.

#### Scenario: Administrator updates PKIoverheid root CA bundle

- GIVEN the Logius PKIoverheid root CA bundle has been updated (new sub-CA, revocation)
- WHEN the admin runs `occ digikoppeling:update-root-bundle` or triggers from the admin UI
- THEN the adapter SHALL:
  - Fetch the bundle from the configured Logius URL (default: Logius's published HTTPS endpoint)
  - Validate the bundle's signature against a hard-coded Logius master key
  - Replace the local bundle file
  - Re-validate every stored `DigikoppelingCertificate` against the new bundle
  - Flag any whose chain no longer terminates at a trusted root (email `digikoppeling_admin` group)

## Out of Scope

- Higher-level message schemas (StUF, ZGW, Berichtenbox, KIK, NLCS) — separate adapters
- Digipoort (tax/finance variant) — separate adapter
- PEPPOL (e-invoicing) — separate adapter
- Configurable circuit breaker or retry thresholds via admin UI — hardcoded defaults per Logius spec; follow-up spec for knobs
- Per-app migration tasks (procest, zaakafhandelapp, docudesk) — separate specs
- DigiD / eHerkenning broker — separate spec (spec 3 in this batch)
- HaalCentraal adapters — separate spec (spec 2 in this batch, shares cert/audit infrastructure)
