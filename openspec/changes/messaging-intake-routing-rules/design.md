# Design: messaging-intake-routing-rules

Kind: code. Size M. The routing engine and the save endpoint exist. What is
missing is every screen that reaches them, a way to route a message that was
held before its rule existed, and an in-Outlook action.

Read at `development` 92f282bc.

## Where it fits

| Piece | File | What it is today |
|---|---|---|
| Rule schema | `lib/Settings/integriq_register.json:5564` (`intake_routing_rule`) | name, channelId, condition, targetSchema, fieldMapping, locationField, order, isEnabled |
| Rule access | `lib/Settings/register.d/99-mail-schemas-lockdown.json` | every action closed to non-admin, non-owner callers |
| Engine | `lib/Intake/IntakeRoutingService.php:123` `route()`, :262 `firstMatchingRule()`, :326 `matches()`, :433 `hold()` | unchanged in behaviour |
| Save | `lib/Controller/IntakeChannelsController.php:212` `saveRule()`, routes `appinfo/routes.php:94-95` | reused as the only write path |
| Channels | `appinfo/routes.php:85` `intakeChannels#channels`, controller :191 | feeds the channel picker |
| Pages | `src/manifest.d/intake-channels.json`, `IntakeMessages` and `IntakeRoutingRules`, both `type: logs` | the rules page becomes `type: index` |
| Editor pattern | `src/manifest.json` page `Rules`, `slots.form-dialog: RuleEditorModal`; `src/registry.js:43` | the pattern this editor copies |
| Mail import | `lib/Controller/MailIntakeController.php:109` `import()`, `appinfo/routes.php:75` | what the Outlook pane posts to |

## D1. The rules page becomes an index page with its own editor dialog

`IntakeRoutingRules` changes from `type: logs` to `type: index` in
`src/manifest.d/intake-channels.json`, with `slots.form-dialog:
IntakeRuleEditorModal`. The modal lives in
`src/modals/v2/IntakeRuleEditorModal.vue` and registers in `src/registry.js`
beside `RuleEditorModal` (:43).

The dialog owns its submit and posts to `POST /api/intake/routing-rules` or
`PUT /api/intake/routing-rules/{id}`. It does not save through OpenRegister's
objects endpoint. The reason is `validateRule()`: it refuses a channel no
adapter answers to and a mapping onto a field the case type lacks, and only
`saveRule()` runs it. An editor that bypassed it would store a rule that holds
every message it matches.

Rejected: the built-in `CnFormDialog` driven by the schema. `condition` and
`fieldMapping` are `type: object`, which the generic form drops, the same
reason the `Rules` page note gives for `RuleEditorModal`.

The dialog fields:

- Channel: a select fed by `GET /api/intake/channels`. It lists the adapters
  the registry describes, so a channel id is picked, never typed. The three
  seeded rules show why: their channel ids are placeholder text no adapter
  answers to (`lib/Settings/integriq_mock_register.json:8896`).
- Condition: field, operator (the schema enum `equals`, `contains`,
  `exists`, `in`) and value. The field select offers the source expressions
  `readSource()` understands (`IntakeRoutingService.php:390`): `text`,
  `channelId`, `externalId`, `location`, `receivedAt`, `correspondent.<key>`
  and `fields.<key>`.
- Opens: a case type picker over OpenRegister's schemas, grouped by
  register. `validateRule()` resolves it through `SchemaMapper::find()`
  (:483), so the stored value is what that call accepts.
- Field mapping: one row per target field, the target field picked from the
  chosen case type's properties.
- Location field, order and enabled.

A `400` from `saveRule()` carries the refusal text; the dialog shows it on
the field it names and keeps the form open.

Delete and reorder use OpenRegister's objects endpoint for the rule, which
the lockdown fragment allows for administrators. Reordering writes `order`
through `saveRule()` so validation runs on every write.

## D2. Test a rule before saving it

The editor has a "Test" button that sends the draft rule and a sample message
to a new route, `POST /api/intake/routing-rules/test`, on
`IntakeChannelsController::testRule()`. It runs `validateRule()`, then
`matches()` and `buildTargetPayload()` (:359) against the sample, and returns
whether the rule matches and the payload the case would receive. It also
returns which saved rule would win for that channel today, from
`firstMatchingRule()`. Nothing is stored and no event is dispatched.

The sample is either typed in or picked from a held message in the inbox.

Rejected: a dry run that dispatches `IntakeMessageRoutedEvent` with a flag.
The listener is dossiq's, and a flag another app must honour is a case
opened by accident the first time it does not.

## D3. Route a held message again

A held message stays held when a rule is added later, because routing runs
once, on arrival. This change adds `POST /api/intake/messages/{id}/route` and
`POST /api/intake/messages/route` (bulk, a list of ids), on
`IntakeChannelsController::routeAgain()`, behind the `intake.rules` action.
It calls a new `IntakeRoutingService::reroute(string $uuid)` that loads the
stored `intake_message`, rebuilds it with `InboundMessage::fromObject()`
(`lib/Intake/InboundMessage.php:203`) and runs the same match and dispatch as
`route()`, writing onto the same uuid. Bulk answers per item, as
`routeBatch()` does (:183).

The inbox page gets a "Route again" row action and a bulk action, shown for
`held` and `failed` rows.

The files problem: `toObject()` stores attachment and media metadata only
(`InboundMessage.php:177`), so a rebuilt message has no bytes. `hold()`
therefore also stores each attachment and media file on the held
`intake_message` object through OpenRegister's object files, and
`reroute()` reads them back into the rebuilt message. A message that is
routed on arrival does not store its files twice; its bytes already went with
the hand-off.

Rejected: routing held messages automatically when a rule is saved. A new
rule written to catch one message would open cases for a month of held
traffic without anyone choosing that.

## D4. The Outlook task pane

The competitors' Outlook path is an action inside Outlook, and integriq has
none. The mailbox path (`GraphMailboxTransport`) needs a user to forward the
mail to a monitored mailbox, which is the step the row wants gone.

integriq serves two things:

- `GET /outlook/manifest.xml` on a new `OutlookAddinController`: an Office
  add-in manifest whose task pane URL is this instance. An administrator
  deploys it through Microsoft 365 centralized deployment. No store listing.
- `GET /outlook/pane`: a `TemplateResponse` that loads Office.js, reads the
  open message with `Office.context.mailbox.item.getAsFileAsync()` as EML
  and posts it as `file` to `POST /api/mail-intake/import` with the
  configured mailbox source id. The response says whether the message linked
  to an existing case or became a new one, from the imported
  `mail_message` status (`linked`, `caseCreated`, `unassigned`,
  `lib/Service/Mail/MailIntakeService.php:79-93`).

Authentication runs in an Office dialog through Nextcloud login flow v2,
which returns an app password the pane keeps in Office roaming settings. A
session cookie does not survive the task pane iframe in every Outlook client.

The mailbox source the pane records under is an app setting,
`outlook_intake_source`, on the integriq admin page (ADR-076, ADR-079).

Rejected: a new `outlook` intake channel adapter. The mail path already
parses the message, detects a case reference and offers it to dossiq through
`MessageReceivedEvent`. A second path for the same mail would give one message
two ways to become a case.

## Declarative versus imperative

Routing stays imperative, because it is already: an inbound webhook on a
`#[PublicPage]` route dispatches a typed event to whichever app owns the
target (ADR-041). Nothing here is lifecycle, aggregation or notification on
an OpenRegister object, so there is no `x-openregister-*` extension to
declare. The rule itself is configuration held as an OpenRegister object,
which is the declarative part.

## Seed data

The `intake_routing_rule` schema is unchanged. The three seeded rules at
`lib/Settings/integriq_mock_register.json:8889-8950` are placeholders whose
`channelId` values ("Voorbeeld Channelid 1") no adapter answers to. They are
replaced by three that work:

- "Teams meldingen naar algemene vraag": channel `teams`, no condition,
  order 10, enabled.
- "Openbare ruimte straatverlichting": channel `public-space-report`,
  condition `text contains lantaarnpaal`, location field mapped, order 10,
  enabled.
- "Formulier bezwaar": channel `form-submission`, condition
  `fields.formType equals bezwaar`, order 20, disabled, so a demo shows a
  disabled rule.

Their `targetSchema` names dossiq's example case types. On an instance
without dossiq the rule still matches and the message is held with "No app
opened", which is the honest demo.

## Risks

- A rule that points at a case type dossiq does not accept holds every
  message. The test button (D2) shows the payload, not dossiq's answer,
  because asking dossiq means opening a case.
- Storing held files (D3) keeps unsolicited content on the instance. Held
  messages fall under the `intake_message` lockdown, administrators only.
- Office.js loads from Microsoft's CDN, so the pane response needs a CSP
  that allows it and allows framing by Outlook hosts. That relaxation is
  scoped to the pane route only.
- The imported mail is created under `mail_message`, which
  `99-mail-schemas-lockdown.json` closes for non-admin, non-owner creates.
  Until ConductionNL/integriq#2105 decides who may create and read mail, the
  pane works for administrators only. The pane says so instead of failing
  with a bare 403.
