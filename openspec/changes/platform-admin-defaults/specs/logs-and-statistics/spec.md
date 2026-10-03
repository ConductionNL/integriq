# logs-and-statistics Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- platform-admin-defaults

## Purpose

An administrator sets integriq's log retention defaults on the Nextcloud admin
page, every service applies the same values, and changed values can be applied
to logs that already exist. Matrix row `integriq:plt-admin-settings`.

## ADDED Requirements

### Requirement: One resolver supplies retention, with the schemas' defaults (REQ-ADEF-001)

Integriq MUST read log retention through one resolver for success logs, call
logs, job logs, synchronization logs, synchronization contract logs, event
messages and execution traces. When no value is configured, the resolver MUST
return the default declared in that schema's `x-openregister-archival` block.
A unit test MUST fail when a resolver default and a schema default differ.

#### Scenario: an unset synchronization log retention is 30 days
- GIVEN an instance with no `retention` app config key
- WHEN a synchronization run writes its log
- THEN the log's `expires` is 30 days after its creation, not 3
- @e2e exclude a computed expiry; covered by PHPUnit on SynchronizationService with the resolver

#### Scenario: the defaults cannot drift again
- GIVEN a resolver default changed to 14 days for call logs while `call_log` still declares `P30D`
- WHEN the unit suite runs
- THEN the drift test fails naming `call_log`
- @e2e exclude a build guard; covered by PHPUnit

### Requirement: An administrator sets retention on the admin page (REQ-ADEF-002)

The integriq section of the Nextcloud admin settings MUST show each retention
value in days or hours with its default beside it, and MUST save changes
through an admin-only settings route. A non-administrator MUST NOT be able to
read or change these values. Saved values MUST apply to logs written after the
save.

#### Scenario: an administrator keeps call logs for 90 days
- GIVEN an administrator on the integriq admin settings
- WHEN they set call log retention to 90 days and save
- THEN the page shows 90 days after a reload, and a call made afterwards is logged with an expiry 90 days out
- e2e: tests/e2e/admin-retention-defaults.spec.ts

#### Scenario: a user cannot change retention
- GIVEN a signed-in user who is not an administrator
- WHEN they send an update to the settings route
- THEN the response is 403 and the stored value is unchanged
- @e2e exclude an authorization refusal; covered by PHPUnit on SettingsController

### Requirement: Changed retention can be applied to existing logs (REQ-ADEF-003)

The admin page MUST offer "Apply to existing logs", which MUST queue a
background job that recomputes `expires` from `created` for the existing log
objects of each changed kind, in bounded pages, and MUST show its progress.
Integriq MUST NOT update the legacy `openconnector_*` tables for this.

#### Scenario: existing call logs follow the new value
- GIVEN 10,000 call logs created under a 30-day retention and a new value of 90 days
- WHEN the administrator chooses "Apply to existing logs"
- THEN a job is queued, the page shows its progress, and afterwards each call log's `expires` is 90 days after its creation
- @e2e exclude a background job over many objects; covered by PHPUnit on RetentionRebaseJob and a Playwright check that the button queues it
