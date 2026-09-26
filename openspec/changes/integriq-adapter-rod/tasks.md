# Tasks: integriq-adapter-rod

## Implementation tasks

### Task 1: Provider interface, registry and log binding
- **spec_ref**: `openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings`
- **files**: `lib/Service/Rod/RodProviderInterface.php`, `lib/Service/Rod/RodProviderRegistry.php`, `lib/Service/Rod/LogRodProvider.php`, `lib/Exception/RodProviderException.php`
- [x] Implement
- [x] Test (an unknown provider id fails naming itself and the ids that do exist)

### Task 2: Envelope translator with the literal-leak guard
- **spec_ref**: `.../spec.md#req-002-outbound-envelope-translation-with-a-literal-leak-guard`
- **files**: `lib/Service/Rod/RodEnvelopeTranslator.php`, `lib/Exception/RodTranslationException.php`, `tests/fixtures/rod/*.xml`
- [x] Implement (four berichtsoort kinds: inschrijving, uitschrijving, verblijfsgegevens, schooladvies)
- [x] Test (each kind's required-field table; a missing field raises before any XML is built)

### Task 3: Edukoppeling binding over the existing Digikoppeling transport
- **spec_ref**: `.../spec.md#req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings`
- **files**: `lib/Service/Rod/RodEdukoppelingClient.php`
- [x] Implement (constructor-injects `PkiOverheidCredentialResolver`, `WusProfileService`; refuses closed without a resolvable certificateRef)
- [x] Test (fail-closed path with the credential resolver mocked)

### Task 4: Acknowledgement/signaalcode translation and the typed event
- **spec_ref**: `.../spec.md#req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event`
- **files**: `lib/Service/Rod/RodAcknowledgementTranslator.php`, `lib/Event/RodAcknowledgementReceivedEvent.php`
- [x] Implement (accepted/rejected mapping from signaalcode; kenmerk required before any dispatch)
- [x] Test (accepted, rejected, and missing-kenmerk paths, against recorded fixtures)

### Task 5: rod_message schema, audit persistence, RodService
- **spec_ref**: `.../spec.md#req-005-per-message-audit-persistence-and-isolated-retry`, `#req-006-bsn-hygiene--raw-on-the-wire-hashed-at-rest`
- **files**: `lib/Settings/integriq_register.json`, `lib/Service/RodService.php`
- [x] Implement (BSN SHA-256-hashed before any persistence; raw BSN never logged)
- [x] Test (sent/failed record persistence; hash-not-raw assertion; event dispatch on retour)

### Task 6: Push and retour controller endpoints
- **spec_ref**: `.../spec.md#req-004-push-endpoint-and-signed-retour-receiver`
- **files**: `lib/Controller/RodController.php`, `appinfo/routes.php`
- [x] Implement (`berichten`: `#[NoAdminRequired]`; `retour`: `#[PublicPage]` + `WebhookSignatureService` verification before any processing)
- [x] Test (200/400/503/502 on berichten; 401 on unsigned retour; 200 `{received:true}` on unresolved-but-signed retour)

### Task 7: Retry job
- **spec_ref**: `.../spec.md#req-005-per-message-audit-persistence-and-isolated-retry`
- **files**: `lib/BackgroundJob/RodRetryJob.php`, `appinfo/info.xml`
- [x] Implement (hourly TimedJob, `allowParallelRuns=false`, per-message isolation, registered in info.xml)
- [x] Test (invokes retryFailed(); no-ops cleanly; contains a sweep-level exception)

### Task 8: Catalogue descriptor (ADR-017 Rule 1) and DI wiring
- **spec_ref**: `.../spec.md#req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings`
- **files**: `lib/Adapters/Rod/RodAdapter.php`, `lib/AppInfo/Application.php`, `lib/Gateway/GatewayCatalogue.php`
- [x] Implement a catalogue card (id `rod`, category government) with the log/edukoppeling config schema — no new menu item, no `/beheer` route
- [x] DI-register `RodProviderRegistry` in `Application.php`; add a `planned`-claim `rod` entry to `GatewayCatalogue` (M3(c) is the gate, not the code)
- [x] Card label/description carry no em-dashes and no Title Case (writing skill applied)

**Seed data:** deliberately none — checked against precedent (see design.md
"Seed Data"): neither `iwmo_ijw_message` nor `digitalPostMessage` seeds any
rows, and most adapter families seed no `source` row either. The
`tests/fixtures/rod/` fixtures serve the testability need instead.

## Verification

- `openspec validate integriq-adapter-rod --strict`: PASS (exit 0)
- `php -l` on every touched PHP file: PASS, all files
- `vendor/bin/phpcs --standard=phpcs.xml <touched files>`: 0 errors on every touched file (1 pre-existing inherited warning on `GatewayCatalogue::entries()`'s own docblock line, not touched by this change)
- `vendor/bin/phpstan analyse <touched files>`: no errors
- `vendor/bin/phpunit -c phpunit-unit.xml --filter Rod`: 98 tests, 531 assertions, all green
- `npm run lint`: no JS/CSS/Vue files touched by this change (expected no-op)
- No PEM string and no raw BSN in any file this change adds
- `composer check:strict` and the hydra gates run once before push (see PR body for exit codes)

## Cross-repo follow-ups

- Tell learniq that `RodAcknowledgementReceivedEvent` is ready for its
  `ExchangeRejectionDetail` worklist to subscribe to — wiring that
  subscription is learniq's change, not this one's
- M3(c): who holds the DUO software-vendor certificate centrally stays open
  in `decisions.md`; `edukoppeling` binding activation is gated on it, not
  on any task above
