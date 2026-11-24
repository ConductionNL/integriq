# app-shell-and-logs-ui Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- observability-log-filters

## Purpose

An administrator on any of integriq's log pages narrows the list by status,
by source, endpoint, job or synchronization, and by time, from controls on the
page, and shares the filtered view as a link. Matrix row
`integriq:obs-filter-logs`.

## ADDED Requirements

### Requirement: Every log page declares its filter controls (REQ-LOGF-001)

The manifest MUST declare `filterControls` on `SourceLogs`, `EndpointLogs`,
`JobLogs`, `SynchronizationLogs`, `CloudEventLogs` and `Traces`. Each MUST
include a status control where the schema has a status, a subject picker for
the page's owning object, and a date range over the page's time column. The
three `call_log` pages MUST also include a direction control. The keys MUST be
the same query keys the "View logs" row actions send.

#### Scenario: an administrator finds last night's failed source calls
- GIVEN an administrator on the source logs page
- WHEN they pick status "Server error", source "BRP Haal Centraal" and a range from yesterday 18:00 to today 08:00
- THEN only outbound calls to that source with a status of 500 or higher in that window are listed
- e2e: tests/e2e/observability-log-filters.spec.ts

#### Scenario: a row action and a picker agree
- GIVEN an administrator who chose "View source logs" on a source
- WHEN the source logs page opens
- THEN the source picker shows that source and the list is filtered by it
- e2e: tests/e2e/observability-log-filters.spec.ts

#### Scenario: traces filter by entry point and status
- GIVEN an administrator on the traces page
- WHEN they pick entry point `sync` and status `failed`
- THEN only failed synchronization traces are listed
- e2e: tests/e2e/observability-log-filters.spec.ts

### Requirement: A filtered view is a link (REQ-LOGF-002)

Changing a filter control MUST write the filter to the page address, and
opening that address MUST restore the same controls and the same list.

#### Scenario: a colleague opens the same view
- GIVEN an administrator who filtered the job logs by level `error` and a job
- WHEN they send the address to a colleague who opens it
- THEN the colleague sees the same controls set and the same rows
- e2e: tests/e2e/observability-log-filters.spec.ts

### Requirement: Source and endpoint logs show only their own direction (REQ-LOGF-003)

`SourceLogs` MUST be scoped to `call_log.direction` `outbound` and
`EndpointLogs` to `inbound` through the page's `filter`, which a query
parameter cannot override.

#### Scenario: an inbound call is not a source log
- GIVEN an inbound call to an endpoint and an outbound call to a source in the call log
- WHEN an administrator opens the source logs page
- THEN only the outbound call is listed
- e2e: tests/e2e/observability-log-filters.spec.ts
