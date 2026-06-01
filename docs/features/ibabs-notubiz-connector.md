# iBabs & NotuBiz Connector

## Overview

The RIS connector provides bidirectional integration with iBabs and NotuBiz, the two dominant
raadsinformatiesystemen (RIS) used by Dutch municipalities for bestuurlijke besluitvorming (B&W/College).
It pushes collegevoorstellen and vergaderstukken from Procest to the RIS and retrieves besluiten and
besluitenlijsten back into the zaak.

## Workflow

```
Procest Zaak → Voorstel + Bijlagen → [PDF conversion via Docudesk] → iBabs/NotuBiz Upload → Agendapunt
                                                                                                  │
                                                                                     Vergaderbehandeling
                                                                                                  │
iBabs/NotuBiz → Besluit (aangenomen/verworpen/aangehouden/doorgeschoven) → Zaak Status Update
                                      │
                                Besluitenlijst PDF → /RIS-besluiten/{year}/{datum}/besluitenlijst.pdf
```

## Source Configuration

### iBabs

Create a Source entity with:

| Field | Value |
|-------|-------|
| **Type** | `json` |
| **Auth method** | `apikey` |
| **Location** | `https://api.ibabs.eu` |

**Configuration JSON:**
```json
{
  "organisatieId": "<your-organisation-id>",
  "defaultVergaderType": "college"
}
```

### NotuBiz

Create a Source entity with:

| Field | Value |
|-------|-------|
| **Type** | `json` |
| **Auth method** | `oauth` |
| **Location** | NotuBiz API URL |

**Configuration JSON:**
```json
{
  "organisatieId": "<your-organisation-id>",
  "clientId": "<oauth-client-id>",
  "clientSecret": "<oauth-client-secret>",
  "tokenEndpoint": "<oauth-token-url>",
  "defaultVergaderType": "raad"
}
```

## API Endpoints Used

### iBabs

| Operation | Method | Path |
|-----------|--------|------|
| Test connection | GET | `/api/v1/organisations/{id}/vergaderingen` |
| Push document | POST | `/api/v1/organisations/{id}/documents` |
| List vergaderingen | GET | `/api/v1/organisations/{id}/vergaderingen` |
| Create agendapunt | POST | `/api/v1/organisations/{id}/vergaderingen/{vergId}/agendapunten` |
| Poll besluiten | GET | `/api/v1/organisations/{id}/vergaderingen/{vergId}/besluiten` |
| Get besluitenlijst | GET | `/api/v1/organisations/{id}/vergaderingen/{vergId}/besluitenlijst` |

### NotuBiz

| Operation | Method | Path |
|-----------|--------|------|
| Test connection | GET | `/api/v1/organisations/{id}` |
| Push vergaderstuk | POST | `/api/v1/organisations/{id}/vergaderstukken` |

## Besluit Status Mapping

| iBabs/NotuBiz Status | Procest Zaak Status |
|----------------------|---------------------|
| `aangenomen` | `Besluit: aangenomen` |
| `verworpen` | `Besluit: verworpen` |
| `aangehouden` | `Besluit: aangehouden` |
| `doorgeschoven` | `Besluit: doorgeschoven` |
| (unknown) | `Besluit: onbekend` |

## Vergadertype Mapping (NotuBiz)

| Input | NotuBiz vergadertype |
|-------|----------------------|
| `college` / `collegevergadering` | `collegevergadering` |
| `raad` / `raadsvergadering` | `raadsvergadering` |
| `commissie` / `commissievergadering` | `commissievergadering` |
| (unknown) | `collegevergadering` |

## Besluitenlijst Storage

Downloaded besluitenlijsten are stored in the behandelaar's Nextcloud Files at:

```
/RIS-besluiten/{year}/{vergadering-datum}/besluitenlijst.pdf
```

Example: `/RIS-besluiten/2026/2026-06-15/besluitenlijst.pdf`

## Sync Record Schema

Sync operations are persisted in the `ris_sync_record` schema in the OpenConnector register.

| Field | Type | Description |
|-------|------|-------------|
| `zaakId` | string (UUID) | Source zaak in Procest |
| `risType` | `ibabs` \| `notubiz` | Which RIS was used |
| `risDocumentId` | string | Document ID in the RIS |
| `risVergaderingId` | string | Vergadering ID in the RIS |
| `direction` | `outbound` \| `inbound` | Push or pull |
| `status` | `pending` \| `synced` \| `failed` \| `conflict` | Sync state |
| `syncedAt` | datetime | Timestamp of last successful sync |
| `retryCount` | integer | Number of retries attempted |
| `nextRetryAt` | datetime | Scheduled time for next retry |
| `errorMessage` | string | Error details if status is `failed` |
| `besluitStatus` | `aangenomen` \| `verworpen` \| `aangehouden` \| `doorgeschoven` | Besluit result |
| `sourceId` | string | UUID of the Source entity used |

## Background Polling

The `RISPollJob` background job polls all sync records with status `synced` at a 15-minute interval
(configurable). It groups records by `risType` and dispatches to `IBabsConnectorService::pollBesluiten()`
or the equivalent NotuBiz handler.

## Implementation Files

| File | Purpose |
|------|---------|
| `lib/Service/IBabsConnectorService.php` | iBabs REST API integration |
| `lib/Service/NotuBizConnectorService.php` | NotuBiz REST API + OAuth2 integration |
| `lib/Cron/RISPollJob.php` | 15-minute background poll for besluiten |
| `tests/Unit/Service/IBabsConnectorServiceTest.php` | iBabs service unit tests |
| `tests/Unit/Service/NotuBizConnectorServiceTest.php` | NotuBiz service unit tests |
| `tests/Unit/Cron/RISPollJobTest.php` | Poll job unit tests |

## Spec References

- REQ-RIS-001: iBabs REST API Connection
- REQ-RIS-002: Collegevoorstel Push to iBabs
- REQ-RIS-003: Agendapunt Creation
- REQ-RIS-004: Besluit Retrieval from iBabs
- REQ-RIS-005: Besluitenlijst Retrieval
- REQ-RIS-020: NotuBiz API Connection
- REQ-RIS-021: Vergaderstuk Push to NotuBiz
