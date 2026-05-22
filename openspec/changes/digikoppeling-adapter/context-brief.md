---
status: draft
---
# Digikoppeling 2.0 transport (overheid-NL)

## Purpose

Digikoppeling is the mandatory Dutch government transport standard (Forum Standaardisatie "pas-toe-of-leg-uit" list) for machine-to-machine messaging between public bodies. Any Conduction app that exchanges structured data with another overheidsorganisatie — be it a municipality, a uitvoeringsorganisatie like SVB or UWV, a province, a water board, a ministry, or a chain partner running zaaksystemen — is legally required to use Digikoppeling for the transport layer when the receiving party publishes a Digikoppeling endpoint. The standard exists in three flavours that solve different problems: WUS (WSDL/UDDI/SOAP) for small synchronous request/response calls, OUS (One-way Asynchronous Service) for fire-and-forget delivery of larger payloads where the response comes back via a separate callback, and Grote Berichten (ebMS3 / AS4 profile) for payloads larger than 20 MB or where guaranteed delivery is non-negotiable. Best Effort is the lightweight variant for non-critical messaging where the OUS retry semantics are overkill but a Digikoppeling-style envelope is still required for interoperability.

OpenConnector currently has a generic source/job model that can talk REST and SOAP, but it has no awareness of the Digikoppeling envelope, the PKIoverheid trust chain, the WS-Security signing requirements, or the OUS callback dance. Every Conduction app that needs to talk to another overheidsorganisatie today has to either reinvent this wheel locally or sit out the integration entirely. This spec captures the requirements for a first-class `digikoppeling-adapter` that wraps the three transport profiles behind a single configuration surface, manages the Logius aansluitnummer registry, handles certificate rotation, performs retries according to the official retry-policy table, and produces an audit trail per message that satisfies the Archiefwet retention requirements. The adapter is consumed by every other Conduction app via the OpenConnector job system: a procest workflow that needs to send a StUF-ZKN bericht to a municipality's zaaksysteem, a zaakafhandelapp that needs to deliver a beschikking via the Berichtenbox, a docudesk pipeline that ships a signed PDF to another agency — all of them call into a Digikoppeling job and let the adapter handle the wire format.

The scope of this spec is the transport layer only. Higher-level message schemas (StUF, ZGW, Berichtenbox payload, KIK, NLCS, etc.) are handled by separate adapters that compose on top of Digikoppeling — the digikoppeling-adapter is the envelope, not the letter. The spec also explicitly excludes Digipoort (which is Logius's tax/finance-only variant, B2G heavy) and PEPPOL (which is e-invoicing and lives in its own adapter). The boundary is: if the receiving party's endpoint is registered in the Digikoppeling Service Registry with a CPA (Collaboration Protocol Agreement) or a WSDL, this adapter handles it.

## Data Model

The adapter introduces six new OpenRegister schemas living in a dedicated `openconnector-digikoppeling` register:

**DigikoppelingEndpoint** — represents a remote party's published endpoint. Fields: `aansluitnummer` (Logius-assigned 5-digit ID of the remote party), `oin` (Organisatie-Identificatienummer, 20 digits, the canonical NL government org ID), `name` (human-readable name from the Digikoppeling Service Registry), `profile` (enum: WUS / OUS / GroteBerichten / BestEffort), `endpointUrl`, `wsdlUrl` (for WUS endpoints), `cpaId` (Collaboration Protocol Agreement ID for ebMS3/AS4 endpoints), `serviceNamespace`, `messageTypes` (array of supported message-type URIs), `pkiCertificateChain` (the remote party's signing cert chain, used for response verification), `validFrom`, `validUntil`, `lastSyncedFromRegistry` (timestamp of last fetch from the Digikoppeling Service Registry). Endpoints can be sourced manually or auto-synced from the Logius service registry.

**DigikoppelingCertificate** — represents a PKIoverheid certificate owned by the local party (the Nextcloud instance). Fields: `aansluitnummer` (our own Logius number), `oin` (our OIN), `certificateType` (enum: signing / encryption / TLS-client), `pemCertificate`, `pemPrivateKeyRef` (reference to the key in Nextcloud's secret store — the key itself never sits in the schema), `pemChain` (intermediate + root certs), `validFrom`, `validUntil`, `subjectDN`, `issuerDN`, `serialNumber`, `rotationReminderDays` (default 60). The adapter raises a warning notification when the cert is within `rotationReminderDays` of expiry.

**DigikoppelingMessage** — every message sent or received gets exactly one row. Fields: `direction` (enum: outbound / inbound), `profile` (WUS / OUS / GroteBerichten / BestEffort), `localEndpointRef`, `remoteEndpointRef`, `messageId` (the WS-Addressing or ebMS3 MessageId), `conversationId` (groups request + response + callbacks for OUS), `messageType` (the StUF / ZGW / business-payload type URI), `payloadHash` (SHA-256 of the canonicalised payload, for archive integrity), `payloadFileRef` (Nextcloud Files reference to the actual payload — kept out of the DB to avoid bloating Postgres with 50 MB SOAP envelopes), `payloadSizeBytes`, `status` (enum: pending / sent / delivered / acknowledged / failed / expired), `statusReason` (free text for failures), `attemptCount`, `nextAttemptAt`, `firstAttemptAt`, `lastAttemptAt`, `acknowledgedAt`, `expiresAt` (per the retry-policy table), `signingCertificateRef`, `correlatesTo` (links a response to its request), `triggeredByJobRef` (the OpenConnector job that initiated the send). Inbound messages get the same row structure with `direction=inbound` and the local app populated as `localEndpointRef`.

**DigikoppelingRetryPolicy** — per-profile retry configuration. Fields: `profile`, `initialDelaySeconds`, `maxAttempts`, `backoffMultiplier`, `maxDelaySeconds`, `expireAfterHours`. Default values match the official Logius retry-policy table (OUS: 7 attempts over 4 hours; Grote Berichten / AS4: 14 attempts over 24 hours with exponential backoff). Per-endpoint overrides allowed.

**DigikoppelingAuditEvent** — append-only audit table. Fields: `messageRef`, `eventType` (enum: created / signed / sent / wire-error / response-received / signature-verified / acknowledged / retry-scheduled / expired / archived), `actor` (the Nextcloud user or system account that triggered the event), `timestamp`, `details` (JSON blob with attempt-specific data: HTTP status, SOAP fault code, signature validation result, ebMS3 receipt MessageId). Retention: minimum 7 years per Archiefwet; configurable up to permanent.

**DigikoppelingServiceRegistryCache** — local mirror of the Logius Digikoppeling Service Registry. Fields: `aansluitnummer`, `oin`, `organisatienaam`, `services` (JSON array of published service URIs), `cpaDocuments` (array of CPA XML blobs), `lastFetchedAt`. Refreshed daily via a system job. Used to populate the lookup dropdown when an admin configures a new outbound endpoint.

## Requirements

### REQ-001: WUS synchronous transport
The adapter SHALL implement the WUS-koppelvlak profile for synchronous SOAP 1.2 request/response with WS-Security message signing.

**GIVEN** a configured `DigikoppelingEndpoint` with profile `WUS` and a valid local signing `DigikoppelingCertificate`
**WHEN** an OpenConnector job invokes the adapter with a SOAP body payload and a target service operation
**THEN** the adapter constructs a SOAP 1.2 envelope with WS-Addressing headers (`MessageID`, `To`, `Action`, `From`, `ReplyTo`), signs the body and timestamp per WS-Security 1.1 using the local certificate, sends the request over HTTPS with PKIoverheid mTLS, verifies the response signature against the remote endpoint's certificate chain, and returns the response body to the caller.

**GIVEN** a WUS request fails with a SOAP fault or transport error
**WHEN** the adapter receives the failure
**THEN** it records the message as `status=failed` with the fault code in `statusReason`, writes a `wire-error` audit event with the raw response, and surfaces a structured error to the caller — WUS is synchronous and does NOT retry automatically (retry is the caller's responsibility for sync calls).

### REQ-002: OUS asynchronous transport with callback
The adapter SHALL implement One-way Asynchronous Service (OUS) where the response is delivered to a callback endpoint registered with `ReplyTo`.

**GIVEN** a configured `DigikoppelingEndpoint` with profile `OUS` and a payload to send asynchronously
**WHEN** the adapter sends the OUS request
**THEN** it sets the WS-Addressing `ReplyTo` to the local callback URL (auto-generated per message), persists the `DigikoppelingMessage` with `status=sent` and `conversationId` equal to the WS-Addressing `MessageID`, and returns immediately to the caller with the conversationId for tracking.

**GIVEN** an OUS callback arrives at the local callback endpoint
**WHEN** the adapter receives the inbound SOAP envelope
**THEN** it verifies the signature against the originating endpoint's certificate, looks up the corresponding outbound message by `RelatesTo` header, updates the original message row to `status=acknowledged` with `acknowledgedAt=now()`, persists a new inbound `DigikoppelingMessage` row with `correlatesTo` pointing at the original, fires a domain event `digikoppeling.message.received` so consuming jobs can act on the response, and returns an empty 200 OK to the remote party.

### REQ-003: Grote Berichten via ebMS3 / AS4
The adapter SHALL implement the Grote Berichten profile for guaranteed-delivery transport of payloads > 20 MB using the ebMS3 AS4 binding.

**GIVEN** a configured `DigikoppelingEndpoint` with profile `GroteBerichten` and a valid CPA (`cpaId`)
**WHEN** the adapter is invoked with a payload larger than 20 MB OR an explicit `forceGroteBerichten=true` flag
**THEN** the adapter packages the payload as an ebMS3 user message with the CPA's PMode, applies AS4 message-level signing and encryption using the local PKIoverheid certificate, sends the message via the AS4 push binding, and expects an AS4 receipt acknowledgement within the receipt-timeout defined in the PMode.

**GIVEN** an AS4 receipt does not arrive within the PMode receipt-timeout
**WHEN** the adapter's retry scheduler runs
**THEN** it retries per the `DigikoppelingRetryPolicy` for `GroteBerichten` (default: exponential backoff, 14 attempts over 24 hours), updates `attemptCount` and `nextAttemptAt` on each retry, and after exhausting attempts marks the message `status=expired` and writes an `expired` audit event.

### REQ-004: PKIoverheid certificate management
The adapter SHALL manage PKIoverheid certificates including private-key isolation, chain validation, and expiry monitoring.

**GIVEN** an admin uploads a PKIoverheid certificate via the OpenConnector admin UI
**WHEN** the certificate is stored
**THEN** the private key is written to Nextcloud's secret store (never to the schema), the public certificate and chain are stored in the `DigikoppelingCertificate` schema, the chain is validated against the PKIoverheid root CA bundle bundled with the adapter, `validFrom`/`validUntil`/`subjectDN`/`issuerDN`/`serialNumber` are extracted, and the certificate is rejected if the chain does not terminate at a trusted PKIoverheid root.

**GIVEN** a stored certificate is within `rotationReminderDays` of expiry
**WHEN** the daily certificate-check job runs
**THEN** a Nextcloud notification is sent to all users with the `digikoppeling_admin` group, an entry is written to the OpenConnector dashboard widget, and once the certificate expires every outbound send attempt fails immediately with a clear error pointing at the rotation procedure.

### REQ-005: Retry policy enforcement
The adapter SHALL enforce per-profile retry policies and expose them as configurable `DigikoppelingRetryPolicy` rows.

**GIVEN** an outbound OUS or Grote Berichten message has `status=failed` after a transient error (network timeout, 5xx, signature-verification-failure on receipt)
**WHEN** the scheduler runs
**THEN** the message is retried per the applicable policy's backoff schedule, `attemptCount` is incremented, and the next attempt is scheduled at `now() + initialDelaySeconds * backoffMultiplier^attemptCount` capped at `maxDelaySeconds`.

**GIVEN** a message has reached `maxAttempts` OR has exceeded `expireAfterHours`
**WHEN** the scheduler next evaluates the message
**THEN** the message is marked `status=expired`, an audit event of type `expired` is written, and a notification is sent to the originating job's owner so they can manually intervene.

### REQ-006: Audit trail per message
The adapter SHALL produce a complete, append-only audit trail for every message sufficient to satisfy Archiefwet 1995 and BIO requirements.

**GIVEN** any state transition on a `DigikoppelingMessage` (created, signed, sent, response-received, acknowledged, retried, expired, archived)
**WHEN** the transition occurs
**THEN** a `DigikoppelingAuditEvent` row is written with `actor`, `timestamp`, `eventType`, and a JSON `details` blob; rows are never updated or deleted; retention is configurable per register (default 7 years).

**GIVEN** an auditor requests the message history for a given `conversationId`
**WHEN** the audit-export endpoint is called
**THEN** the adapter returns a JSON document containing every message and every audit event in that conversation in chronological order, signed with the local certificate to prove integrity.

### REQ-007: Service registry sync
The adapter SHALL maintain a local cache of the Logius Digikoppeling Service Registry to support endpoint configuration without manual data entry.

**GIVEN** the daily service-registry-sync job runs
**WHEN** the job executes
**THEN** the adapter fetches the registry, upserts every aansluiting into `DigikoppelingServiceRegistryCache` with `lastFetchedAt=now()`, and writes a summary log of additions, updates, and removals.

**GIVEN** an admin is configuring a new outbound `DigikoppelingEndpoint`
**WHEN** they type into the "remote organisation" lookup
**THEN** the adapter returns matching results from the local cache (matched on `organisatienaam`, `aansluitnummer`, or `oin`) and pre-fills `endpointUrl`, `serviceNamespace`, `cpaId`, and the remote certificate chain from the cached registry entry.

### REQ-008: StUF / ZGW / Berichtenbox composability
The adapter SHALL expose a payload-agnostic API so that higher-level adapters (StUF, ZGW, Berichtenbox) can compose on top without modification to the Digikoppeling layer.

**GIVEN** a higher-level adapter (e.g., a future `stuf-zkn-adapter`) needs to send a StUF-ZKN bericht
**WHEN** it calls the digikoppeling-adapter with `{ profile: 'WUS' | 'OUS' | 'GroteBerichten', endpoint: ..., body: <raw-xml>, messageType: 'stuf-zkn:bv03Bericht' }`
**THEN** the adapter wraps the raw XML in the appropriate envelope without inspecting the body, sets the `messageType` on the persisted `DigikoppelingMessage` for filtering and audit, and returns either the synchronous response (WUS) or the conversationId (OUS / Grote Berichten) without imposing any structure on the body.

### REQ-009: PKIoverheid root CA bundle update
The adapter SHALL ship with the PKIoverheid root CA bundle and provide a mechanism to update it without redeploying the app.

**GIVEN** Logius publishes an updated PKIoverheid root CA bundle (typically when a new sub-CA is issued or one is revoked)
**WHEN** the admin runs the `occ digikoppeling:update-root-bundle` command or triggers it from the admin UI
**THEN** the adapter fetches the bundle from the configured Logius URL, validates the bundle's signature against a hard-coded master key, replaces the local bundle file, and re-validates every stored `DigikoppelingCertificate` against the new bundle — flagging any whose chain no longer terminates at a trusted root.

### REQ-010: Throughput and concurrency limits
The adapter SHALL respect per-endpoint throughput limits to avoid being rate-limited or blocked by the remote party.

**GIVEN** a `DigikoppelingEndpoint` has a configured `maxConcurrentRequests` and `requestsPerMinute`
**WHEN** the scheduler picks up pending messages for that endpoint
**THEN** at most `maxConcurrentRequests` are in flight at any time and the total send rate does not exceed `requestsPerMinute`; surplus messages remain in `status=pending` until a slot opens.

## Standards & Sources

- Forum Standaardisatie — Digikoppeling 2.0 on the "pas-toe-of-leg-uit" list (mandatory for NL government interop).
- Logius — Digikoppeling Koppelvlakstandaard WUS 3.5 (synchronous SOAP).
- Logius — Digikoppeling Koppelvlakstandaard OUS 1.3 (asynchronous one-way).
- Logius — Digikoppeling Koppelvlakstandaard Grote Berichten / ebMS3-AS4 profiel.
- Logius — Digikoppeling Best Effort koppelvlak.
- OASIS — WS-Security 1.1, WS-Addressing 1.0, SOAP 1.2.
- OASIS — ebMS 3.0 Part 1 + AS4 Profile.
- Logius — PKIoverheid Programma van Eisen (PvE) deel 3 + 3a.
- Logius — Digikoppeling Service Registry (the authoritative endpoint catalogue).
- Archiefwet 1995 + Selectielijst gemeenten en intergemeentelijke organen 2026 (retention).
- BIO (Baseline Informatiebeveiliging Overheid) — logging, signing, encryption-in-transit controls.

## Cross-app integration

The Digikoppeling adapter is the foundation that every other Conduction app sits on for overheid-to-overheid messaging. The integration model is composition, never coupling: the adapter does not know that StUF or ZGW exist; the higher-level adapters do not know that ebMS3 exists.

- **openconnector base** — the adapter is shipped as part of openconnector and uses the existing source/job/synchronisation infrastructure. A Digikoppeling endpoint shows up in the OpenConnector UI as a special source type, and Digikoppeling jobs are normal OpenConnector jobs with extra envelope handling.
- **procest** — workflow steps can call a Digikoppeling job as a transport. A workflow that needs to send a beschikking to another municipality drops a "Send via Digikoppeling" step and picks the endpoint from the cached registry.
- **zaakafhandelapp** — zaken that need to be doorgezet to a chain partner use the digikoppeling-adapter via the future `stuf-zkn-adapter` and `zgw-api-adapter` layers.
- **docudesk** — signed PDFs and other long-lived documents are shipped to external archives via Grote Berichten when payloads exceed 20 MB.
- **opencatalogi** — the catalogi can describe a Digikoppeling endpoint as a Service in its register, making it discoverable to other Conduction installations.
- **openregister** — provides the schema/register infrastructure for all six Digikoppeling schemas, the audit-event append-only enforcement, and the file-attachment mechanism used to store large payloads outside Postgres.
- **mydash** — surfaces per-endpoint throughput, retry queues, certificate-expiry countdowns, and audit-event volumes as dashboard widgets so operations teams can monitor the bedrock layer.

The HaalCentraal-Personen/BAG/HR/KvK adapter and DigiD/eHerkenning broker (specs 2 and 3 in this batch) are independent of Digikoppeling — HaalCentraal is REST/OAuth2 and authentication is HTTP redirect — but they share the certificate management and audit infrastructure introduced here, which is why this spec lands first.

## Target users

- **NL gemeenten, provincies, waterschappen, GR's** — every public body that needs to exchange messages with another public body. Today most of them outsource this to a leverancier-specific koppelvlak (Centric, PinkRoccade, Equalit). This adapter gives them an open-source path.
- **Uitvoeringsorganisaties (SVB, UWV, DUO, RDW, CJIB, etc.)** — both as senders and as receivers; the adapter can be installed at either end of a bilateral integration.
- **Ketenpartners** — health insurers, woningcorporaties (sectoraal aangesloten op Digikoppeling), notarissen via KNB, etc.
- **Implementatiepartners en system integrators** — Conduction's own delivery partners need a turn-key Digikoppeling layer to bid on overheidsopdrachten without quoting two extra sprints for transport.
- **Internal app teams** — every Conduction app team that wants to talk to overheid systems benefits from not having to learn ebMS3, WS-Security, PKIoverheid certificate handling, or Logius's retry table.
- **Security and compliance officers** — the per-message audit trail and 7-year retention satisfy Archiefwet, BIO, and internal SoX-style controls without a separate compliance layer.
