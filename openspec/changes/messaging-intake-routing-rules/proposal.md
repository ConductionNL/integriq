---
kind: code
depends_on: []
---

# Proposal: messaging-intake-routing-rules

## Summary

Integriq routes every inbound channel message through a routing rule, and no
page can create one. So on a real install every Teams message, form
submission and public space report is held in the intake inbox and never
opens a case. This change turns the read-only "Intake routing" page into an
editor, lets an administrator route a held message again once a rule exists,
and adds the in-Outlook half of "start a case from Outlook": a task pane that
sends the open message into the mail intake integriq already has.

## Why

This change covers five rows. One comes from a sibling matrix.

- `dossiq:1.9`, "Create case from MS Office, Teams or Outlook", rated `no`
  for dossiq with integriq named as provider. Two competitors rate it yes:
  - opencase: "opencase@1.1.0 docs/dokumenter/ms-office.md:8,50-62 (vendor
    docs, not code) Outlook task pane can create a new case and save the mail
    as a document, Enterprise only; appinfo/routes.php:70,91 login-flow
    helper and manifest download serve the add-in".
  - zaaksysteem (xxllnc Zaken): "zaaksysteem@b7354824
    frontend-mono/apps/main/src/Routes.tsx:161 exposed create-case route;
    modules/createCase/index.tsx mounts CaseCreateDialog with a party;
    createCase/library.ts:18-25 teams, sharepoint and outlook parties call
    back to teams.zaken.xxllnc.nl".
- `integriq:msg-routing`, "Route incoming messages to the right team with
  routing rules", partial and built. n8n rates yes: "SwitchV3.node.ts:121
  'rules' route each incoming message by its content to a per-team output".
  The matrix note: "staff cannot create or edit a rule from any page, so on a
  real install every message is held. The rules page is a list, not an
  editor."
- `integriq:msg-teams`, "Open a case from a Teams message", partial and
  built. n8n rates yes: "MicrosoftTeamsTrigger.node.ts:121
  'newChannelMessage' and :131 'newChatMessage' start a flow on a Teams
  message". The note: a Teams message "only opens a case if a routing rule
  exists (no page can create one)".
- `integriq:msg-form-intake`, "Take submissions from an outside form tool in
  as intake", partial and built. n8n rates yes: "trigger nodes for outside
  form tools ship in the tree: packages/nodes-base/nodes/Typeform, JotForm,
  Wufoo, Formstack, FormIo and KoBoToolbox".
- `integriq:msg-public-space`, "Take reports about the public space in as
  intake", partial and built. No competitor rates yes. The note: "Without a
  routing rule, which no page can create, every report sits held in the
  intake inbox rather than opening a case."

No row carries a demand entry. `dossiq:1.9` meets the bar on its two
competitors. The four integriq rows ride with it, because their whole missing
half is the same rule editor.

## What integriq already has

- The routing engine. `lib/Intake/IntakeRoutingService.php:123` stores the
  message, finds the first enabled rule for the channel by `order` (:262),
  dispatches `IntakeMessageRoutedEvent` (:147) and holds the message with a
  reason when nothing matched or nothing opened a case (:133, :150, :433).
- The save path. `appinfo/routes.php:94-95` routes `POST` and `PUT
  /api/intake/routing-rules` to `IntakeChannelsController::saveRule()`
  (`lib/Controller/IntakeChannelsController.php:212`), behind the
  `intake.rules` action (:218). It refuses a rule whose channel has no
  adapter or whose mapping names a field the case type lacks
  (`IntakeRoutingService::validateRule()`, :220).
- Four channel adapters, registered at `lib/AppInfo/Application.php:485-493`:
  form submission, messaging, public space report and Teams.
- The pages. `src/manifest.d/intake-channels.json` declares `IntakeMessages`
  and `IntakeRoutingRules`. Both are `type: logs`. The rules page shows five
  read-only columns. `grep -rn routing-rules src/` finds only the page route,
  so nothing in the frontend calls the save endpoint.
- The Outlook mailbox path. `lib/Service/Mail/Transport/GraphMailboxTransport.php`
  reads a shared Exchange Online mailbox, and `POST /api/mail-intake/import`
  (`appinfo/routes.php:75`, `lib/Controller/MailIntakeController.php:109`)
  imports a saved `.msg` or `.eml`. A mail becomes a `MessageReceivedEvent`
  that dossiq now answers.
- dossiq's half is in place on its `development` branch.
  `lib/Listener/IntakeMessageRoutedListener.php:74` listens for
  `OCA\Integriq\Event\IntakeMessageRoutedEvent` and answers the created case
  (:120), and `lib/Listener/MessageReceivedListener.php:83` answers
  `MessageReceivedEvent`. Both register in
  `lib/AppInfo/Registrar/CrossAppListenerRegistrar.php:159` and :181.

## What this change builds

1. A rule editor on the "Intake routing" page: create, edit, reorder,
   enable, disable and delete a rule, with a channel picker fed by
   `GET /api/intake/channels` and a case type picker fed by OpenRegister's
   schemas. Every save goes through `saveRule()`, so the existing refusal
   shows as a field error.
2. A test of a rule against a sample message before it is saved, so an
   administrator sees which rule would win.
3. "Route again" on a held message in the intake inbox, singly and in bulk.
   A held message keeps its attachment and media files so a later route can
   hand them over.
4. An Outlook task pane that sends the open message into
   `POST /api/mail-intake/import`, served by integriq with its add-in
   manifest.
5. Seed rules that name channels integriq has, replacing three placeholders
   whose channel ids no adapter answers to.

## Out of scope

- The Open Formulieren bridge handoff (`appinfo/routes.php:272`,
  `OpenFormulierenController::handoff()`). It is a second path for a form
  submission and has its own change, `open-formulieren-intake`. The
  `form-submission` channel routes through the rules this change makes
  editable.
- Posting case updates back into Teams beyond the existing reply.
- A Word, Excel or PowerPoint add-in. The row names Office, but a document
  reaches a case through Nextcloud Files, as `teams-messages-open-cases`
  records.
- Who may read an imported mail. `lib/Settings/register.d/99-mail-schemas-lockdown.json`
  closes `mail_message` to administrators and owners as an interim, tracked
  as ConductionNL/integriq#2105.

## Sibling half

dossiq builds nothing new for this change. Its two listeners already answer
both events. What dossiq must keep true is that a case type an administrator
picks in the rule editor is one `IntakeMessageRoutedListener` accepts
(`isForACase()`, dossiq `lib/Listener/IntakeMessageRoutedListener.php:104`).

## Open question

The Outlook task pane (item 4 above) reverses a line in `teams-messages-open-cases`, whose proposal left "an Outlook add-in or an Office task pane" out because "it needs a product decision before it needs a spec". The reason to specify it now is `dossiq:1.9`: opencase's Outlook task pane is one of the two competitor cells. Whether the pane is built is Ruben's product decision. Items 1, 2, 3 and 5 do not depend on the answer; if the answer is no, the pane's task is closed with that reason.
