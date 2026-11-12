# edurep-source (new spec)

**Spec refs**: `edurep-source` (canonical text in `openspec/specs/edurep-source/spec.md`); consumer: learniq `open-learning-material`.

# edurep-source Specification

Boards (in ConductionNL/design-system, app learniq): `LqOpenLesmateriaal` (search and facets),
`LqOpenLesmateriaalDetail` (one record), `LqOpenLesmateriaalStaten` (source down), `LqKoppelingen` (the
connection). Consumer spec: learniq `open-learning-material`. Decisions 376 and 377 (5 Nov 2026).

## Purpose
integriq provides the search over Edurep (Kennisnet) that learniq uses to find open learning material.
Edurep's SRU endpoint is free and keyless and covers about 495,000 Dutch records across every sector
(Wikiwijs Maken, Wikiwijs Delen, Wikiwijs Toetsen, Schooltv, SURFsharekit, KlasCement, artik, metaplus,
wur_staff and more). Edurep records are IMS LOM 1.2 XML (`czp:` namespace) escaped inside
`srw:recordData`. The adapter exposes two calls, `search(query, filters, page)` and `find(id)`, and maps
every record to one normalised shape, so a second source (Edusources, once SURF offers a supported API) can
sit behind the same interface without the consumer changing.

@e2e exclude backend source adapter (SRU over HTTP, no browser UI); covered by PHPUnit against recorded fixtures.

## ADDED Requirements

### Requirement: A learning material source interface with one normalised record
integriq MUST define a learning material source interface under `lib/Adapters/` with
`search(string $query, array $filters, int $page): SearchResult` and `find(string $id): ?MaterialRecord`,
following the Pdok adapter pattern (interface, Http implementation, Mock implementation, registered in the
DI container and in the integration registry per ADR-019). `MaterialRecord` MUST carry exactly: `sourceId`,
`title`, `description`, `url`, `files[]` (each with `url`, `format`, `accessRights`), `embedUrl` (nullable),
`authors[]`, `license` (normalised code: `CC0-1.0`, `PDM`, `CC-BY-4.0`, `CC-BY-SA-4.0`, `CC-BY-NC-4.0`,
`CC-BY-ND-4.0`, `CC-BY-NC-SA-4.0`, `CC-BY-NC-ND-4.0`, the 3.0 forms, `rights-reserved`, `unknown`),
`licenseUrl` (nullable), `accessRights` (`OpenAccess`, `RestrictedAccess`, `ClosedAccess`, nullable),
`levels[]` (NL-LOM context codes: `PO`, `VO`, `SO`, `BVE`, `HBO`, `WO`, `HO`), `disciplines[]`,
`materialType`, `language`, `collection` (the `meta.repository.id`), `published` (date, nullable) and
`source` (`edurep`). `SearchResult` MUST carry `total`, `page`, `pageSize`, `records[]` and `facets`
(a map of facet name to a list of `value` and `count`).

#### Scenario: A Wikiwijs record is normalised
- GIVEN the recorded SRU response for query `betoog AND meta.repository.id=wikiwijsmaken`
- WHEN `search('betoog', ['collection' => ['wikiwijsmaken']], 1)` runs
- THEN the first record has `sourceId` `urn:wikiwijsmaken:194413`, `title` "Het schrijven van een betoog",
  `url` `https://maken.wikiwijs.nl/194413/Het_schrijven_van_een_betoog`, `license` `CC-BY-4.0`,
  `licenseUrl` `https://creativecommons.org/licenses/by/4.0/`, `authors` `["Kirstin Beekman"]` (the
  repository's own name "Wikiwijs Maken" dropped from authors), `materialType`
  `application/x-Wikiwijs-Arrangement`, `collection` `wikiwijsmaken`, `published` `2023-01-29`

#### Scenario: Rights values yes and no are not mistaken for licences
- GIVEN a record whose `copyrightandotherrestrictions` value is `yes` with a rights description holding a
  URL, and another whose value is `no`
- WHEN both are normalised
- THEN `license` is `rights-reserved` for `no`, and for `yes` the URL is parsed: a creativecommons.org URL
  becomes its code (`http://creativecommons.org/licenses/by-nc-nd/3.0/nl/` becomes `CC-BY-NC-ND-3.0`),
  anything else becomes `unknown` with `licenseUrl` kept

### Requirement: Search maps filters to CQL and asks for facets
`search` MUST build a CQL query against
`https://wszoeken.edurep.kennisnet.nl/edurep/sruns?operation=searchRetrieve&version=1.2&recordSchema=lom`
with the user's words as a free-text term and these filter indexes: `lom.educational.context` for levels,
`lom.rights.copyrightandotherrestrictions` for licence codes, `lom.technical.format` for file types,
`meta.repository.id` for collections and `lom.general.language` (default `nl`). It MUST request facets with
`x-term-drilldown` on those four indexes (`lom.rights.copyrightandotherrestrictions:10`,
`meta.repository.id:12`, `lom.educational.context:8`, `lom.technical.format:8`). Pages MUST stay small:
`maximumRecords` at most 50 (1000 returns nothing), `startRecord` from the page number. Query values MUST be
escaped so a user cannot inject CQL. Timeout 10 seconds; on timeout or a non-200 answer the adapter MUST
throw a typed source-unavailable exception that the consumer renders as "Edurep antwoordt niet".

#### Scenario: Facet counts come back with the results
- GIVEN the recorded response for `betoog AND lom.general.language=nl` with drilldown
- WHEN `search('betoog', [], 1)` runs
- THEN `total` is 242 and `facets['meta.repository.id']` contains `artik` 86, `metaplus` 43,
  `wikiwijsmaken` 35, `KlasCement` 32, `WikiwijsDelen` 21, `Schooltv` 2, `surfsharekit` 2
- AND `facets['lom.rights.copyrightandotherrestrictions']` contains `yes` 138, `cc-by-40` 36,
  `cc-by-30` 30, `cc-by-sa-40` 25, `cc-by-nc-nd-30` 9

#### Scenario: A level filter narrows the query
- GIVEN filters `['levels' => ['VO'], 'licenses' => ['CC-BY-4.0', 'CC-BY-SA-4.0']]`
- WHEN the CQL is built
- THEN it contains `lom.educational.context=VO` and `(lom.rights.copyrightandotherrestrictions=cc-by-40 OR
  lom.rights.copyrightandotherrestrictions=cc-by-sa-40)` joined with AND to the free-text term

#### Scenario: Edurep does not answer
- GIVEN the HTTP client times out after 10 seconds
- WHEN `search` runs
- THEN a source-unavailable exception is thrown with the source name and the last successful time
- AND nothing is cached as a result

### Requirement: find returns one record by its identifier
`find(id)` MUST query the quoted record identifier (the full `catalogentry` value, or its last path segment
for identifiers such as `/NTR/POMS/WO_NTR_20306689`) and return the single `MaterialRecord`, or null when
Edurep returns zero records. The consumer uses it when a teacher adds a result, so the stored attribution
comes from the record and never from the browser.

#### Scenario: A Schooltv record is found by id
- GIVEN the recorded response for the quoted identifier `"WO_NTR_20306689"`
- WHEN `find('/NTR/POMS/WO_NTR_20306689')` runs
- THEN the record has `collection` `Schooltv`, `license` `rights-reserved`, `materialType` `video/H264`,
  `url` on schooltv.nl, `authors` `["Schooltv"]` and no `embedUrl`

### Requirement: Recorded fixtures, no live calls in tests
Tests MUST run against recorded SRU responses under `tests/fixtures/edurep/` (the four queries above plus
one empty result and one malformed body) and MUST NOT call Kennisnet. The Mock implementation serves those
fixtures. A fixture refresh script MAY exist but MUST NOT run in CI.

#### Scenario: A malformed response is rejected cleanly
- GIVEN a fixture whose `recordData` is not valid LOM
- WHEN it is normalised
- THEN the record is skipped, the skip is logged with the record position, and the other records are
  returned

### Requirement: The source is a connection with health and settings
The adapter MUST register as a connection that learniq's connections page (`LqKoppelingen`) can show: a
health check (one small SRU call, cached 5 minutes) reporting Werkt or Fout with the response time, settings
for the collections on or off (KlasCement off by default), the default language, and the terms link to
Kennisnet. A second source (Edusources) MUST be addable as another implementation of the same interface
with its own connection entry; until then learniq declares it as not available.

#### Scenario: Health is reported to the consumer
- GIVEN the health check succeeded in 0.3 seconds
- WHEN learniq asks for the connection status
- THEN it receives Werkt, the response time and the time of the check

## Out of scope
Harvesting or mirroring Edurep (needs an agreement with Kennisnet), supplying metadata to Edurep, an
Edusources implementation (no public API as of 5 Nov 2026).
