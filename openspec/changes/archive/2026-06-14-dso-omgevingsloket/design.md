# Design: DSO / Omgevingsloket Adapter

## Architecture

The DSO adapter follows the existing OpenConnector adapter pattern with several new components:

### New Services
- **DSOAdapterService** (`lib/Service/DSOAdapterService.php`): Main orchestrator handling verzoek intake, parsing, and zaak creation coordination
- **DSOParserService** (`lib/Service/DSOParserService.php`): Parses DSO-verzoek XML/JSON payloads into structured data
- **DSOStatusService** (`lib/Service/DSOStatusService.php`): Pushes zaak status updates back to DSO-LV via STAM API
- **DSOSamenwerkingService** (`lib/Service/DSOSamenwerkingService.php`): Handles DSO-SWF adviesverzoeken and adviezen

### New Controller
- **DSOController** (`lib/Controller/DSOController.php`): Exposes the STAM koppelvlak inbound endpoint at `/api/dso/stam/verzoeken`

### Data Model
- DSO-Verzoek schema stored in OpenRegister (verzoekId, type, aanvrager, locatie, activiteiten, bijlagen)
- Activiteiten-Mapping schema stored in OpenRegister (dsoActiviteitCode to zaaktypeIdentificatie)

### Integration Flow
1. DSO-LV pushes verzoek to STAM endpoint
2. DSOController validates signature and schema
3. DSOAdapterService enqueues via JobService
4. Job processes: parse payload, download bijlagen, map activiteiten, create zaak(en) in Procest
5. Status changes in Procest trigger DSOStatusService to push updates back

## Dependencies
- **Procest**: Required for zaak creation (not yet available as app)
- **Docudesk**: Required for PDF generation of beschikkingen
- **PKIoverheid certificates**: Required for mTLS authentication
- **DSO-LV test environment access**: Required for integration testing

## Risks
- Procest app dependency not yet available -- zaak creation will use OpenRegister directly until Procest is ready
- DSO-LV STAM API access requires PKIoverheid certificates and OIN registration
- GML to GeoJSON conversion requires a geometry library (not yet in OpenConnector)
