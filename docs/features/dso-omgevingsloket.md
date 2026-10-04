# DSO / Omgevingsloket Adapter

## Overview

The DSO adapter integrates Integriq with the Digitaal Stelsel Omgevingswet (DSO) Landelijke Voorziening for receiving and processing vergunningaanvragen, meldingen, and informatieverzoeken from the Omgevingsloket. Required by Dutch VTH-related government tenders.

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
    {
      "imowId": "nl.imow-gm0000.activiteit.DemoBouwen",
      "activityId": "Demo-0000-Bouwen",
      "activityName": "Bouwen van een woning",
      "volgnr": 1
    }
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

You map DSO activities to case types in **Settings > Administration > Integriq > DSO activities**. Each row is a `dso_activity_mapping` object in OpenRegister. Only administrators can change it.

- A verzoek activity matches a row on its imow-id first, then on its activity id. An onderliggende activiteit is tried before its parent.
- One row can give several case types, each with the department that handles it.
- Samenloop: each row says deelzaken or gecombineerd. A samenloop rule on a row decides one specific pair.
- Integriq ships no activity codes. There is no public list of them: each gemeente, provincie or waterschap defines its own. A fresh install starts empty.
- Activities that no row maps show up under **Unmapped DSO activities**, with how often they arrived. Choose **Map** to add a row for one.
- The `code` and `omschrijving` fields of older pushes are read as the activity id and name.

The demo data holds three rows with gemeentecode 0000. That code does not exist, so they never match a real verzoek.

## Validation

The parser validates:
- Required fields (verzoekId, type, indieningsdatum, aanvrager, locatie, activiteiten)
- BSN 11-proef validation
- ISO 8601 date format
- Enum values for type field

## Bijlagen

You find every bijlage of a verzoek as a file on its `dso_verzoek` object in Nextcloud Files. Each file carries the tag `dso-bijlage` and the object's access rights.

The STAM endpoint answers 202 as soon as the verzoek is saved. A background job then downloads the bijlagen on the next cron run.

- The job downloads through the active DSO source, with its token or its PKIoverheid certificate.
- Each bijlage gets three attempts, with a short wait between them.
- A bijlage above the source's `maxFileSize` is not stored. The default is 100 MB.
- Only `https` URLs are downloaded.

The verzoek's `attachments` list shows each bijlage's status: `pending`, `stored`, `failed` or `too-large`. When a bijlage is `failed` or `too-large`, `attachmentMissing` is true. Handle that bijlage by hand.

Nothing is written outside Nextcloud Files.

## PKIoverheid Authentication

DSO-LV communication uses PKIoverheid certificates for mutual TLS. Certificates are configured via the Source entity's configuration field and managed through CallService's existing certificate handling.

## Implementation

- **DSOController**: `lib/Controller/DSOController.php` -- STAM endpoint
- **DSOParserService**: `lib/Service/DSOParserService.php` -- Payload parsing and validation
- **DsoAttachmentFetcher**: `lib/Service/Dso/DsoAttachmentFetcher.php`. Downloads bijlagen and stores them on the request
- **FetchDsoAttachmentsJob**: `lib/BackgroundJob/FetchDsoAttachmentsJob.php`. The queued job that runs the fetcher
- **Route**: `appinfo/routes.php` -- POST /api/dso/stam/verzoeken
- **Tests**: `tests/Unit/Service/DSOParserServiceTest.php`

## Status

Foundational implementation complete (endpoint, parser, validator). The following features require external dependencies and are planned for future implementation:

- Automatic zaak creation (requires Procest app)
- Status push back to DSO-LV
- DSO-SWF samenwerking
