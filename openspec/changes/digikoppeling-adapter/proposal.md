# Digikoppeling 2.0 Transport Adapter

## Why

Digikoppeling is the mandatory Dutch government transport standard for machine-to-machine messaging between public bodies. Every Conduction app that exchanges structured data with another overheidsorganisatie (gemeente, SVB, UWV, provincie, waterschap, ministry, or chain partner) is legally required to use Digikoppeling when the receiving party publishes a Digikoppeling endpoint.

Today, OpenConnector has a generic source/job model that talks REST and SOAP, but lacks awareness of the Digikoppeling envelope, PKIoverheid trust chains, WS-Security signing, retry semantics, and audit trails. Every integration to another government system requires local reinvention of this wheel or sitting out the integration entirely.

This spec establishes a first-class `digikoppeling-adapter` that wraps three transport profiles (WUS / OUS / Grote Berichten / Best Effort) behind a single configuration surface, manages the Logius aansluitnummer registry, handles PKIoverheid certificate rotation, enforces official retry policies, and produces audit trails satisfying Archiefwet retention requirements.

## What

- A complete digikoppeling adapter module with six new OpenRegister schemas:
  - `DigikoppelingEndpoint` — remote party's published endpoint with profile, certificate chain, message types
  - `DigikoppelingCertificate` — local PKIoverheid cert with private-key isolation and rotation monitoring
  - `DigikoppelingMessage` — every sent/received message with status, retry state, and audit hooks
  - `DigikoppelingRetryPolicy` — per-profile retry configuration (WUS/OUS/Grote Berichten/Best Effort)
  - `DigikoppelingAuditEvent` — append-only audit trail for Archiefwet compliance
  - `DigikoppelingServiceRegistryCache` — local mirror of Logius service registry for endpoint discovery

- WUS synchronous SOAP transport with WS-Security message signing and mTLS verification.
- OUS asynchronous transport with callback endpoint and conversation tracking.
- Grote Berichten via ebMS3/AS4 with guaranteed delivery and exponential backoff.
- Best Effort lightweight variant for non-critical messaging.
- Per-endpoint throughput limits and concurrency control.
- Automatic Logius service registry sync (daily) for endpoint discovery.
- PKIoverheid certificate management with private-key isolation and expiry warnings.
- Structured audit events with 7-year retention (configurable to permanent).
- Payload-agnostic API for higher-level adapters (StUF, ZGW, Berichtenbox) to compose on top.

## Capabilities

### New Capabilities

- `digikoppeling-adapter`: First-class Digikoppeling 2.0 transport for OpenConnector — wraps WUS, OUS, Grote Berichten, and Best Effort behind a unified configuration surface; manages Logius aansluitnummer registry; handles PKIoverheid certificates with private-key isolation; enforces per-profile retry policies; produces complete audit trails satisfying Archiefwet; enables composition by higher-level adapters (StUF, ZGW, Berichtenbox).

## Affected Repos

- openconnector (primary)
- openregister (schemas and audit-event enforcement)

## Out of Scope

- Higher-level message schemas (StUF, ZGW, Berichtenbox, KIK, NLCS) — handled by separate adapters
- Digipoort (tax/finance variant) — separate adapter
- PEPPOL (e-invoicing) — separate adapter
- Configurable circuit breaker thresholds or retry delays — hardcoded defaults per Logius spec; follow-up for admin UI knobs
- Frontend UI for endpoint configuration — captured in OpenConnector admin surface
- Procest, zaakafhandelapp, docudesk migration tasks — separate per-app specs
