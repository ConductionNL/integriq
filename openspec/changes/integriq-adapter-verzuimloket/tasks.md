# Tasks: integriq-adapter-verzuimloket

## Implementation tasks

### Task 1: Provider interface, registry and log binding
- **spec_ref**: `openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings`
- **files**: `lib/Service/Verzuimloket/VerzuimloketProviderInterface.php`, `lib/Service/Verzuimloket/VerzuimloketProviderRegistry.php`, `lib/Service/Verzuimloket/LogVerzuimloketProvider.php`, `lib/Exception/VerzuimloketProviderException.php`
- [x] Implement
- [x] Test (an unknown provider id fails naming itself and the ids that do exist)

### Task 2: Envelope translator with the literal-leak guard
- **spec_ref**: `.../spec.md#req-002-outbound-envelope-translation-with-a-literal-leak-guard`
- **files**: `lib/Service/Verzuimloket/VerzuimloketEnvelopeTranslator.php`, `lib/Exception/VerzuimloketTranslationException.php`, `tests/fixtures/verzuimloket/*.xml`
- [x] Implement (three meldingType kinds: eerste-melding, herhaalmelding, langdurig-relatief-verzuim)
- [x] Test (required-field table per kind; missing field raises before any XML)

### Task 3: Edukoppeling binding over the existing Digikoppeling transport
- **spec_ref**: `.../spec.md#req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings`
- **files**: `lib/Service/Verzuimloket/VerzuimloketEdukoppelingClient.php`
- [x] Implement (refuses closed without a resolvable certificateRef)
- [x] Test (fail-closed path with the credential resolver mocked)

### Task 4: Acknowledgement translation and the typed event
- **spec_ref**: `.../spec.md#req-003-duo-acknowledgement-translation-to-a-typed-event`
- **files**: `lib/Service/Verzuimloket/VerzuimloketAcknowledgementTranslator.php`, `lib/Event/VerzuimloketAcknowledgementReceivedEvent.php`
- [x] Implement (accepted/rejected mapping; kenmerk required before any dispatch)
- [x] Test (accepted, rejected, missing-kenmerk paths against recorded fixtures)

### Task 5: verzuim_message schema, audit persistence, VerzuimloketService
- **spec_ref**: `.../spec.md#req-005-per-message-audit-persistence-and-isolated-retry`, `#req-006-bsn-hygiene--raw-on-the-wire-hashed-at-rest`
- **files**: `lib/Settings/integriq_register.json`, `lib/Service/VerzuimloketService.php`
- [x] Implement (BSN SHA-256-hashed before persistence)
- [x] Test (sent/failed record persistence; hash-not-raw assertion; event dispatch on retour)

### Task 6: Push and retour controller endpoints
- **spec_ref**: `.../spec.md#req-004-push-endpoint-and-signed-retour-receiver`
- **files**: `lib/Controller/VerzuimloketController.php`, `appinfo/routes.php`
- [x] Implement (`berichten`: `#[NoAdminRequired]`; `retour`: `#[PublicPage]` + HMAC verification)
- [x] Test (200/400/503/502 on berichten; 401 on unsigned retour; 200 on unresolved-but-signed retour)

### Task 7: Retry job
- **spec_ref**: `.../spec.md#req-005-per-message-audit-persistence-and-isolated-retry`
- **files**: `lib/BackgroundJob/VerzuimloketRetryJob.php`, `appinfo/info.xml`
- [x] Implement (hourly TimedJob, per-message isolation, registered in info.xml)
- [x] Test (invokes retryFailed(); no-ops cleanly; contains a sweep-level exception)

### Task 8: Catalogue descriptor (ADR-017 Rule 1)
- **spec_ref**: `.../spec.md#req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings`
- **files**: `lib/Adapters/Verzuimloket/VerzuimloketAdapter.php`, `lib/AppInfo/Application.php`, `lib/Gateway/GatewayCatalogue.php`
- [x] Implement a catalogue card (id `verzuimloket`, category government) with the log/edukoppeling config schema
- [x] DI-register `VerzuimloketProviderRegistry` in `Application.php`; add a `planned`-claim entry to `GatewayCatalogue`
- [x] Card label/description carry no em-dashes and no Title Case (writing skill applied)

**Seed data:** deliberately none, same precedent as `integriq-adapter-rod`.

## Verification

- `openspec validate integriq-adapter-verzuimloket --strict`: exit code recorded in PR body
- `php -l` on every touched PHP file
- `vendor/bin/phpcs --standard=phpcs.xml <touched files>`
- `vendor/bin/phpstan analyse <touched files>`
- `vendor/bin/phpunit -c phpunit-unit.xml --filter Verzuimloket`
- `npm run lint`: no JS/CSS/Vue files touched (expected no-op)
- No PEM string and no raw BSN in any file this change adds
- `composer check:strict` and the hydra gates run once before push (see PR body for exit codes)

## Cross-repo follow-ups

- Tell learniq that `VerzuimloketAcknowledgementReceivedEvent` is ready to
  subscribe to
- M3(c): DUO certificate holder stays open; `edukoppeling` activation is
  gated on it, shared with ROD/OSO
