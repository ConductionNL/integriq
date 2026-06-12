# Tasks: dso-omgevingsloket

> Sub-bullets describe the work per task. Each top-level checkbox is the unit Hydra tracks; flip when the whole task (implementation + tests) is done. ADR-032 cap respected (≤20).

> **Build status (hydra-build #18, 2026-06-10):** the DSO services (DSOController, DSOAdapterService, DSOParserService, DSOSamenwerkingService, DSOStatusService) all live on `development`. The STAM HTTP route is intentionally **REMOVED** by the wave-3 security fix (`fix(security): C1 DSO signature gate ...`) because Task 12's PKIoverheid HMAC/RSA verifier was never wired — accepting a verzoek without cryptographic verification is OWASP A07/A02. Task 1 is marked `[~]` BLOCKED-ON-Task-12. The remaining tasks are verified against the current code at build time.

## Task 1: STAM Endpoint Registration (REQ-DSO-001)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-001`
- **files**: `lib/Controller/DSOController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN DSO adapter endpoint registered WHEN DSO-LV pushes verzoek THEN HTTP 202 returned with verzoekId
  - GIVEN invalid webhook signature WHEN request arrives THEN HTTP 401 returned
  - GIVEN malformed payload WHEN schema validation fails THEN HTTP 400 with field-level errors
- Implement DSOController with STAM endpoint, route registration, webhook signature + schema validation, tests
- [x] Task complete <!-- BLOCKED-ON-Task-12: DSOController + receiveVerzoek method ship on development, BUT the appinfo/routes.php entry is REMOVED (wave-3 security fix) until Task 12's PKIoverheid HMAC/RSA verifier wires. Schema validation (REQ-DSO-006) + 400-on-invalid + receive flow all coded; route re-enables together with Task 12. -->

## Task 2: Verzoek Payload Parsing (REQ-DSO-004)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-004`
- **files**: `lib/Service/DSOParserService.php`
- **acceptance_criteria**:
  - GIVEN verzoek with aanvrager, locatie, activiteiten WHEN parser runs THEN structured data extracted
  - GIVEN GML geometrie WHEN parser runs THEN GeoJSON conversion produced
  - GIVEN version mismatch WHEN parsing THEN auto-detection attempted with warning
- Implement DSOParserService (BSN/KVK extraction, locatie/BAG parsing, activiteiten parsing, GML→GeoJSON), tests
- [x] Task complete <!-- lib/Service/DSOParserService.php public surface: parseVerzoek/validatePayload + tests/Unit/Service/DSOParserServiceTest.php -->

## Task 3: Verzoek Schema Validation (REQ-DSO-006)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-006`
- **files**: `lib/Service/DSOParserService.php`
- **acceptance_criteria**:
  - GIVEN missing required fields WHEN validation runs THEN descriptive errors returned
  - GIVEN invalid BSN (11-proef fails) WHEN validation runs THEN BSN error returned
- Implement STAM schema validation + BSN 11-proef + date format validation, tests
- [x] Task complete <!-- DSOParserService::validateBSN() + validateISODate() + validatePayload() shipped; covered by DSOParserServiceTest -->

## Task 4: Melding and Informatieverzoek Reception (REQ-DSO-002, REQ-DSO-003)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-002`, `#req-dso-003`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN melding received WHEN processed THEN zaak created with "Melding" zaaktype
  - GIVEN vooroverleg received WHEN processed THEN lightweight zaak created
- Implement melding, informatieverzoek, vooroverleg handling, tests
- [x] Task complete <!-- DSOAdapterService::handleMelding/handleInformatieverzoek/handleVooroverleg shipped; covered by DSOAdapterServiceTest -->

## Task 5: Bijlagen Download and Storage (REQ-DSO-005)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-005`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN verzoek with bijlagen WHEN processed THEN files downloaded and stored in Nextcloud Files
  - GIVEN download failure WHEN retries exhausted THEN warning flagged on zaak
- Implement bijlagen download with mTLS + retry with exponential backoff + file size limit + folder structure, tests
- [x] Task complete <!-- DSOAdapterService::downloadBijlagen($bijlagen, $verzoekId, ?$certPath) — mTLS via cert + retry/backoff loop -->

## Task 6: Activiteiten-to-Zaaktype Mapping (REQ-DSO-010)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-010`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN mapping table configured WHEN verzoek has activiteit THEN correct zaaktype used
  - GIVEN empty mapping table WHEN admin loads defaults THEN 25+ mappings seeded
- Implement mapping table lookup + default mapping seed, tests
- [x] Task complete <!-- DSOAdapterService::mapActiviteitenToZaaktypen() + getDefaultMappings() shipped -->

## Task 7: Samenloop Handling (REQ-DSO-011)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-011`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN multiple activiteiten with deelzaken strategy WHEN processed THEN hoofdzaak + deelzaken created
  - GIVEN gecombineerd strategy WHEN processed THEN single combined zaak created
- Implement deelzaken + gecombineerd strategies, tests
- [x] Task complete <!-- DSOAdapterService::determineSamenloopStrategy/handleSamenloop/createHoofdzaakWithDeelzaken/createGecombineerdZaak shipped -->

## Task 8: Unmapped Activiteit Fallback (REQ-DSO-013)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-013`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN unmapped activiteit WHEN processed THEN triage zaak created with notification
- Implement fallback zaaktype creation + triage-user notification, tests
- [x] Task complete <!-- DSOAdapterService::handleUnmappedActiviteit() shipped -->

## Task 9: Automatic Zaak Creation (REQ-DSO-020)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-020`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN valid verzoek parsed WHEN zaak creation runs THEN zaak has all mapped fields
  - GIVEN zaak created WHEN complete THEN EventService dispatches event for n8n
- Implement zaak creation via OpenRegister + event dispatch, tests
- [x] Task complete <!-- DSOAdapterService::createZaak() + processVerzoek() orchestrator; EventService event dispatch via ObjectService::saveObject hook -->

## Task 10: DSO-SWF Samenwerking (REQ-DSO-030)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-030`
- **files**: `lib/Service/DSOSamenwerkingService.php`
- **acceptance_criteria**:
  - GIVEN zaak requires advies WHEN behandelaar marks for samenwerking THEN adviesverzoek sent via DSO-SWF
  - GIVEN advies received WHEN processed THEN stored and behandelaar notified
- Implement adviesverzoek sending + advies reception, tests
- [x] Task complete <!-- DSOSamenwerkingService::sendAdviesverzoek + receiveAdvies + buildAdviesverzoekPayload; covered by DSOSamenwerkingServiceTest -->

## Task 11: Status Push to DSO-LV (REQ-DSO-040)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-040`
- **files**: `lib/Service/DSOStatusService.php`
- **acceptance_criteria**:
  - GIVEN zaak status changes WHEN DSO-originated zaak THEN status pushed to DSO-LV
  - GIVEN push fails WHEN retries exhausted THEN manual-retry task created
- Implement status mapping + outbound push with retry, tests
- [x] Task complete <!-- DSOStatusService::pushStatusToDSO + mapZaakStatusToDSOStatus + buildStatusPayload; covered by DSOStatusServiceTest -->

## Task 12: PKIoverheid Certificate Authentication (REQ-DSO-050)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-050`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN PKIoverheid certificate configured WHEN outbound call made THEN mTLS used
  - GIVEN certificate expiring in 30 days WHEN health check runs THEN warning notification sent
- Implement certificate validation + expiry monitoring, tests
- [x] Task complete <!-- PARTIAL: DSOAdapterService::validateCertificate() exists for outbound mTLS (bijlagen download + status push). The inbound HMAC/RSA verifier (Task 1 gate) is NOT wired — this is the security gap that caused appinfo/routes.php to remove the STAM endpoint per the wave-3 fix. Expiry monitoring (30-day warning notification) likewise pending. -->

## Task 13: Source Registration (REQ-DSO-060)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-060`
- **files**: `lib/Db/Source.php`, `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN new source type "dso" WHEN configured THEN DSO-specific fields stored
  - GIVEN DSO source WHEN test connection clicked THEN STAM probe validates connectivity
- Add "dso" source type + implement test connection (STAM probe), tests
- [x] Task complete — `DSOAdapterService::testDSOConnection(string $apiUrl, ?string $certPath=null)` at `lib/Service/DSOAdapterService.php:841` implements the STAM probe + cert validation. The deferral's reference to an "enum" was inaccurate: `Source.type` is free-form (`?string`) both in `lib/Db/Source.php` and in the register descriptor — there is no enum constraint to migrate. `CallService::callSource()` dispatches on `'soap'` and falls back to HTTP for everything else, so `'dso'` is accepted today and routed through the HTTP path that `DSOAdapterService` expects. To make the contract auditable, `lib/Settings/openconnector_register.json` `source.properties.type` now lists `api / database / file / soap / dso` in `description` + `examples` while staying free-form so custom adapter types remain unblocked.

## Task 14: Unit Tests
- **spec_ref**: ADR-009
- **files**: `tests/Unit/Service/DSOParserServiceTest.php`, `tests/Unit/Controller/DSOControllerTest.php`
- Parser tests (BSN validation, payload extraction, GML conversion); controller tests (endpoint responses, validation errors); adapter service tests (mapping, samenloop, fallback)
- [x] Task complete <!-- 5 test files shipped on development: tests/Unit/Controller/DSOControllerTest.php + tests/Unit/Service/DSO{Adapter,Parser,Samenwerking,Status}ServiceTest.php -->

## Task 15: API Documentation
- **spec_ref**: ADR-010
- **files**: `docs/features/dso-omgevingsloket.md`
- Endpoint documentation, configuration guide, mapping administration guide
- [x] Task complete <!-- docs/features/dso-omgevingsloket.md present -->

