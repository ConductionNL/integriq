# Design: iBabs & NotuBiz Connector

## Architecture

The RIS connector follows the existing OpenConnector synchronization pattern:

### New Services
- **IBabsConnectorService** (`lib/Service/IBabsConnectorService.php`): Handles iBabs API interactions (document push, agendapunt creation, besluit retrieval)
- **NotuBizConnectorService** (`lib/Service/NotuBizConnectorService.php`): Handles NotuBiz API interactions
- **RISMappingService** (`lib/Service/RISMappingService.php`): Maps zaak fields to RIS metadata fields

### Integration Pattern
Uses the existing Source + CallService + SynchronizationService infrastructure:
- iBabs: Source type "json" with API key auth
- NotuBiz: Source type "json" with OAuth2 auth
- Outbound push: SynchronizationService with custom mapping
- Inbound poll: Cron job polling for besluiten at configurable intervals

### Data Flow
1. **Outbound (documents out):** Zaak -> extract voorstel -> PDF conversion via Docudesk -> upload to iBabs/NotuBiz -> create agendapunt
2. **Inbound (decisions back):** Poll RIS for besluiten -> map besluit status -> update zaak in Procest -> download besluitenlijst to Nextcloud Files

## Dependencies
- **Procest**: For zaak lifecycle management
- **Docudesk**: For DOCX to PDF conversion
- **iBabs REST API**: External service (api.ibabs.eu)
- **NotuBiz API**: External service with OAuth2

## Risks
- iBabs and NotuBiz APIs are proprietary with limited public documentation
- Procest app dependency not yet available
- Rate limiting on external APIs requires careful throttling
