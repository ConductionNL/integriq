# StUF Adapter

## Overview

The StUF Adapter provides bidirectional translation between REST/ZGW APIs and legacy StUF-BG/StUF-ZKN SOAP interfaces. 79% of Dutch government tenders still require StUF support. This adapter enables:

- **Inbound**: Exposing OpenRegister objects as StUF-BG/StUF-ZKN SOAP endpoints for legacy consumers
- **Outbound**: Querying external StUF-BG/StUF-ZKN sources and storing results in OpenRegister

## Supported Standards

| Standard | Version | Use Case |
|---|---|---|
| StUF-BG | 3.10 | Person/address queries (BRP/BAG) |
| StUF-ZKN | 3.10 | Zaak management |

## Service Architecture

### StUFBGService

Handles StUF-BG 3.10 person and address operations.

**Inbound (legacy system queries Integriq):**

- `handleNpsLv01(string $soapXml, array $stuurgegevens): string` — Processes persoon opvragen requests; returns npsLa01 or Fo01 fault
- `handleAdrLv01(string $soapXml, array $stuurgegevens): string` — Processes adres opvragen requests; returns adrLa01 or Fo01 fault

**Outbound (Integriq parses external StUF-BG responses):**

- `parseNpsLa01Response(string $soapXml): array` — Parses npsLa01 from an external source; returns OpenRegister-mapped person arrays

### StUFZKNService

Handles StUF-ZKN 3.10 zaak management.

- `handleZakLk01(string $soapXml, array $stuurgegevens): string` — Processes zaak create/update; returns Bv03 or Fo03
- `handleZakLv01(string $soapXml, array $stuurgegevens): string` — Processes zaak query; returns zakLa01 or Fo03

### StUFXMLBuilder

Builds all outbound StUF XML messages with correct namespace declarations and stuurgegevens population.

### StUFFieldMapper

Maps between OpenRegister object properties and StUF-BG XML field names. Handles date conversions (ISO 8601 to/from StUF YYYYMMDD), nested verblijfsadres, and configurable field mappings.

## Field Mapping

### BRP Persons (StUF-BG)

| OpenRegister Property | StUF-BG XML Path |
|---|---|
| `burgerservicenummer` | `inp.bsn` |
| `geslachtsnaam` | `geslachtsnaam` |
| `voorvoegsel` | `voorvoegselGeslachtsnaam` |
| `voornamen` | `voornamen` |
| `geboortedatum` | `geboortedatum` (YYYYMMDD) |
| `geslachtsaanduiding` | `geslachtsaanduiding` |
| `verblijfsadres.straatnaam` | `verblijfsadres.gor.straatnaam` |
| `verblijfsadres.huisnummer` | `verblijfsadres.aoa.huisnummer` |
| `verblijfsadres.postcode` | `verblijfsadres.aoa.postcode` |
| `verblijfsadres.woonplaats` | `verblijfsadres.wpl.woonplaatsNaam` |

### ZKN Zaken (StUF-ZKN)

| OpenRegister Property | StUF-ZKN XML Path |
|---|---|
| `zaakidentificatie` | `identificatie` |
| `omschrijving` | `omschrijving` |
| `startdatum` | `startdatum` |
| `einddatum` | `einddatum` |
| `zaaktype` | `zaaktype` |
| `status` | `status` |

## Stuurgegevens Configuration

Configure stuurgegevens in the Source entity `configuration` field:

```json
{
  "zenderOrganisatie": "001122334",
  "zenderApplicatie": "OpenConnector",
  "ontvangerOrganisatie": "998877665",
  "ontvangerApplicatie": "LegacyApp",
  "stufVersion": "0310"
}
```

## Authentication

### PKIoverheid mTLS

Configure PKIoverheid client certificates via the standard CallService certificate fields on the Source entity. The existing getCertificate() / removeFiles() infrastructure handles mTLS automatically when the Source type is soap.

### WS-Security UsernameToken

Add WS-Security authentication using AuthenticationService buildWsSecurityHeader():

```php
$header = $authenticationService->buildWsSecurityHeader([
    'username'     => 'myuser',
    'password'     => 'mypassword',
    'passwordType' => 'PasswordDigest',
]);
```

PasswordDigest hashes the password as Base64(SHA1(Nonce + Created + Password)) per the WS-Security UsernameToken 1.0 profile. Use PasswordText only over TLS.

## XML Namespaces

| Prefix | URI |
|---|---|
| SOAP-ENV | http://schemas.xmlsoap.org/soap/envelope/ |
| StUF | http://www.egem.nl/StUF/StUF0301 |
| BG | http://www.egem.nl/StUF/sector/bg/0310 |
| ZKN | http://www.egem.nl/StUF/sector/zkn/0310 |

## OpenRegister Registers

| Register | Schema | Used For |
|---|---|---|
| brp | ingeschreven-persoon | BRP person data |
| bag | nummeraanduiding | BAG address data |
| zaken | zaak | ZKN zaak data |

## Test Data

Use the mock BRP/BAG registers for testing:

```bash
php occ openregister:load-register lib/Settings/brp_register.json
php occ openregister:load-register lib/Settings/bag_register.json
```

Test person: BSN 999993653 (Suzanne Moulin)
