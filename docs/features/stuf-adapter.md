# StUF Adapter

## Overview

The StUF adapter provides bidirectional translation between modern REST/ZGW APIs and legacy StUF-BG (personen/adressen) and StUF-ZKN (zaken/documenten) SOAP-based interfaces. Required by 79% of Dutch government tenders that still need StUF support.

## Supported Standards

| Standard | Version | Direction | Description |
|----------|---------|-----------|-------------|
| StUF-BG | 3.10 | Inbound + Outbound | Person and address queries |
| StUF-ZKN | 3.10/3.10e | Inbound + Outbound | Zaak management |

## StUF-BG Operations

### Person Query (npsLv01 / npsLa01)

Query persons by BSN, name, or other criteria. Returns matching person records from OpenRegister.

**Inbound:** Legacy applications send `npsLv01` SOAP requests; adapter returns `npsLa01` responses.

**Outbound:** OpenConnector sends `npsLv01` to external StUF-BG sources; parses `npsLa01` responses into OpenRegister objects.

### Address Query (adrLv01 / adrLa01)

Query addresses by postcode and huisnummer from the BAG register.

## Field Mapping

The adapter maps between StUF-BG field names and OpenRegister property names:

| OpenRegister Field | StUF-BG Field |
|-------------------|---------------|
| burgerservicenummer | inp.bsn |
| geslachtsnaam | geslachtsnaam |
| voorvoegsel | voorvoegselGeslachtsnaam |
| voornamen | voornamen |
| geboortedatum | geboortedatum |
| verblijfsadres.straatnaam | gor.straatnaam |
| verblijfsadres.huisnummer | aoa.huisnummer |
| verblijfsadres.postcode | aoa.postcode |
| verblijfsadres.woonplaats | wpl.woonplaatsNaam |

Field mappings are configurable via OpenRegister mapping objects to support custom schemas.

## Date Format Handling

- **OpenRegister:** ISO 8601 format (`1990-05-15`)
- **StUF-BG:** YYYYMMDD format (`19900515`)
- The mapper automatically converts between formats.

## Authentication

### PKIoverheid mTLS
For connections to government StUF services. Uses existing CallService certificate handling (`getCertificate()`, `removeFiles()`).

### WS-Security UsernameToken
SOAP header authentication with username and password. Supports both PasswordText and PasswordDigest modes.

## Implementation

- **StUFFieldMapper**: `lib/Service/StUFFieldMapper.php` -- Field mapping and date conversion
- **Tests**: `tests/Unit/Service/StUFFieldMapperTest.php`

## Status

Field mapper with date conversion and configurable mapping implemented and tested. The following features are planned:

- StUF XML builder (response generation with proper namespaces)
- SOAP endpoint registration via EndpointService
- Inbound npsLv01/npsLa01 handling
- Outbound StUF queries via SOAPService
- WS-Security UsernameToken authentication
- StUF-ZKN zaak operations
