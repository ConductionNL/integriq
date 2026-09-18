# Tasks: berichtenbox-digital-post-adapter

## Implementation tasks

### Task 1: Provider interface and log binding
- **spec_ref**: `openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001`
- **files**: `lib/Service/DigitalPost/DigitalPostProviderInterface.php`, `lib/Service/DigitalPost/LogDigitalPostProvider.php`, `lib/Service/DigitalPost/DigitalPostProviderRegistry.php`, `lib/Service/DigitalPost/DigitalPostResult.php`
- [x] Implement
- [x] Test (an unknown provider id fails naming itself and the ids that do exist, rather than falling back to the log binding)

### Task 2: `digitalPostMessage` schema and send path
- **spec_ref**: `openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-a-send-is-a-typed-command-with-a-tracked-message-req-dpa-002`
- **files**: `lib/Settings/integriq_register.json`, `lib/Event/DigitalPostSendRequestedEvent.php`, `lib/Event/DigitalPostDeliveredEvent.php`, `lib/Service/DigitalPost/DigitalPostService.php`, `lib/EventListener/DigitalPostSendRequestedListener.php`
- [x] Implement
- [x] Test (the result slot carries the id or a refusal and never both; a failed send keeps the letter and its attachments)

### Task 3: Berichtenbox and Postex bindings
- **spec_ref**: `openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001`
- **files**: `lib/Service/DigitalPost/BerichtenboxProvider.php`, `lib/Service/DigitalPost/PostexProvider.php`
- [x] Implement (activation refused without certificate and OIN, naming which of the two is missing; Postex over the shared gateway transport rather than a client of its own)
- [x] Test (against the shipped mock and a faked transport)

### Task 4: Inbound post to filinq, status job, health
- **spec_ref**: `openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-inbound-post-feeds-the-document-intake-inbox-req-dpa-003`
- **files**: `lib/BackgroundJob/DigitalPostStatusJob.php`, `lib/BackgroundJob/DigitalPostInboundJob.php`
- [x] Implement (the status job polls only letters still on their way; the inbound job offers each item to the same document intake seam the mail intake uses, on channel `digitalPost`)
- [x] Test (health reports last send, last error and queue depth; a source naming no digital post provider is not polled)

### Task 5: Source form, i18n, docs
- Provider picker and config fields on the source page; Dutch and English strings; docs with screenshots.
- [x] Implement the half the form reads: every binding describes the configuration it needs through `getConfigSchema()`, so the picker and its fields can be built from the registry rather than hardcoded.
- [ ] The source page's provider picker itself, its Dutch and English strings, and the docs with screenshots.
- [x] Test (`tests/e2e/digital-post-source.spec.ts`)

### Task 6: The feature flag selects the binding
- **spec_ref**: `openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-the-feature-flag-selects-the-binding-and-a-flagged-instance-without-credentials-refuses-req-dpa-005`
- **files**: `lib/AppInfo/Application.php`, `lib/Sources/Berichtenbox/BerichtenboxSourceAdapter.php`
- **acceptance_criteria**:
  - GIVEN `logius.berichtenbox.feature_flag` is `1` and credentials are absent WHEN a send runs THEN it is refused naming the missing credential, and the mock is not served
- [x] Implement (the DI factory branches on the flag, which it did not before; a flagged instance resolves to `BerichtenboxClientUnavailable`, which refuses every call and names what is missing)
- [x] Test (both flag states, and the flagged-without-credentials refusal)

### Task 7: Credentials by reference
- **spec_ref**: `openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-signing-material-is-resolved-by-reference-never-passed-by-value-req-dpa-004`
- **files**: `lib/Adapters/Berichtenbox/BerichtenboxClient.php`, `lib/Adapters/Berichtenbox/BerichtenboxClientMock.php`, `lib/Adapters/Digikoppeling/PkiOverheidCredentialResolver.php`
- **acceptance_criteria**:
  - GIVEN a source with a `certificateRef` WHEN a send runs THEN material is resolved inside integriq and no PEM appears in a call argument
- [x] Implement (`dispatch(array $message, string $certificateRef)` on the abstract, the mock and the source adapter, so a PEM cannot travel through a call argument at all)
- [x] Test (the envelope carries no certificate and no key, and the fail-closed path when the broker cannot supply)

### Task 8: The live network leg. BLOCKED, do not start
- **spec_ref**: `openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-berichtenbox-code-path-built-on-the-client-that-ships-req-dpa-006`
- **files**: `lib/Adapters/Berichtenbox/BerichtenboxClientHttp.php`
- **blocked_on**:
  - Logius BBK OAuth 2.0 client credentials (procurement)
  - A PKIoverheid Services-server certificate (procurement)
  - `CredentialBrokerService::issueSigningMaterial` in OpenRegister, which does not exist, so `PkiOverheidCredentialResolver` fails closed for every reference
- **acceptance_criteria**:
  - GIVEN real credentials WHEN a letter is sent THEN it appears in the recipient's Berichtenbox and the delivery receipt verifies
- [ ] Implement (only once all three blockers clear)
- [ ] Test (against the Logius preproduction environment, not a fixture)

## Verification

- [x] `openspec validate berichtenbox-digital-post-adapter --strict` passes
- [x] PHPUnit run, exit code read rather than the summary line
- [ ] `composer check:strict`. Deferred to the fleet quality sweep with the rest of this phase, and recorded in `quality-debt.md`.
- [x] No PEM string appears in any method signature, source configuration or
      app-config key added by this change
- [x] With the flag unset, every send is reported as simulated: the flag is
      carried on the result and stored on the message, so a screen cannot lose it

## Cross-repo follow-ups

- [ ] Record in `absorb-dossiq-deliveries` that its open Berichtenbox item is
      closed by this change, so the repo does not hold two shapes for one
      capability (design D7)
- [ ] Task 8, the live network leg, stays untouched and still blocked on all
      three of its blockers
- [ ] Raise the OpenRegister need for `CredentialBrokerService::issueSigningMaterial`,
      which blocks Digikoppeling signing as well as this
- [ ] Tell dossiq when a real transport binds, so it can register
      `BerichtenboxReadStatusJob`, which is deliberately unscheduled until then
