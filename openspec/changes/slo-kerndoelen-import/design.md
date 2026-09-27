# Design: slo-kerndoelen-import

## Sources verified (URLs and dates)

Every claim below was read or probed on 2026-09-27. Times are UTC.

| What | URL | Read | Finding |
|---|---|---|---|
| API base, live probe | `https://opendata.slo.nl/curriculum/api/v1/` (and `kerndoel/`, `niveau/`, `vakleergebied/`, `uuid/{id}`, `openapi.json`) | 10:51 to 10:53 | `401 Unauthorized`, `WWW-Authenticate: Basic`, empty body, for every `Accept: application/json` request. `text/html` returns the browser shell only. |
| SLO's API page | `https://opendata.slo.nl/curriculum/api/` (content served from `https://opendata.slo.nl/data/data.json`, key `/curriculum/api/`) | 10:55 | "Als u programmatische toegang wenst, moet u zich eerst registreren." JSON needs "uw apiKey als basic authentication". Registration page: `https://opendata.slo.nl/curriculum/2021/api/v1/register/`. |
| OpenAPI 2024.1 | `https://api.swaggerhub.com/apis/AUKE_1/slo-curriculum-open-data-api/2024.1` | 10:58 | 105 paths, `basicAuth` required on all, collections return `{data, page, count, root, @isPartOf}`, `/tree/{id}` returns `application/jsontag`. |
| Licence | `https://data.overheid.nl/data/api/3/action/package_show?id=slo-curriculumdatabase` | 11:02 | `license_id` `http://creativecommons.org/licenses/by/4.0/deed.nl` (CC-BY 4.0), publisher Stichting Leerplan Ontwikkeling, access PUBLIC, record modified 2022-04-07. |
| SLO disclaimer | `https://opendata.slo.nl/data/data.json`, key `/Disclaimer/` | 11:00 | Copying allowed "mits de bron wordt vermeld". |
| Server source | `https://github.com/slonl/curriculum-rest-api` @ `master` (last push 2026-08-20) | 11:05 | Typed queries per entity type, `storeQuery` reads `perPage` (not `pageSize`), `/tree/{id}` is `JSONTag.stringify(Index(id))`. |
| Dev harness | `https://github.com/slonl/curriculum-restapi-dev` @ `929314f7` | 11:08 | Pins `curriculum-rest-api` as a submodule: the deployed server is that source. |
| Datasets | `slonl/curriculum-fo@2026.8`, `curriculum-basis@2026.7`, `curriculum-kerndoelen@2026.7`, `curriculum-examenprogramma@2026.7`, `curriculum-leerdoelenkaarten@2026.7` | 11:10 | Source of the recorded fixture (see "Fixture provenance"). |
| Learniq target | `ConductionNL/learniq` `development`, `lib/Settings/learniq_register.json` (register 0.24.9) | 10:58 | `competency-framework` and `competency` in register `learniq`. |
| Field contract | `/home/rubenlinde/memcap-work/lq-lanes/CONTRACT-competency-fields.md` (lane r2-curriculum) | 11:15 | `applicableYears` (free labels, `groep N` / `leerjaar N`), `subjectId` (Course UUID or null). |

## Architecture overview

```
register.d/slo-curriculum-source.json
  source  slo-curriculum            (dormant: isEnabled false, auth basic)
    configuration.sets              the set profiles (levels, leaf types, niveau filter, field paths)
    configuration.attribution       CC BY 4.0 credit
    configuration.proficiencyLevels default scale for every imported framework
    configuration.yearNiveaus       SLO niveau uuid -> year labels
  mapping slo-curriculum-framework-mapping   learniq CompetencyFramework field names
  mapping slo-curriculum-competency-mapping  learniq Competency field names

SloCurriculumSourceAdapter (lib/Sources/Slo)
  -> SloCurriculumPresetRegistry   reads the fragment above
  -> SloCurriculumClient           mock (recorded fixture) | http (CallService + seeded source)
  -> SloCurriculumTreeWalker       JSONTag tree -> flat, parent-first node list
       -> JsonTagReader
  -> SloCurriculumMapper           nodes -> learniq records with UUID v5 ids
       -> SloYearAllocator
```

`importFramework(setKey, tenantId, rootUuid, subjectCourseIds)` returns one framework record and its competency records, parents before children, plus the attribution and run statistics. Nothing is written.

## Decisions

### D1: Read each root through `/tree/{id}`, parse JSONTag in integriq
The renewed kerndoelen hang every doelzin under a kernzin, and the server's `FoSet`/`FoDomein` typed queries never select `FoKernzin` (discovery finding 5). `/tree/{id}` returns the raw graph, so it carries every level for every set type, in one request per root.
JSONTag is JSON with a `<type attr="...">` annotation in front of a value. `JsonTagReader` copies string literals verbatim, turns `<object class="X" id="Y">{` into `{"@type":"X","@id":"Y",`, turns `<link>"Y"` into `{"@link":"Y"}`, drops every other annotation, and hands the result to `json_decode`. The walker resolves `@link` through an index of every `@id` in the document.
Alternatives: walk `/uuid/{id}` level by level (misses the kernzin level, one request per node); build a JSONTag parser from the npm package (a Node dependency in a PHP app). Rejected.
The walker still expands a bare reference (a node without `title`, or a link that is not in the document) through `/uuid/{id}`, so a shallow JSON response also works.

### D2: One profile per SLO set, stored in the source template's configuration
SLO sets differ in depth and naming. A profile names the discovery route, the child collections that form the levels (in descent order), the leaf types, an optional niveau filter and per-type field paths. Profiles are data on the seeded source, so an operator adds a set (for example the SO kerndoelen) without code.

| Set key | Discovery | Framework | Levels | Leaf | Authority | Level | Edition |
|---|---|---|---|---|---|---|---|
| `fo-kerndoelen` | `fo_kerndoelen/` | one per root | FoDomein, FoSubdomein, FoKernzin, FoDoelzin | FoDoelzin | slo-kerndoelen | null | SLO `status` |
| `fo-examenprogramma` | `fo_examenprogrammas/` | one per root | same | FoDoelzin | slo-eindtermen | vo | SLO `status` |
| `kerndoelen-2006-po` | `kerndoel_vakleergebied/` | one over all roots | KerndoelDomein, Kerndoel | Kerndoel, niveau `po` | slo-kerndoelen | po | 2006 |
| `kerndoelen-2006-onderbouw-vo` | `kerndoel_vakleergebied/` | one over all roots | KerndoelDomein, Kerndoel | Kerndoel, niveau `ob vo` | slo-kerndoelen | vo | 2006 |
| `examenprogramma` | `examenprogramma` | one per root | ExamenprogrammaDomein, ExamenprogrammaSubdomein, ExamenprogrammaEindterm | ExamenprogrammaEindterm | slo-eindtermen | vo | SLO `versie` |
| `leerdoelenkaarten` | `ldk_vakleergebied/` | one per root | LdkVakkern, LdkVaksubkern, LdkVakinhoud, Doelniveau | Doelniveau | other | null | none |

Descent is depth first, in the listed key order. A 2006 kerndoel that hangs both under a domein and directly under its vakleergebied is therefore placed under the domein (the more specific parent); the second sighting counts as a duplicate. The niveau filter compares SLO niveau uuids (immutable), not titles; a non-leaf node with no kept descendant is pruned.
Field paths per type pick `code`, `title` and `description` from the first non-empty candidate. Defaults: code from `prefix` then `title`; title from `title`; description from `description`. Overrides: FO kernzin and doelzin take code from `title` ("Kerndoel 19", "Doelzin 19A") and title from `description` (the goal sentence). A 2006 kerndoel takes title from `kerndoelLabel` and description from `title` (the full statement). A doelniveau takes title and description from its `Doel`.

### D3: Mapping presets are integriq `mapping` objects, applied with MappingService's copy rule
Per D7 the mapping lives in integriq's own mapping schema. The fragment seeds two `mapping` objects whose keys are learniq field names and whose values name a field of the normalised record. The mapper applies them with the rule `MappingService::executeMapping()` uses first: a value that names an input path is copied, any other value is a literal. That subset is exact for these presets, so a future Synchronization that runs the same objects through `MappingService` produces the same output. The mapper does not load `MappingService` itself, because its Twig runtime pulls in `CallService` and the object services for a pure rename.
If learniq renames a field, the preset is the one place to change.

### D4: Deterministic ids with UUID v5
Framework uuid: `v5(ns, "framework|{tenant}|{setKey}|{rootUuid or 'aggregate'}")`. Competency uuid: `v5(ns, "competency|{frameworkUuid}|{sloUuid}")`. The namespace is the constant `c2af4e70-7135-4b1b-9be9-85715348d906` in `SloCurriculumMapper`. Consequences: a re-import yields the same ids (an upsert, never a duplicate); `parentId` is computed, not looked up; two tenants never collide. SLO ids never change their data, so a revised set arrives with a new root uuid and becomes a new framework next to the old one: the 2006 kerndoelen and a later edition coexist, and the school archives the old one. Uses `symfony/uid` (already a runtime dependency; ADR-011, no new utility).

### D5: Years come only from SLO's own niveau structure
`SloYearAllocator` maps a node's niveaus to `applicableYears`, canonical per the contract:
- `configuration.yearNiveaus` maps the 39 SLO niveau uuids that name a year: groep 1 to 8, the bands groep 1-2, 3-4, 5-6 and 7-8 (two labels each), and the VO leerjaren of vmbo bb, kb, gl, tl, havo and vwo (each to `leerjaar N`). Built from `curriculum-basis@2026.7/data/niveaus.json`.
- A niveau not in the table falls back to its title: `groep N`, `groep N-M`, `<vmbo xx|havo|vwo>[,] N`.
- Anything else (`po`, `ob vo`, `fase 1`, `1F`, `A2`, `bb havo`) names a phase, a school type or a reference level, not a year, and yields nothing. There is no SLO statement that maps a fase to groepen, so none is invented.
Result: renewed and 2006 kerndoelen and examenprogramma eindtermen get `[]` (framework-wide, as the contract prescribes for end-of-phase goals); leerdoelenkaart doelniveaus get real years. Labels are unique and sorted by number.

### D6: `subjectId` only from a caller-supplied map
The contract forbids creating Course rows. `importFramework()` takes `subjectCourseIds`, a map from an SLO vakleergebied uuid or lower-cased title to a learniq Course uuid. Only top-level competencies get a `subjectId` (the rollup inherits it downward). For a per-root set the key comes from the root's `Vakleergebied`, then the root itself; for the 2006 kerndoelen each top-level vakleergebied node uses its own. A value that is not a UUID rejects the call. No map, no `subjectId`: the school links it later.

### D7: Emit contract-ready records now, write them in a follow-up
ADR-005 makes Source, Synchronization and SynchronizationContract the shape of every sync. This change ships the Source and the mappings, and emits each record with `originId` (the SLO uuid), a deterministic target `uuid` and an `originHash` (sha256 over the mapped object), which is exactly what a contract stores. The write step, a Synchronization into register `learniq`, comes after learniq's `competency-year-scope` merges: OpenRegister drops properties a schema does not declare, so writing `applicableYears` and `subjectId` today would lose them in silence. The generic synchronization engine also has no hook for a tree-shaped adapter source (`getAllObjectsFromSource()` dispatches `api`, `nextcloud-table`, `nextcloud-form`); adding one belongs with the write step.

### D8: The source template lives in register.d, not in `lib/sources.seed.json`
`lib/sources.seed.json` has no PHP reader (documented in `register.d/environments-and-promotion.json`; `git grep sources.seed.json lib` finds only comments). Register.d fragments are imported by OpenRegister on install and listed by `CatalogRegistryService`, so that is where a dormant source becomes real and visible. No row is added to the orphaned file.

### D9: The live client goes through `CallService` and the seeded source
`SloCurriculumClientHttp` finds the `slo-curriculum` source through OpenRegister's object service and calls `CallService::call()` with an `Accept` header per request (`application/jsontag` for `/tree/`). Credentials, rate limits, call logs and the circuit breaker are integriq's usual machinery. The operator sets the registered e-mail as `username` and the API key as `password` (write-only), or a credential broker `credentialRef`. DI binds the mock unless `slo.curriculum.feature_flag` is `1` or `true`, the same switch shape as `pdok.feature_flag`.

### D10: Attribution travels with every framework
CC BY 4.0 requires credit, a licence link and a note of changes. `configuration.attribution.text` holds one Dutch sentence with all three. It is appended to each framework's `description`, returned in every import result, and the framework's `sourceRef` links the SLO root. The seeded source description repeats it.

### D11: A default proficiency scale
`proficiencyLevels` is required with at least one item, and SLO defines no scale. Every imported framework gets the three-step scale from `configuration.proficiencyLevels`: `introduce` "Kennismaken", `practise` "Oefenen", `master` "Beheersen". This matches the depth vocabulary of learniq's `goal-alignment-depth` (A4: depth uses the framework's own levels). A school can edit it after import.

### D12: Skip what SLO says not to use
Nodes with `deprecated: true` are skipped (SLO moves replaced entities there). Nodes with `unreleased: true` are skipped, because SLO states their uuid may still change or disappear. Each skip is counted in the run statistics.

### D13: Guards
A run refuses more than 25,000 nodes, a depth beyond 16, more than 500 expansions or more than 20 discovery pages, with a `SloCurriculumException` naming the limit. SLO's largest set is well inside these; the guards stop a runaway graph, not a real import.

## Declarative-vs-imperative decision
| Behaviour | Path | Rationale |
|---|---|---|
| Fetch, flatten and map SLO's tree | imperative (PHP adapter) | External integration: ADR-031's first named exception. |
| Field names on the learniq side | declarative (seeded `mapping` objects) | Data, operator-visible, per D7 of learniq round 1. |
| Set profiles, attribution, scale, year table | declarative (source `configuration`) | Data on the seeded source. |
No lifecycle, aggregation, calculation or notification is added.

## Nextcloud integration
- Controllers: none.
- Services: `SloCurriculumSourceAdapter`, `SloCurriculumPresetRegistry`, `SloCurriculumTreeWalker`, `SloCurriculumNodeReader`, `SloCurriculumMapper`, `SloYearAllocator`, `JsonTagReader`; clients `SloCurriculumClientMock`, `SloCurriculumClientHttp` behind abstract `SloCurriculumClient`.
- DI: one `registerService(SloCurriculumClient::class, ...)` in `Application::register()`; everything else autowires.
- Config: `IAppConfig` key `integriq` / `slo.curriculum.feature_flag` (default `0`).
- OpenRegister: `OCA\OpenRegister\Service\ObjectService::findAll()` to resolve the seeded source (live flavour only).

## Security considerations
- No endpoint, no route, no user input reaches a URL: paths are built from profile data and SLO uuids.
- The API key is a credential: it is never seeded, never logged, and lives on the source's write-only `password` or in the credential broker. The browser token in SLO's JavaScript is not used (discovery finding 1).
- No personal data: SLO's curriculum is public reference data. The fixture and the fragment contain no names, e-mail addresses or identifiers of people; a test asserts it.
- Logs carry counts, the set key, the root uuid and the client flavour only.
- JSONTag parsing never evaluates anything; malformed input throws `SloCurriculumException`.

## File structure
```
lib/
  Adapters/Slo/
    SloCurriculumClient.php            abstract client
    SloCurriculumClientMock.php        dormant default, serves the recorded fixture
    SloCurriculumClientHttp.php        live, CallService + seeded source
    JsonTagReader.php
    SloCurriculumTreeWalker.php         traversal only
    SloCurriculumNodeReader.php         code, title, description, niveaus, subject keys of one entity
    SloYearAllocator.php
    SloCurriculumMapper.php
    SloCurriculumPresetRegistry.php
    slo-curriculum-recorded.json       recorded fixture (real SLO data)
  Sources/Slo/SloCurriculumSourceAdapter.php
  Exception/SloCurriculumException.php
  Exception/UnknownSloCurriculumSetException.php
  Settings/register.d/slo-curriculum-source.json
  AppInfo/Application.php              (+ one DI binding)
  Service/CatalogRegistryService.php   (+ one category override)
tests/Unit/
  Adapters/Slo/*Test.php
  Sources/Slo/SloCurriculumSourceAdapterTest.php
  Settings/SloCurriculumSourceTemplateTest.php
  Service/CatalogRegistryServiceTest.php (+ one assertion)
  AppInfo/ApplicationBindsSloCurriculumClientTest.php (the DI binding runs)
```

## Seed data
This change adds no OpenRegister schema. It seeds three integriq objects in `register.d/slo-curriculum-source.json`:
- `source` `slo-curriculum`: "SLO curriculum (open data)", type `api`, location `https://opendata.slo.nl/curriculum/api/v1`, auth `basic`, `isEnabled: false`, with the configuration above.
- `mapping` `slo-curriculum-framework-mapping`: name, sourceAuthority, sourceRef, edition, level, description, proficiencyLevels, tenant_id.
- `mapping` `slo-curriculum-competency-mapping`: frameworkId, parentId, code, title, description, order, applicableYears, subjectId, tenant_id.

Example output for the fixture's "Kerndoelen burgerschap" (FO, release 2026.8): one framework (`sourceAuthority` slo-kerndoelen, edition "definitief concept"), 3 domeinen, 6 kernzinnen and 10 doelzinnen as competencies, all with `applicableYears: []`.

## Fixture provenance
`lib/Adapters/Slo/slo-curriculum-recorded.json` holds real SLO records, not a captured HTTP exchange: without a registered key every JSON call answers 401. Each response body is built from the dataset files at the tags above, in the shape the server source builds it (`/tree/` as JSONTag with `<object class id>` and `<link>`; collections as `{data, page, count, root, @isPartOf}` with `shortInfo` fields). Trimmed for size, and stated in the file's `$comment`: collections list the roots the tests use (with the real `count`), and links outside the imported levels (uitwerkingen, illustraties, syllabi, tags, `replaces`) are left out.
To re-record once a key exists (placeholders, not values):
```
curl -H 'Accept: application/jsontag' --user 'YOUR_EMAIL:YOUR_API_KEY' \
  https://opendata.slo.nl/curriculum/api/v1/tree/612afa33-c49c-4b12-a7d1-7e44f2d69d25
```
and the same for the other keys in the fixture's `responses` map.

## Risks / trade-offs
- [The fixture shape is reconstructed from source code] → the reader and walker accept both annotated JSONTag and plain JSON-LD; re-record after key registration; the discovery route and parsing live in one class each.
- [SLO changes a profile's structure] → profiles are data; the walker ignores unknown keys and reports empty results in the statistics rather than guessing.
- [Edition labels are SLO statuses for FO sets] → identity keys on the root uuid, so labels never cause an overwrite.
- [Learniq renames a contract field before merge] → edit the mapping preset only.

## Migration plan
Deploy: nothing to migrate; OpenRegister imports the fragment on the next `occ app:enable` or upgrade. To go live: register a key at SLO, set it on the source, enable the source, set `slo.curriculum.feature_flag` to `1`. Rollback: revert the merge commit; the source was dormant and nothing was written.

## Open questions
- Who holds the SLO key: Conduction centrally (like the chain certifications in D9 of learniq round 1) or each school?
- Learniq could add `slo-leerdoelen` to `sourceAuthority`; until then leerdoelenkaarten import as `other`.
