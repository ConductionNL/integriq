---
kind: code
---

# Proposal: slo-kerndoelen-import

## Summary
Add a dormant integriq adapter that reads SLO's open curriculum data (opendata.slo.nl, CC BY 4.0) and turns one SLO set into one learniq `CompetencyFramework` plus its `Competency` goal tree. It covers the renewed kerndoelen (funderend onderwijs), the 2006 kerndoelen for primary and lower secondary, the examenprogramma's, and the leerdoelenkaarten (vakinhouden and doelen). Every imported goal keeps its SLO code, its place in the tree, the school years SLO itself names, and a stable id, so a school gets the national goals as data instead of typing them in.

## Motivation
Learniq's round 2 curriculum ask (recon `B-curriculum-goals-coverage.md`) is "which goals does this lesson cover, per subject and year, and is the whole set covered". Learniq already has the goal tree for that (`CompetencyFramework`, `Competency`, whose schema names "SLO kerndoelen/eindtermen" as a source), but the only way to fill it today is by hand. SLO publishes the whole Dutch primary and secondary curriculum as open data with a REST API, and it is the one sector source with an open, attribution-only licence (recon section 3).

Competitor evidence: SERA Datawijzer ships a bulk import of SLO domains, kerndoelen and learning goals into the school's leerlijnen (`_round1/compare/proposed-rows.md:222`, row L-new-7, https://sera.nl/datawijzer/leerlijnen/). Placement: rung 1, data only; the goals land in learniq's existing competency pages, no new page or menu item.

Decisions: D3 and D7 (every external connection is an integriq adapter; integriq owns the source, the mapping and the job), D16 (the SLO importer is built in wave 1, in parallel with learniq's competency schema work, against field names agreed up front in `CONTRACT-competency-fields.md`).

## Capabilities

### New Capabilities
- `slo-curriculum-import`: read one SLO curriculum set and emit it as a learniq goal tree.

### Modified Capabilities
- None.

## Affected Projects
- [x] Project: `integriq` — new dormant `slo-curriculum` source template, two seeded mapping presets, an SLO client (mock and live), a JSONTag reader, a tree walker, a year allocator and a mapper that emits learniq-shaped records.
- [ ] Project: `learniq` — no code change. Consumes the emitted `CompetencyFramework` and `Competency` records; the two fields this change fills (`applicableYears`, `subjectId`) are added by learniq's `competency-year-scope` change.

## Scope

### In Scope
- A dormant `slo-curriculum` source template in `lib/Settings/register.d/`, `isEnabled: false`, Basic auth, with the set profiles, the attribution text and the default proficiency scale in its `configuration`.
- Two integriq `mapping` presets (framework and competency) that name learniq's field names, per the contract.
- An abstract `SloCurriculumClient` with a mock default (serves a recorded fixture, no network) and a live flavour through integriq's `CallService` against the seeded source.
- A JSONTag reader for SLO's `/tree/{id}` responses, a profile-driven tree walker, a year allocator (SLO niveau to `groep N` / `leerjaar N`), and a mapper with deterministic UUIDs so a re-import updates instead of duplicating.
- A source adapter facade: `describeSets()`, `discoverRoots()`, `importSet()`.
- A recorded fixture of real SLO data (release 2026.7) so every test runs offline, and a catalogue entry.
- The CC BY 4.0 attribution on every imported framework.

### Out of Scope
- Writing the records into learniq. That is a Synchronization in a follow-up change, after learniq's `competency-year-scope` merges: OpenRegister drops unknown properties in silence, so writing `applicableYears` and `subjectId` before learniq declares them would lose them.
- MBO kwalificatiedossiers (SBB): licence unverified, deferred per the plan.
- The referentiekader, ERK and syllabus datasets: not requested this round.
- Creating learniq `Course` rows for subjects. The contract forbids it; `subjectId` is only filled from a caller-supplied map.

## Approach
Follow the dormant-adapter pattern merged today for lvs, rostering and swv (abstract client, mock default, source facade), and the register.d source-template pattern of `ideal-ouderbijdrage` and `endoflife-date`. SLO's data is a tree with a variable depth per set, so a profile per set names which child collections form the levels. Per root, the adapter reads SLO's full tree in one call, walks it, allocates years only where SLO's own niveau structure names one, and maps each node through the seeded mapping preset. Ids are UUID v5 over tenant, framework and SLO uuid; SLO uuids are immutable, so an edition change arrives as a new framework and never overwrites the old one.

## New Dependencies
- External service: SLO curriculum REST API, `https://opendata.slo.nl/curriculum/api/v1/`. Free, but programmatic access needs a registered e-mail and API key (Basic auth). The source ships dormant until an operator enters one.
- No new Composer or npm packages; UUID v5 uses the existing `symfony/uid`.

## Impact
- New files under `lib/Adapters/Slo/`, `lib/Sources/Slo/`, `lib/Exception/`, `tests/Unit/Adapters/Slo/`, `tests/Unit/Sources/Slo/`, `tests/Unit/Settings/`.
- One new register.d fragment (`slo-curriculum-source.json`), no schema changes.
- `lib/AppInfo/Application.php`: one DI binding (mock unless `slo.curriculum.feature_flag` is on).
- `tests/Unit/Service/CatalogRegistryServiceTest.php`: one assertion for the new catalogue slug.
- No routes, no controllers, no frontend.

## Cross-Project Dependencies
Emits records for learniq's `CompetencyFramework` and `Competency` schemas. The field names come from `CONTRACT-competency-fields.md` (lane r2-curriculum, 2026-09-27): `applicableYears` and `subjectId` are added by learniq `competency-year-scope`; every other field exists today. The mapping presets are the single place to change if a name moves.

## Risks

### Risk 1: The fixture is real data in the documented format, not a captured keyed response
**Severity:** Medium — **Mitigation:** without a registered key every JSON call answers 401, so no live body could be captured. The fixture is SLO release 2026.7 data (git tag `2026.7` of `slonl/curriculum-*`) serialised exactly as the REST server source (`slonl/curriculum-rest-api@master`) builds it. design.md holds the one command that re-records it once a key exists.

### Risk 2: SLO's published per-entity query hides the kernzin level of the renewed kerndoelen
**Severity:** Medium — **Mitigation:** the `FoSet` and `FoDomein` typed queries select `FoDoelzin` and `FoSubdomein` but not `FoKernzin`, while every renewed kerndoelenset in release 2026.7 hangs its doelzinnen under a kernzin. The adapter reads `/tree/{id}` (the raw graph) instead of `/uuid/{id}`, so the level is present. The walker also expands a shallow node through `/uuid/{id}` when a tree arrives without children.

### Risk 3: Edition labels are not a jaarversie for every set
**Severity:** Low — **Mitigation:** framework identity keys on the immutable SLO root uuid (`sourceRef`), so two editions can never overwrite each other. `edition` carries the profile's year where one is known (2006) and SLO's own `status` otherwise.

## Rollback Strategy
Revert the merge commit. The source ships `isEnabled: false`, nothing calls the adapter outside its tests, and no learniq data is written, so removal leaves nothing behind.

## Open Questions
- Which account registers the SLO API key: a Conduction-wide key held in the credential broker, or one per school? Not a code blocker; the source template takes either.
- Learniq's `sourceAuthority` enum has no value for SLO leerdoelenkaarten; this change uses `other` with the SLO uri in `sourceRef`. A `slo-leerdoelen` value would be a small learniq follow-up.
