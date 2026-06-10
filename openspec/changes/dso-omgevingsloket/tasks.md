# Tasks: dso-omgevingsloket

> Sub-bullets describe the work per task. Each top-level checkbox is the unit Hydra tracks; flip when the whole task (implementation + tests) is done. ADR-032 cap respected (≤20).

## Task 1: STAM Endpoint Registration (REQ-DSO-001)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-001`
- **files**: `lib/Controller/DSOController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN DSO adapter endpoint registered WHEN DSO-LV pushes verzoek THEN HTTP 202 returned with verzoekId
  - GIVEN invalid webhook signature WHEN request arrives THEN HTTP 401 returned
  - GIVEN malformed payload WHEN schema validation fails THEN HTTP 400 with field-level errors
- Implement DSOController with STAM endpoint, route registration, webhook signature + schema validation, tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 2: Verzoek Payload Parsing (REQ-DSO-004)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-004`
- **files**: `lib/Service/DSOParserService.php`
- **acceptance_criteria**:
  - GIVEN verzoek with aanvrager, locatie, activiteiten WHEN parser runs THEN structured data extracted
  - GIVEN GML geometrie WHEN parser runs THEN GeoJSON conversion produced
  - GIVEN version mismatch WHEN parsing THEN auto-detection attempted with warning
- Implement DSOParserService (BSN/KVK extraction, locatie/BAG parsing, activiteiten parsing, GML→GeoJSON), tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 3: Verzoek Schema Validation (REQ-DSO-006)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-006`
- **files**: `lib/Service/DSOParserService.php`
- **acceptance_criteria**:
  - GIVEN missing required fields WHEN validation runs THEN descriptive errors returned
  - GIVEN invalid BSN (11-proef fails) WHEN validation runs THEN BSN error returned
- Implement STAM schema validation + BSN 11-proef + date format validation, tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 4: Melding and Informatieverzoek Reception (REQ-DSO-002, REQ-DSO-003)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-002`, `#req-dso-003`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN melding received WHEN processed THEN zaak created with "Melding" zaaktype
  - GIVEN vooroverleg received WHEN processed THEN lightweight zaak created
- Implement melding, informatieverzoek, vooroverleg handling, tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 5: Bijlagen Download and Storage (REQ-DSO-005)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-005`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN verzoek with bijlagen WHEN processed THEN files downloaded and stored in Nextcloud Files
  - GIVEN download failure WHEN retries exhausted THEN warning flagged on zaak
- Implement bijlagen download with mTLS + retry with exponential backoff + file size limit + folder structure, tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 6: Activiteiten-to-Zaaktype Mapping (REQ-DSO-010)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-010`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN mapping table configured WHEN verzoek has activiteit THEN correct zaaktype used
  - GIVEN empty mapping table WHEN admin loads defaults THEN 25+ mappings seeded
- Implement mapping table lookup + default mapping seed, tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 7: Samenloop Handling (REQ-DSO-011)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-011`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN multiple activiteiten with deelzaken strategy WHEN processed THEN hoofdzaak + deelzaken created
  - GIVEN gecombineerd strategy WHEN processed THEN single combined zaak created
- Implement deelzaken + gecombineerd strategies, tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 8: Unmapped Activiteit Fallback (REQ-DSO-013)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-013`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN unmapped activiteit WHEN processed THEN triage zaak created with notification
- Implement fallback zaaktype creation + triage-user notification, tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 9: Automatic Zaak Creation (REQ-DSO-020)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-020`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN valid verzoek parsed WHEN zaak creation runs THEN zaak has all mapped fields
  - GIVEN zaak created WHEN complete THEN EventService dispatches event for n8n
- Implement zaak creation via OpenRegister + event dispatch, tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 10: DSO-SWF Samenwerking (REQ-DSO-030)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-030`
- **files**: `lib/Service/DSOSamenwerkingService.php`
- **acceptance_criteria**:
  - GIVEN zaak requires advies WHEN behandelaar marks for samenwerking THEN adviesverzoek sent via DSO-SWF
  - GIVEN advies received WHEN processed THEN stored and behandelaar notified
- Implement adviesverzoek sending + advies reception, tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 11: Status Push to DSO-LV (REQ-DSO-040)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-040`
- **files**: `lib/Service/DSOStatusService.php`
- **acceptance_criteria**:
  - GIVEN zaak status changes WHEN DSO-originated zaak THEN status pushed to DSO-LV
  - GIVEN push fails WHEN retries exhausted THEN manual-retry task created
- Implement status mapping + outbound push with retry, tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 12: PKIoverheid Certificate Authentication (REQ-DSO-050)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-050`
- **files**: `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN PKIoverheid certificate configured WHEN outbound call made THEN mTLS used
  - GIVEN certificate expiring in 30 days WHEN health check runs THEN warning notification sent
- Implement certificate validation + expiry monitoring, tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 13: Source Registration (REQ-DSO-060)
- **spec_ref**: `specs/dso-omgevingsloket/spec.md#req-dso-060`
- **files**: `lib/Db/Source.php`, `lib/Service/DSOAdapterService.php`
- **acceptance_criteria**:
  - GIVEN new source type "dso" WHEN configured THEN DSO-specific fields stored
  - GIVEN DSO source WHEN test connection clicked THEN STAM probe validates connectivity
- Add "dso" source type + implement test connection (STAM probe), tests
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 14: Unit Tests
- **spec_ref**: ADR-009
- **files**: `tests/Unit/Service/DSOParserServiceTest.php`, `tests/Unit/Controller/DSOControllerTest.php`
- Parser tests (BSN validation, payload extraction, GML conversion); controller tests (endpoint responses, validation errors); adapter service tests (mapping, samenloop, fallback)
- [~] Task complete — deferred to downstream cycle (handoff)

## Task 15: API Documentation
- **spec_ref**: ADR-010
- **files**: `docs/features/dso-omgevingsloket.md`
- Endpoint documentation, configuration guide, mapping administration guide
- [~] Task complete — deferred to downstream cycle (handoff)
