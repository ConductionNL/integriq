# iBabs & NotuBiz Connector

## Overview

The RIS connector provides bidirectional integration with iBabs and NotuBiz, the two dominant raadsinformatiesystemen (RIS) used by Dutch municipalities for bestuurlijke besluitvorming. It pushes collegevoorstellen to the RIS and retrieves besluiten back into the zaak.

## Workflow

```
Procest Zaak -> Voorstel + Bijlagen -> [PDF conversion] -> iBabs/NotuBiz Upload -> Agendapunt
                                                                                      |
                                                                              Vergaderbehandeling
                                                                                      |
iBabs/NotuBiz -> Besluit (aangenomen/verworpen/aangehouden) -> Zaak Status Update
```

## Source Configuration

### iBabs

Create a Source entity with:
- **Type:** `json`
- **Auth method:** `apikey`
- **Location:** `https://api.ibabs.eu`
- **Configuration:**
  ```json
  {
    "organisatieId": "<your-organisation-id>",
    "defaultVergaderType": "college"
  }
  ```

### NotuBiz

Create a Source entity with:
- **Type:** `json`
- **Auth method:** `oauth`
- **Location:** NotuBiz API URL
- **Configuration:**
  ```json
  {
    "organisatieId": "<your-organisation-id>",
    "clientId": "<oauth-client-id>",
    "clientSecret": "<oauth-client-secret>",
    "tokenEndpoint": "<oauth-token-url>"
  }
  ```

## Besluit Status Mapping

| iBabs/NotuBiz Status | Procest Zaak Status |
|----------------------|---------------------|
| aangenomen | Besluit: aangenomen |
| verworpen | Besluit: verworpen |
| aangehouden | Besluit: aangehouden |
| doorgeschoven | Besluit: doorgeschoven |

## Besluitenlijst Storage

Downloaded besluitenlijsten are stored in Nextcloud Files at:
```
/RIS-besluiten/{year}/{vergadering-datum}/besluitenlijst.pdf
```

## Implementation

- **IBabsConnectorService**: `lib/Service/IBabsConnectorService.php`
- **Tests**: `tests/Unit/Service/IBabsConnectorServiceTest.php`

## Status

Foundational implementation complete (service structure, status mapping, connection testing). The following features require external API access and Procest app:

- Document push (requires Procest zaak data + Docudesk PDF conversion)
- Agendapunt creation (requires iBabs API access)
- Besluit polling (requires iBabs API access)
- NotuBiz connector (requires NotuBiz API access + OAuth2)
