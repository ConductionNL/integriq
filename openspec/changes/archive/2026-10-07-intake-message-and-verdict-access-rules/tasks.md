# Tasks: intake-message-and-verdict-access-rules

Ruben approved the model on 2026-10-05.

- [x] 1.1 Blocks on `intake_message` and `verdict` in the base and the demo register (1.1.0), out of the lockdown fragment; ratchet updated. Verify against the merged register (red before)
- [x] 1.2 Four groups in `IntakeGroups`; `WebhookProfile` carries them; the webhook settings enrol, withdraw and return `handlerGroup`. Verify in PHPUnit against the real block (red before)
- [x] 1.3 `ProvisionIntakeGroups` creates the groups and enrols the webhook accounts; app version bumped
- [x] 1.4 Warning in `WebhookConnectionRow.vue`, English and Dutch
- [x] 1.5 Live per role on the throwaway instance (`ifu-live/commands.md`)
