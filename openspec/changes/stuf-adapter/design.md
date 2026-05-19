# Design: StUF Adapter

## Architecture

The StUF adapter provides bidirectional translation between REST/ZGW APIs and legacy StUF-BG/StUF-ZKN SOAP interfaces.

### New Services
- **StUFBGService** (`lib/Service/StUFBGService.php`): Handles StUF-BG person and address queries (npsLv01/npsLa01, adrLv01/adrLa01)
- **StUFZKNService** (`lib/Service/StUFZKNService.php`): Handles StUF-ZKN zaak operations (zakLk01/zakLv01)
- **StUFXMLBuilder** (`lib/Service/StUFXMLBuilder.php`): Builds StUF-compliant XML responses with proper namespaces and stuurgegevens
- **StUFFieldMapper** (`lib/Service/StUFFieldMapper.php`): Maps StUF fields to/from OpenRegister object properties

### Integration with Existing Infrastructure
- **SOAPService**: Already exists for SOAP communication; StUF outbound queries leverage it
- **CallService**: Routes SOAP requests through existing logging and certificate handling
- **EndpointService**: StUF inbound endpoints registered as OpenConnector endpoints
- **AuthenticationService**: Extended with WS-Security UsernameToken support

### Inbound Flow (legacy apps query OpenConnector)
1. SOAP request arrives at StUF endpoint
2. EndpointService routes to StUFBGService/StUFZKNService
3. Service parses SOAP XML, extracts query parameters
4. OpenRegister is queried for matching objects
5. Results are mapped via StUFFieldMapper and built into StUF XML response

### Outbound Flow (OpenConnector queries legacy StUF sources)
1. Workflow triggers StUF query via SynchronizationService
2. SOAPService sends npsLv01/zakLv01 SOAP request
3. Response parsed by StUFBGService/StUFZKNService
4. Mapped data stored in OpenRegister

## Dependencies
- **SOAPService**: Existing SOAP client infrastructure
- **OpenRegister**: Object storage for person/address/zaak data
- **EndpointService**: Endpoint routing
- **php-soap extension**: Required for SOAP handling

## Risks
- StUF XML namespace handling is complex and version-dependent
- StUF-BG 3.10 vs StUF-ZKN 3.10e have subtle schema differences
- WS-Security PasswordDigest implementation requires careful crypto handling
