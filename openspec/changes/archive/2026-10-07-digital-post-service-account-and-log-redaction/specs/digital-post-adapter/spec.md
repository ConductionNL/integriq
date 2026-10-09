## ADDED Requirements

### Requirement: Digital post is stored as its service account (REQ-DPA-007)

integriq MUST store and update every `digitalPostMessage` as the account of the one consumer with `authorizationType` `digital-post`, through OpenRegister's `ObjectService::runAs()`, and MUST restore the previous user afterwards, also on a failure. This holds for an interactive send, a send with no user session, and the status poll. The person who asked MUST stay in `requestedBy`. The `digitalPostMessage` and `outbound_message` schemas MUST grant create, read and update to the group `digitale-post-verzenders` (the letter and its outbound log row), and no other account, and the repair step MUST create that group and enrol the consumer's account. When there is no consumer, more than one, no account, an unknown or disabled account, or an account without the rights, integriq MUST refuse the send with code `no_service_account`, MUST NOT call the provider, MUST log the reason and MUST notify the administrators. No digital post write may run with RBAC off. An administrator MUST be able to pick the account in the admin settings, and a setup check MUST warn when a digital post source exists and the account is not usable. Approved by Ruben on 2026-10-07.

#### Scenario: A letter sent without a user session is stored as the service account

- **GIVEN** a digital post consumer whose account `digitalepost` is enabled and in `digitale-post-verzenders`
- **AND** nobody is signed in
- **WHEN** a sibling app dispatches `DigitalPostSendRequestedEvent`
- **THEN** the letter is stored and sent as `digitalepost`
- **AND** no user is signed in afterwards
- @e2e exclude background path without a browser, proven live on idp-live and by PHPUnit

#### Scenario: An interactive send keeps the person who asked

- **GIVEN** the same consumer and `behandelaar1` signed in
- **WHEN** dossiq sends a letter for `behandelaar1`
- **THEN** the letter is stored as `digitalepost` with `requestedBy` `behandelaar1`
- **AND** `behandelaar1` is signed in again afterwards
- @e2e exclude backend path, covered by PHPUnit and the live run

#### Scenario: A missing or disabled account refuses the send out loud

- **GIVEN** no digital post consumer, or one whose account is disabled
- **WHEN** a sibling app dispatches `DigitalPostSendRequestedEvent`
- **THEN** the refusal reads code `no_service_account`
- **AND** the provider is not called and nothing is stored
- **AND** the reason is logged and the administrators get a notification
- @e2e exclude backend path, covered by PHPUnit and the live run

#### Scenario: The setup check names an unusable account

- **GIVEN** a source of type `digital-post` and no usable digital post account
- **WHEN** an administrator opens the overview
- **THEN** the setup check warns that digital post cannot be stored
- @e2e exclude setup check, covered by PHPUnit and occ setupchecks live
