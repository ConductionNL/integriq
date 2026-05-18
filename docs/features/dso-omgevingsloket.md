# DSO / Omgevingsloket Adapter

## Overview

The DSO adapter integrates OpenConnector with the Digitaal Stelsel Omgevingswet (DSO) Landelijke Voorziening for receiving and processing vergunningaanvragen, meldingen, and informatieverzoeken from the Omgevingsloket. Required by Dutch VTH-related government tenders (32% of municipal IT tenders).

The adapter receives DSO-verzoeken via the STAM koppelvlak, validates them, parses them into structured zaak data, maps activiteiten to zaaktypen, handles samenloop (multiple activiteiten per verzoek), downloads bijlagen, and supports coordinating with ketenpartners via DSO-SWF. Status updates are pushed back to DSO-LV so the aanvrager can track progress in the Omgevingsloket.

## Endpoints

### POST /api/dso/stam/verzoeken

Receives DSO-verzoek payloads from DSO-LV via the STAM koppelvlak.

**Authentication:** Public endpoint with optional webhook signature validation via `X-DSO-Signature` header. Full PKIoverheid certificate-chain validation is applied when the source is configured with certificates.

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
- `401 Unauthorized` — Invalid webhook signature
- `400 Bad Request` — Payload validation errors with field-level details, e.g. `{"error":"validation_failed","errors":[{"field":"activiteiten","error":"required_field_missing","message":"Activiteiten is verplicht"}]}`

## Verzoek Types

| Type | Description | Zaak created |
|------|-------------|--------------|
| `aanvraag` | Vergunningaanvraag | Full zaak with behandelproces |
| `melding` | Melding (notification) | Simplified zaak with type 'melding', no besluit required |
| `informatieverzoek` | Request for information | Lightweight zaak for advies |
| `vooroverleg` | Pre-application consultation | Lightweight zaak, no formal besluit |

## Activiteiten Mapping

DSO activiteiten (bouwen, milieu, kappen, etc.) are mapped to zaaktypen via a configurable mapping table. The mapping supports:

- **One-to-one:** One activiteit maps to one zaaktype
- **One-to-many:** One activiteit generates multiple zaaktypen for different afdelingen
- **Samenloop (deelzaken):** Multiple activiteiten create one hoofdzaak with one deelzaak per activiteit
- **Samenloop (gecombineerd):** Multiple activiteiten create one combined zaak

### Loading Default Mappings

The adapter includes 25+ default mappings. Use `DSOAdapterService::getDefaultMappings()` to retrieve them:

| DSO Activiteitcode | Zaaktype | Strategie |
|---|---|---|
| `bouwen-01` | ZAAKTYPE-BOUWEN-2024 | deelzaken |
| `kappen-01` | ZAAKTYPE-KAPPEN-2024 | gecombineerd |
| `uitrit-01` | ZAAKTYPE-UITRIT-2024 | gecombineerd |
| `milieu-01` | ZAAKTYPE-MILIEU-2024 | deelzaken |
| `slopen-01` | ZAAKTYPE-SLOPEN-2024 | gecombineerd |
| ... (20+ more) | | |

Unmapped activiteiten automatically create a triage zaak (`onbekend-dso-activiteit`) with a notification to the configured DSO-triage user.

## Bijlagen Download

Bijlagen are downloaded to `/DSO-verzoeken/{year}/{verzoekId}/bijlagen/` with retry logic (up to 3 attempts with exponential backoff). mTLS is used when a PKIoverheid certificate is configured.

## Status Push to DSO-LV

Zaak status changes trigger `DSOStatusService::pushStatusToDSO()` which:
1. Maps the zaak status to the DSO-LV status code
2. POSTs the update to DSO-LV with the stored verzoekId
3. Retries up to 3 times on failure (2s, 4s, 8s delays)

| Zaak status | DSO-LV status |
|---|---|
| `ontvangen` | `ontvangen` |
| `in_behandeling` | `in behandeling` |
| `besluit_genomen` | `besluit genomen` |
| `afgerond` | `afgerond` |
| `buiten_behandeling` | `buiten behandeling` |

## DSO-SWF Samenwerking

Coordination with ketenpartners (provincies, waterschappen, omgevingsdiensten):

- `DSOSamenwerkingService::sendAdviesverzoek()` — Send adviesverzoek to a partner OIN via DSO-SWF
- `DSOSamenwerkingService::receiveAdvies()` — Store incoming advies linked to a zaak

## PKIoverheid Certificate Authentication

Certificates are configured in the Source entity's `configuration` JSON field. Use `DSOAdapterService::validateCertificate()` to check expiry status. A warning is raised when a certificate expires within 30 days.

Use `DSOAdapterService::testDSOConnection()` to probe connectivity to the DSO-LV API.

## Configuration

Add a Source of type `dso` with the following configuration fields:

```json
{
  "dsoApiUrl": "https://api.dso-lv.nl",
  "organisatieOin": "00000001234567890000",
  "bevoegdGezagCode": "0344",
  "stamApiVersion": "v1",
  "pkiCertPath": "/var/www/nextcloud/data/certs/dso-cert.pem",
  "pkiKeyPath": "/var/www/nextcloud/data/certs/dso-key.pem"
}
```

## Validation

The parser validates:
- Required fields: `verzoekId`, `type`, `indieningsdatum`, `aanvrager`, `locatie`, `activiteiten`
- BSN 11-proef validation (Burger Service Nummer check digit)
- ISO 8601 date format for `indieningsdatum`
- Enum values for `type` field: `aanvraag | melding | informatieverzoek | vooroverleg`
- Activiteiten must be an array

## Implementation

| File | Role |
|---|---|
| `lib/Controller/DSOController.php` | STAM endpoint, signature validation, HTTP 202 response |
| `lib/Service/DSOParserService.php` | Payload parsing, BSN/date validation, GML→GeoJSON |
| `lib/Service/DSOAdapterService.php` | Verzoek routing, bijlagen download, mapping, samenloop, zaak creation |
| `lib/Service/DSOStatusService.php` | Status push to DSO-LV with retry logic |
| `lib/Service/DSOSamenwerkingService.php` | DSO-SWF adviesverzoek send/receive |
| `appinfo/routes.php` | Route: POST /api/dso/stam/verzoeken |
| `tests/Unit/Service/DSOParserServiceTest.php` | Parser and validation tests |
| `tests/Unit/Service/DSOAdapterServiceTest.php` | Adapter: mapping, samenloop, zaak creation |
| `tests/Unit/Service/DSOStatusServiceTest.php` | Status mapping and payload tests |
| `tests/Unit/Service/DSOSamenwerkingServiceTest.php` | Advies send/receive tests |
| `tests/Unit/Controller/DSOControllerTest.php` | Controller endpoint tests |
