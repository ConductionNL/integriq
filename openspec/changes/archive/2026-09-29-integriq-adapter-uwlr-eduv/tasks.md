# Tasks: integriq-adapter-uwlr-eduv

## Implementation tasks

### Task 1: Provider interface, registry and log/uwlr-eduv bindings
- **spec_ref**: `openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings`
- **files**: `lib/Service/UwlrEduV/UwlrEduVProviderInterface.php`, `lib/Service/UwlrEduV/UwlrEduVProviderRegistry.php`, `lib/Service/UwlrEduV/LogUwlrEduVProvider.php`, `lib/Service/UwlrEduV/UwlrEduVKennisnetClient.php`, `lib/Exception/UwlrEduVProviderException.php`
- [x] Implement (uwlr-eduv binding reuses the shared Digikoppeling transport; refuses closed without a resolvable certificateRef)
- [x] Test (unknown provider id fails naming itself; fail-closed path)

### Task 2: UWLR export envelope translator (three subtypes)
- **spec_ref**: `.../spec.md#req-002-uwlr-export-envelope-translation-across-three-subtypes`
- **files**: `lib/Service/UwlrEduV/UwlrExportEnvelopeTranslator.php`, `lib/Exception/UwlrEduVTranslationException.php`, `tests/fixtures/uwlr-eduv/*.xml`
- [x] Implement (pupil/group/teacher subtypes, eckId required, literal-leak guard)
- [x] Test (each subtype; missing eckId raises before any XML)

### Task 3: Edu-V export envelope translator (three qualified data services)
- **spec_ref**: `.../spec.md#req-003-edu-v-export-envelope-translation-across-three-qualified-data-services`
- **files**: `lib/Service/UwlrEduV/EduVExportEnvelopeTranslator.php`
- [x] Implement (onderwijsdeelnemers/onderwijsgroepen/onderwijsmedewerkers, each naming its own targetSchema)
- [x] Test (three distinct targetSchema values; unknown data service rejected)

### Task 4: Basispoort sync translator
- **spec_ref**: `.../spec.md#req-004-basispoort-sync-translation-with-sso-hand-off`
- **files**: `lib/Service/UwlrEduV/BasispoortSyncTranslator.php`
- [x] Implement (PO pupil/group/staff export + ssoAudience hand-off)
- [x] Test (complete payload; missing ssoAudience raises)

### Task 5: Entree content sync translator
- **spec_ref**: `.../spec.md#req-005-entree-content-sso-hand-off-translation`
- **files**: `lib/Service/UwlrEduV/EntreeContentSyncTranslator.php`
- [x] Implement (VO content SSO hand-off, structurally distinct from entree-surfconext-sso-contract)
- [x] Test (complete payload; missing schoolBrin raises)

### Task 6: Shared acknowledgement translation and event
- **spec_ref**: `.../spec.md#req-006-shared-acknowledgement-translation-and-event-dispatch`
- **files**: `lib/Service/UwlrEduV/UwlrEduVAcknowledgementTranslator.php`, `lib/Event/UwlrEduVAcknowledgementReceivedEvent.php`
- [x] Implement (kenmerk required before any dispatch)
- [x] Test (accepted, rejected, missing-kenmerk paths)

### Task 7: uwlr_eduv_message schema, audit persistence, UwlrEduVService
- **spec_ref**: `.../spec.md#req-007-per-target-audit-persistence-and-isolated-retry`
- **files**: `lib/Settings/integriq_register.json`, `lib/Service/UwlrEduVService.php`
- [x] Implement (send/sync/receiveReturn/retryFailed orchestration across all four targets)
- [x] Test (per-target persistence; event dispatch on retour)

### Task 8: Push/sync and retour controller endpoints
- **spec_ref**: `.../spec.md#req-008-pushsync-endpoints-and-a-shared-signed-retour-endpoint`
- **files**: `lib/Controller/UwlrEduVController.php`, `appinfo/routes.php`
- [x] Implement (`uwlr`/`eduV`/`basispoort`/`entreeContent`: NoAdminRequired; `retour`: PublicPage + HMAC verification before any processing)
- [x] Test (200/400/503 on each send endpoint; 401 on unsigned retour)

### Task 9: Retry job
- **spec_ref**: `.../spec.md#req-007-per-target-audit-persistence-and-isolated-retry`
- **files**: `lib/BackgroundJob/UwlrEduVRetryJob.php`, `appinfo/info.xml`
- [x] Implement (hourly TimedJob, retries only status=failed rows across all targets, per-message isolation)
- [x] Test (invokes retryFailed(); no-ops cleanly; contains a sweep-level exception)

### Task 10: Catalogue descriptor (ADR-017 Rule 1)
- **spec_ref**: `.../spec.md#req-009-catalogue-descriptor-adr-017-rule-1`
- **files**: `lib/Adapters/UwlrEduV/UwlrEduVAdapter.php`, `lib/AppInfo/Application.php`, `lib/Gateway/GatewayCatalogue.php`
- [x] Implement a catalogue card (id `uwlr-eduv`, category government) with the log/uwlr-eduv config schema
- [x] DI-register `UwlrEduVProviderRegistry` in `Application.php`; add a `planned`-claim entry to `GatewayCatalogue`
- [x] Card label/description carry no em-dashes and no Title Case (writing skill applied); icon MUST already be registered in `src/icons.js` (verified before commit)

**Seed data:** deliberately none, same precedent as the other three
adapters in this lane.

## Verification

- `openspec validate integriq-adapter-uwlr-eduv --strict`: exit code recorded in PR body
- `php -l` on every touched PHP file
- `vendor/bin/phpcs --standard=phpcs.xml <touched files>`
- `vendor/bin/phpstan analyse <touched files>`
- `vendor/bin/phpmd lib text phpmd.xml --baseline-file phpmd.baseline.xml` with an isolated `HOME` (shared pdepend-cache lesson from integriq-adapter-rod)
- `vendor/bin/phpunit -c phpunit-unit.xml --filter UwlrEduV`
- `npm run lint`: no JS/CSS/Vue files touched (expected no-op)
- No PEM string, no raw learner-identifying data leak in any file this change adds
- `composer check:strict` and the hydra gates run once before push (see PR body for exit codes)

## Cross-repo follow-ups

- Tell learniq's `uwlr-eduv-basispoort-contract` owner (PR #914) that all
  four `UwlrEduVController` endpoints and `UwlrEduVAcknowledgementReceivedEvent`
  are ready for its `DataMappingProfile`-driven job runner to call/subscribe to.
- M3(c): Edu-V keurmerk (per data service), Basispoort connection agreement
  and any UWLR-specific access agreement stay open; `uwlr-eduv` provider
  binding activation is gated on each independently.
- If `uwlr-eduv-basispoort-contract`'s seed field names shift before merge,
  the four translators' required-field lookups need a matching follow-up
  (proposal.md Risk 2).
- The real UWLR/Edu-V/Basispoort/Entree-content acknowledgement wire
  formats are unknown pending certification (proposal.md Risk 1) —
  `UwlrEduVAcknowledgementTranslator`'s generic shape is a placeholder to
  revisit once any one of the four is certified.
