# DSO / Omgevingsloket Adapter

## Overview

The DSO adapter integrates OpenConnector with the Digitaal Stelsel Omgevingswet (DSO) Landelijke Voorziening for receiving and processing vergunningaanvragen, meldingen, and informatieverzoeken from the Omgevingsloket. Required by Dutch VTH-related government tenders.

## Endpoints

### POST /api/dso/stam/verzoeken

Receives DSO-verzoek payloads from DSO-LV via the STAM koppelvlak.

**Authentication:** Public endpoint with webhook signature validation via `X-DSO-Signature` header.

**Request body:** JSON payload conforming to the STAM schema:

```json
{
  "verzoekId": "dso-12345",
  "bronorganisatie": "00000001234567890000",
  "type": "aanvraag",
  "indieningsdatum": "2024-06-15",
  "aanvrager": {
    "bsn": "999993653",
    "naam": "J. Jansen",
    "adres": { "straatnaam": "Hoofdstraat", "huisnummer": "10", "postcode": "1234AB", "woonplaats": "Utrecht" },
    "contactgegevens": { "email": "j.jansen@example.nl", "telefoon": "0612345678" }
  },
  "locatie": {
    "bagAdres": { "postcode": "1234AB", "huisnummer": "10" },
    "gmlGeometrie": "<gml:Point><gml:pos>52.370216 4.895168</gml:pos></gml:Point>"
  },
  "activiteiten": [
    { "code": "bouwen-01", "omschrijving": "Bouwen van een woning" }
  ],
  "bouwkosten": 250000,
  "bijlagen": [
    { "naam": "bouwtekening.pdf", "type": "tekening", "url": "https://dso-lv.nl/docs/abc123" }
  ]
}
```

**Response (202 Accepted):**

```json
{
  "verzoekId": "dso-12345",
  "status": "ontvangen",
  "message": "Verzoek ontvangen en wordt verwerkt"
}
```

**Error responses:**
- `401 Unauthorized` -- Invalid webhook signature
- `400 Bad Request` -- Payload validation errors with field-level details

## Verzoek Types

| Type | Description | Zaak created |
|------|-------------|--------------|
| `aanvraag` | Vergunningaanvraag | Full zaak with behandelproces |
| `melding` | Melding (notification) | Simplified zaak, no besluit required |
| `informatieverzoek` | Request for information | Lightweight zaak for advies |
| `vooroverleg` | Pre-application consultation | Lightweight zaak, no formal besluit |

## Activiteiten Mapping

DSO activiteiten (bouwen, milieu, kappen, etc.) are mapped to zaaktypen via a configurable mapping table stored in OpenRegister. The mapping supports:

- **One-to-one:** One activiteit maps to one zaaktype
- **One-to-many:** One activiteit generates multiple zaaktypen for different afdelingen
- **Samenloop:** Multiple activiteiten in one verzoek can create deelzaken or a combined zaak

## Validation

The parser validates:
- Required fields (verzoekId, type, indieningsdatum, aanvrager, locatie, activiteiten)
- BSN 11-proef validation
- ISO 8601 date format
- Enum values for type field

## PKIoverheid Authentication

DSO-LV communication uses PKIoverheid certificates for mutual TLS. Certificates are configured via the Source entity's configuration field and managed through CallService's existing certificate handling.

## Implementation

- **DSOController**: `lib/Controller/DSOController.php` -- STAM endpoint
- **DSOParserService**: `lib/Service/DSOParserService.php` -- Payload parsing and validation
- **Route**: `appinfo/routes.php` -- POST /api/dso/stam/verzoeken
- **Tests**: `tests/Unit/Service/DSOParserServiceTest.php`

## Status

Foundational implementation complete (endpoint, parser, validator). The following features require external dependencies and are planned for future implementation:

- Bijlagen download from DSO-LV (requires mTLS certificates)
- Automatic zaak creation (requires Procest app)
- Status push back to DSO-LV
- DSO-SWF samenwerking
- Activiteiten-mapping administration UI
