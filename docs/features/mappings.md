# Mappings

## Overview

A **Mapping** defines how to transform data from one shape to another. Mappings sit between sources and targets in a synchronization flow, and are also used by endpoints to reshape request and response bodies. The mapping engine supports direct field assignments, Twig template expressions, type casts, dot-notation paths, and JSON Logic conditions.

## Mapping Object Structure

A mapping consists of a `mapping` object (field assignments) and an optional `cast` object (type conversions):

```json
{
  "name": "ZGW Zaak to OpenRegister",
  "slug": "zgw-zaak-to-openregister",
  "mapping": {
    "identificatie": "{{ input.zaakIdentificatie }}",
    "omschrijving": "{{ input.omschrijving }}",
    "startdatum": "{{ input.startdatum | date('Y-m-d') }}",
    "status.code": "input.status.statustype.code",
    "zaaktype": "{{ input.zaaktype | split('/') | last }}"
  },
  "cast": {
    "bouwkosten": "integer",
    "aantalBijlagen": "integer",
    "metadata": "jsonToArray"
  }
}
```

## Mapping Strategies

### Direct Field Reference

Reference source fields by dot-notation path. No Twig delimiters needed:

```json
{
  "naam": "input.naam",
  "adres.straat": "input.verblijfsadres.straatnaam"
}
```

### Twig Template Expression

Use Twig syntax for transformations, string manipulation, conditionals, and loops:

```json
{
  "volledigeNaam": "{{ input.voornamen }} {{ input.geslachtsnaam }}",
  "geboortejaar": "{{ input.geboortedatum | date('Y') }}",
  "actief": "{% if input.status == 'actief' %}true{% else %}false{% endif %}"
}
```

### Static Value

Provide a literal value (not a path or Twig expression):

```json
{
  "bron": "TenderNed",
  "versie": "1.0"
}
```

### Nested Object Mapping

Use dot-notation keys to build nested output structures:

```json
{
  "adres.straatnaam": "input.straat",
  "adres.huisnummer": "input.nummer",
  "adres.postcode": "input.postcode"
}
```

This produces `{ "adres": { "straatnaam": "...", "huisnummer": "...", "postcode": "..." } }`.

### Conditional Mapping

Apply a transformation only when a JSON Logic condition is true. Use `_conditions` at the top level of the mapping to skip or override fields:

```json
{
  "mapping": {
    "type": "{{ input.type }}"
  },
  "_conditions": [
    {
      "condition": { "!=": [{ "var": "input.type" }, null] },
      "mapping": {
        "type": "{{ input.type | upper }}"
      }
    }
  ]
}
```

## Type Casts

The `cast` section applies type conversions after field assignment:

| Cast | Description |
|------|-------------|
| `string` | Convert to string |
| `integer` / `int` | Convert to integer |
| `float` | Convert to float |
| `boolean` / `bool` | Convert to boolean |
| `array` | Convert to array (wraps scalars) |
| `jsonToArray` | Parse a JSON string into an object/array |
| `date` | Parse and normalize date strings |
| `url` | Encode as a valid URL |
| `unset` | Remove the field from output |

## List Processing

Apply a mapping to each item in an array by enabling list mode. The engine iterates over the input array and maps each item independently. Configure `passlist` to preserve the array structure in the output.

## Twig Extensions

The mapping engine provides custom Twig functions and filters beyond standard Twig:

| Extension | Description |
|-----------|-------------|
| `oauthToken(source)` | Fetch an OAuth token for a Source |
| `jwToken(source)` | Generate a JWT for a Source |
| `callService(source, endpoint, method, body)` | Make an HTTP call within a mapping |
| `mappingService(mappingSlug, input)` | Apply another mapping recursively |
| Standard Twig filters | `date`, `split`, `last`, `upper`, `lower`, `replace`, `json_encode`, etc. |

## OpenRegister Delegation

The MappingService in OpenConnector delegates execution to OpenRegister's `MappingService` when OpenRegister is installed. This provides a shared, maintained mapping engine across the Conduction app suite. OpenConnector falls back to its own implementation when OpenRegister is not available.

## Implementation

- `lib/Service/MappingService.php` — Twig-based mapping engine, delegation to OpenRegister
- `lib/Controller/MappingsController.php` — REST CRUD API
- `lib/Db/Mapping.php` — Entity
- `lib/Db/MappingMapper.php` — Database mapper
- `lib/Twig/MappingExtension.php` — Custom Twig functions
- `lib/Twig/MappingRuntimeLoader.php` — Runtime loader for lazy-loaded Twig services
