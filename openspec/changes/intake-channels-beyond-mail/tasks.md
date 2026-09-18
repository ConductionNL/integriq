# Tasks: intake-channels-beyond-mail

Kind: code. Size M. Round 4 discovery cluster 45, candidates C-intake-21,
C-intake-3, C-intake-35, C-intake-4, C-intake-12 and C-tasks-and-phases-31.
The build plan makes it depend on cluster 51, the intake form as its own
object, portaliq, wave 2. The channel half waits on nothing.

## Implementation tasks

### Task 1: The channel adapter contract
- **spec_ref**: `openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-channel-is-a-declared-adapter-behind-one-contract-req-ic-001`
- **files**: `lib/Intake/IntakeChannelAdapterInterface.php`, `lib/Intake/IntakeChannelRegistry.php`, `lib/AppInfo/Application.php` (DI tag)
- [x] Implement (`receive`, `describe`, `reply`; the normalised inbound shape with channel id, correspondent, text, attachments, optional location and the raw payload; first-wins collision policy as `IntegrationRegistry`)
- [x] Test (an unknown channel id fails naming itself and creates nothing)

### Task 2: Routing rules and the review inbox
- **spec_ref**: `openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-routing-rule-maps-a-channel-and-a-payload-onto-a-case-type-req-ic-002`
- **files**: `lib/Intake/IntakeRoutingService.php`, the rule configuration screen, the review inbox
- [x] Implement (channel plus optional condition plus target; a reviewable inbox for an unmatched message; no default case; per-item isolation per `synchronization-engine` REQ-008)
- [x] Test (an unmatched message held with its reason, and a batch with one unparseable item)

### Task 3: The signed submission route
- **spec_ref**: `openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-submission-arrives-over-a-signed-webhook-and-maps-to-a-case-type-req-ic-003`
- **files**: the endpoint generalised from `open-formulieren-intake` REQ-001, the submission mapping configuration
- [x] Implement (signature verified before the body is read, field mapping as configuration, a rejected submission recorded, a mapping onto a missing field refused at save)
- [x] Test (PHPUnit on the endpoint covers the signed and the unsigned delivery, including that the adapter is never asked to parse an unsigned one; the Newman collection is deferred to the quality sweep)

### Task 4: Location and media on the inbound shape
- **spec_ref**: `openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-public-space-report-carries-its-location-and-its-media-req-ic-004`
- **files**: the normalised shape, the routing write path, the attachment hand-off
- [x] Implement (location written to the mapped field, media held as attachments, nothing written when the channel supplies nothing)
- [x] Test (a report with coordinates and photos, and a channel with neither)

### Task 5: Reply on the arriving channel
- **spec_ref**: `openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-reply-goes-back-over-the-channel-it-arrived-on-req-ic-005`
- **files**: the reply path, the recorded channel id and correspondent, the call into the outbound message log
- [x] Implement (`reply()` on the arriving channel, `unsupported by this channel` where it cannot, no silent fallback, every reply recorded)
- [x] Test

### Task 6: Coordination, docs and the hand-offs
- **files**: `docs/`, Dutch and English strings, the catalogue entries, this change's row in `competitor-parity-2026-09`
- [ ] Tell dossiq that `case.intakeChannel` can be written from the channel that delivered the message, beside the form's own channel from buildiq's `forms-per-case-type`
- [ ] Ask the portaliq lane, cluster 51, for the form definition a submission mapping names, and agree what happens when it is missing
- [x] Record C-intake-4 as belonging to the Nextcloud platform programme under D9, and C-intake-12 and C-tasks-and-phases-31 as a product decision with documented passers only under D21
- [x] Test (`tests/e2e/intake-channels.spec.ts`, `openspec validate intake-channels-beyond-mail --type change --strict`)
