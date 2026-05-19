---
status: proposed
---

# StUF Adapter

## Purpose

Provides bidirectional translation between modern REST/ZGW APIs and legacy StUF-BG (personen/adressen) and StUF-ZKN (zaken/documenten) SOAP-based interfaces. 79% of Dutch government tenders still require StUF support despite the migration to ZGW APIs. The adapter enables OpenRegister objects to be exposed as StUF services (for legacy consumers) and allows OpenConnector to query legacy StUF sources (for data import). Supports StUF-BG 3.10 and StUF-ZKN 3.10/3.10e.

## Requirements

### REQ-STUF-001: StUF-BG Inbound Person Query (npsLv01/npsLa01)

The adapter MUST expose a SOAP endpoint that accepts StUF-BG 3.10 `npsLv01` (persoon opvragen) requests and returns `npsLa01` (persoon antwoord) responses with correctly formed StUF-BG XML. The endpoint is registered as an OpenConnector Endpoint entity of type "source" with targetType pointing to a SOAP handler. Incoming SOAP XML is parsed by a raw POST handler that extracts the SOAP action and delegates to the appropriate StUF message handler.

**Scenarios:**

1. **GIVEN** the StUF-BG endpoint is registered in OpenConnector **AND** a legacy application sends a `npsLv01` SOAP request with BSN `999993653` **WHEN** the adapter receives the request **THEN** it extracts the BSN from the StUF-BG XML, queries OpenRegister for the matching person object (using the BRP schema), and returns a `npsLa01` SOAP response with the person's geslachtsnaam, voorvoegsel, voornamen, geboortedatum, and verblijfsadres.

2. **GIVEN** a `npsLv01` request queries by geslachtsnaam "Moulin" (partial match) **WHEN** the adapter searches OpenRegister **THEN** it returns all matching persons in a multi-record `npsLa01` response, respecting the `maximumAantal` parameter if specified.

3. **GIVEN** a `npsLv01` request includes a `scope` element requesting only BSN and naam fields **WHEN** the adapter builds the response **THEN** only the requested fields are included in the `npsLa01` response, with unrequested fields omitted (not set to `geenWaarde`).

4. **GIVEN** a `npsLv01` request with a BSN that does not exist in OpenRegister **WHEN** the adapter searches **THEN** it returns an empty `npsLa01` response (zero records) with correct stuurgegevens but no error.

5. **GIVEN** a `npsLv01` request has malformed XML or missing required StUF elements **WHEN** the adapter validates the request **THEN** it returns a StUF `Fo01` fault message with diagnostic information including the specific validation error.

### REQ-STUF-002: StUF-BG Field Mapping

The adapter MUST map StUF-BG person fields to OpenRegister object properties using configurable mapping objects. The default mapping covers the core BRP fields: `bsn` -> `burgerservicenummer`, `geslachtsnaam`, `voorvoegsel`, `voornamen`, `geboortedatum`, `verblijfsadres` (with sub-fields straatnaam, huisnummer, postcode, woonplaats). Mappings are stored as OpenRegister objects in a dedicated "stuf-mappings" schema.

**Scenarios:**

1. **GIVEN** the default BRP field mapping is loaded **AND** an OpenRegister person object has `{"burgerservicenummer": "999993653", "geslachtsnaam": "Moulin", "voornamen": "Suzanne"}` **WHEN** the adapter builds a `npsLa01` response **THEN** the XML contains `<inp.bsn>999993653</inp.bsn>`, `<geslachtsnaam>Moulin</geslachtsnaam>`, and `<voornamen>Suzanne</voornamen>` in the correct StUF-BG namespace.

2. **GIVEN** a municipality uses a custom field name "achternaam" instead of "geslachtsnaam" in their OpenRegister schema **WHEN** they update the StUF-BG mapping to map `geslachtsnaam` -> `achternaam` **THEN** the adapter uses the custom mapping for all subsequent `npsLa01` responses.

3. **GIVEN** an OpenRegister person has a geboortedatum stored in ISO 8601 format ("1990-05-15") **WHEN** the adapter maps to StUF-BG **THEN** the date is transformed to StUF format `YYYYMMDD` ("19900515") using the date transformation rule.

4. **GIVEN** a person's verblijfsadres is stored as a nested object in OpenRegister **WHEN** the adapter maps to StUF-BG **THEN** the nested fields are correctly placed into the StUF-BG `verblijfsadres` element hierarchy.

### REQ-STUF-004: StUF-BG Address Query (adrLv01/adrLa01)

The adapter MUST expose `adrLv01` (adres opvragen) and `adrLa01` (adres antwoord) for BAG-adressen. Address queries search the BAG register in OpenRegister and return nummeraanduiding data in StUF-BG format.

**Scenarios:**

1. **GIVEN** a legacy application queries an address by postcode "1234AB" and huisnummer "10" **WHEN** the adapter receives the `adrLv01` request **THEN** it queries the BAG schema in OpenRegister and returns an `adrLa01` response with the matching nummeraanduiding(en).

2. **GIVEN** the BAG register contains 3 addresses matching postcode "1234AB" **WHEN** the query does not specify huisnummer **THEN** all 3 addresses are returned in the `adrLa01` response.

3. **GIVEN** the BAG register has no matching address **WHEN** the query is processed **THEN** an empty `adrLa01` response is returned.

### REQ-STUF-010: StUF-BG Outbound Query (OpenConnector Queries Legacy Source)

The adapter MUST support querying external StUF-BG services via SOAP and mapping the responses to OpenRegister objects. Outbound queries use the existing SOAPService (`lib/Service/SOAPService.php`) to send `npsLv01` SOAP requests and parse `npsLa01` responses into JSON objects. The parsed data is stored in OpenRegister via the SynchronizationService.

**Scenarios:**

1. **GIVEN** a StUF-BG source is configured in OpenConnector with WSDL URL and endpoint **WHEN** a workflow requests person data by BSN **THEN** CallService routes the request to SOAPService, which sends a `npsLv01` SOAP request, parses the `npsLa01` XML response, and returns a JSON object with mapped person fields.

2. **GIVEN** the external StUF-BG service returns a `Fo01` fault message **WHEN** the adapter processes the response **THEN** the SOAP fault is mapped to a CallLog entry with HTTP-equivalent status (e.g., Fo01 "not found" -> 404, Fo01 "unauthorized" -> 401) and descriptive error details.

3. **GIVEN** the external StUF-BG service returns multiple person records **WHEN** the adapter processes the response **THEN** each person is extracted as a separate JSON object and can be stored as individual OpenRegister objects via SynchronizationService.

4. **GIVEN** a StUF-BG synchronization is configured to pull person data nightly **WHEN** the sync job runs **THEN** SynchronizationService queries the external StUF source, maps responses to OpenRegister objects using the configured field mapping, and creates/updates records with change detection.

### REQ-STUF-011: PKIoverheid mTLS Authentication

The adapter MUST support certificate-based mutual TLS authentication for StUF endpoints. This leverages the existing CallService certificate handling: `getCertificate()` writes client certificates and SSL keys to temporary files, the SOAP/HTTP request uses them for mTLS, and `removeFiles()` cleans up after the request.

**Scenarios:**

1. **GIVEN** a StUF source is configured with a PKIoverheid client certificate and private key **WHEN** the adapter makes a SOAP request **THEN** CallService writes the certificate to a temporary file, passes it to the Guzzle/SOAPService client for mTLS, and removes the file after the response.

2. **GIVEN** the PKIoverheid certificate is stored as a PEM string in the Source configuration **AND** the PEM contains escaped newlines (`\n`) **WHEN** CallService writes the certificate **THEN** escaped newlines are converted to actual newlines (existing `writeFile()` behavior) ensuring the certificate is valid.

3. **GIVEN** the certificate has expired **WHEN** the adapter attempts a connection **THEN** the mTLS handshake fails, a descriptive error is logged in CallLog, and the Source status is updated to indicate certificate expiry.

### REQ-STUF-012: WS-Security UsernameToken Authentication

The adapter MUST support WS-Security UsernameToken authentication for StUF endpoints. This adds a SOAP header with username and password (optionally with nonce and timestamp) to outbound SOAP requests. The authentication method is configured as a new auth type in AuthenticationService.

**Scenarios:**

1. **GIVEN** a StUF source is configured with WS-Security authentication (username + password) **WHEN** the adapter sends a SOAP request **THEN** the SOAP envelope includes a `wsse:Security` header with `wsse:UsernameToken`, `wsse:Username`, and `wsse:Password` elements.

2. **GIVEN** WS-Security with PasswordDigest is configured **WHEN** the adapter builds the security header **THEN** the password is hashed as `Base64(SHA1(Nonce + Created + Password))` per the WS-Security UsernameToken 1.0 profile.

3. **GIVEN** WS-Security with PasswordText is configured **WHEN** the adapter builds the security header **THEN** the password is included as plaintext in the UsernameToken (suitable only over TLS).

### REQ-STUF-020: StUF-ZKN Inbound Zaak Management (zakLk01/zakLv01)

The adapter MUST expose SOAP endpoints for StUF-ZKN 3.10 zaak operations: `zakLk01` (zaak aanmaken/bijwerken) for creating or updating zaken, and `zakLv01`/`zakLa01` (zaak opvragen) for retrieving zaak data including related documenten and statussen. The adapter maps StUF-ZKN zaak fields to Procest zaak objects in OpenRegister.

**Scenarios:**

1. **GIVEN** a legacy formulierensysteem sends a StUF-ZKN `zakLk01` message to create a new zaak **WHEN** the adapter receives the SOAP request **THEN** it maps the StUF fields (zaakidentificatie, omschrijving, startdatum, zaaktype, status) to OpenRegister properties, creates the zaak object in Procest's register, and returns a `Bv03` bevestiging message with the zaakidentificatie.

2. **GIVEN** a legacy system sends a `zakLk01` with an existing zaakidentificatie **WHEN** the adapter receives the update message **THEN** it finds the existing zaak in OpenRegister, updates the modified fields, and returns a `Bv03` bevestiging.

3. **GIVEN** a legacy application sends a `zakLv01` request for zaak "ZAAK-2024-001" **WHEN** the adapter processes the query **THEN** it retrieves the zaak from OpenRegister with its statussen and linked documenten, and returns a `zakLa01` response with the complete zaak data in StUF-ZKN format.

4. **GIVEN** the `zakLk01` message contains invalid data (e.g., missing required zaaktype) **WHEN** validation fails **THEN** the adapter returns a `Fo03` foutmelding with the specific validation error.

5. **GIVEN** a `zakLv01` request queries by zaaktype and date range **WHEN** the adapter processes the query **THEN** it filters OpenRegister objects by the zaaktype and startdatum range and returns matching zaken in the `zakLa01` response.

### REQ-STUF-022: StUF-ZKN Document Linking (edcLk01)

The adapter MUST support `edcLk01` (document koppelen aan zaak) messages for document management via StUF-ZKN. This builds on the existing edcLk01 handling in SOAPService which already detects `body['edcLk01']['object']['inhoud']` and base64-decodes document content.

**Scenarios:**

1. **GIVEN** a legacy DMS sends an `edcLk01` message with a base64-encoded PDF document **WHEN** the adapter processes the message **THEN** the document content is base64-decoded (using the existing SOAPService logic at lines 224-232), stored in Nextcloud Files, and linked to the referenced zaak.

2. **GIVEN** an `edcLk01` message contains document metadata (titel, auteur, creatiedatum, vertrouwelijkheidaanduiding) **WHEN** the adapter processes the message **THEN** the metadata is stored alongside the document in Nextcloud Files and linked as zaak-document properties.

3. **GIVEN** an `edcLk01` references a zaak that does not exist **WHEN** the adapter validates the reference **THEN** it returns a `Fo03` fault message indicating the zaak was not found.

### REQ-STUF-030: StUF-ZKN Outbound Zaak Query

The adapter MUST support querying external StUF-ZKN services for zaak data and mapping responses to OpenRegister objects. This enables data import from legacy zaaksystemen during migration. The adapter sends `zakLv01` SOAP requests and parses `zakLa01` responses.

**Scenarios:**

1. **GIVEN** a legacy zaaksysteem is configured as a StUF-ZKN source **WHEN** a migration workflow queries for all zaken of type "Omgevingsvergunning" **THEN** the adapter sends a `zakLv01` with zaaktype filter, parses the `zakLa01` response, and maps each zaak to an OpenRegister object.

2. **GIVEN** the legacy system supports `genereerZaakIdentificatie` **WHEN** a workflow needs to create a zaak in the legacy system **THEN** the adapter first requests a zaak ID via `genereerZaakIdentificatie` and uses it in the subsequent `zakLk01`.

3. **GIVEN** the `zakLa01` response includes linked document references **WHEN** the adapter processes the zaak data **THEN** document references are stored as zaak-eigenschappen with their StUF document IDs, enabling subsequent `edcLv01` retrieval.

### REQ-STUF-040: WSDL and XSD Bundling

The adapter MUST bundle WSDL files for StUF-BG 3.10 and StUF-ZKN 3.10 with the app. The WSDL files are used both for outbound SOAP client setup (SOAPService engine configuration) and for inbound request validation. The XSD schemas are used for XML validation of outbound messages.

**Scenarios:**

1. **GIVEN** the adapter is installed **WHEN** a developer inspects the app directory **THEN** WSDL files are present at `lib/StUF/wsdl/stuf-bg-3.10.wsdl` and `lib/StUF/wsdl/stuf-zkn-3.10.wsdl` along with their XSD dependencies.

2. **GIVEN** a StUF-BG source is configured **WHEN** SOAPService.setupEngine() initializes the SOAP client **THEN** the bundled WSDL is used if no external WSDL URL is specified in the Source configuration.

3. **GIVEN** a municipality uses StUF-ZKN 3.10e (extended version) **WHEN** they configure the source **THEN** they can specify the extended WSDL URL in the Source configuration, overriding the bundled 3.10 version.

### REQ-STUF-041: XML Namespace Handling

The adapter MUST correctly handle XML namespaces for `StUF`, `BG`, `ZKN`, `xsi`, and `gml` in all generated SOAP messages. Namespace prefixes and URIs must match the StUF-BG and StUF-ZKN schema definitions exactly, as legacy systems are strict about namespace validation.

**Scenarios:**

1. **GIVEN** the adapter generates a `npsLa01` response **WHEN** the XML is built **THEN** it includes the correct namespace declarations: `xmlns:StUF="http://www.egem.nl/StUF/StUF0301"`, `xmlns:BG="http://www.egem.nl/StUF/sector/bg/0310"`, etc.

2. **GIVEN** a legacy consumer validates responses against the StUF XSD **WHEN** the adapter sends a response **THEN** the XML validates against the schema with zero namespace errors.

3. **GIVEN** the adapter processes an incoming message with a non-standard namespace prefix **WHEN** it parses the XML **THEN** it resolves elements by namespace URI (not prefix) ensuring interoperability with different StUF implementations.

### REQ-STUF-042: Stuurgegevens Population

The adapter MUST correctly populate StUF `stuurgegevens` on all outbound messages. Stuurgegevens include: `zender` (with organisatie code and applicatie naam), `ontvanger` (from the configured target), `referentienummer` (unique message ID), `tijdstipBericht` (timestamp), and `crossRefnummer` (referencing the inbound message for responses).

**Scenarios:**

1. **GIVEN** the adapter sends a `npsLa01` response to an inbound `npsLv01` request **WHEN** stuurgegevens are populated **THEN** `zender` contains the adapter's OIN and application name, `ontvanger` contains the requesting system's code (from the request's zender), `referentienummer` is a unique UUID, `tijdstipBericht` is the current datetime in StUF format, and `crossRefnummer` is the request's referentienummer.

2. **GIVEN** the adapter sends an outbound `npsLv01` query to a BRP system **WHEN** stuurgegevens are populated **THEN** `zender` contains the municipality's OIN (from Source configuration) and `ontvanger` contains the BRP system's code.

3. **GIVEN** the configured zender code is missing in the Source settings **WHEN** the adapter attempts to send a message **THEN** it returns an error indicating that StUF stuurgegevens configuration is incomplete, preventing malformed messages.

### REQ-STUF-043: noValue Attribute Handling

The adapter MUST handle StUF `noValue` attribute semantics correctly. The StUF standard defines four noValue indicators: `geenWaarde` (empty by design), `nietOndersteund` (field not supported), `waardeOnbekend` (value unknown), and `vastgesteldOnbekend` (officially determined as unknown). These are represented as `StUF:noValue` attributes on XML elements.

**Scenarios:**

1. **GIVEN** an OpenRegister person object has an explicit null value for `voorvoegsel` (not applicable for this person) **WHEN** the adapter generates StUF-BG XML **THEN** the `voorvoegsel` element includes `StUF:noValue="geenWaarde"` with empty content.

2. **GIVEN** the adapter receives a StUF-BG response with `geboortedatum StUF:noValue="waardeOnbekend"` **WHEN** it maps to an OpenRegister object **THEN** the field is stored as null with a metadata annotation indicating "waardeOnbekend".

3. **GIVEN** the adapter receives a StUF field with `noValue="nietOndersteund"` **WHEN** mapping to OpenRegister **THEN** the field is omitted from the stored object (not supported by the source system).

4. **GIVEN** the scope element in a `npsLv01` request does not include a specific field **WHEN** building the response **THEN** that field is excluded entirely from the XML (different from `noValue` which explicitly communicates absence).

### REQ-STUF-050: Configurable Field Mapping

The adapter MUST provide configurable field mappings between StUF XML paths and OpenRegister object properties, stored as mapping objects in OpenRegister. Default mappings for BRP-personen (StUF-BG) and ZGW-zaken (StUF-ZKN) are pre-seeded. Custom mappings can be added for municipality-specific StUF extensions.

**Scenarios:**

1. **GIVEN** the default StUF-BG person mapping is loaded **WHEN** a developer inspects the mapping in OpenRegister **THEN** it contains entries like `{"stufPath": "inp.bsn", "registerProperty": "burgerservicenummer", "direction": "bidirectional", "transformation": null}` for each mapped field.

2. **GIVEN** a municipality has a custom StUF extension adding a "klantbeeld-id" field **WHEN** the admin adds a custom mapping entry **THEN** the adapter includes this field in both inbound and outbound StUF messages.

3. **GIVEN** the mapping includes a date transformation (StUF `YYYYMMDD` to ISO 8601) **WHEN** the adapter maps a geboortedatum field **THEN** the value is transformed in both directions: "19900515" (StUF) <-> "1990-05-15" (OpenRegister).

4. **GIVEN** a mapping entry has direction "inbound-only" **WHEN** the adapter processes an inbound StUF message **THEN** the field is mapped from StUF to OpenRegister but is NOT included when generating outbound StUF responses.

5. **GIVEN** the default ZGW-zaak mapping is loaded **WHEN** a `zakLk01` message arrives **THEN** StUF-ZKN fields (zaakidentificatie, omschrijving, startdatum, einddatum, zaaktype, status) are mapped to their OpenRegister equivalents using the pre-seeded mapping.

### REQ-STUF-053: Value Transformations

The adapter MUST support value transformations in field mappings: date format conversion (StUF `YYYYMMDD` to ISO 8601), code list lookups (e.g., geslachtsaanduiding "M"/"V"/"O" to full text), and string concatenation (combining voorvoegsel + geslachtsnaam).

**Scenarios:**

1. **GIVEN** a date transformation is configured for geboortedatum **WHEN** the adapter maps StUF "19900515" to OpenRegister **THEN** the stored value is "1990-05-15T00:00:00Z" in ISO 8601 format.

2. **GIVEN** a code list transformation maps geslachtsaanduiding **WHEN** StUF sends "V" **THEN** the OpenRegister object stores "vrouw", and vice versa for outbound messages.

3. **GIVEN** a concatenation transformation combines voorvoegsel and geslachtsnaam **WHEN** the adapter maps to a "volledige_naam" field **THEN** it produces "van Moulin" from voorvoegsel "van" and geslachtsnaam "Moulin", handling missing voorvoegsel gracefully.

### REQ-STUF-060: OpenConnector Source Registration

The adapter MUST be registered as an OpenConnector source type, configurable via the connector UI. Connection settings include endpoint URL, authentication method (mTLS or WS-Security), certificates, and zender/ontvanger codes. The source supports health checks validating connectivity and authentication against the StUF endpoint.

**Scenarios:**

1. **GIVEN** an administrator creates a new StUF source **WHEN** they select type "soap" and configure WSDL URL, mTLS certificate, and stuurgegevens codes **THEN** the Source entity is created with StUF-specific configuration in the `configuration` JSON field.

2. **GIVEN** a StUF source is configured **WHEN** the administrator tests connectivity **THEN** the adapter sends a minimal SOAP request (e.g., a ping or capability query) and reports success/failure with diagnostic details.

3. **GIVEN** a StUF source is configured with WS-Security **WHEN** an n8n workflow queries the source **THEN** the WS-Security headers are automatically added to the SOAP envelope by AuthenticationService.

4. **GIVEN** a StUF source health check detects an SSL handshake failure **WHEN** the health check result is displayed **THEN** it includes the specific SSL error (e.g., certificate expired, CN mismatch) to help diagnose the issue.

## Data Model

### StUF Field Mapping (stored in OpenRegister)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| stufStandard | string (enum) | Yes | `StUF-BG` or `StUF-ZKN` |
| stufVersion | string | Yes | e.g., "3.10", "3.10e" |
| stufPath | string | Yes | XPath-like path in StUF XML (e.g., `inp.bsn`, `zakLa01.object.omschrijving`) |
| registerSchema | string | Yes | Target OpenRegister schema slug |
| registerProperty | string | Yes | Target property name in OpenRegister |
| direction | string (enum) | Yes | `bidirectional`, `inbound-only`, `outbound-only` |
| transformation | string (enum) | No | `date-stuf-to-iso`, `date-iso-to-stuf`, `code-list`, `concatenation`, `custom` |
| transformationConfig | object | No | Configuration for the transformation (e.g., code list values, concatenation template) |
| isActive | boolean | Yes | Whether this mapping is currently active |

### Stuurgegevens Configuration (stored in Source.configuration)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| zenderOrganisatie | string | Yes | OIN of the sending organization |
| zenderApplicatie | string | Yes | Name of the sending application |
| ontvangerOrganisatie | string | Yes | OIN of the target organization |
| ontvangerApplicatie | string | No | Name of the target application |
| stufVersion | string | Yes | StUF version ("0301" for StUF 3.01, "0310" for StUF 3.10) |

## Dependencies

- **OpenConnector**: Source/endpoint registration and connection management (Source entity, CallService, SOAPService, EndpointService, AuthenticationService)
- **OpenRegister**: Object storage, field mapping configuration, and BRP/BAG/zaak register schemas
- **PHP SOAP extension**: SOAP client/server functionality (ext-soap)
- **PKIoverheid root certificates**: For mTLS validation
- **StUF-BG 3.10 and StUF-ZKN 3.10 XSD schemas**: For XML validation

### Using Mock Register Data

The **BRP** and **BAG** mock registers provide test data for StUF-BG person/address queries without requiring external government endpoints.

**Loading the registers:**
```bash
# Load BRP register (35 persons, register slug: "brp", schema: "ingeschreven-persoon")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json

# Load BAG register (32 addresses, register slug: "bag", schema: "nummeraanduiding")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/bag_register.json
```

**Test data for this spec's use cases:**
- **StUF-BG npsLv01/npsLa01**: BSN `999993653` (Suzanne Moulin) -- test person query and response mapping
- **StUF-BG adrLv01/adrLa01**: Use BAG `nummeraanduiding` records -- test address query and response mapping
- **Field mapping validation**: BRP records include all fields from the StUF-BG mapping table (bsn, geslachtsnaam, voorvoegsel, voornamen, geboortedatum, verblijfsadres)

## Current Implementation Status

### Implemented (partial)
- **SOAP engine** (`lib/Service/SOAPService.php`): A working generic SOAP client that supports WSDL-driven requests, SOAP 1.1/1.2, cookie jar management, and XML response parsing. This is the outbound foundation.
- **edcLk01 handling** (`lib/Service/SOAPService.php`, lines 224-232): There is specific StUF-ZKN code -- the SOAPService already handles `edcLk01` document messages by detecting `body['edcLk01']['object']['inhoud']` and base64-decoding the document content. This directly relates to REQ-STUF-022.
- **Source type `soap`** (`src/entities/source/source.types.ts`): Sources can be configured as type `soap` with WSDL URL, SOAP version, and authentication. StUF endpoints can be set up as SOAP sources today.
- **CallService SOAP routing** (`lib/Service/CallService.php`, line ~466): When a source has type `soap`, calls are automatically routed to the SOAPService.
- **Certificate handling** (`lib/Service/CallService.php`): `getCertificate()` writes client certificates and SSL keys to temporary files, `removeFiles()` cleans up after requests. Supports PEM format with escaped newline conversion.
- **AuthenticationService** (`lib/Service/AuthenticationService.php`): Has JWT, OAuth2, API key, and password authentication. Can be extended for WS-Security UsernameToken.

### Not implemented
- **Inbound SOAP server** (REQ-STUF-001, REQ-STUF-020): No SOAP server endpoint exists. The current SOAPService is client-only (outbound). Exposing StUF-BG/ZKN endpoints as a SOAP server requires a raw POST handler that parses incoming SOAP XML.
- **StUF-BG field mapping** (REQ-STUF-002): No mapping between StUF-BG XML paths and OpenRegister object properties.
- **StUF-ZKN field mapping** (REQ-STUF-020): No mapping between StUF-ZKN zaak fields and Procest/OpenRegister objects.
- **WSDL files bundled** (REQ-STUF-040): No StUF-BG or StUF-ZKN WSDL/XSD files are included in the codebase.
- **XML namespace handling** (REQ-STUF-041): No StUF-specific namespace management.
- **Stuurgegevens** (REQ-STUF-042): No automatic population of zender/ontvanger/referentienummer/tijdstip.
- **noValue attribute handling** (REQ-STUF-043): No support for StUF noValue semantics.
- **Configurable field mapping** (REQ-STUF-050): No mapping configuration storage in OpenRegister.
- **Value transformations** (REQ-STUF-053): No date format, code list, or concatenation transformations.
- **WS-Security UsernameToken** (REQ-STUF-012): Not implemented as a specific auth method.
- **Scope filtering** (in REQ-STUF-001): Not implemented.
- **Fault message handling** (Fo01/Fo02/Fo03, Bv03): Not implemented.

### Summary
The outbound SOAP client infrastructure is in place and already has one piece of StUF-ZKN awareness (edcLk01 document handling). The inbound SOAP server side is entirely missing and represents the larger implementation effort.

## Standards & References

- **StUF-BG 3.10**: Standaard Uitwisseling Formaat - Basisgegevens. SOAP-based standard for person and address data exchange in Dutch government. Maintained by VNG Realisatie.
- **StUF-ZKN 3.10 / 3.10e**: Standaard Uitwisseling Formaat - Zaak-/Documentservices. SOAP-based standard for case and document management exchange. The "e" extension adds extra message types.
- **ZGW APIs (Zaakgericht Werken)**: The modern REST-based successor to StUF-ZKN. This adapter bridges the gap between legacy StUF and modern ZGW.
- **WS-Security**: OASIS standard for SOAP message security. UsernameToken profile is commonly used by Dutch government StUF endpoints.
- **PKIoverheid**: Dutch government PKI for mTLS authentication. Required for most production StUF endpoints.
- **GEMMA**: Reference architecture for Dutch municipalities -- defines the role of StUF in the information architecture.
- **BRP (Basisregistratie Personen)**: National person registry, accessed via StUF-BG by municipalities.
- **RGBZ (Referentiemodel Gemeentelijke Basisgegevens Zaken)**: The information model underlying StUF-ZKN.
- **CMIS**: Content Management Interoperability Services -- sometimes used alongside StUF-ZKN for document management.
