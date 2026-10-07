# Design: intake-message-and-verdict-access-rules

## Decisions

- **One group pair per schema, not per channel.** The four intake channels all write `intake_message`, and an authorization block is per schema. A group per channel would need four entries in every list for the same rights.
- **The groups sit on the profile.** `WebhookProfile` carries `intakeGroup` and `handlerGroup`. The settings controller and the repair step read them, so no second table of webhook to group exists. A profile without groups (Peppol, ROD and the other bridges, whose schemas have no block) behaves as before.
- **A shared account stays.** One account may serve several channels. Taking it out of `intakekanalen-intake` when one channel moves to another account would break the others, so the controller first checks every consumer of the same group.
- **The blocks live in the register, not in the lockdown fragment.** The fragment merges last and would overwrite a block in the register. Keeping the block next to the schema matches `dso_verzoek`.

## Rejected

- **A public `create` grant.** Anyone could then write an intake message without a signature.
- **Granting the existing lockdown to the webhook account by uid.** The account differs per instance, and the register import rewrites the block on every upgrade.
