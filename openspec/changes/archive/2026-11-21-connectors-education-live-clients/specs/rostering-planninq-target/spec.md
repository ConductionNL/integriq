# rostering-planninq-target Specification

## ADDED Requirements

### Requirement: A live rostering client reads lessons from the school's scheduling package (REQ-007)

Integriq MUST ship a live `RosterImportClient` for Zermelo and bind it when the source's live flag is on. The client MUST read the lessons for the requested period from the school's own Zermelo address with a brokered token. With the flag off, the mock MUST stay bound.

#### Scenario: a school's Zermelo week reaches planninq
- GIVEN a Zermelo source with the live flag on and a brokered token
- WHEN learniq asks for the timetable of next week
- THEN the lessons Zermelo returns arrive in planninq as sessions

#### Scenario: the flag off keeps the mock
- GIVEN the same source with the live flag off
- WHEN the import runs
- THEN the mock answers and Zermelo is not called
