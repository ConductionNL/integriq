# openconnector-notifications

## ADDED Requirements

### Requirement: Operational schemas declare notification rules

Integriq's operational schemas SHALL declare `x-openregister-notifications`
rules so the OpenRegister notification engine dispatches notifications on
call/job/sync failures, exhausted event-delivery retries, and overdue scheduled
jobs. Every rule SHALL use a trigger type that works today (`created` + filter,
`threshold`, or `scheduled`), reference only properties that exist on its schema,
and provide both `nl` and `en` subject strings. Where a rule tells a group, it
SHALL name the alert group through the `ConnectionAlertRecipientResolver`
expression recipient: the group an administrator named in the app setting
`connection_alert_group`, or `admin` when none is named (Ruben's decision of
29 Sep 2026). No rule SHALL name the `openconnector-ops` group, which exists on
no instance.

#### Scenario: Failed API call notifies the triggering user and the alert group

- **WHEN** a `call_log` record is created with `statusCode` >= 400
- **THEN** the engine dispatches an `nc-notification` to the `userId` on the record and to the members of the alert group (`admin` unless an administrator named another)
- **AND** the subject is rendered in the user's locale (nl/en) including the status code and source id
- @e2e exclude requires a notification to actually FIRE, which needs an exhausted retry chain or a failed API call against a real upstream. Nothing in the e2e seed produces one, and gate-18 checks the ADR-031 declaration rather than the delivery

#### Scenario: Event delivery retries exhausted notifies the alert group

- **WHEN** an `event_message` record's `retryCount` reaches or exceeds the threshold (5)
- **THEN** the engine dispatches an `nc-notification` to the members of the alert group
- @e2e exclude requires a notification to actually FIRE, which needs an exhausted retry chain or a failed API call against a real upstream. Nothing in the e2e seed produces one, and gate-18 checks the ADR-031 declaration rather than the delivery

#### Scenario: Disabled-by-default rules do not fire until opted in

- **WHEN** a `synchronization_log` row is created (rule `sync-failed`, `enabled: false`)
- **THEN** no notification is dispatched unless an admin has enabled the rule via override-only user-config prefs
- @e2e exclude requires a notification to actually FIRE, which needs an exhausted retry chain or a failed API call against a real upstream. Nothing in the e2e seed produces one, and gate-18 checks the ADR-031 declaration rather than the delivery
