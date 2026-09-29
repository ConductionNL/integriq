# Tasks: integriq-adapter-oso

## Implementation tasks

### Task 1: Provider interface, registry and log/Kennisnet bindings (export leg)
- **spec_ref**: `openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings`
- **files**: `lib/Service/Oso/OsoProviderInterface.php`, `lib/Service/Oso/OsoProviderRegistry.php`, `lib/Service/Oso/LogOsoProvider.php`, `lib/Service/Oso/OsoKennisnetClient.php`, `lib/Exception/OsoProviderException.php`
- [x] Implement (Kennisnet binding reuses the shared Digikoppeling transport; refuses closed without a resolvable certificateRef)
- [x] Test (unknown provider id fails naming itself; fail-closed path)

### Task 2: Export envelope translator with the literal-leak guard
- **spec_ref**: `.../spec.md#req-002-export-envelope-translation-with-a-literal-leak-guard`, `#req-006-data-minimisation-is-a-pass-through-not-a-integriq-decision`
- **files**: `lib/Service/Oso/OsoExportEnvelopeTranslator.php`, `lib/Exception/OsoTranslationException.php`, `tests/fixtures/oso/*.xml`
- [x] Implement (categories transmitted as-is, included/excluded never reinterpreted)
- [x] Test (required-field table; missing field raises before any XML; excluded category still present)

### Task 3: Import translator and OsoDossierReceivedEvent
- **spec_ref**: `.../spec.md#req-003-import-parsing-into-learniqs-osoimportdossier-field-shape`
- **files**: `lib/Service/Oso/OsoImportTranslator.php`, `lib/Event/OsoDossierReceivedEvent.php`
- [x] Implement (XXE-hardened parse via shared StufXmlParser; output field names match OsoImportDossier exactly)
- [x] Test (complete dossier dispatches the event; malformed input logs and never dispatches)

### Task 4: Export acknowledgement translation and OsoAcknowledgementReceivedEvent
- **spec_ref**: `.../spec.md#req-004-push-export-signed-inbound-import-and-signed-export-retour`
- **files**: `lib/Service/Oso/OsoAcknowledgementTranslator.php`, `lib/Event/OsoAcknowledgementReceivedEvent.php`
- [x] Implement (kenmerk required before any dispatch)
- [x] Test (accepted, rejected, missing-kenmerk paths)

### Task 5: oso_message schema, audit persistence, OsoService
- **spec_ref**: `.../spec.md#req-005-per-message-audit-persistence-and-isolated-retry`
- **files**: `lib/Settings/integriq_register.json`, `lib/Service/OsoService.php`
- [x] Implement (sendExport/receiveImport/receiveReturn/retryFailed orchestration)
- [x] Test (export persistence; import persistence; event dispatch on both legs)

### Task 6: Export, import and retour controller endpoints
- **spec_ref**: `.../spec.md#req-004-push-export-signed-inbound-import-and-signed-export-retour`
- **files**: `lib/Controller/OsoController.php`, `appinfo/routes.php`
- [x] Implement (`export`: NoAdminRequired; `import`/`retour`: PublicPage + HMAC verification before any processing)
- [x] Test (200/400/503 on export; 401 on unsigned import/retour; 200 on unresolved-but-signed callbacks)

### Task 7: Retry job
- **spec_ref**: `.../spec.md#req-005-per-message-audit-persistence-and-isolated-retry`
- **files**: `lib/BackgroundJob/OsoRetryJob.php`, `appinfo/info.xml`
- [x] Implement (hourly TimedJob, retries only direction=export rows, per-message isolation)
- [x] Test (invokes retryFailed(); no-ops cleanly; contains a sweep-level exception)

### Task 8: Catalogue descriptor (ADR-017 Rule 1)
- **spec_ref**: `.../spec.md#req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings`
- **files**: `lib/Adapters/Oso/OsoAdapter.php`, `lib/AppInfo/Application.php`, `lib/Gateway/GatewayCatalogue.php`
- [x] Implement a catalogue card (id `oso`, category government) with the log/kennisnet config schema
- [x] DI-register `OsoProviderRegistry` in `Application.php`; add a `planned`-claim entry to `GatewayCatalogue`
- [x] Card label/description carry no em-dashes and no Title Case (writing skill applied); icon MUST already be registered in `src/icons.js` (verified before commit, per the verzuimloket lesson)

**Seed data:** deliberately none, same precedent as the other two adapters
in this lane.

## Verification

- `openspec validate integriq-adapter-oso --strict`: exit code recorded in PR body
- `php -l` on every touched PHP file
- `vendor/bin/phpcs --standard=phpcs.xml <touched files>`
- `vendor/bin/phpstan analyse <touched files>`
- `vendor/bin/phpmd lib text phpmd.xml --baseline-file phpmd.baseline.xml` with an isolated `HOME` (shared pdepend-cache lesson from integriq-adapter-rod)
- `vendor/bin/phpunit -c phpunit-unit.xml --filter Oso`
- `npm run lint`: no JS/CSS/Vue files touched (expected no-op)
- No PEM string, no raw learner-identifying data leak in any file this change adds
- `composer check:strict` and the hydra gates run once before push (see PR body for exit codes)

## Cross-repo follow-ups

- Tell learniq's `oso-inbound-contract` owner that `OsoDossierReceivedEvent`
  is ready for its `OsoImportDossier` materialisation listener to subscribe
  to
- M3(c): Kennisnet OSO aansluiting approval stays open; `kennisnet` binding
  activation is gated on it
- If `OsoImportDossier`'s field names shift before `oso-inbound-contract`
  merges, `OsoDossierReceivedEvent`/`OsoImportTranslator` need a matching
  follow-up (proposal.md Risk 1)
