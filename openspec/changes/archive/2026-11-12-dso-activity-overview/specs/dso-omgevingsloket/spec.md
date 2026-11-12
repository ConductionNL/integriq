# dso-omgevingsloket Delta: dso-activity-overview

## ADDED Requirements

### Requirement: A case type entry of a mapping row may name its variant (REQ-DSO-013)

A `dso_activity_mapping` case type entry MAY carry `variant`, the name of the route of that case type a case from the activity takes. The mapper MUST hand the variant on with the case type in `mappedActivities[].caseTypes`. An entry without a variant MUST map as before.

#### Scenario: A row maps an activity to a case type route
- **GIVEN** a row mapping "Kappen van een houtopstand" to Omgevingsvergunning with variant "regulier"
- **WHEN** a verzoek with that activity is mapped
- **THEN** its case type entry carries `variant: "regulier"`

### Requirement: Administrators read the activities with when each last arrived (REQ-DSO-014)

`GET /api/admin/dso-activities` MUST be admin only and MUST return `results`: every `dso_activity_mapping` row with `lastReceivedAt`, the newest `receivedAt` among the latest 500 verzoeken whose mapped activity matched that row (null when none did), followed by one row per unmapped activity of REQ-DSO-012 with `unmapped: true`, `waiting` (how many verzoeken carried it) and `lastReceivedAt` (when it was last seen).

#### Scenario: The overview
- **GIVEN** two rows, one matched by a verzoek received on 6 October, and one unmapped activity seen once
- **WHEN** an administrator reads the overview
- **THEN** the first row has `lastReceivedAt` 6 October, the second null, and the last row is the unmapped activity with `waiting: 1`
