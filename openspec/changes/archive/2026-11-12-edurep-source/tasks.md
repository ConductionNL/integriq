# Tasks: edurep-source

Pattern: `lib/Adapters/Pdok/` (interface, Http, Mock). Code is generic (decision 182): the interface and
value objects are named for what they do (learning material source), only the implementation is named Edurep.

## Implementation Tasks

### Task 1: Interface and value objects
- **spec_ref**: `openspec/changes/edurep-source/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record`
- **files**: `lib/Adapters/LearningMaterial/LearningMaterialSource.php`, `.../MaterialRecord.php`, `.../SearchResult.php`
- **acceptance_criteria**:
  - GIVEN the interface WHEN read THEN it has exactly `search(string $query, array $filters, int $page): SearchResult` and `find(string $id): ?MaterialRecord`
  - GIVEN `MaterialRecord` WHEN constructed THEN every field in the spec's list exists, typed, with no extra fields
  - GIVEN the licence codes WHEN listed THEN they are a closed enum including `rights-reserved` and `unknown`
- [x] Implement
- [x] Test

### Task 2: Edurep SRU implementation
- **spec_ref**: `openspec/changes/edurep-source/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets`
- **files**: `lib/Adapters/LearningMaterial/EdurepSourceHttp.php`, `lib/Adapters/LearningMaterial/EdurepSourceMock.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN filters for levels, licences, file types, collections and language WHEN the CQL is built THEN each maps to its index, values are escaped, groups are OR-ed and joined with AND
  - GIVEN any page WHEN the request is built THEN `maximumRecords` is at most 50 and `x-term-drilldown` names the four facet indexes
  - GIVEN a timeout or a non-200 answer WHEN `search` runs THEN a typed source-unavailable exception carries the source name and the last successful time, and nothing is cached
  - GIVEN the DI container and the integration registry WHEN queried THEN the source is registered (ADR-019)
- [x] Implement
- [x] Test

### Task 3: LOM normalisation
- **spec_ref**: `openspec/changes/edurep-source/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record`
- **files**: `lib/Adapters/LearningMaterial/LomRecordReader.php` (or `kennisnet/nllom` if verified on Packagist with a compatible licence)
- **acceptance_criteria**:
  - GIVEN the Wikiwijs fixture WHEN read THEN the record matches the spec's scenario field for field
  - GIVEN rights `yes` with a creativecommons.org URL in the description WHEN read THEN `license` is the code from the URL; GIVEN `no` THEN `rights-reserved`; GIVEN `yes` with another URL THEN `unknown` with `licenseUrl` kept
  - GIVEN vCard `FN` values WHEN read THEN the repository's own name (Wikiwijs Maken, KlasCement, Schooltv when it is the only author stays) is not duplicated into `authors`
  - GIVEN a malformed `recordData` WHEN read THEN the record is skipped and logged and the rest returned
- [x] Implement
- [x] Test

### Task 4: find by id
- **spec_ref**: `openspec/changes/edurep-source/specs/edurep-source/spec.md#requirement-find-returns-one-record-by-its-identifier`
- **files**: `lib/Adapters/LearningMaterial/EdurepSourceHttp.php`
- **acceptance_criteria**:
  - GIVEN `urn:wikiwijsmaken:194413` WHEN `find` runs THEN the quoted identifier is the CQL term and one record returns
  - GIVEN `/NTR/POMS/WO_NTR_20306689` WHEN `find` runs THEN the last path segment is quoted and the Schooltv record returns
  - GIVEN an unknown id WHEN `find` runs THEN null
- [x] Implement
- [x] Test

### Task 5: Recorded fixtures
- **spec_ref**: `openspec/changes/edurep-source/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests`
- **files**: `tests/fixtures/edurep/betoog-nl-facets.xml`, `betoog-wikiwijsmaken.xml`, `betoog-surfsharekit.xml`, `betoog-schooltv.xml`, `signaalwoorden-nl.xml`, `empty.xml`, `malformed.xml`, `tests/unit/Adapters/LearningMaterial/`
- **acceptance_criteria**:
  - GIVEN the test suite WHEN run offline THEN every test passes and no HTTP call leaves the process
  - GIVEN the facets fixture WHEN searched THEN `total` 242 and the facet counts in the spec's scenario match
- [x] Implement
- [x] Test

### Task 6: Connection health and settings
- **spec_ref**: `openspec/changes/edurep-source/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings`
- **files**: the connection registry integriq uses for adapters, `lib/Adapters/LearningMaterial/`
- **acceptance_criteria**:
  - GIVEN the health check WHEN it runs THEN one small SRU call, cached 5 minutes, reports Werkt or Fout with the response time
  - GIVEN the settings WHEN rendered THEN collections on or off (KlasCement off by default), default language and the Kennisnet terms link exist
  - GIVEN learniq's connections page WHEN it asks THEN it gets status, response time and time of check
- [x] Implement
- [x] Test

### Task 7: Verify
- **files**: PR body
- **acceptance_criteria**:
  - GIVEN the branch WHEN finished THEN the diff check ran while building and `composer check:strict`, lint and the unit suite ran once at the end with exit codes in the PR body
  - GIVEN every changed method WHEN read THEN it carries `@spec openspec/specs/edurep-source/spec.md`
- [x] Implement (done: lib/Adapters/LearningMaterial/ (EdurepSource, EdurepHealthCheck, LearningMaterialService); closed on code by decision 399)
- [x] Test (done: lib/Adapters/LearningMaterial/ (EdurepSource, EdurepHealthCheck, LearningMaterialService); closed on code by decision 399)
