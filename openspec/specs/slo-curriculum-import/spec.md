# slo-curriculum-import Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- slo-kerndoelen-import

## Purpose
Read one SLO curriculum set from opendata.slo.nl (CC BY 4.0) and emit it as one learniq `CompetencyFramework` with its `Competency` goal tree: codes, titles, the tree, the school years SLO itself names, and stable ids. Integriq owns the adapter and the mapping (learniq round 1 decisions D3, D7); the set is chosen by a profile on a dormant source template (D16).

## Requirements

### Requirement: A dormant SLO source template carries the set profiles and the attribution (REQ-001)
The system MUST seed a `source` object `slo-curriculum` in register `integriq` through a register.d fragment, with type `api`, location `https://opendata.slo.nl/curriculum/api/v1`, auth `basic` and `isEnabled: false`. Its `configuration` MUST carry the set profiles, the CC BY 4.0 attribution (publisher, licence name, licence URL, one credit sentence), the default proficiency scale and the year-niveau table. The fragment MUST NOT contain a username, password, API key or token. The source MUST appear in the integriq catalogue as `source-template:slo-curriculum`.

@e2e exclude seed fragment and catalogue listing with no browser surface; proven by SloCurriculumSourceTemplateTest and CatalogRegistryServiceTest

#### Scenario: The seeded source is dormant and credential-free
- GIVEN the fragment `lib/Settings/register.d/slo-curriculum-source.json`
- WHEN it is read
- THEN it contains a `source` object with slug `slo-curriculum`, `isEnabled` false and auth `basic`
- AND it carries no `username`, `password`, `apikey` or `secret` value

#### Scenario: The attribution names SLO, the licence and the change
- GIVEN the seeded source's `configuration.attribution`
- WHEN its `text` is read
- THEN it names SLO, "CC BY 4.0" and the licence URL `https://creativecommons.org/licenses/by/4.0/deed.nl`
- AND it states that the structure was converted

#### Scenario: The catalogue lists the template
- GIVEN the register.d fragments on disk
- WHEN `CatalogRegistryService::collect()` runs
- THEN an entry with slug `source-template:slo-curriculum` is present

### Requirement: Mapping presets name learniq's contract fields (REQ-002)
The system MUST seed two integriq `mapping` objects, `slo-curriculum-framework-mapping` and `slo-curriculum-competency-mapping`, whose keys are learniq field names (`name`, `sourceAuthority`, `sourceRef`, `edition`, `level`, `description`, `proficiencyLevels`, `tenant_id`; and `frameworkId`, `parentId`, `code`, `title`, `description`, `order`, `applicableYears`, `subjectId`, `tenant_id`). The mapper MUST apply them with MappingService's copy rule: a value naming a field of the normalised record is copied, any other value is a literal.

@e2e exclude PHP mapping with no browser surface; proven by SloCurriculumMapperTest and SloCurriculumSourceTemplateTest

#### Scenario: A competency record carries exactly the preset's keys
- GIVEN the seeded competency mapping
- WHEN a normalised node is mapped
- THEN the object's keys equal the mapping's keys
- AND `tenant_id` holds the tenant uuid passed to the import

#### Scenario: A literal value passes through unchanged
- GIVEN a mapping value that names no input field
- WHEN the mapping is applied
- THEN the output holds that value literally

### Requirement: The client is a mock by default and live only behind the flag (REQ-003)
The system MUST provide an abstract `SloCurriculumClient` with a `SloCurriculumClientMock` that serves recorded responses from a fixture of real SLO data without network access, and a `SloCurriculumClientHttp` that calls SLO through integriq's `CallService` with the seeded source. Dependency injection MUST resolve the mock unless the app-config key `slo.curriculum.feature_flag` is `1` or `true`. A request the mock has no recording for, an error status, or an unusable body MUST raise `SloCurriculumException`.

@e2e exclude HTTP client and DI binding with no browser surface; proven by SloCurriculumClientMockTest, SloCurriculumClientHttpTest and ApplicationBindsSloCurriculumClientTest

#### Scenario: The mock serves a recorded tree offline
- GIVEN the mock client
- WHEN it fetches `tree/612afa33-c49c-4b12-a7d1-7e44f2d69d25`
- THEN it returns the recorded JSONTag body
- AND `flavour()` returns `mock`

#### Scenario: The live client sends the Accept header through the seeded source
- GIVEN the live client and a seeded `slo-curriculum` source
- WHEN it fetches `tree/{id}` with accept `application/jsontag`
- THEN `CallService::call()` receives that source, endpoint `/tree/{id}` and header `Accept: application/jsontag`

#### Scenario: An error status is not a result
- GIVEN the live client and a call log with status 401
- WHEN it fetches any path
- THEN a `SloCurriculumException` carrying status 401 is thrown

### Requirement: JSONTag responses are read into linked arrays (REQ-004)
The system MUST read SLO's `application/jsontag` bodies: an `<object class="X" id="Y">` annotation becomes `@type` X and `@id` Y on the object, a `<link>"Y"` value becomes a reference resolved against the objects in the same document, and every other annotation is dropped. String contents MUST be copied verbatim, including `<` and `>`. Malformed input MUST raise `SloCurriculumException`.

@e2e exclude response parser with no browser surface; proven by JsonTagReaderTest

#### Scenario: An annotated object keeps its type and id
- GIVEN the body `<object class="Niveau" id="/uuid/abc">{"title":"po"}`
- WHEN it is read
- THEN the result has `@type` "Niveau", `@id` "/uuid/abc" and `title` "po"

#### Scenario: A link is a reference, not a string
- GIVEN a body whose second niveau is `<link>"/uuid/abc"`
- WHEN it is read
- THEN that value is `{"@link":"/uuid/abc"}`

### Requirement: The tree walk follows the set profile (REQ-005)
The system MUST walk a root depth first through the child collections the profile lists, in that order, and emit one node per SLO entity, parents before children. It MUST skip entities marked `deprecated` or `unreleased`, place an entity reachable twice under its first parent only, drop leaves whose niveaus miss the profile's niveau filter and prune branches left without leaves, expand a bare reference through `/uuid/{id}`, and refuse a run beyond 25,000 nodes, depth 16 or 500 expansions.

@e2e exclude tree walk with no browser surface; proven by SloCurriculumTreeWalkerTest and SloCurriculumNodeReaderTest

#### Scenario: A renewed kerndoelenset keeps its kernzin level
- GIVEN the recorded tree of "Kerndoelen burgerschap"
- WHEN it is walked with the `fo-kerndoelen` profile
- THEN 3 domeinen, 6 kernzinnen and 10 doelzinnen are emitted
- AND every doelzin's parent is a kernzin

#### Scenario: The primary-school filter keeps only primary kerndoelen
- GIVEN the recorded 2006 kerndoelen trees
- WHEN they are walked with the `kerndoelen-2006-po` profile
- THEN every emitted kerndoel carries the SLO niveau `po`
- AND no vakleergebied without a kept kerndoel is emitted

#### Scenario: A deprecated entity is skipped and counted
- GIVEN a tree containing an entity with `deprecated: true`
- WHEN it is walked
- THEN that entity and its children are not emitted
- AND `skippedDeprecated` is at least 1

### Requirement: Years come only from SLO's own niveaus (REQ-006)
The system MUST fill `applicableYears` only from SLO niveaus that name a year: `groep N`, a groep band (both years), or a VO leerjaar (`leerjaar N`), resolved by SLO niveau uuid first and by title second, written lower case with one space. Phases, school types and reference levels MUST yield no year. A node without a year niveau MUST get an empty list.

@e2e exclude year allocation with no browser surface; proven by SloYearAllocatorTest

#### Scenario: A groep band becomes two years
- GIVEN a doelniveau with SLO niveaus `po` and `groep 3-4`
- WHEN its years are allocated
- THEN `applicableYears` is `["groep 3", "groep 4"]`

#### Scenario: End-of-phase kerndoelen are framework-wide
- GIVEN a 2006 kerndoel with niveaus `po` and `fase 3`
- WHEN its years are allocated
- THEN `applicableYears` is `[]`

### Requirement: One framework per set and root, with stable ids and attribution (REQ-007)
The system MUST emit one `competency-framework` record per profile and SLO root (or one per aggregate profile), and one `competency` record per kept node, each with register `learniq`, a UUID v5 id over tenant, set, root and SLO uuid, the SLO uuid as `originId` and a sha256 `originHash`. `parentId` MUST be the id of the parent node's record or null at the top. The framework's `description` MUST end with the attribution text, its `sourceRef` MUST link the SLO root, and its `proficiencyLevels` MUST be the configured default scale. `subjectId` MUST be set only on top-level records and only from the caller's `subjectCourseIds` map; the adapter MUST NOT create learniq Course rows. The tenant id and every map value MUST be UUIDs.

@e2e exclude import records with no browser surface; proven by SloCurriculumSourceAdapterTest and SloCurriculumMapperTest

#### Scenario: A re-import yields the same ids
- GIVEN the same set, root and tenant
- WHEN `importFramework()` runs twice
- THEN both runs return identical framework and competency uuids

#### Scenario: A subject map fills only the top level
- GIVEN `subjectCourseIds` mapping "burgerschap" to a Course uuid
- WHEN "Kerndoelen burgerschap" is imported
- THEN every top-level competency has that `subjectId`
- AND every deeper competency has `subjectId` null

#### Scenario: A tenant id that is not a UUID is refused
- GIVEN tenant id "school-1"
- WHEN `importFramework()` runs
- THEN an `InvalidArgumentException` is thrown and nothing is fetched

### Requirement: Roots are discovered through SLO's collection routes (REQ-008)
The system MUST list a set's roots through its profile's discovery route, send `page` and `perPage` (the parameter SLO's server reads), follow pages until `count` is reached or 20 pages, accept both a `{data}` envelope and a bare array, and skip deprecated and unreleased entries.

@e2e exclude root discovery with no browser surface; proven by SloCurriculumSourceAdapterTest

#### Scenario: Discovery lists the recorded examenprogramma
- GIVEN the mock client
- WHEN `discoverRoots('examenprogramma')` runs
- THEN the result contains "Examenprogramma Tekenen vwo" with its SLO uuid

### Requirement: No personal data and no secrets (REQ-009)
The fragment, the recorded fixture and every log entry MUST contain no personal data and no credential. Log entries MUST carry only the set key, the root uuid, counts, the client flavour and whether the live flag is on.

@e2e exclude data and log hygiene with no browser surface; proven by SloCurriculumSourceTemplateTest and SloCurriculumSourceAdapterTest

#### Scenario: The fixture holds no e-mail address or token
- GIVEN the recorded fixture and the fragment
- WHEN they are scanned
- THEN neither contains an e-mail address, a Basic token or the word "password" with a value

## Non-Functional Requirements

- **Performance:** one request per root for per-root sets; one per vakleergebied plus discovery for the 2006 kerndoelen.
- **Accessibility:** not applicable, no user interface.
- **Internationalization:** framework names and goal texts are SLO's own Dutch data; the preset's scale labels are Dutch data values. No UI strings are added.

## Acceptance Criteria

- The source template is seeded dormant and listed in the catalogue.
- The mock imports every recorded set offline.
- Records match the learniq field names of `CONTRACT-competency-fields.md`.

## Notes

- The write step into learniq is a follow-up change after learniq `competency-year-scope` merges (design.md D7).
- Discovery findings and source URLs with dates: see design.md "Sources verified".
