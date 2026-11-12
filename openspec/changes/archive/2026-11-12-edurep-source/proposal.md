# Proposal: edurep-source

## Summary

Give integriq a learning material source: one interface, one Edurep implementation. learniq's change
`open-learning-material` (ConductionNL/learniq) calls `search(query, filters, page)` and `find(id)` through
the exchange client and gets one normalised record shape back, whatever the source. Spec:
`openspec/specs/edurep-source/spec.md`, mirrored in this change's delta.

## Motivation

- Ruben, 5 Nov 2026 (decision 376): learniq searches and imports open learning material; sources are
  integriq adapters, not learniq code.
- Tested the same day (decision 377): Edurep's SRU endpoint is free, keyless, covers every sector and
  supports the filters, facets and id lookup we need. Edusources has no reachable public API, so there is
  one implementation now and the interface stays open for a second.
- Licence decisions are learniq's (server-side, with golden tests), but they depend on the adapter
  normalising Edurep's rights values honestly: `yes` and `no` are not licences, and a creativecommons.org
  URL in the rights description is the only reliable code for many records.

## Affected projects

- [x] integriq: `lib/Adapters/<LearningMaterial>/` (interface, Http, Mock, record and result value objects),
  DI and integration registry (ADR-019), connection health and settings, `tests/fixtures/edurep/`,
  `tests/unit/`.
- [ ] learniq: consumer, in its own change.

## Scope

### In scope
The interface and value objects, the Edurep SRU implementation with CQL building, facets, paging and
escaping, LOM normalisation, `find` by id, recorded fixtures, the connection entry with health check and
settings.

### Out of scope
Harvest or mirror, supplying metadata to Edurep, an Edusources implementation, any UI beyond the connection
settings integriq already renders for adapters.

## Open questions, answered here

- Packagist `kennisnet/nllom`: verify it exists, its licence and that it parses LOM 1.2 `czp:` records before
  depending on it. If it does not fit, a small reader over SimpleXML is enough: the fields we read are
  title, description, location, format, rights, vCard `FN`, context, learningresourcetype, keyword,
  datetime and the catalogentry.
- Facet limits: Edurep returns nothing for `maximumRecords=1000`; pages stay at most 50, `startRecord=1000`
  works.

## Evidence

Live SRU calls on 5 Nov 2026 (lane od-design), recorded in the fixtures named in `tasks.md`: `betoog`
(242 Dutch results with facets), `betoog AND meta.repository.id=wikiwijsmaken` (35),
`betoog AND meta.repository.id=surfsharekit` (2), `betoog AND meta.repository.id=Schooltv` (2),
`signaalwoorden AND lom.general.language=nl` (250).
