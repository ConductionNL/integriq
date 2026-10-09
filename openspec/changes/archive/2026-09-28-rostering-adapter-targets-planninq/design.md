# Design: rostering-adapter-targets-planninq

## Architecture Overview

```
learniq timetable-import job
  └─dispatchTyped─▶ RosterImportRequestedEvent ──▶ RosterImportRequestedListener
                                                        │
                                                        ▼
                                              RosterDeliveryService::deliver(systemId, options, correlationId)
                                                ├─ RosterImportSourceAdapter::importLessons(systemId, options)
                                                │     ├─ RosterImportClient (mock: vendor-shaped records per source)
                                                │     ├─ RosterMappingPresetRegistry::get(systemId)
                                                │     ├─ RosterTargetConfiguration::forSystem(systemId, options)
                                                │     └─ RosterSessionMapper::map(preset, record, maps)
                                                └─ PlanninqTimetableTarget::deliver(systemId, sessions, correlationId)
                                                      └─dispatchTyped─▶ OCA\Planninq\Event\TimetableUpsertRequestedEvent
```

## Decisions

### D1: The client returns vendor-shaped records; presets do the mapping
#2166's mock returned an already-normalised record, so the four sources were indistinguishable and the only mapping was a hard-coded method. Moving vendor knowledge into seed presets means a live binding only has to fetch, a captured payload corrects a preset without code, and every preset is exercised by the mock. The learniq timetable presets (`DataMappingProfile` seeds for Zermelo, Untis, Xedule, TimeEdit) supplied the vendor field names, so the corpus's research carries over; the target side changes from learniq `Session` fields to planninq session fields.

Rejected: keep the normalised client contract and map once to planninq. It works, but it leaves "mapping presets" as a single method and hides which vendor field feeds which planninq field.

### D2: ADR-041 events in both directions
Integriq delivers by dispatching planninq's event; learniq asks by dispatching integriq's event. No class of another app is imported, so gate 27 stays green and each app runs without the others. Rejected: learniq calling `RosterDeliveryService` through the container (cross-container resolution, which ADR-041 rules out), and any HTTP route between the apps (a server-to-server call carries no session).

### D3: School codes are resolved in integriq, never read from the vendor as fleet ids
The learniq presets wrote the vendor's group code into `cohortId`. Planninq keeps codes and ids apart (`groupReference` and `cohortId`), so the mapper writes the code to `groupReference` and fills `cohortId` only from a configured map. A lesson with an unmapped code still lands and is still readable by group code.

### D4: Dormant still delivers the mock batch
With `roster.import.feature_flag` off the mock answers, as in #2166. The delivery result names `flavour` and `active` so a consumer can say "example timetable" instead of implying a live import.

### D5: DI binding to the mock only
There is no live client class, so the binding does not branch on the flag. When a live binding lands it adds the branch, as `SloCurriculumClient` does.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Vendor to planninq field mapping | Declarative seed (`roster-mapping-presets.seed.json`) applied by one mapper | Data, correctable without code. |
| Delivery to planninq | Imperative (`PlanninqTimetableTarget`) | External-integration exception: a cross-app command per ADR-041. |
| Answering learniq | Imperative listener | Same. |

## API Design
No HTTP routes. The interfaces are the two events; `contract.md` is authoritative.

## Database Changes
None. The target configuration lives in `IAppConfig`.

## Nextcloud Integration
- Services: `RosterMappingPresetRegistry`, `RosterSessionMapper`, `RosterTargetConfiguration` (`IAppConfig`), `PlanninqTimetableTarget` (`IEventDispatcher`), `RosterDeliveryService`.
- Events: `OCA\Integriq\Event\RosterImportRequestedEvent` (new, published); `OCA\Planninq\Event\TimetableUpsertRequestedEvent` (consumed by name).
- Listener: `RosterImportRequestedListener`, registered with `addServiceListener` like `DeliveryRequestedListener`.
- DI: `registerService(RosterImportClient::class, ... RosterImportClientMock)`.

## Security Considerations
- The listener is reachable only from in-process server code. It accepts four known source ids and two string maps; anything else is ignored or refused with `unknown-source`.
- Planninq writes the rows with its own rules (validation, upsert key); integriq sends no OpenRegister metadata.
- No pupil personal data: a lesson carries group, teacher and room codes, as #2166 recorded.

## File Structure
```
lib/
  Adapters/Roster/RosterImportClient.php          (docblock: vendor-shaped records)
  Adapters/Roster/RosterImportClientMock.php      (four vendor batches)
  Sources/Roster/RosterImportSourceAdapter.php    (maps through preset + configuration)
  Sources/Roster/RosterMappingPresetRegistry.php  (new)
  Sources/Roster/RosterSessionMapper.php          (new)
  Sources/Roster/RosterTargetConfiguration.php    (new)
  Sources/Roster/PlanninqTimetableTarget.php      (new)
  Sources/Roster/RosterDeliveryService.php        (new)
  Sources/Roster/RosterDeliveryException.php      (new)
  Event/RosterImportRequestedEvent.php            (new)
  EventListener/RosterImportRequestedListener.php (new)
  AppInfo/Application.php                         (client binding, listener)
  roster-mapping-presets.seed.json                (new)
tests/
  fixtures/roster/fixture-roster-batch.json       (four sources)
  stubs/planninq/TimetableUpsertRequestedEvent.php (verbatim copy of planninq #685's class)
  Unit/Sources/Roster/*Test.php (listener covered in RosterDeliveryServiceTest)
docs/features/rostering-to-planninq.md            (new)
```

## Seed Data
No OpenRegister schema is introduced or changed. The seed is the preset file:

| Source | externalRef | subject | startsAt / endsAt | groupReference | teacherReference | roomReference | status |
|---|---|---|---|---|---|---|---|
| `roster-zermelo` | `appointmentInstance` | `subjects` (first) | `start` / `end` (Unix) | `groups` (first) | `teachers` (first) | `locations` (first) | `cancelled` true |
| `roster-untis-oneroster` | `id` | `faechId` | `startDateTime` / `endDateTime` | `klasseId` | `lehrerId` | `raumId` | `code` = `cancelled` |
| `roster-xedule` | `eventId` | `activityName` | `startMoment` / `endMoment` | `groupCode` | `teacherCode` | `locationName` | `status` = `cancelled` |
| `roster-timeedit` | `activityId` | `activityTitle` | `beginTime` / `endTime` | `resourceGroup` | `staffId` | `roomName` | `cancelled` true |

## Migration Plan
None needed: nothing is stored. Rollback is a revert.
