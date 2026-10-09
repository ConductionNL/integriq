# Tasks: kcc-cti-adapter

> Tasks 1, 2 and 3 are done. Task 4, the source form and the docs, is open.
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
- [x] The bindings and the endpoint: `LogCtiProvider`, `WebhookCtiProvider`, `CtiSourceResolver`, `CtiEventIntake`, `CtiController`, `appinfo/routes.php`
- [x] Test the seam (an unplaceable number is refused; a repeated event is claimed once; no cache lets everything through)
- [x] Test the endpoint (a binding that throws has not said yes; a source naming no installed binding is refused rather than falling back to the sandbox; a retry dispatches nothing)
- **note**: `WebhookCtiProvider` verifies through the existing
  `WebhookSignatureService`, the same HMAC-over-the-raw-body check four other
  inbound endpoints use, rather than a scheme of its own. A source configuring
  NEITHER a signature nor a shared secret is refused: an unauthenticated public
  endpoint is not a configuration anybody chooses on purpose.
- **note**: `CtiSourceResolver` does NOT fall back to the log binding for an
  unknown provider id, unlike `KissSyncService::resolveProvider()`. A typo
  would otherwise look like a working integration quietly delivering to a log
  file. Said here because the two now differ deliberately.
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
- [x] `CallerLookup`, `CallerDirectory`, `CallContextService`: resolve the number to a partij and read the open cases
- [x] Test the event
- [x] Test the lookup, probed with numbers differing only by their prefix
- [ ] A binding that implements `CallerSearchInterface`. Until one exists, every caller reads as unknown, which is true and visible.
- **note**: the search seam is `CallerSearchInterface`, kept OFF
  `KlantinteractiesProviderInterface` deliberately. Adding a method there would
  force the log sandbox and the REST client to answer a question neither was
  written for. A binding that can search implements this as well; one that
  cannot simply does not.
- **note on the lookup, which is where the wrong-person risk lives**: the
  comparison is EXACT over full E.164 strings. Not a suffix match, not the last
  nine digits, not a LIKE. `+31612345678` and `+49612345678` differ only by
  their prefix and are different people, and an agent reading the panel says a
  name out loud before anybody can check it. More than one party on a number is
  no match either: a shared landline is common, and picking the first is
  picking at random. When no party matches there are no open cases either,
  because a case list beside a null caller is somebody's cases on screen under
  "unknown caller".
- **note**: the event distinguishes a WITHHELD number from an UNKNOWN one.
  Both give `caller = null`, but only one is worth asking the caller for their
  number, and the panel says different things. `isAnonymous()` tells them
  apart.

### Task 3: Contact moment on request, retention
- **spec_ref**: `openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007`
- **files**: `lib/Service/Kiss/CallEventLog.php`, `lib/Service/Kiss/CtiEventIntake.php`, `lib/Service/KissSyncService.php`, `lib/Controller/KissController.php`, `lib/Exception/CallEventNotFoundException.php`, `lib/Settings/register.d/cti-call-events.json` (`call_event` log with 30-day retention), `tests/Unit/Service/Kiss/ContactMomentForCallTest.php`
- [x] Implement
- [x] Test
- **note**: the files line named `CallContextService` and the base register.
  The log is its own class, `CallEventLog`, because the context service only
  reads, and the schema went into an ADR-037 fragment, not the base register.
- **note on what is stored where**: every accepted event is written to
  `call_event` BEFORE it is dispatched, so a panel that records the contact
  moment the instant it sees `ended` finds the call. The schema declares
  `x-openregister-archival.retention.default = P30D` and no rule, and the
  authorization block is the lockdown shape (admin and owner only), because a
  row holds a phone number. The log is written and read in system context.
- **note on the push**: `POST /api/kiss/klantcontacten` takes `callId` and,
  optionally, `callSourceId`. Integriq then records the contact moment on
  `kanaal = telefoon` (whatever channel the panel posted), takes
  `plaatsgevondenOp` from the call unless the panel sent one, and links the
  case as before. The VNG Klantinteracties klantcontact has NO duration field,
  so the duration (and the call) live on the local `kiss_klantcontact` mirror
  and are not sent on the wire: sending a field the API does not define is the
  failure KissSyncService already documents. A second push for the same call
  answers with the first contact moment and creates nothing. A callId with no
  ended event, or one callId ended on two sources without `callSourceId`, is
  refused with 404 `unknown_call` before anything reaches KISS.
- **note**: the sweep itself is OpenRegister's `ArchivalRetentionTask`; the
  unit test pins the declaration with a fixed clock. That a row is really gone
  after 30 days is a live check (Still owed).

### Task 4: Source form, i18n, docs
- CTI provider picker and field mapping on the source page; Dutch and English strings; docs with screenshots.
- [ ] Implement
- [ ] Test (`tests/e2e/cti-source.spec.ts`)
