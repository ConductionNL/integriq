## ADDED Requirements

### Requirement: An opt-out stops only its own purpose (REQ-OOA-011)

integriq MUST store a purpose on every opt-out and consent row and MUST read it when it decides. The purpose `marketing` MUST cover the category `marketing` only. The purpose `service` MUST cover `case-update`, `reminder` and `service`. An empty purpose MUST cover every non-exempt category. A row without a purpose, including every row written before this requirement, MUST be read as an empty purpose. A purpose given as a category name MUST be normalised to its group, and an unknown purpose MUST be stored as empty. The dedupe key MUST include the purpose when it is set. A version 3 unsubscribe link MUST carry the purpose of the message it was sent with. Choosing to stop everything MUST write an instance opt-out with an empty purpose. The exempt categories and `reply` MUST keep their rules whatever the purpose.

#### Scenario: A marketing unsubscribe leaves reminders running

- **GIVEN** a channel opt-out for `piet@example.org` on `email` with purpose `marketing`
- **WHEN** a sender asks for a `marketing` email to that address
- **THEN** the decision reads `send: false` with code `opted-out`
- **AND** a `reminder` email to the same address reads `send: true` with code `allowed`
- @e2e exclude event path, covered by PHPUnit and the live proof

#### Scenario: A service opt-out stops case updates and reminders, not marketing

- **GIVEN** a channel opt-out for `piet@example.org` on `email` with purpose `service`
- **WHEN** a sender asks for a `case-update` and a `reminder` email
- **THEN** both read `send: false` with code `opted-out`
- **AND** a `marketing` email with recorded consent reads `send: true`
- @e2e exclude event path, covered by PHPUnit

#### Scenario: Stop everything stops every non-exempt message and no besluit

- **GIVEN** an instance opt-out for `piet@example.org` with an empty purpose
- **WHEN** a sender asks for a `marketing`, a `reminder` and a `besluit` email
- **THEN** `marketing` and `reminder` read `send: false`
- **AND** `besluit` reads `send: true` with code `exempt-override`
- @e2e exclude event path, covered by PHPUnit and the live proof

#### Scenario: A row written before purposes stops everything

- **GIVEN** an instance opt-out row with no purpose, written by the earlier unsubscribe link
- **WHEN** a sender asks for a `marketing` and a `reminder` email to that address
- **THEN** both read `send: false` with code `opted-out`
- @e2e exclude event path, covered by PHPUnit and the live proof

#### Scenario: A marketing and an everything opt-out are two rows

- **GIVEN** a channel opt-out on `email` with purpose `marketing`
- **WHEN** the same address opts out on the same channel with an empty purpose
- **THEN** the table holds two rows for that address and channel
- **AND** when pipelinq records a marketing opt-in for that address, only the marketing row changes
- @e2e exclude event path, covered by PHPUnit

#### Scenario: The link in a marketing mail stops marketing

- **GIVEN** an allowed `marketing` email to `piet@example.org`
- **WHEN** Piet posts `choice=this` to the link in that mail
- **THEN** the table holds a row with purpose `marketing`
- **AND** the page says newsletters and campaigns stop, and statutory notices still arrive
- @e2e exclude public link path, covered by PHPUnit and the live proof

### Requirement: A probe answers without writing (REQ-OOA-012)

`OutboundSendDecisionRequestedEvent` MUST accept a `probe` flag as its last constructor argument, default false, and MUST expose it through `isProbe()`. A probe MUST return the same `send`, `code` and `reason` as a real ask with the same input. A probe MUST return no unsubscribe material. A probe MUST NOT write any opt-out log entry and MUST NOT store a short link. A real ask MUST keep logging as before.

#### Scenario: A probe on an opted-out address writes no log row

- **GIVEN** a channel opt-out for `+31612345678` on `sms`
- **WHEN** pipelinq dispatches the event with that address, category `service` and `probe: true`
- **THEN** the decision reads `send: false` with code `opted-out`
- **AND** the opt-out log holds no new entry
- @e2e exclude event path, covered by PHPUnit and the live proof

#### Scenario: A probe on an allowed address mints nothing

- **GIVEN** no opt-out for `+31687654321`
- **WHEN** the event is dispatched for that address on `sms` with `probe: true`
- **THEN** the decision reads `send: true` with `unsubscribe: null`
- **AND** the short link table and the opt-out log hold no new row
- @e2e exclude event path, covered by PHPUnit

#### Scenario: A real ask still logs

- **GIVEN** a channel opt-out for `+31612345678` on `sms`
- **WHEN** the event is dispatched without `probe`
- **THEN** the opt-out log holds one new `suppressed` entry
- @e2e exclude event path, covered by PHPUnit and the live proof
