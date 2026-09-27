# connection-run-monitoring Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- observability-connection-run-summary

## Purpose

An administrator sees per source, per day which pulls ran and what they
delivered, restarts a failed pull with one click, and is warned when a source
or synchronization passes a threshold of failures. Matrix rows
`opencatalogi:int-connector-monitor` (integriq's half) and
`integriq:obs-threshold-counts`.

## ADDED Requirements

### Requirement: Every run records its source and what started it (REQ-CRUN-001)

A synchronization run record MUST carry the `sourceId` of the synchronization's
source at the moment the run started, and `triggeredBy` of `cron`, `manual` or
`rerun`.

#### Scenario: a scheduled pull records its source
- GIVEN a synchronization reading source `KVK`
- WHEN the scheduler starts it
- THEN the run record holds the `KVK` source id and `triggeredBy` `cron`
- @e2e exclude a background run; covered by PHPUnit on SynchronizationRunProgressService

### Requirement: A source shows its pulls per day (REQ-CRUN-002)

`GET /api/sources/{id}/run-summary` MUST return one row per day in the
requested window of at most 31 days, with runs, succeeded, failed, found,
created, updated and invalid, and MUST require the action `source.logs`. The
source detail page MUST show this table and a list of the source's runs.

#### Scenario: an administrator reads last week's pulls
- GIVEN a source with fourteen runs over the last seven days, two of them failed
- WHEN an administrator opens the source detail page
- THEN the "Pulls per day" table shows seven rows whose run counts add up to fourteen and whose failed counts add up to two
- e2e: tests/e2e/connection-run-summary.spec.ts

#### Scenario: a window longer than 31 days is refused
- GIVEN an administrator's browser requesting a summary from January to June
- WHEN the route is called
- THEN the answer is 400 naming the 31-day limit
- @e2e exclude an API guard; covered by PHPUnit on SourcesController

### Requirement: A failed pull restarts with one click (REQ-CRUN-003)

A failed run MUST offer "Run again", which MUST start the same synchronization
without a dialog, record `triggeredBy` `rerun`, and show a notice linking to the
new run. It MUST require the action `synchronization.run`.

#### Scenario: a failed Monday pull is restarted
- GIVEN a failed run on the source detail page
- WHEN the administrator chooses "Run again"
- THEN a new run starts for the same synchronization and the notice links to it
- e2e: tests/e2e/connection-run-summary.spec.ts

### Requirement: Thresholds per source and synchronization open an alert (REQ-CRUN-004)

A source and a synchronization MUST accept `alertThresholds` for failed calls,
failed runs and invalid objects, each a count over a window in minutes. Every
five minutes integriq MUST count against each threshold and MUST open one
`connection_alert` when a count passes it, and MUST clear that alert when the
count falls back. While an alert is open for a rule and subject, integriq MUST
NOT open a second one.

#### Scenario: a source fails more than ten times in an hour
- GIVEN a source with a failed-calls threshold of 10 in 60 minutes
- WHEN eleven calls to it fail within the hour
- THEN one open `connection_alert` exists for that source and rule with count 11
- @e2e exclude a background job; covered by PHPUnit on ConnectionThresholdJob

#### Scenario: an alert does not repeat while open
- GIVEN an open alert for that source and rule
- WHEN the job runs again and the count is still above the threshold
- THEN no second alert is opened
- @e2e exclude a background job; covered by PHPUnit on ConnectionThresholdJob

### Requirement: An opened alert notifies the operations group (REQ-CRUN-005)

The `connection_alert` schema MUST declare an `x-openregister-notifications`
rule with a `created` trigger that notifies the `openconnector-ops` group,
naming the subject, the rule, the count and the threshold. Integriq MUST NOT
call the notification manager directly for this. The alerts page MUST list
alerts with their state.

#### Scenario: the operations group is told
- GIVEN a member of `openconnector-ops`
- WHEN an alert opens for source `KVK`
- THEN they receive a Nextcloud notification naming `KVK`, the rule and the count, and the alerts page lists the alert as open
- e2e: tests/e2e/connection-run-summary.spec.ts
