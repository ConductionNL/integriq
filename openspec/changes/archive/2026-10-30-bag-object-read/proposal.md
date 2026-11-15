# bag-object-read

## Why

Dossiq checks, when an administrator has switched the check on, that a case location which says it
comes from the BAG names a nummeraanduiding the BAG really holds. That check ran on dossiq's own
BAG client, which leaves dossiq (ADR-118, hydra design section 5). Integriq already reaches PDOK's
BAG WFS and Locatieserver, so the read is built here, beside `BrpRelationReader`.

A guard that decides on the answer must tell "the BAG holds no such object" apart from "there was
no answer" (decision 224: a guard refuses when it is unsure). The PDOK clients could not: both
answered an empty result when PDOK was down.

## What changes

- `BagObjectReader::read(type, identificatie)` answers whether a `pand`, `verblijfsobject` or
  `nummeraanduiding` exists, as a plain array: `available`, `reason`, `found`, `simulated` and
  `object`. A pand and a verblijfsobject are read from the BAG WFS with a filter on
  `identificatie`; a nummeraanduiding from the Locatieserver with `nummeraanduiding_id:<id>`.
- An identificatie that is not 16 digits with the type's code at positions 5 and 6 is not asked.
- An answer about another object than the one asked is `unclear-answer`, not a find.
- `simulated` is true while the PDOK clients run in their mock flavour (`pdok.feature_flag` off).
- `PdokGeocodingClient::find()` is new: it answers null when the Locatieserver gave no answer, and
  an empty list when it answered with nothing. `suggest()` is unchanged.
- `PdokWfsClientHttp::getFeature()` marks the empty collection it returns on a failure with
  `unanswered: true`. Every existing key stays.

## Impact

No schema, route, setting or version change. Existing callers of `suggest()` and `getFeature()`
see the same answers as before. Dossiq reaches the reader by class name at runtime (change
`bag-client-retired` in dossiq).
