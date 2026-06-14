# Tasks: stuf-adapter

> Sub-bullets describe the work per task. Each top-level checkbox is the unit Hydra tracks; flip when the whole task (implementation + tests) is done. ADR-032 cap respected (≤20).

## Task 1: StUF-BG Inbound Person Query (REQ-STUF-001)
- **spec_ref**: `specs/stuf-adapter/spec.md#req-stuf-001`
- **files**: `lib/Service/StUFBGService.php`
- **acceptance_criteria**:
  - GIVEN npsLv01 request with BSN WHEN processed THEN npsLa01 response returned with person data
  - GIVEN BSN not found WHEN searched THEN empty npsLa01 returned (no error)
  - GIVEN malformed XML WHEN received THEN Fo01 fault returned
- Implement StUFBGService (npsLv01/npsLa01 handling, SOAP XML parsing, scope field filtering), tests
- [x] Task complete

## Task 2: StUF-BG Field Mapping (REQ-STUF-002)
- **spec_ref**: `specs/stuf-adapter/spec.md#req-stuf-002`
- **files**: `lib/Service/StUFFieldMapper.php`
- **acceptance_criteria**:
  - GIVEN default BRP mapping WHEN person data mapped THEN StUF-BG XML fields correct
  - GIVEN custom field mapping WHEN configured THEN custom mapping used
  - GIVEN ISO date WHEN mapped THEN converted to YYYYMMDD format
- Implement StUFFieldMapper (configurable mappings, date format transformation, nested object mapping verblijfsadres), tests
- [x] Task complete

## Task 3: StUF-BG Address Query (REQ-STUF-004)
- **spec_ref**: `specs/stuf-adapter/spec.md#req-stuf-004`
- **files**: `lib/Service/StUFBGService.php`
- **acceptance_criteria**:
  - GIVEN adrLv01 with postcode WHEN searched THEN matching addresses returned
  - GIVEN no match WHEN searched THEN empty adrLa01 returned
- Implement adrLv01/adrLa01 handling, tests
- [x] Task complete

## Task 4: StUF XML Builder (REQ-STUF-001, REQ-STUF-002)
- **spec_ref**: `specs/stuf-adapter/spec.md#req-stuf-001`
- **files**: `lib/Service/StUFXMLBuilder.php`
- **acceptance_criteria**:
  - GIVEN person data WHEN building npsLa01 THEN valid StUF-BG 3.10 XML produced
  - GIVEN error condition WHEN building Fo01 THEN valid SOAP fault produced
- Implement StUFXMLBuilder for response generation + namespace management for StUF-BG 3.10 + Fo01 fault message generation, tests
- [x] Task complete

## Task 5: StUF-BG Outbound Query (REQ-STUF-010)
- **spec_ref**: `specs/stuf-adapter/spec.md#req-stuf-010`
- **files**: `lib/Service/StUFBGService.php`
- **acceptance_criteria**:
  - GIVEN StUF source configured WHEN BSN queried THEN npsLv01 sent via SOAPService
  - GIVEN Fo01 fault returned WHEN processed THEN mapped to CallLog entry
- Implement outbound npsLv01 query via SOAPService + response parsing + SynchronizationService integration, tests
- [x] Task complete

## Task 6: PKIoverheid mTLS Authentication (REQ-STUF-011)
- **spec_ref**: `specs/stuf-adapter/spec.md#req-stuf-011`
- **files**: `lib/Service/CallService.php`
- **acceptance_criteria**:
  - GIVEN PKIoverheid certificate in source WHEN SOAP request made THEN mTLS used
  - GIVEN expired certificate WHEN connection attempted THEN error logged
- Verify existing CallService certificate handling works for StUF, tests
- [x] Task complete

## Task 7: WS-Security UsernameToken (REQ-STUF-012)
- **spec_ref**: `specs/stuf-adapter/spec.md#req-stuf-012`
- **files**: `lib/Service/AuthenticationService.php`, `lib/Service/StUFBGService.php`
- **acceptance_criteria**:
  - GIVEN WS-Security configured WHEN SOAP sent THEN wsse:Security header included
  - GIVEN PasswordDigest mode WHEN building header THEN Base64(SHA1(Nonce+Created+Password))
- Add WS-Security UsernameToken auth type + implement PasswordDigest hashing, tests
- [x] Task complete

## Task 8: StUF-ZKN Inbound Zaak Management (REQ-STUF-020)
- **spec_ref**: `specs/stuf-adapter/spec.md#req-stuf-020`
- **files**: `lib/Service/StUFZKNService.php`
- **acceptance_criteria**:
  - GIVEN zakLk01 message WHEN processed THEN zaak created in OpenRegister
  - GIVEN zakLv01 query WHEN processed THEN zaak data returned in StUF-ZKN format
- Implement StUFZKNService (zakLk01 create/update + zakLv01/zakLa01 query handling), tests
- [x] Task complete

## Task 9: Unit Tests
- **spec_ref**: ADR-009
- **files**: `tests/Unit/Service/StUFFieldMapperTest.php`, `tests/Unit/Service/StUFXMLBuilderTest.php`
- Field mapper tests (BRP mapping, date conversion, nested objects); XML builder tests (valid StUF-BG output, Fo01 fault); service tests (query handling, response parsing)
- [x] Task complete

## Task 10: API Documentation
- **spec_ref**: ADR-010
- **files**: `docs/features/stuf-adapter.md`
- WSDL endpoint documentation + field mapping configuration guide + WS-Security setup guide
- [x] Task complete
