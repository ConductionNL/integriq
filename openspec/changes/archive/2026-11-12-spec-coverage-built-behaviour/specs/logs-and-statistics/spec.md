# logs-and-statistics Specification

## ADDED Requirements

### Requirement: The dashboard shows traffic over a chosen period (REQ-SCB-010)

Integriq's start page MUST be a dashboard with a date range. It MUST show lead counts of synchronization runs, dead letters, event deliveries and source circuit breakers by status. It MUST show volume charts over time for the chosen range.

#### Scenario: an administrator looks at last week
- GIVEN an administrator on the dashboard
- WHEN they set the date range to the last seven days
- THEN the counts and the volume charts cover those seven days
- @e2e exclude retroactive requirement for a built page; a Playwright test is a follow-up

### Requirement: One page shows operational health (REQ-SCB-011)

Integriq MUST offer an operational health page under Reports. It MUST bring together the status of synchronization runs, execution traces, event deliveries and dead letters in one view, so an administrator sees how all integrations are doing without opening each log.

#### Scenario: an administrator checks how things are going
- GIVEN failed runs and pending deliveries in the logs
- WHEN an administrator opens the operational health page
- THEN the page shows the failed runs and the pending deliveries next to the other statuses
- @e2e exclude retroactive requirement for a built page; a Playwright test is a follow-up
