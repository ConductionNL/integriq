---
kind: code
depends_on: []
---

# Proposal: mapping-woo-index-field-mapping

## Summary

Opencatalogi fills the Woo-index fields of a publication with a mapping written
in PHP, so changing which field becomes the publisher, the category or the
document action needs a developer, and the official title is not filled at
all. Integriq already has a mapping editor that needs no developer. This change
gives integriq a seeded, editable Woo-index mapping and a typed event another
app dispatches to run a mapping by slug and read the result. Opencatalogi
switching its sitemap to that event is opencatalogi's half.

## Why

Matrix row `opencatalogi:woo-metadata-map`, from opencatalogi's matrix: "Map a
source system's metadata onto the Woo-index fields (publisher, official title,
category, document action) and change that mapping without a developer."
Opencatalogi rates itself `partial` with `built.state` `built` and names
integriq as owner and provider. This change covers that one sibling row.

Demand:

- tender, https://www.tenderned.nl/aankondigingen/overzicht/407973. The
  matrix origin note: "TenderNed 407973 requirement VPB-01 (eis): translation
  must be extensible and adaptable".

No competitor rates `yes`. The closest is iprox at `partial`, where "the
mapping is configured by iprox as part of the koppeling ... not changed by the
customer without a developer" (https://iprox.nl/marketing/477/blog-woo-publiceren-koppeling).

The sibling matrix records the missing half, from opencatalogi's code read at
a00f9f8: "the Woo-index fields are filled by a fixed mapping in code:
lib/Service/SitemapService.php:508-590 reads publisher from the organisation's
TOOI id, category from category/tooiCategorieUri/tooiCategorieNaam and
soortHandeling from the publication, and emits no official title at all".
That path is opencatalogi's and was not re-read for this change.

## What integriq already has

- A mapping is an OpenRegister object of schema `mapping` in integriq's
  register (`lib/Settings/integriq_register.json:1414`), edited on the
  `/mappings` pages without code.
- `lib/Service/MappingService.php:277` (`executeMapping()`) runs a mapping
  given as an object, an array, a uuid or a slug; `:190`
  (`normaliseMapping()`) resolves a uuid first and falls back to `slug` and
  `reference` through `findMappingByIdentifier()` at `:239`.
- Integriq already answers a sibling app's typed command with a result slot:
  `lib/Event/DocumentRenderRequestedEvent.php` with its listener registered at
  `lib/AppInfo/Application.php:297`, following ADR-041.
- There is no event to run a mapping, and no seeded Woo-index mapping; the
  three seeded mappings are examples (`lib/Settings/integriq_seed_data.json:268`).

## What this change builds

1. `MappingExecutionRequestedEvent`, a public typed event carrying the mapping
   slug, the input, the requesting app and a correlation id, with a result slot
   for the output or a refusal.
2. A listener that runs the mapping through `MappingService` and writes the
   result, refusing an unknown slug or a mapping the requesting app is not
   allowed to run.
3. A seeded mapping `woo-index-publication` that maps a publication onto the
   Woo-index fields: `publisher`, `officieleTitel`, `informatiecategorie` and
   `soortHandeling`, with its rules readable and editable on `/mappings`.
4. A per-mapping `callableBy` list naming the apps allowed to run it by event;
   the seeded Woo mapping lists `opencatalogi`.

## Opencatalogi's half, not built here

Opencatalogi's `SitemapService` dispatches `MappingExecutionRequestedEvent`
with slug `woo-index-publication` for each publication, guarded by
`class_exists()`, and uses the result for the DIWOO fields. What it does when
integriq is absent, or when the mapping is refused, is opencatalogi's decision
under ADR-041. That work is opencatalogi's and is named here so it is not
assumed done when this change lands.

## Out of scope

- Mapping source systems onto publications. That already happens in integriq
  synchronizations.
- Validating the Woo-index output against the DIWOO XSD. That is
  `mapping-message-schema-validation` once opencatalogi declares the schema.
