# rostering-planninq-target Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- rostering-adapter-targets-planninq

## Purpose
The dormant rostering adapter delivers a school timetable from Zermelo, Untis, Xedule or TimeEdit into planninq, the fleet's timetable owner (decision D10). Presets map each vendor's lesson onto planninq's timetable session shape (planninq contract v1), a per-source target configuration links school codes to fleet ids, and delivery runs through planninq's typed event (ADR-041). Learniq asks for a delivery through integriq's own typed event. This supersedes requirement REQ-002 of `integriq-adapter-rostering-imports`, which mapped onto learniq's rostering-import payload.

## ADDED Requirements

### Requirement: One mapping preset per rostering source (REQ-001)
The system MUST ship `lib/roster-mapping-presets.seed.json` with a preset for each of `roster-zermelo`, `roster-untis-oneroster`, `roster-xedule` and `roster-timeedit`, each targeting `planninq.timetableSession` at contract version 1 and naming at least `externalRef`, `subject`, `startsAt` and `endsAt`. `RosterMappingPresetRegistry` MUST return a preset by source id and refuse an unknown id.

#### Scenario: Every source has a preset
- GIVEN the seed file
- WHEN the registry loads it
- THEN it holds exactly the four rostering source ids
- AND each preset names `externalRef`, `subject`, `startsAt` and `endsAt`

#### Scenario: An unknown source is refused
- GIVEN the registry
- WHEN a preset for `roster-unknown` is requested
- THEN it throws

### Requirement: The mapper turns a vendor lesson into a planninq session (REQ-002)
`RosterSessionMapper` MUST apply a preset to one vendor record: `text` takes a scalar or the first element of a list, `datetime` turns Unix seconds or a parseable date into ISO 8601, `status` yields `cancelled` for a value in `cancelledValues` and `scheduled` otherwise. It MUST fill `cohortId` and `teacherUserId` only from the target configuration's maps, never from the vendor record. The adapter's output MUST NOT carry the former learniq payload names `startTime` or `endTime`.

#### Scenario: A Zermelo appointment becomes a planninq session
- GIVEN the Zermelo preset and an appointment with `start` in Unix seconds, `subjects` `["wi"]`, `groups` `["3a"]` and `cancelled` true
- WHEN it is mapped with a group map `{"3a": "cohort-1"}`
- THEN the session has `startsAt` in ISO 8601, `subject` `wi`, `groupReference` `3a`, `cohortId` `cohort-1` and `status` `cancelled`

#### Scenario: Each mock source maps to the same session shape
- GIVEN the mock client
- WHEN lessons are imported for each of the four sources
- THEN every session carries `externalRef`, `subject`, `startsAt` and `endsAt`
- AND none carries `startTime` or `endTime`

### Requirement: A per-source target configuration links school codes to fleet ids (REQ-003)
`RosterTargetConfiguration` MUST name planninq as the target and read `roster.<systemId>.group_map` and `roster.<systemId>.teacher_map` from integriq app config as JSON objects, treating an unreadable value as empty. Maps passed with a delivery MUST be merged over the stored maps, the delivery's entries winning.

#### Scenario: A delivery's map wins over the stored one
- GIVEN a stored group map `{"3a": "old", "3b": "b"}`
- WHEN a delivery passes `{"3a": "new"}`
- THEN the effective map is `{"3a": "new", "3b": "b"}`

### Requirement: Delivery goes to planninq through planninq's typed event (REQ-004)
`PlanninqTimetableTarget` MUST look `OCA\Planninq\Event\TimetableUpsertRequestedEvent` up by name, construct it with `sourceApp: integriq`, the source id, the sessions and a correlation id, dispatch it, and return planninq's result. When the class is absent or the event comes back unhandled it MUST fail closed with `planninq-absent`, never reporting a delivered timetable.

#### Scenario: Planninq answers the delivery
- GIVEN planninq's event class exists and a listener answers it
- WHEN two sessions are delivered for `roster-zermelo`
- THEN the result is planninq's upsert result

#### Scenario: Planninq is not installed
- GIVEN planninq's event class does not exist
- WHEN a delivery is attempted
- THEN it fails with `planninq-absent`

### Requirement: Learniq asks for a delivery through integriq's typed event (REQ-005)
Integriq MUST publish `OCA\Integriq\Event\RosterImportRequestedEvent` (contract version 1) and a listener that runs `RosterDeliveryService::deliver()` for the event's source, options and correlation id, and always answers: `status: delivered` with planninq's result, or `status: failed` with an `errorCode` (`unknown-source`, `fetch-failed`, `planninq-absent`, `planninq-refused`).

#### Scenario: A learniq timetable-import job lands in planninq
- GIVEN planninq is installed
- WHEN learniq dispatches `RosterImportRequestedEvent` for `roster-zermelo`
- THEN the result has `status: delivered`, `target: planninq`, `flavour: mock` and planninq's counts

#### Scenario: An unknown source is answered, not dropped
- GIVEN any instance
- WHEN the event names `roster-unknown`
- THEN it is handled with `status: failed` and `errorCode: unknown-source`

### Requirement: The adapter can be constructed on an instance (REQ-006)
`Application` MUST bind the abstract `RosterImportClient` so `RosterImportSourceAdapter` and `RosterDeliveryService` resolve from the container. Until a live binding exists it MUST resolve to `RosterImportClientMock`, whatever `roster.import.feature_flag` says.

#### Scenario: The client binding resolves to the mock
- GIVEN the application's service registrations
- WHEN `RosterImportClient` is resolved
- THEN a `RosterImportClientMock` is returned

## Non-Functional Requirements

- **Performance:** one delivery reads one batch and dispatches one event; no network in mock mode.
- **Accessibility:** no user interface.
- **Internationalization:** no new user-facing strings in the interface; error texts are English log and API text.

## Acceptance Criteria

- The four presets map the four mock batches onto planninq sessions.
- A delivery without planninq fails closed with `planninq-absent`.
- Learniq's event is always answered.

## Notes
The vendor field names are representative, as in #2166; none of the four systems offers a credential-free sandbox.
