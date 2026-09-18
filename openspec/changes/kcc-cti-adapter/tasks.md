# Tasks: kcc-cti-adapter

> The seam is built: the provider interface, the shared normaliser, the typed
> event and the retry guard, with 51 tests. The controller, the caller lookup
> and the contact-moment path are open and named below.
>
> Two things found while building, recorded rather than worked around:
>
> - **`PhoneNumberValidator` already normalises to E.164** and the SMS path
>   uses it. This reuses it, with ONE deliberate difference: bare digits
>   carrying no `+`, `00` or trunk `0` are refused here. That validator
>   prefixes them with `+` and accepts the result, so `612345678` becomes
>   `+612345678`, a valid number elsewhere. For an SMS that is fine, it simply
>   fails to deliver. For a caller lookup it matches somebody else's partij and
>   an agent picks up looking at the wrong person's case history. Asserted in
>   both directions so neither side can be tidied into the other.
> - **`nl.conduction.integriq.call.<kind>` would be the first
>   `nl.conduction.integriq.*` type in the codebase.** Every existing one is
>   `nl.conduction.<domain>.*`: peppol, cardfeed, sms, zgw, delivery. The spec
>   asks for `integriq`, so `CallEvent::CLOUDEVENT_PREFIX` follows the spec,
>   but the inconsistency is real and somebody should rule on it before a
>   second one is minted.

## Implementation tasks

### Task 1: Provider interface, log and webhook bindings, endpoint
- **spec_ref**: `openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005`
- **files**: `lib/Service/Kiss/CtiProviderInterface.php`, `lib/Service/Kiss/CallEventNormaliser.php`, `lib/Service/Kiss/CallEventDeduplicator.php`, `tests/Unit/Service/Kiss/CallEventNormaliserTest.php`, `tests/Unit/Service/Kiss/CallEventDeduplicatorTest.php`
- [x] The seam: interface, shared normaliser, retry guard
- [ ] The bindings and the endpoint: `LogCtiProvider`, `WebhookCtiProvider`, `CtiController`, `appinfo/routes.php`
- [x] Test the seam (an unplaceable number is refused; a repeated event is claimed once; no cache lets everything through)
- [ ] Test the endpoint (unverified request is 401)
- **note on idempotency**: `CallEventDeduplicator` is a distributed cache, not a
  ledger. Two nodes in the same millisecond can both pass, and an evicted entry
  lets a late retry through. That is survivable because nothing here WRITES: a
  klantcontact is only created when the consuming app pushes for it against a
  `callId` it names, and the idempotency that protects the record belongs
  there. The class says so; "idempotent" in a task description reads as a
  stronger promise than a cache can keep.
- **note on the endpoint**: the template is
  `NotificatiesSubscriberController::callback()`, which is
  `#[NoCSRFRequired] #[PublicPage] #[AnonRateLimit]`, reads the credential at
  the point of denial rather than inside its helper, and returns an
  undifferentiated 401 so the endpoint is not an existence oracle.

### Task 2: Caller identification and `CallEvent`
- **spec_ref**: `openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006`
- **files**: `lib/Event/CallEvent.php`, `tests/Unit/Event/CallEventTest.php`
- [x] `CallEvent`, with its kinds, its CloudEvents type and the caller context
- [ ] `CallContextService`: resolve the number to a partij and read the open cases
- [x] Test the event
- [ ] Test the lookup
- **note**: `KlantinteractiesProviderInterface` has NO way to find a partij by
  phone number. The lookup needs a new method on the interface and on both
  bindings, so it is its own task rather than a line inside this one. The VNG
  field is `betrokkene.digitaleAdressen`.
- **note**: the event distinguishes a WITHHELD number from an UNKNOWN one.
  Both give `caller = null`, but only one is worth asking the caller for their
  number, and the panel says different things. `isAnonymous()` tells them
  apart.

### Task 3: Contact moment on request, retention
- **spec_ref**: `openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007`
- **files**: `lib/Service/Kiss/CallContextService.php`, `lib/Settings/integriq_register.json` (`callEvent` log with 30-day retention)
- [ ] Implement
- [ ] Test

### Task 4: Source form, i18n, docs
- CTI provider picker and field mapping on the source page; Dutch and English strings; docs with screenshots.
- [ ] Implement
- [ ] Test (`tests/e2e/cti-source.spec.ts`)
