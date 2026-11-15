# Proposal: intake-message-and-verdict-access-rules

kind: code. Cites **ADR-022** (apps consume OpenRegister abstractions) and **ADR-099** (scoped identity). Ruben approved the model on 2026-10-05: the same pattern as `dso_verzoek` and `openformulieren_submission` (`bsn-intake-records-access-rules`).

## Why

`intake_message` holds what a citizen sent through an intake channel, a BSN included. `verdict` holds what an outside checker said about an object. Both were closed by `99-mail-schemas-lockdown.json` with empty rule lists, so only administrators and an object's owner could use them.

That blocks the webhooks of `public-webhooks-on-the-consumer-model`. Live on 2026-10-04 (`iwh-live` run-6): choosing an ordinary account for the form-submission channel or for the verdicts webhook answered 400 "lacks the create, update right". Only an administrator account worked, which the settings warn against.

## What changes

- **Both schemas get an authorization block.** The intake group may create and update. The handler group may read and update. Administrators keep everything. Nobody else gets anything, and nobody may delete.
- **Four fixed groups.** `intakekanalen-intake` and `intakekanalen-behandelaars` for every intake channel (form-submission, teams, public-space-report, messaging). `verdicts-intake` and `verdicts-behandelaars` for the verdicts webhook. The names follow `dso-*` and `openformulieren-*`.
- **The webhook account joins its group.** Choosing it in the webhook settings adds it, a refused account leaves again, and the previous account leaves unless another channel of the same group still acts as it.
- **Existing instances keep working.** `ProvisionIntakeGroups` creates the groups and enrols each webhook's current account. It never fills a handler group.
- **The empty handler group notice applies here too.** The webhook settings return `handlerGroup: {id, empty}` and the row shows a warning while the group is empty.

## Capabilities

### Modified Capabilities

- `intake-access`: new REQ-IAC-002 and REQ-IAC-003.

## Impact

- Registers: `intake_message` and `verdict` 1.0.0 -> 1.1.0 with the block, in the base and the demo register. Both leave `99-mail-schemas-lockdown.json`.
- `IntakeGroups`, `WebhookProfile` (`intakeGroup`, `handlerGroup`), `WebhookProfiles::intakeChannel()`, `WebhookConnectionsSettingsController`, `ProvisionIntakeGroups`, `WebhookConnectionRow.vue`.
- `GET /api/verdicts` and the intake reply endpoint now answer only to handlers, administrators and owners. That was already so under the lockdown.
- Operators: put the case workers who handle intake messages in `intakekanalen-behandelaars`, and those who read verdicts in `verdicts-behandelaars`.
