# Tasks: connectors-job-board-multiposting

Kind: code. Size M. Half for humaniq `hiring-multiposting` (row humaniq `hir-multiposting`).

## Implementation tasks

### Task 1: Per-item source and mapping references
- **spec_ref**: `openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-a-flow-step-can-pick-its-source-and-mapping-per-item-req-jbc-001`
- **files**: `lib/Flow/SourceCallNode.php`, `lib/Flow/ApplyMappingNode.php`, their node config schemas, `tests/Unit/Flow/`
- **acceptance_criteria**:
  - GIVEN items for `werk-nl` and `linkedin` WHEN the step uses `jobboard-{{ json.channel }}` THEN each item is sent through its own source
  - GIVEN an item whose board has no source WHEN the step runs with `onError: continue` THEN that item is an error item naming the source and the others succeed
  - GIVEN a plain reference WHEN an existing flow runs THEN its output is unchanged
- [x] Implement
  - `FlowTemplate::renderReference()` renders a reference per item and reads both `{{ channel }}` and `{{ json.channel }}`. `SourceCallNode` resolves a plain reference once before any call, as before; a templated one is resolved per distinct name in the call pass. An unresolved item is an error item ("No source configured") under `continue` and refuses the step before any request otherwise. `ApplyMappingNode` does the same through `findMapping()`, once per distinct name.
- [x] Test (PHPUnit: `SourceCallNodeTest`, `ApplyMappingNodeTest`, `FlowTemplateTest`)

### Task 2: Board templates and mappings
- **spec_ref**: `openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-werknl-linkedin-and-indeed-are-job-board-templates-req-jbc-002`
- **files**: `lib/Settings/register.d/jobboard-werk-nl-source.json`, `jobboard-linkedin-source.json`, `jobboard-indeed-source.json`, mappings in `lib/Settings/integriq_seed_data.json`
- **acceptance_criteria**:
  - GIVEN humaniq's seeded vacancy WHEN it is mapped for each board THEN the output matches that board's recorded example
- [ ] Implement
- [ ] Test (PHPUnit per mapping)
  > 2026-11-14, partly built: `jobboard-linkedin-source.json` (the dormant LinkedIn template: simpleJobPostings batch create, OAuth client secret by broker reference, the partner programme named) with the `jobboard-linkedin` mapping, and `jobboard-indeed-source.json` with the `jobboard-indeed` mapping to one Indeed feed job. Recorded examples under `tests/fixtures/jobboards/` (the seeded vacancy as the flow passes it, the LinkedIn request and answer, the Indeed feed job); `JobBoardSetsTest` maps humaniq's seeded vacancy through the real MappingService and compares. Left: werk.nl, because UWV publishes no employer posting API or format to record an example from (the boxes stay unticked until it has one), and the Indeed template's "published in feed" answer, which belongs with the feed of task 3. The catalogue golden digests move with the two new fragments.
  > 2026-11-16, built: the Indeed template's answer. `jobboard-indeed-source.json` now holds the dormant source `jobboard-indeed` of the new source type `feed`, which `CallService::dispatchInProcess()` answers through `Service\Call\FeedSource`: a 200 JSON `{"status": "published in feed", "link": <the source's location, the feed URL>}`, without a request leaving the instance, logged like any call (design D2). PHPUnit: `FeedSourceTest`, `CallServiceFeedSourceTest` (the real CallService: answer, async answer, a disabled feed source refused), `JobBoardSetsTest::testTheIndeedTemplateIsADormantFeedSource`; the catalogue golden digests move with the new source. Still left: werk.nl, for the reason above, so the boxes stay unticked. Note: humaniq's `Vacature plaatsen` flow names the sources `werk-nl`/`linkedin`/`indeed` and the mappings `humaniq-vacancy-<code>`, while this change names both `jobboard-<code>`; one side needs to follow the other.

### Task 3: Vacancy feed endpoint
- **spec_ref**: `openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-werknl-linkedin-and-indeed-are-job-board-templates-req-jbc-002`
- **files**: endpoint configuration in the seed data, `lib/Service/EndpointService.php` (only if the XML rendering needs a hook)
- **acceptance_criteria**:
  - GIVEN two published vacancies of which one lists `indeed` WHEN the Indeed feed is requested THEN it lists that one only, without applicant data
- [x] Implement
- [ ] Test (Newman)
  > 2026-11-15, built: the endpoint `jobboard-indeed-feed` (`GET jobfeeds/indeed.xml`, anonymous with a 60-a-minute throttle, over `humaniq/Vacancy` with the fixed filters `status: gepubliceerd` and `channels: indeed`) in `jobboard-indeed-source.json`, with the rules `jobboard-indeed-feed-item` (the `jobboard-indeed` mapping over each vacancy, so no applicant data leaves) and `jobboard-indeed-feed-envelope` (the `jobboard-indeed-feed` mapping: Indeed's `<source>` root with the publisher and one `<job>` per vacancy), the same declarative shape as the ORI public endpoints. The XML hook is in `EndpointsController::inRequestedFormat()`, not `EndpointService`: a path ending in `.xml` answers as XML without an XML `Accept` header, because a crawler sends none. PHPUnit: `JobBoardSetsTest::testThePublishedVacancyIsRenderedAsTheRecordedIndeedFeed` (the seeded vacancy rendered as `tests/fixtures/jobboards/indeed-feed.xml`), `::testTheFeedEndpointListsPublishedIndeedVacanciesOnly`, `EndpointsControllerHandlePathTest::testAnXmlPathIsAnsweredAsXml`. Left: the Newman run against a live instance. humaniq's Vacancy has no `channels` field yet (humaniq `hiring-multiposting` adds it); until then the `channels` filter finds nothing.
  > 2026-11-17, written: `tests/postman/integriq-job-board-feed.postman_collection.json` seeds two published humaniq vacancies (one listing `indeed`, one only `linkedin`), requests the feed anonymously without an Accept header, and checks the 200, the XML content type, the `<source>` root, no applicant elements, the indeed vacancy listed and the linkedin one left out; it deletes the vacancies again. humaniq's Vacancy now has `channels` on development. Without humaniq installed the two listing checks are skipped, not passed. Not run here (no live instance), so the box stays unticked until a Newman run reads green.

### Task 4: Docs and strings
- **spec_ref**: `openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-werknl-linkedin-and-indeed-are-job-board-templates-req-jbc-002`
- **files**: `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the docs WHEN an administrator connects a board THEN the partner programme and the feed URL registration are named
- [x] Implement
  > 2026-11-15, built: `docs/features/job-board-connectors.md` (per-item board references, connecting LinkedIn through its partner programme, the Indeed feed URL and its registration, werk.nl not shipped and why), linked from `docs/features/README.md`. No new user-facing strings: the change adds no screen. Left: the docs build.
- [x] Test (docs build)
  - `npm ci --legacy-peer-deps && npm run build` in `docs/` on 2026-11-21: the build succeeds, all 10 AI-baseline checks pass, and the build reports no warning for `docs/features/job-board-connectors.md`.

## Verification
- [x] `openspec validate connectors-job-board-multiposting --strict` passes
  - OpenSpec 2.1.1, 2026-11-21: the change validates ("is valid") under `--strict`.
- [ ] `composer check:strict` once before push; PHPUnit and Newman exit codes read
