# Tasks: teams-messages-open-cases

Kind: code. Size S. Parity ledger row 1.9, the Teams third of it. The channel
contract, the routing rules, the review inbox and the reply path shipped in
`intake-channels-beyond-mail`; the Outlook third shipped in
`mail-intake-creates-cases` as the `graph` mailbox transport. Nothing below
rebuilds any of that.

## Implementation tasks

### Task 1: The signature scheme
- **spec_ref**: `openspec/changes/teams-messages-open-cases/specs/webhook-signing/spec.md#requirement-inbound-verification-reads-the-microsoft-teams-scheme-req-whs-005`
- **files**: `lib/Service/WebhookSignatureService.php`
- [ ] Implement (`teams`: `HMAC <base64>` over the raw body under the
      base64-decoded secret, `hash_equals`, no timestamp, tolerance ignored
      with a warning)
- [ ] Test (a good signature, a signature made under the literal secret, a
      tampered body, a missing header, and the ignored tolerance)

### Task 2: The adapter
- **spec_ref**: `openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006`
- **files**: `lib/Intake/Adapter/TeamsChannelAdapter.php`, `lib/AppInfo/Application.php`
- [ ] Implement (`receive`, `describe`, `reply`; the author as correspondent,
      the text stripped of markup, the attachments, the conversation and
      message ids, the raw payload kept whole; the reply posted into the same
      conversation, and in mock mode recorded and not sent)
- [ ] Test (a message naming a case number, one naming nothing, one matching
      no rule, and a reply the gateway refuses reported as a refusal)

### Task 3: Say what it needs on the Microsoft side
- **spec_ref**: `openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006`
- **files**: the channel's documentation page
- [ ] Implement (what an administrator creates in Teams, which URL to point
      the outgoing webhook at, where the shared secret goes, and the one
      thing that bites: Teams expects an answer within five seconds, so the
      reply path acknowledges and the case opens asynchronously)
- [ ] Test (the documented setup walked once against a real tenant, and the
      finding written down either way)
