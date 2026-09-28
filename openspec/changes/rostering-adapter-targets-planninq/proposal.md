---
kind: code
depends_on: []
---

# Proposal: rostering-adapter-targets-planninq

## Summary
The dormant rostering adapter (integriq #2166) stops shaping lessons for learniq's `Session` and delivers them into planninq instead. Four mapping presets turn a Zermelo, Untis, Xedule or TimeEdit lesson into planninq's timetable session shape (contract v1 of planninq change `school-timetable-target`). A per-source target configuration links the school's group and teacher codes to fleet ids. A delivery service hands the batch to planninq through planninq's own typed event and reports what planninq did with it. Learniq's `timetable-import` job kind keeps working: learniq asks integriq for a delivery through a new typed event, and the lessons land in planninq.

## Motivation
Decision D10 (learniq round 1, `market-intelligence/learniq/_round1/compare/decisions.md`, Ruben, 2026-09-27): planninq owns the timetable, the integriq adapter delivers into planninq, learniq reads sessions from there. D10 names this exact change: "The rostering adapter merged as integriq #2166 needs its target repointed from learniq to planninq." D25 says it is built now.

Today `RosterImportSourceAdapter::toRosteringImportPayload()` maps onto field names from learniq's rostering-import `DataExchangeJob` payload (`startTime`, `roomLabel`), and nothing delivers the result anywhere: there is no DI binding for `RosterImportClient`, so the adapter cannot even be constructed on an instance. The four learniq timetable presets (Zermelo, Untis, Xedule, TimeEdit) map vendor fields onto learniq `Session` fields (`cohortId`, `title`, `location`), and the school's group code is written straight into `cohortId`.

Corpus: M3 row `I11` (`_round1/compare/M3-integrations.md`), a live Zermelo, Untis or Xedule timetable koppeling at every VO, MBO and HE incumbent surveyed (vo-las#11.7, mbo-he-sis#11.7); section (b) recommends planninq own the rostering contract. Vendor surfaces as cited in #2166: `docs.zportal.nl` (Zermelo appointments), `developer.untis.com` (WebUntis), the SURF DPIA of 8 July 2025 (Xedule Connect), `developer.timeedit.com`.

## Affected Projects
- [x] Project: `integriq`: roster mapping presets and their registry, the session mapper, per-source target configuration, the planninq target (ADR-041 dispatch), the delivery service, `RosterImportRequestedEvent` and its listener, the missing DI binding, docs.
- [ ] Project: `planninq`: producer of the target contract (`school-timetable-target`, planninq #685).
- [ ] Project: `learniq`: consumer of `RosterImportRequestedEvent` (`sessions-from-planninq`).

## Scope

### In Scope
- `lib/roster-mapping-presets.seed.json`: one preset per rostering source row, vendor field to planninq session field, with the transforms a vendor needs (first element of a list, Unix seconds or text to ISO 8601, a cancellation flag or code to `status`).
- `RosterMappingPresetRegistry` and `RosterSessionMapper`.
- `RosterTargetConfiguration`: the target (planninq) and the per-source `groupMap` and `teacherMap` in integriq app config, overridable per delivery.
- The mock client returns each source's records in that source's own field names, so each preset is exercised; the contract fixture grows to four sources.
- `RosterImportSourceAdapter::importLessons()` returns planninq session rows.
- `PlanninqTimetableTarget`: looks up `OCA\Planninq\Event\TimetableUpsertRequestedEvent` by name, dispatches it, reads the result, fails closed when planninq is absent or silent.
- `RosterDeliveryService` and `OCA\Integriq\Event\RosterImportRequestedEvent` with its listener, the door learniq's `timetable-import` job uses.
- DI binding `RosterImportClient` to the mock (no live binding exists yet; D9 keeps the adapters dormant until certification).
- A docs page for operators and integrators.

### Out of Scope
- A live HTTP binding to any rostering system (unchanged from #2166; each needs its own institution onboarding).
- Learniq's side: the job handler that dispatches `RosterImportRequestedEvent` and the pages that read planninq are learniq change `sessions-from-planninq`.
- Integriq's native exchange job runner (`learniq-exchange-jobs-native`, lane r3-exchange, not merged): its contract lists `timetable-import` with no handler and points at this lane. `RosterDeliveryService` is the handler that runner can call once it lands.
- A screen to edit the group and teacher maps. They are app config values for now.

## Approach
Presets are data, applied by one mapper; the registry mirrors `MigrationMappingPresetRegistry`. Delivery follows ADR-041 in both directions: integriq dispatches planninq's event to deliver, and learniq dispatches integriq's event to ask for a delivery. Neither side imports the other's classes. Details in design.md.

## New Dependencies
None.

## Impact
- Changed: `lib/Adapters/Roster/RosterImportClient.php` (docblock contract), `RosterImportClientMock.php`, `lib/Sources/Roster/RosterImportSourceAdapter.php`, `tests/fixtures/roster/fixture-roster-batch.json`, `lib/AppInfo/Application.php`.
- New: `lib/roster-mapping-presets.seed.json`, `lib/Sources/Roster/{RosterMappingPresetRegistry,RosterSessionMapper,RosterTargetConfiguration,PlanninqTimetableTarget,RosterDeliveryService}.php`, `lib/Event/RosterImportRequestedEvent.php`, `lib/EventListener/RosterImportRequestedListener.php`, `docs/features/rostering-to-planninq.md`, tests.
- The adapter's output field names change. Nothing on development consumed the old names (the learniq handler calls an integriq route that does not exist).

## Cross-Project Dependencies
Builds against planninq's contract v1 (`openspec/changes/school-timetable-target/contract.md` in planninq #685). Learniq builds against this change's `contract.md`. All three PRs can land in any order: a missing event class is reported as "planninq is not installed" or "integriq is not installed", never as a delivered timetable.

## Risks

### Risk 1: Vendor field names are representative, not captured live
**Severity:** Medium. **Mitigation:** the field names stay in one seed file per source, the same caveat #2166 recorded; a captured payload corrects the preset without touching code.

### Risk 2: Overlap with the native exchange job runner
**Severity:** Medium. **Mitigation:** this change adds no job schema fields and no runner; `RosterDeliveryService` is a plain service the runner can call. Named in both PR bodies so the landing orders them.

### Risk 3: Unmapped group codes
**Severity:** Low. **Mitigation:** a lesson whose group code has no cohort in the map still lands with its `groupReference`; planninq reads by either.

## Rollback Strategy
Revert the merge commit. The Source rows stay disabled; learniq's event lookup finds nothing and reports integriq as unable to deliver.

## Open Questions
None.
