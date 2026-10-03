# intake-channels Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- intake-channels-beyond-mail
- messaging-intake-routing-rules

## Purpose

An inbound channel message opens a case only through a routing rule. This
delta makes the rule something an administrator writes on a page, lets a held
message be routed again once its rule exists, and adds an Outlook action that
sends the open message into the mail intake. Rows `dossiq:1.9`,
`integriq:msg-routing`, `integriq:msg-teams`, `integriq:msg-form-intake` and
`integriq:msg-public-space`.

## ADDED Requirements

### Requirement: An administrator writes a routing rule on the intake routing page (REQ-IRE-001)

The "Intake routing" page MUST let a principal holding the `intake.rules`
action create, edit, enable, disable, reorder and delete a routing rule.
Every create and edit MUST be saved through `POST` or `PUT
/api/intake/routing-rules`, so the rule is validated against its channel and
its case type. The channel MUST be picked from the channels
`GET /api/intake/channels` returns, and the case type from OpenRegister's
schemas. A refusal from the save MUST be shown on the field it names, and the
dialog MUST stay open.

#### Scenario: an administrator routes Teams messages to a case type
- GIVEN an administrator on the "Intake routing" page and no rule for the `teams` channel
- WHEN they add a rule, pick the `teams` channel and the case type "Algemene vraag", and save
- THEN the rule appears in the list, and the next Teams message opens an "Algemene vraag" case instead of being held
- e2e: `tests/e2e/intake-routing-rules.spec.ts`

#### Scenario: a mapping onto a missing field is refused in the dialog
- GIVEN an administrator editing a rule whose case type has no field `straatnaam`
- WHEN they map `straatnaam` and save
- THEN the dialog stays open and shows that the case type has no field `straatnaam`, and no rule is stored
- e2e: `tests/e2e/intake-routing-rules.spec.ts`

#### Scenario: only a channel integriq has can be picked
- GIVEN an administrator opening the channel select
- WHEN the list loads
- THEN it shows the channels the intake registry describes, and offers no free text entry
- e2e: `tests/e2e/intake-routing-rules.spec.ts`

#### Scenario: a principal without the rules action cannot save
- GIVEN a signed-in user who does not hold `intake.rules`
- WHEN they post a rule to `/api/intake/routing-rules`
- THEN the request is refused and no rule is stored
- @e2e exclude an authorization refusal on the endpoint; covered by PHPUnit on IntakeChannelsController

### Requirement: A draft rule is tested against a sample message without side effects (REQ-IRE-002)

The rule editor MUST offer a test that sends the draft rule and a sample
message to `POST /api/intake/routing-rules/test`. The response MUST say
whether the draft matches, show the payload the case would receive, and name
the saved rule that wins for that channel today. The test MUST NOT store an
object and MUST NOT dispatch `IntakeMessageRoutedEvent`.

#### Scenario: an administrator checks a condition before saving
- GIVEN a draft rule with condition `text contains lantaarnpaal` on the `public-space-report` channel
- WHEN the administrator tests it against a held report whose text mentions a lantaarnpaal
- THEN the editor shows that the rule matches and lists the fields the case would receive
- e2e: `tests/e2e/intake-routing-rules.spec.ts`

#### Scenario: a test opens no case
- GIVEN a draft rule that matches the sample
- WHEN the test runs
- THEN no `intake_message` is written and no routed event is dispatched
- @e2e exclude a negative on the event bus; covered by PHPUnit on IntakeChannelsController::testRule

### Requirement: A held message is routed again on request, with its files (REQ-IRE-003)

The intake inbox MUST offer "Route again" on a message whose status is `held`
or `failed`, singly and for a selection. Routing again MUST run the same
match and dispatch as a new arrival and MUST write the outcome onto the same
`intake_message`. A bulk request MUST answer per message. When a message is
held, its attachment and media files MUST be kept on the held message, so a
later route hands them to the app that opens the case. Saving a rule MUST NOT
route held messages by itself.

#### Scenario: a report held before its rule existed opens a case
- GIVEN a public space report held with "No routing rule matched" and a rule for its channel added since
- WHEN an administrator chooses "Route again" on it
- THEN the report opens a case, its status becomes `routed` and it names the rule and the case
- e2e: `tests/e2e/intake-routing-rules.spec.ts`

#### Scenario: the photo travels with a late route
- GIVEN a held report that arrived with a photo
- WHEN it is routed again
- THEN the app that opens the case receives the photo with the message
- @e2e exclude the file hand-off is read by the listener in another app; covered by PHPUnit on IntakeRoutingService::reroute

#### Scenario: a new rule leaves the held queue alone
- GIVEN twenty held messages on the `teams` channel
- WHEN an administrator saves a rule for `teams`
- THEN all twenty stay held until someone routes them again
- e2e: `tests/e2e/intake-routing-rules.spec.ts`

### Requirement: An Outlook user sends the open message to intake from a task pane (REQ-IRE-004)

Integriq MUST serve an Office add-in manifest and a task pane. The pane MUST
read the open message as EML and post it to `POST /api/mail-intake/import`
under the mailbox source named in the `outlook_intake_source` setting. The
pane MUST tell the user whether the message linked to an existing case,
opened a new one or is waiting unassigned. A user who may not import mail
MUST see why, not a bare error.

#### Scenario: a case worker starts a case from a mail in Outlook
- GIVEN an administrator deployed the add-in and set the mailbox source, and a user reading a mail in Outlook on the web
- WHEN the user opens the integriq pane and chooses "Send to intake"
- THEN the mail is imported, and the pane shows the case it linked to or the new case it opened
- @e2e exclude Outlook hosts the pane and cannot be driven from Playwright; covered by PHPUnit on OutlookAddinController and a manual check listed in tasks

#### Scenario: the manifest names this instance
- GIVEN an administrator on the integriq admin page
- WHEN they download the Outlook add-in manifest
- THEN its task pane URL points at this instance's `/outlook/pane` route
- e2e: `tests/e2e/intake-routing-rules.spec.ts`

### Requirement: The seeded routing rules name channels integriq has (REQ-IRE-005)

Every seeded `intake_routing_rule` MUST name a `channelId` that an adapter in
the intake registry answers to, and MUST pass `validateRule()` on an instance
where its case type exists.

#### Scenario: a fresh demo install shows working rules
- GIVEN a fresh install with demo data loaded
- WHEN an administrator opens "Intake routing"
- THEN each listed rule names the `teams`, `public-space-report` or `form-submission` channel, and none names placeholder text
- e2e: `tests/e2e/intake-routing-rules.spec.ts`
