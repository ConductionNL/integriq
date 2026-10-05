## ADDED Requirements

### Requirement: Every integriq sender asks the opt-out list before it sends (REQ-OOA-001)

Before integriq sends a message to a person, it MUST ask `OptOutRegistry` for a decision. This MUST hold for the NotifyNL SMS send, the intake reply, the digital post send, and a delivery request that names a personal recipient. A decision of `send: false` MUST stop the send and MUST be reported to the caller with the decision code. This implements REQ-OSI-004 of `outbound-sender-identity-and-deliverability` and the fleet contract in ConductionNL/hydra `openspec/changes/opt-out-before-send` (REQ-CMO-001, REQ-CMO-002). Approved by Ruben on 2026-10-05.

#### Scenario: An SMS to an opted-out number is refused

- **GIVEN** an instance-wide opt-out for `+31612345678`
- **WHEN** a user posts a `service` SMS to `0612345678` on `/api/notifynl/messages`
- **THEN** no provider send happens
- **AND** the answer is 409 with `error: opted-out`
- @e2e exclude provider send path, covered by PHPUnit

#### Scenario: An intake reply to an opted-out sender is refused

- **GIVEN** an instance-wide opt-out for the sender of an intake message
- **WHEN** a handler replies to that message
- **THEN** the adapter is not called
- **AND** the reply result names the refusal
- @e2e exclude adapter path, covered by PHPUnit

#### Scenario: A digital post case update to an opted-out recipient is refused

- **GIVEN** a case opt-out for a recipient on case `Z-2026-001`
- **WHEN** `DigitalPostSendRequestedEvent` arrives with category `case-update` and that case
- **THEN** the provider is not called
- **AND** the event is handled with refusal code `opted-out`
- @e2e exclude provider path, covered by PHPUnit

#### Scenario: A digital post besluit goes out despite an opt-out

- **GIVEN** an instance-wide opt-out for a recipient
- **WHEN** `DigitalPostSendRequestedEvent` arrives with category `besluit`
- **THEN** the provider sends the letter without an unsubscribe link
- **AND** the opt-out log holds an `override` entry
- @e2e exclude provider path, covered by PHPUnit

### Requirement: Sibling apps ask through a public decision event (REQ-OOA-002)

integriq MUST publish `OCA\Integriq\Event\OutboundSendDecisionRequestedEvent` and MUST answer it synchronously through a listener. The event MUST accept a batch of recipients with one channel and one category, and MUST return one decision per recipient with `send`, `overridden`, `code`, `reason` and the unsubscribe material. The listener MUST set the event handled only when it answered every recipient. When it cannot answer, it MUST leave the event unhandled. The decision MUST read integriq's own table and MUST NOT depend on the acting user.

#### Scenario: A batch is answered per recipient

- **GIVEN** opt-outs for two of five addresses
- **WHEN** OpenRegister dispatches the event with the five addresses and category `service`
- **THEN** the event is handled
- **AND** two decisions read `send: false` with code `opted-out` and three read `send: true` with code `allowed`
- @e2e exclude event path, covered by PHPUnit

#### Scenario: The answer does not depend on who asks

- **GIVEN** an opt-out for an address
- **WHEN** the event is dispatched from a background job with no user session
- **THEN** the decision reads `send: false`
- @e2e exclude background path, covered by PHPUnit

#### Scenario: A listener failure leaves the event unhandled

- **GIVEN** the opt-out table cannot be read
- **WHEN** a sibling app dispatches the event
- **THEN** the event is not handled
- @e2e exclude fault injection, covered by PHPUnit

### Requirement: Sibling apps record wishes through a public change event (REQ-OOA-003)

integriq MUST publish `OCA\Integriq\Event\OptOutChangeRequestedEvent` and MUST record each request in `integriq_opt_outs`. The event MUST accept the states `opted-out` and `opted-in`, the scopes `instance`, `channel`, `case` and `list`, and for `opted-in` a lawful basis and evidence. A request with a `legacyRef` that is already recorded MUST NOT write again and MUST return the existing record id. Every recorded change MUST write a `change` entry to the opt-out log.

#### Scenario: A STOP keyword becomes a channel opt-out

- **WHEN** pipelinq dispatches the event with address `+31612345678`, state `opted-out`, scope `channel`, channel `sms`
- **THEN** the table holds one `opted-out` row for that number on `sms`
- **AND** the log holds one `change` entry with source app `pipelinq`
- @e2e exclude event path, covered by PHPUnit

#### Scenario: A START keyword lifts the channel opt-out

- **GIVEN** an `opted-out` row for `+31612345678` on `sms`
- **WHEN** pipelinq dispatches the event with state `opted-in` on the same scope and channel
- **THEN** the row reads `opted-in`
- **AND** the log keeps the earlier `opted-out` change
- @e2e exclude event path, covered by PHPUnit

#### Scenario: A migrated record is written once

- **GIVEN** a row recorded with `legacyRef` `pq-123`
- **WHEN** the event arrives again with `legacyRef` `pq-123`
- **THEN** the table gains no row and the event returns the existing id
- @e2e exclude repair path, covered by PHPUnit

### Requirement: The exempt categories are a fixed floor (REQ-OOA-004)

integriq MUST treat `besluit`, `statutory`, `account` and `security` as exempt. It MUST accept `ontvangstbevestiging` and `invordering` as aliases of `statutory`. A value of `outbound.protected_categories` MUST NOT remove a floor category and MUST NOT add a category outside the floor. integriq MUST treat an empty or unknown category as `service` and MUST log a warning naming the source app.

#### Scenario: Config cannot remove besluit

- **GIVEN** `outbound.protected_categories` holds `["statutory"]`
- **WHEN** integriq decides on a `besluit` for an opted-out address
- **THEN** the decision reads `send: true` and `overridden: true`
- @e2e exclude config path, covered by PHPUnit

#### Scenario: Config cannot make reminders exempt

- **GIVEN** `outbound.protected_categories` holds `["reminder"]`
- **WHEN** integriq decides on a `reminder` for an opted-out address
- **THEN** the decision reads `send: false`
- **AND** integriq logs a warning about the ignored value
- @e2e exclude config path, covered by PHPUnit

### Requirement: Marketing needs recorded consent (REQ-OOA-005)

When a request sets `requiresConsent`, integriq MUST allow a recipient only when an `opted-in` row matches on the channel, or on the list when a list ref is given. A channel row MUST NOT open a list, and a list row MUST NOT open a channel. The lawful basis `imported` MUST NOT permit a send. The basis `soft-opt-in` MUST permit a send only when its evidence records that an objection was offered. Any matching `opted-out` row MUST win over an `opted-in` row.

#### Scenario: A list consent does not open the channel

- **GIVEN** an `opted-in` row on list `nieuws` for `jan@example.nl`
- **WHEN** pipelinq asks for a `marketing` email to `jan@example.nl` with no list ref
- **THEN** the decision reads `send: false` with code `no-consent`
- @e2e exclude backend decision, covered by PHPUnit

#### Scenario: An imported consent does not permit a send

- **GIVEN** an `opted-in` row with lawful basis `imported`
- **WHEN** pipelinq asks for a `marketing` email to that address
- **THEN** the decision reads `send: false` with code `no-consent`
- @e2e exclude backend decision, covered by PHPUnit

### Requirement: The unsubscribe link fits the channel and changes nothing on GET (REQ-OOA-006)

For a non-exempt decision, integriq MUST return unsubscribe material: a link, a one-click URL, an SMS text and the email headers. The SMS text MUST be at most 50 characters. Following the link with GET MUST show a confirmation and MUST NOT write. A POST to the same URL MUST write the opt-out and MUST answer 200 without a redirect. Tokens of version 1 and 2 MUST keep working as case stops. For an exempt decision, integriq MUST return no unsubscribe material. This implements REQ-OSI-005.

#### Scenario: A GET does not unsubscribe

- **GIVEN** a valid version 3 link for a channel stop
- **WHEN** the link is fetched with GET
- **THEN** the page asks for confirmation
- **AND** the table gains no row

#### Scenario: A one-click POST unsubscribes

- **GIVEN** a valid version 3 link for a channel stop
- **WHEN** a mail provider posts `List-Unsubscribe=One-Click` to it
- **THEN** the answer is 200 with no redirect
- **AND** the table holds an `opted-out` row for that address and channel
- @e2e exclude provider-side POST, covered by PHPUnit

#### Scenario: The SMS text is short

- **WHEN** integriq decides on a `service` SMS for an address with no opt-out
- **THEN** the returned SMS text is at most 50 characters
- @e2e exclude backend decision, covered by PHPUnit

#### Scenario: An old case link still works

- **GIVEN** a version 2 link minted before this change
- **WHEN** the recipient confirms on the page
- **THEN** the table holds a case opt-out for that address and case

### Requirement: Suppressions and overrides are logged (REQ-OOA-007)

integriq MUST write an append-only entry to `integriq_opt_out_log` for every suppressed recipient, every exempt override and every recorded change. Each entry MUST name the source app, category, channel and correlation id. For allowed recipients integriq MUST write one count entry per batch. The log MUST be readable by administrators only.

#### Scenario: A batch with one suppression

- **GIVEN** a batch of 500 addresses of which 1 opted out
- **WHEN** a sibling app asks with correlation id `c-1`
- **THEN** the log holds one `suppressed` entry and one `allowed-count` entry of 499 for `c-1`
- @e2e exclude backend decision, covered by PHPUnit

### Requirement: A digital post recipient is never stored as a plain BSN (REQ-OOA-008)

When the channel is digital post and the recipient is a BSN, integriq MUST store and match the opt-out under `bsn:` and an HMAC-SHA256 of the BSN with an instance secret. The plain BSN MUST NOT be written to the opt-out table, the log or the unsubscribe token.

#### Scenario: A Berichtenbox opt-out holds no BSN

- **GIVEN** a digital post `case-update` to BSN `999993653`
- **WHEN** the recipient follows the link and confirms
- **THEN** the table row's address starts with `bsn:` and does not contain `999993653`
- @e2e exclude digital post flow, covered by PHPUnit
