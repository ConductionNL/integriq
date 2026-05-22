# StUF BG/ZKN Koppelvlak

## Why

StUF (Standaard Uitwisselings Formaat) remains the dominant SOAP-based exchange standard between Dutch municipal back-offices. Every municipality in the Netherlands still operates one or more StUF-based connections — typically to the BRP (basisregistratie personen) via StUF-BG, to the zaaksysteem via StUF-ZKN, and to e-formulier engines via StUF-EF. Suppliers cannot reach the installed base without StUF support, and municipalities migrating to ZGW-REST keep StUF running in parallel for years. Procurement scoring documents from VNG Realisatie treat StUF-BG 0310 and StUF-ZKN 0310 conformance as hard pass/fail criteria.

openconnector must provide a production-grade StUF adapter family to compete in municipal integrations, and ADR-019 (integration registry) enables this spec to ship a generic StUF adapter that any consumer can configure and activate.

## What

This change implements a generic StUF adapter family covering three sectormodellen:
- **StUF-BG** (basisgegevens — personen, niet-natuurlijke personen, adressen, gerelateerden)
- **StUF-ZKN** (zaken — zaken, zaaktypen, statussen, documenten, betrokkenen)
- **StUF-EF** (electronisch formulier — inkomende en uitgaande formulieren)

All three are SOAP 1.1/1.2 envelopes with sector-specific bodies governed by published XSDs from VNG Realisatie. Version-pinned XSD bundles (0310 default, 0204 available) are cached in memory and validated on every bericht.

The adapter supports both StUF interaction patterns:
- **Kennisgeving** — asynchronous push (wijzigingsberichten on natural-person mutations triggered by BRP mutations)
- **Vraag/antwoord** — synchronous pull (npsLv01 to fetch a person, zakLv01 to fetch a case)

For kennisgeving, openconnector exposes an HTTPS callback endpoint per adapter, parses inbound envelopes, fans the parsed event to subscribed downstream consumers, and returns a Bv01 acknowledgement.

For vraag/antwoord, the adapter constructs the request envelope with configured stuurgegevens (zender/ontvanger organisatie + applicatie), POSTs to the source endpoint with the correct SOAPAction header, and awaits the matching La01 response with crossRefnummer linkage.

The adapter is transport-agnostic at the SOAP layer but designed to ride on top of Digikoppeling for production deployments (WUS for synchronous, ebMS for asynchronous, Grote Berichten profile for payloads exceeding 20 MB), inheriting mTLS, signing, and reliable-messaging. For development and integration testing, a plain HTTPS transport is supported with optional Basic-Auth.

The adapter integrates fully with the ipaas-reliability tier (retry/circuit-breaker/DLQ apply to transport failures but not to permanent StUF fauts) and with the auth-protocol-suite (mTLS profiles, WS-Security key management).

## Capabilities

### New Capabilities

- `stuf-bg-zkn-adapter`: Production-grade SOAP 1.1/1.2 adapter family for openconnector covering StUF-BG, StUF-ZKN, and StUF-EF sectormodellen; supports version-pinned XSD validation (0310/0204), both kennisgeving and vraag/antwoord interaction patterns, Digikoppeling transport (WUS/ebMS/Grote Berichten), SOAP fault to typed error mapping, mTLS and WS-Security integration, abonnement management with mutatiefilters, bericht correlation via referentienummer, idempotent kennisgeving handling, and VNG conformance test harness.

## Affected Repos

openconnector only.

## References

- VNG Realisatie StUF documentation: https://www.vngirealisatie.nl/stuf
- StUF-BG 0310 XSD: Bundled in this adapter
- StUF-ZKN 0310 XSD: Bundled in this adapter
- StUF-EF XSD: Bundled in this adapter
- Digikoppeling 3.0: Transport for production deployments
- ADR-019 (integration registry): Connector registration pattern
- ADR-003 (CallLog as observability): SOAP faults logged to CallLog
- ipaas-reliability tier: Retry/circuit-breaker on transport failures
- auth-protocol-suite: mTLS and WS-Security key management

## Out of Scope

- Custom sectormodellen beyond BG/ZKN/EF — separate specs if needed
- Configurable XSD bundles or versions beyond 0310/0204 — hardcoded for this spec
- Admin UI for adapter configuration — API-only for this spec
- Migration tooling for legacy StUF gateways — separate operational guides
- Other message formats (e.g. HL7, EDIFACT) — separate adapters when needed
