---
status: implemented
retrofit: true
---

# Job Management

## Purpose

OpenConnector provides a Jobs section in its SPA where administrators can
configure scheduled background jobs — cron-like tasks that invoke a PHP
`jobClass` at a configured `interval`. Jobs can be enabled/disabled, run on
demand, and tested via dry runs. Their execution history is tracked in
`job_log` records. This spec covers the observable browser UI behaviour and
the backend job-execution internals (covered by PHPUnit/Newman). It is a
retrofit spec.

## Requirements

### REQ-JOB-UI-001: Job Management UI

OpenConnector MUST provide a Jobs section in its SPA where administrators can
browse, create, edit, enable/disable, and manually trigger background jobs.

#### Scenario: jobs list page mounts and shows content

- GIVEN an authenticated admin visits the openconnector app
- WHEN they navigate to the Jobs section via the sidebar nav or direct URL `/apps/openconnector/jobs`
- THEN the Jobs index page renders inside the main content area with content visible

#### Scenario: add job button opens the creation modal

- GIVEN the Jobs index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the job creation form

#### Scenario: job logs sub-page mounts

- GIVEN an authenticated admin
- WHEN they navigate to the Job logs page at `/apps/openconnector/jobs/logs`
- THEN the page mounts and renders the main content area

### REQ-JOB-001: Job execution runtime

The system SHALL execute registered jobs via the Nextcloud background-job
framework, resolving the `jobClass`, passing `arguments` (decoded from JSON),
and persisting a `job_log` on every run. A job disabled via `isEnabled: false`
SHALL be skipped by the scheduler. The run-on-demand endpoint `POST
/api/jobs/{id}/run` SHALL execute the job synchronously and return the log
entry.

@e2e exclude backend job-execution runtime — covered by PHPUnit/Newman, not browser UI

#### Scenario: disabled job is skipped by the scheduler

- **GIVEN** a job with `isEnabled: false`
- **WHEN** the scheduler tick runs
- **THEN** the job is not executed and no `job_log` is written

#### Scenario: scheduled job runs and persists a job_log

- **GIVEN** a job with a valid `jobClass` and `interval`
- **WHEN** its next execution time is reached
- **THEN** the job runs and a `job_log` record is persisted with start time,
  duration, and status

#### Scenario: run-on-demand returns the resulting log

- **GIVEN** `POST /api/jobs/{id}/run` is called by an admin
- **WHEN** the job completes
- **THEN** the response includes the resulting `job_log` entry

### REQ-JOB-002: Job dry-run (test mode)

The system SHALL provide a `POST /api/jobs/{id}/test` endpoint that executes
the job in dry-run mode (no side-effects committed) and returns a preview log.

@e2e exclude backend job dry-run — covered by PHPUnit/Newman, not browser UI

#### Scenario: dry-run writes no persistent changes

- **GIVEN** `POST /api/jobs/{id}/test` is called
- **WHEN** the job runs in test mode
- **THEN** no persistent changes are written to the data store and a preview
  result is returned
