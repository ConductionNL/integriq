# Design: stuf-bg-zkn-bg-koppelvlak

## Architecture

### Class Structure

#### StUFAdapterConfig (openregister schema)

Stores a single StUF adapter instance configuration. Fields:
- `id` (UUID) — unique identifier
- `slug` (string) — URL-friendly name
- `sectormodel` (enum: `BG`, `ZKN`, `EF`) — which sectormodel this adapter handles
- `schema_version` (string, e.g. `0310`, `0204`) — XSD version
- `endpoint_url` (string) — source SOAP endpoint (https://...)
- `ontvanger_organisatie` (string) — receiver organisation code
- `ontvanger_applicatie` (string) — receiver application code
- `zender_organisatie` (string) — sender organisation code
- `zender_applicatie` (string) — sender application code
- `transport_profile` (enum: `digikoppeling_wus`, `digikoppeling_ebms`, `plain_https`) — transport binding
- `auth_profile_ref` (UUID) — reference to auth-protocol-suite credentials
- `reliability_profile_ref` (UUID) — reference to ipaas-reliability retry policy
- `xsd_bundle_ref` (string) — cached XSD bundle identifier
- `retention_days` (integer, default 90) — how long to retain bericht bodies before redaction
- `testbed_mode` (boolean, default false) — if true, use VNG StUF-Testplatform for conformance runs

#### StUFBericht (openregister, append-only)

Stores a single inbound or outbound SOAP message. Fields:
- `id` (UUID) — unique identifier
- `adapter_id` (UUID) — reference to StUFAdapterConfig
- `direction` (enum: `inbound`, `outbound`) — which direction
- `interaction` (enum: `kennisgeving`, `vraag`, `antwoord`, `fout`) — StUF interaction type
- `berichtsoort` (string, e.g. `Lv01`, `La01`, `Bv01`, `Kv01`) — message type code
- `entiteittype` (string, e.g. `NPS`, `ZAK`, `EDC`) — entity type
- `referentienummer` (UUID) — StUF message correlation ID
- `tijdstipBericht` (timestamp) — StUF message timestamp (yyyyMMddHHmmssSSS)
- `crossRefnummer` (UUID, nullable) — response correlation reference
- `raw_envelope_ref` (UUID) — reference to stored SOAP envelope (blob storage)
- `parsed_payload` (JSON) — parsed and validated SOAP body (structure varies per sectormodel)
- `soap_action` (string, e.g. `http://www.egem.nl/StUF/sector/bg/0310/npsLv01`) — SOAPAction header
- `response_to` (UUID, nullable) — if this is an antwoord, reference to the outbound vraag
- `created_at` (timestamp) — openconnector receipt time

#### StUFAbonnement (openregister)

Stores a subscription to receive kennisgevingen. Fields:
- `id` (UUID) — unique identifier
- `adapter_id` (UUID) — reference to StUFAdapterConfig
- `entiteittype` (string, e.g. `NPS`, `ZAK`) — entity type to subscribe to
- `mutatiefilters` (array of strings, e.g. `[overlijden, verhuizing, naamswijziging]`) — VNG mutatiesoort codes
- `callback_endpoint` (string) — HTTPS endpoint where this adapter receives kennisgevingen
- `status` (enum: `active`, `paused`, `cancelled`) — subscription state
- `created` (timestamp) — when subscription was created
- `last_delivery` (timestamp, nullable) — last successful kennisgeving delivery time

#### StUFFout (extends CallLog)

Stores structured SOAP fault information. Extends CallLog with:
- `foutcode` (string, e.g. `StUF016`, `Fo01`) — VNG StUF error code
- `ernst` (enum: `info`, `waarschuwing`, `fout`) — severity level
- `omschrijving` (string) — error description from fault
- `plek` (string) — fault location (adapter_id)
- `gerelateerd_xpath` (string, nullable) — XPath to the faulted element

### Request/Response Flow

#### Vraag/Antwoord (Synchronous)

1. Consumer (e.g., workflow app) calls `POST /api/connector/{adapter_slug}/request` with query parameters
2. StUFConnector constructs SOAP envelope:
   - Generate `referentienummer` (UUIDv4)
   - Stamp `tijdstipBericht` (now in yyyyMMddHHmmssSSS)
   - Inject configured `stuurgegevens`
   - Serialize the request body per sectormodel XSD
   - Validate against XSD before sending
3. POST to configured `endpoint_url` with `SOAPAction` header
4. Await response (with configurable timeout, e.g. 30s)
5. Validate response envelope against XSD
6. Parse response body
7. Persist StUFBericht (direction=outbound, interaction=vraag, response_to=null)
8. On receipt of antwoord:
   - Validate envelope
   - Persist StUFBericht (direction=inbound, interaction=antwoord, response_to=outbound_id, crossRefnummer from response)
   - Emit `stuf.vraag.answered` domain event
   - Resolve in-flight promise waiting on referentienummer
   - Return parsed antwoord body to consumer

#### Kennisgeving (Asynchronous)

1. Setup: Consumer creates StUFAbonnement via API with callback_endpoint
2. StUFConnector POSTs abonnement-bericht to source's abonnement-endpoint per sectormodel
3. Source responds with abonnement-id, stored in StUFAbonnement.id
4. At runtime: Source PUSHes kennisgeving to callback_endpoint
5. openconnector receives:
   - Validates envelope against XSD
   - Deduplicates by (referentienummer, sender, berichtsoort) within 24h window
   - If duplicate: return previously-sent Bv01, increment counter, skip downstream event
   - If new: parse payload, persist StUFBericht, emit `stuf.bericht.received` event
   - Return Bv01 (bevestigingsbericht) acknowledgement to source
6. Subscribed downstream consumers react to the domain event

### SOAP Fault Handling

On any SOAP fault response:
1. Parse fault envelope (SOAP 1.1 or 1.2 format)
2. Extract StUF-specific fields from fault body:
   - `<Fo02>` (fault detail block) containing `foutcode`, `omschrijving`
   - XPath to the offending element (if provided)
3. Map to typed StUFFault error with foutcode, ernst (derived from foutcode), omschrijving, plek, gerelateerd_xpath
4. Persist to CallLog as a StUFFault entry
5. Return typed error to caller (not a generic HTTP 5xx, but a specific StUFError)
6. reliability_profile retry rules do NOT apply (SOAP faults are permanent by contract)

### Schema Validation

On every bericht (inbound or outbound):
1. Load XSD bundle from cache (keyed by sectormodel + schema_version)
2. Parse SOAP envelope
3. Validate SOAP body against the imported schemas for the sectormodel
4. On validation error: raise typed StUFValidationError with XSD error location and message
5. On validation success: proceed with processing

XSD bundles are version-pinned (0310 is primary, 0204 fallback) and loaded into memory at adapter startup.

### Transport Binding

- **plain_https**: Direct HTTPS POST to endpoint_url with optional Basic-Auth
- **digikoppeling_wus**: Delegate transport to digikoppeling-adapter (WUS profile with WS-Security signing, mTLS, WS-Addressing)
- **digikoppeling_ebms**: Delegate transport to digikoppeling-adapter (ebMS2 profile with Message Service Handler); for Grote Berichten, digikoppeling-adapter splits payload per GB profile and tracks reference in StUFBericht

### Seed Data

#### Example StUFAdapterConfig for StUF-BG 0310 (BRP mutations)

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440001",
  "slug": "brp-mutations-0310",
  "sectormodel": "BG",
  "schema_version": "0310",
  "endpoint_url": "https://brp-source.gemeente.nl/soap/npsLv01",
  "ontvanger_organisatie": "1234567890",
  "ontvanger_applicatie": "openconnector",
  "zender_organisatie": "0000000000",
  "zender_applicatie": "BRP-systeem",
  "transport_profile": "plain_https",
  "auth_profile_ref": null,
  "reliability_profile_ref": "550e8400-e29b-41d4-a716-446655440002",
  "xsd_bundle_ref": "stuf-bg-0310",
  "retention_days": 90,
  "testbed_mode": false
}
```

#### Example StUFAdapterConfig for StUF-ZKN 0310 (Case mutations)

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440003",
  "slug": "zaken-mutations-0310",
  "sectormodel": "ZKN",
  "schema_version": "0310",
  "endpoint_url": "https://zaak-source.gemeente.nl/soap/zakLv01",
  "ontvanger_organisatie": "1234567890",
  "ontvanger_applicatie": "openconnector",
  "zender_organisatie": "0000000000",
  "zender_applicatie": "Zaaksysteem",
  "transport_profile": "digikoppeling_wus",
  "auth_profile_ref": "550e8400-e29b-41d4-a716-446655440004",
  "reliability_profile_ref": "550e8400-e29b-41d4-a716-446655440005",
  "xsd_bundle_ref": "stuf-zkn-0310",
  "retention_days": 90,
  "testbed_mode": false
}
```

#### Example StUFBericht (outbound vraag for natural person lookup)

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440010",
  "adapter_id": "550e8400-e29b-41d4-a716-446655440001",
  "direction": "outbound",
  "interaction": "vraag",
  "berichtsoort": "Lv01",
  "entiteittype": "NPS",
  "referentienummer": "550e8400-e29b-41d4-a716-446655440100",
  "tijdstipBericht": "20260522143022123",
  "crossRefnummer": null,
  "raw_envelope_ref": "blob:soap-envelope-001",
  "parsed_payload": {
    "npsLv01": {
      "stuurgegevens": {
        "berichtcode": "Lv01",
        "zender": {"organisatie": "1234567890", "applicatie": "openconnector"},
        "ontvanger": {"organisatie": "0000000000", "applicatie": "BRP-systeem"},
        "referentienummer": "550e8400-e29b-41d4-a716-446655440100",
        "tijdstipBericht": "20260522143022123"
      },
      "parameters": {"sortering": false, "indicatorVervolgvraag": false},
      "gelijk": {"inp.bsn": "123456782"}
    }
  },
  "soap_action": "http://www.egem.nl/StUF/sector/bg/0310/npsLv01",
  "response_to": null,
  "created_at": "2026-05-22T14:30:22Z"
}
```

#### Example StUFBericht (inbound antwoord for natural person)

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440011",
  "adapter_id": "550e8400-e29b-41d4-a716-446655440001",
  "direction": "inbound",
  "interaction": "antwoord",
  "berichtsoort": "La01",
  "entiteittype": "NPS",
  "referentienummer": "550e8400-e29b-41d4-a716-446655440101",
  "tijdstipBericht": "20260522143023500",
  "crossRefnummer": "550e8400-e29b-41d4-a716-446655440100",
  "raw_envelope_ref": "blob:soap-envelope-002",
  "parsed_payload": {
    "npsLa01": {
      "stuurgegevens": {
        "berichtcode": "La01",
        "referentienummer": "550e8400-e29b-41d4-a716-446655440101",
        "crossRefnummer": "550e8400-e29b-41d4-a716-446655440100"
      },
      "antwoord": {
        "nps": {
          "inp.bsn": "123456782",
          "voornamen": "Jan",
          "geslachtsnaam": "Jansen",
          "geslachtsaanduiding": "M",
          "geboortedatum": "19700101"
        }
      }
    }
  },
  "soap_action": "http://www.egem.nl/StUF/sector/bg/0310/npsLa01",
  "response_to": "550e8400-e29b-41d4-a716-446655440010",
  "created_at": "2026-05-22T14:30:23Z"
}
```

#### Example StUFAbonnement (BRP natural person mutations)

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440020",
  "adapter_id": "550e8400-e29b-41d4-a716-446655440001",
  "entiteittype": "NPS",
  "mutatiefilters": ["overlijden", "verhuizing", "naamswijziging"],
  "callback_endpoint": "https://openconnector.gemeente.nl/api/stuf/kennisgeving/brp-mutations-0310",
  "status": "active",
  "created": "2026-05-15T10:00:00Z",
  "last_delivery": "2026-05-22T09:15:00Z"
}
```

#### Example StUFFout (fault logged to CallLog)

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440030",
  "adapter_id": "550e8400-e29b-41d4-a716-446655440001",
  "foutcode": "StUF016",
  "ernst": "fout",
  "omschrijving": "Object niet gevonden",
  "plek": "550e8400-e29b-41d4-a716-446655440001",
  "gerelateerd_xpath": "/npsLa01/antwoord/nps"
}
```

## XSD Bundle Management

XSD bundles are cached in memory at adapter startup (during activation or app boot). The bundled XSDs ship with openconnector:

- `lib/Resources/xsd/stuf-bg-0310/` — StUF-BG sector model 0310 (GEB, GPH, NAH, GEL, ...)
- `lib/Resources/xsd/stuf-bg-0204/` — StUF-BG sector model 0204 (legacy fallback)
- `lib/Resources/xsd/stuf-zkn-0310/` — StUF-ZKN sector model 0310 (ZAK, ZAAKTYPE, STATUS, ...)
- `lib/Resources/xsd/stuf-zkn-0301/` — StUF-ZKN sector model 0301 (legacy fallback)
- `lib/Resources/xsd/stuf-ef/` — StUF-EF sector model (formulier, bijlage, ...)

Each bundle includes the base StUF 0.1 schemas and sector-specific extensions. All validation failures are captured with XPath and error message.

## Observability

Structured log entries are emitted per StUF bericht interaction (not per re-delivery of cached/deduplicated kennisgevingen). Log fields:

- `adapter_id` (UUID)
- `interaction` (vraag|antwoord|kennisgeving|fout)
- `berichtsoort` (string)
- `entiteittype` (string)
- `direction` (inbound|outbound)
- `upstream_latency_ms` (integer, nullable for inbound)
- `http_status` (integer, nullable)
- `soap_status` (success|fault)
- `foutcode` (string, nullable if no fault)
- `transport_profile` (string)

Log levels: `debug` for 2xx and successful validation, `warning` for non-fatal issues (unsupported schema version, schema mismatch), `error` for SOAP faults and transport failures.
