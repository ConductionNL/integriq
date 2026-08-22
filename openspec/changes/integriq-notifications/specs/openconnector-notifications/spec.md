# openconnector-notifications

## ADDED Requirements

### Requirement: Operational schemas declare notification rules

Integriq's operational schemas SHALL declare `x-openregister-notifications`
rules so the OpenRegister notification engine dispatches notifications on
call/job/sync failures, exhausted event-delivery retries, and overdue scheduled
jobs. Every rule SHALL use a trigger type that works today (`created` + filter,
`threshold`, or `scheduled`), reference only properties that exist on its schema,
and provide both `nl` and `en` subject strings.

#### Scenario: Failed API call notifies the triggering user and ops group

- **WHEN** a `call_log` record is created with `statusCode` >= 400
- **THEN** the engine dispatches an `nc-notification` to the `userId` on the record and to the `openconnector-ops` group
- **AND** the subject is rendered in the user's locale (nl/en) including the status code and source id

#### Scenario: Event delivery retries exhausted notifies ops

- **WHEN** an `event_message` record's `retryCount` reaches or exceeds the threshold (5)
- **THEN** the engine dispatches an `nc-notification` to the `openconnector-ops` group

#### Scenario: Disabled-by-default rules do not fire until opted in

- **WHEN** a `synchronization_log` row is created (rule `sync-failed`, `enabled: false`)
- **THEN** no notification is dispatched unless an admin has enabled the rule via override-only user-config prefs
