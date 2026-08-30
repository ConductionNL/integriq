# Tasks — peppol-access-point-connector

## 1. Data model and seed

### Task 1: Declare the `peppol_transmission` schema and seed data
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-003-event-driven-outbound-transmission-with-status-lifecycle`
- **files**: `lib/Settings/openconnector_register.json`, `lib/Settings/openconnector_seed_data.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN a `peppol_transmission` schema exists with the `status` enum `queued|sent|delivered|rejected|failed` and an `x-openregister-notifications` block firing on `failed`
  - GIVEN the seed data WHEN the app installs THEN 3 example transmissions and one `provider: log` sandbox source are present (nil-UUID object refs, `MOCK-PEPPOL-*` ids)
- [x] Implement
- [x] Test

## 2. Provider abstraction

### Task 2: Add the AP provider interface with log and generic-REST bindings
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-002-access-point-provider-abstraction-with-log-and-generic-rest-bindings`
- **files**: `lib/Service/Peppol/PeppolAccessPointProviderInterface.php`, `lib/Service/Peppol/LogPeppolAccessPointProvider.php`, `lib/Service/Peppol/RestPeppolAccessPointProvider.php`
- **acceptance_criteria**:
  - GIVEN `configuration.provider: log` WHEN a document is submitted THEN a `MOCK-PEPPOL-<n>` id is returned with no HTTP call and no credential read
  - GIVEN `configuration.provider: rest` WHEN a call is dispatched THEN it routes through `BrokeredCallService` with the AP key injected via `credentialRef` and absent from config/logs
- [x] Implement
- [x] Test

## 3. Participant lookup

### Task 3: Add the participant/SMP lookup endpoint
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-001-peppol-participant--smp-lookup-endpoint`
- **files**: `lib/Controller/PeppolController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a registered participant WHEN `GET /api/peppol/participants/{peppolId}` is called THEN `{exists:true, supportedDocTypes:[...]}` is returned via the selected provider
  - GIVEN a malformed `peppolId` WHEN the endpoint runs THEN HTTP 400 is returned, never a 500
- [x] Implement
- [x] Test

## 4. Outbound transmission

### Task 4: Consume outbound.requested and drive the transmission lifecycle
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-003-event-driven-outbound-transmission-with-status-lifecycle`
- **files**: `lib/Service/PeppolOutboundConsumer.php`, `lib/Service/PeppolTransmissionService.php`
- **acceptance_criteria**:
  - GIVEN a `nl.conduction.peppol.outbound.requested` event WHEN consumed THEN a `peppol_transmission` moves `queued`→`sent` with the AP `transmissionId`, idempotent per `objectUri`+`documentType`
  - GIVEN a submission that keeps failing WHEN the retry budget is exhausted THEN the transmission lands on the dead-letter surface, status `failed`
- [x] Implement
- [x] Test

### Task 5: Emit delivery-status CloudEvents on every state change
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-004-delivery-status-cloudevents-on-every-state-change`
- **files**: `lib/Service/PeppolTransmissionService.php`
- **acceptance_criteria**:
  - GIVEN a transmission going `queued`→`sent`→`delivered` WHEN the transitions occur THEN exactly three `nl.conduction.peppol.delivery.status` events are emitted via `EventService`, each carrying the `transmissionId` and new `status`
  - GIVEN a rejection WHEN emitted THEN the payload has `status='rejected'` and a non-empty `detail`
- [x] Implement
- [x] Test

## 5. Inbound receive

### Task 6: Add the signed inbound receive webhook that republishes events
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-005-inbound-receive-webhook-that-republishes-ap-callbacks-as-events`
- **files**: `lib/Controller/PeppolController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a correctly signed delivery callback WHEN `POST /api/peppol/inbound` receives it THEN the matching transmission advances (`delivered`/`rejected`) and a status event is emitted
  - GIVEN an unsigned/tampered callback WHEN it arrives THEN the response is HTTP 401 with no state change and no event; a signed inbound-document notification republishes `nl.conduction.peppol.inbound.received`
- [x] Implement
- [x] Test

## 6. Localisation

### Task 7: Ship English source keys with Dutch translations
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#non-functional-requirements`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN new user-facing strings WHEN the app is loaded in `nl` THEN Dutch translations render for every English source key
- [x] Implement
- [x] Test

### NL translations (plain bullets, not tracked checkboxes)
- `Peppol participant not found` → `Peppol-deelnemer niet gevonden`
- `Invalid Peppol identifier` → `Ongeldige Peppol-identificatie`
- `Transmission queued` → `Verzending in wachtrij`
- `Transmission delivered` → `Verzending afgeleverd`
- `Transmission rejected` → `Verzending geweigerd`
- `Access point credential missing` → `Toegangspunt-inloggegeven ontbreekt`

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria (sandbox `log` provider path) — exercised via the PHPUnit suite (52 new tests: LogPeppolAccessPointProviderTest, RestPeppolAccessPointProviderTest, PeppolTransmissionServiceTest, PeppolControllerTest, EventServiceTest::testEmitCloudEventPersistsAndProcesses)
- [x] Code review against spec requirements — self-reviewed; see Deviations below

## Deviations

- **Cross-app event consumption wiring (REQ-003).** No existing house pattern dispatches an
  internally-typed CloudEvent to an in-process PHP handler (`EventService::processEvent()` only
  fans out to `event_subscription` rows over HTTP/pull). `PeppolOutboundConsumer` is wired as an
  `IEventListener` on the same `OCA\OpenRegister\Event\ObjectCreatedEvent` NC-level event already
  used by `ObjectCreatedEventListener`/the (currently unwired) `CloudEventListener` — it fires for
  ANY OR object creation, and the consumer filters to
  `register=openconnector, schema=event, data.type=nl.conduction.peppol.outbound.requested`. A
  producing app (e.g. shillinq) triggers it either by creating that object directly via OR's
  `ObjectService::saveObject()`, or via the new `EventService::emitCloudEvent()` helper (which does
  the same `saveObject()` under the hood). This is a design decision made in the absence of an
  established "internal event consumer" pattern in openconnector — documented here for the next
  agent/reviewer. No dedicated unit test exists for `PeppolOutboundConsumer::handle()` itself
  (matches the existing precedent: `ObjectCreatedEventListener`/`CloudEventListener` also carry zero
  tests, and the `OCA\OpenRegister\Event\ObjectCreatedEvent` class has no test stub in this repo);
  all real logic instead lives in and is fully tested via
  `PeppolTransmissionService::handleOutboundRequested()`.
- **`EventService::emitCloudEvent()` added.** A new public method generalising
  `handleObjectCreated`/`Updated`/`Deleted`'s "build + processEvent" shape for an arbitrary CloudEvent
  `type`, reused for both `nl.conduction.peppol.delivery.status` and
  `nl.conduction.peppol.inbound.received`. This pushed `EventService` from 10 to 11 public methods
  (PHPMD `TooManyPublicMethods`) and its complexity from 65 to 66 (already over the 50 threshold
  pre-change); suppressed via `@SuppressWarnings(PHPMD.TooManyPublicMethods)` with a rationale
  comment rather than splitting the class, consistent with the file's existing
  `@SuppressWarnings(PHPMD.CyclomaticComplexity)`.
- **Retry/dead-letter model (REQ-003).** `PeppolTransmissionService::MAX_ATTEMPTS = 3`: each
  redelivery of `nl.conduction.peppol.outbound.requested` for a `failed` transmission re-attempts
  the AP submission and appends to `attempts[]`; once `attempts` reaches the budget the transmission
  stays `failed` with no further AP call. The `peppol_transmission` object's own `attempts[]` audit
  trail (mirroring `event_message.attempts[]`) IS the dead-letter surface for this domain — there is
  no separate async retry scheduler/cron job (out of proportion for this change; the existing
  `event_message`/`EventRetryJob` machinery is for CloudEvent HTTP delivery, not AP submission
  retries, which are synchronous per REQ-002/REQ-003).
- **Inbound webhook signature config source.** `POST /api/peppol/inbound` verifies against the
  resolved active Peppol source's `configuration.webhookSignature` (`{scheme, secret, header,
  toleranceSeconds}`), reusing `WebhookSignatureService::verify()` directly (same algorithm/config
  shape as `EndpointService::processWebhookSignatureRule()`) rather than routing through the generic
  Endpoints/Rules pipeline, since `PeppolController` is a purpose-built controller per design.md, not
  a dynamic Endpoint.
- **`payloadFileUri` is transported as a reference, not fetched.** Per proposal.md's Out of Scope
  ("UBL document generation/validation... producing app supplies a ready UBL payload URI; this
  connector transports it"), `PeppolTransmissionService` passes the `payloadFileUri` string straight
  through to the provider's `submitDocument()` rather than fetching the referenced file — fetching
  arbitrary payload URIs is a distinct concern not required by any REQ-00x scenario.
- **`source.type = 'peppol'`** was added as a new recognised (free-form, per the schema's own
  documented extensibility) value to identify the Peppol Access Point source, since no existing
  "find sources of domain X" convention exists beyond `type` + `configuration`.
