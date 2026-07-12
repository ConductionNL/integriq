# Tasks — peppol-access-point-connector

## 1. Data model and seed

### Task 1: Declare the `peppol_transmission` schema and seed data
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-003-event-driven-outbound-transmission-with-status-lifecycle`
- **files**: `lib/Settings/openconnector_register.json`, `lib/Settings/openconnector_seed_data.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN a `peppol_transmission` schema exists with the `status` enum `queued|sent|delivered|rejected|failed` and an `x-openregister-notifications` block firing on `failed`
  - GIVEN the seed data WHEN the app installs THEN 3 example transmissions and one `provider: log` sandbox source are present (nil-UUID object refs, `MOCK-PEPPOL-*` ids)
- [ ] Implement
- [ ] Test

## 2. Provider abstraction

### Task 2: Add the AP provider interface with log and generic-REST bindings
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-002-access-point-provider-abstraction-with-log-and-generic-rest-bindings`
- **files**: `lib/Service/Peppol/PeppolAccessPointProviderInterface.php`, `lib/Service/Peppol/LogPeppolAccessPointProvider.php`, `lib/Service/Peppol/RestPeppolAccessPointProvider.php`
- **acceptance_criteria**:
  - GIVEN `configuration.provider: log` WHEN a document is submitted THEN a `MOCK-PEPPOL-<n>` id is returned with no HTTP call and no credential read
  - GIVEN `configuration.provider: rest` WHEN a call is dispatched THEN it routes through `BrokeredCallService` with the AP key injected via `credentialRef` and absent from config/logs
- [ ] Implement
- [ ] Test

## 3. Participant lookup

### Task 3: Add the participant/SMP lookup endpoint
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-001-peppol-participant--smp-lookup-endpoint`
- **files**: `lib/Controller/PeppolController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a registered participant WHEN `GET /api/peppol/participants/{peppolId}` is called THEN `{exists:true, supportedDocTypes:[...]}` is returned via the selected provider
  - GIVEN a malformed `peppolId` WHEN the endpoint runs THEN HTTP 400 is returned, never a 500
- [ ] Implement
- [ ] Test

## 4. Outbound transmission

### Task 4: Consume outbound.requested and drive the transmission lifecycle
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-003-event-driven-outbound-transmission-with-status-lifecycle`
- **files**: `lib/Service/PeppolOutboundConsumer.php`, `lib/Service/PeppolTransmissionService.php`
- **acceptance_criteria**:
  - GIVEN a `nl.conduction.peppol.outbound.requested` event WHEN consumed THEN a `peppol_transmission` moves `queued`→`sent` with the AP `transmissionId`, idempotent per `objectUri`+`documentType`
  - GIVEN a submission that keeps failing WHEN the retry budget is exhausted THEN the transmission lands on the dead-letter surface, status `failed`
- [ ] Implement
- [ ] Test

### Task 5: Emit delivery-status CloudEvents on every state change
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-004-delivery-status-cloudevents-on-every-state-change`
- **files**: `lib/Service/PeppolTransmissionService.php`
- **acceptance_criteria**:
  - GIVEN a transmission going `queued`→`sent`→`delivered` WHEN the transitions occur THEN exactly three `nl.conduction.peppol.delivery.status` events are emitted via `EventService`, each carrying the `transmissionId` and new `status`
  - GIVEN a rejection WHEN emitted THEN the payload has `status='rejected'` and a non-empty `detail`
- [ ] Implement
- [ ] Test

## 5. Inbound receive

### Task 6: Add the signed inbound receive webhook that republishes events
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#req-005-inbound-receive-webhook-that-republishes-ap-callbacks-as-events`
- **files**: `lib/Controller/PeppolController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a correctly signed delivery callback WHEN `POST /api/peppol/inbound` receives it THEN the matching transmission advances (`delivered`/`rejected`) and a status event is emitted
  - GIVEN an unsigned/tampered callback WHEN it arrives THEN the response is HTTP 401 with no state change and no event; a signed inbound-document notification republishes `nl.conduction.peppol.inbound.received`
- [ ] Implement
- [ ] Test

## 6. Localisation

### Task 7: Ship English source keys with Dutch translations
- **spec_ref**: `openspec/specs/peppol-access-point-connector/spec.md#non-functional-requirements`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN new user-facing strings WHEN the app is loaded in `nl` THEN Dutch translations render for every English source key
- [ ] Implement
- [ ] Test

### NL translations (plain bullets, not tracked checkboxes)
- `Peppol participant not found` → `Peppol-deelnemer niet gevonden`
- `Invalid Peppol identifier` → `Ongeldige Peppol-identificatie`
- `Transmission queued` → `Verzending in wachtrij`
- `Transmission delivered` → `Verzending afgeleverd`
- `Transmission rejected` → `Verzending geweigerd`
- `Access point credential missing` → `Toegangspunt-inloggegeven ontbreekt`

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria (sandbox `log` provider path)
- [ ] Code review against spec requirements
