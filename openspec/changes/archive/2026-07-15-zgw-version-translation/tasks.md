# Tasks — zgw-version-translation

## 1. Data model

### Task 1: Declare the `zgw_version_translation_log` schema
- **spec_ref**: `openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-persistence-and-observability-zgw_version_translation_log-req-004`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN a `zgw_version_translation_log` schema exists with `resource`, `fromVersion`, `toVersion`, `status`, `error`, `translatedAt`
  - GIVEN the register's schemas list WHEN compared to `components.schemas` THEN the new schema slug is listed in both places
- [x] Implement
- [x] Test

## 2. Translator seam

### Task 2: Add ZgwResourceTranslatorInterface and exceptions
- **spec_ref**: `openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001`
- **files**: `lib/Service/ZgwVersion/ZgwResourceTranslatorInterface.php`, `lib/Exception/ZgwLiteralLeakException.php`, `lib/Exception/ZgwVersionTranslationException.php`
- **acceptance_criteria**:
  - GIVEN the interface WHEN a resource translator implements it THEN `getResource()`, `translateToV16()`, `translateToV1x()` are all present
- [x] Implement
- [x] Test

### Task 3: Implement the 7 resource translators + literal-leak guards
- **spec_ref**: `openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001`
- **files**: `lib/Service/ZgwVersion/{ZaakTranslator,ZaakTypeTranslator,InformatieObjectTranslator,BesluitTranslator,RolTranslator,StatusTranslator,ResultaatTranslator}.php`
- **acceptance_criteria**:
  - GIVEN a conformant `1.0` payload for each of the 7 resources WHEN translated to `1.6` and back THEN the round trip is lossless for the 6 structurally-identical resources
  - GIVEN a `resultaat` payload WHEN translated `1.0→1.6` THEN `resultaattoelichting` is absent; WHEN translated `1.6→1.0` (legacy) THEN `resultaattoelichting` mirrors `toelichting`
  - GIVEN a `rol` payload with no `betrokkeneType` WHEN translated to `1.6` THEN `betrokkeneType` defaults to `natuurlijk_persoon`; WHEN translated back to `1.0` THEN `betrokkeneType` is stripped
  - GIVEN a `zaaktype` payload with a non-array `besluittypen`/`informatieobjecttypen` WHEN translated either direction THEN `ZgwLiteralLeakException` is raised
  - GIVEN any resource carrying `vertrouwelijkheidaanduiding` (or `enkelvoudiginformatieobject`'s `status`) outside its documented enum WHEN translated THEN `ZgwLiteralLeakException` is raised
  - GIVEN a payload missing one of a resource's required fields WHEN translated THEN `ZgwLiteralLeakException` is raised naming the field
- [x] Implement
- [x] Test

## 3. Negotiation + orchestration

### Task 4: Add ZgwVersionNegotiationService
- **spec_ref**: `openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-version-negotiation-with-passthrough-default-req-002`
- **files**: `lib/Service/ZgwVersionNegotiationService.php`, `lib/Exception/ZgwUnknownVersionException.php`, `lib/Exception/ZgwVersionNotImplementedException.php`
- **acceptance_criteria**:
  - GIVEN no version signal WHEN resolved THEN both `fromVersion`/`toVersion` default to `"1.0"`
  - GIVEN both a body field and a header WHEN resolved THEN the body field wins
  - GIVEN `toVersion: "0.9"` WHEN asserted THEN `ZgwUnknownVersionException` is raised
  - GIVEN `toVersion: "2.0"` WHEN asserted THEN `ZgwVersionNotImplementedException` is raised, distinguishable from unknown-version
  - GIVEN a query parameter set containing `expand` WHEN stripped THEN the key is absent from the result
- [x] Implement
- [x] Test

### Task 5: Add ZgwVersionTranslationService (orchestration + persistence)
- **spec_ref**: `openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-persistence-and-observability-zgw_version_translation_log-req-004`
- **files**: `lib/Service/ZgwVersionTranslationService.php`, `lib/Exception/ZgwUnknownResourceException.php`
- **acceptance_criteria**:
  - GIVEN `fromVersion === toVersion` WHEN `translate()` runs THEN the payload is returned unchanged AND a log record with `status: passthrough` is persisted, no translator invoked
  - GIVEN a successful translation WHEN it completes THEN a log record with `status: success` is persisted
  - GIVEN a translator raises `ZgwLiteralLeakException` WHEN `translate()` runs THEN a log record with `status: failed` and `error` set is persisted AND the exception still propagates
  - GIVEN a resource outside the 7 supported WHEN `translate()` runs THEN `ZgwUnknownResourceException` is raised before any translator is resolved
- [x] Implement
- [x] Test

## 4. REST surface

### Task 6: Add ZgwVersionTranslateController + route
- **spec_ref**: `openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-rest-surface-for-sibling-apps-and-external-consumers-req-003`
- **files**: `lib/Controller/ZgwVersionTranslateController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated session and a conformant payload WHEN `POST /api/zgw-translate` is called THEN HTTP 200 with `{resource, fromVersion, toVersion, payload}` is returned
  - GIVEN a missing `resource` field WHEN posted THEN HTTP 400 `missing_fields` is returned
  - GIVEN an unknown resource WHEN posted THEN HTTP 400 `unknown_resource` is returned
  - GIVEN an unknown version WHEN posted THEN HTTP 400 `unknown_version` is returned
  - GIVEN the next-gen placeholder version WHEN posted THEN HTTP 501 `not_implemented` is returned
  - GIVEN a literal-leak rejection WHEN posted THEN HTTP 422 `literal_leak` is returned
  - GIVEN no authenticated session WHEN posted THEN HTTP 401 is returned and the service is never invoked
  - GIVEN the route wired in `appinfo/routes.php` AND a test proving the controller method actually invokes `ZgwVersionTranslationService` THEN the orphaned-capability rule is satisfied (not just declared)
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate --strict` passes (this change only)
- [x] Manual testing against acceptance criteria — exercised via the PHPUnit suite
- [x] Code review against spec requirements — self-reviewed; see Deviations below
- [x] `composer lint`, `composer cs:check`, `phpmd`, `phpstan` clean on the new files; full suite diffed against the pristine `origin/development` baseline

## Deviations

- **No authoritative published OAS diff for ZGW v1.6 exists in this
  environment.** Every `1.6` delta is labelled VERIFIED (grounded in VNG's
  own fetched `zaken-api` CHANGELOG.rst 1.5.x entries) or ASSUMED
  (extrapolated from VNG's own "stability, no breaking changes"
  characterisation of the 1.6 line) in design.md's delta table — never
  presented as verified fact when it is not.
- **Next-generation ZGW standard is stubbed, not implemented.** No stable
  OAS exists yet; the seam recognises version `"2.0"` and raises a typed
  `ZgwVersionNotImplementedException` — documented explicitly as this
  change's central Open Question, not a silent omission.
- **No transparent proxy mode fronting procest's ZGW endpoints.** Explicitly
  permitted as optional scope by the task brief; does not compose cleanly
  within this change's time-box (procest's endpoints are path-versioned,
  not header-negotiated). Documented as a follow-up in design.md "Open
  Questions".
- **`?expand=` object-graph resolution is not implemented** — a verified
  real ZGW 1.5.x addition, but resolving an expansion requires querying
  additional resources, out of scope for a pure payload translator. The
  negotiation layer strips the hint explicitly rather than ignoring it.
- **`rol`'s `betrokkeneType` default (`natuurlijk_persoon`) is a documented
  lossy best-effort translation**, not a verified fact about any specific
  `rol` payload's real participant type — see design.md "Rol: documented
  lossy translation".
- **`zgw_version_translation_log` retention/expiry is not implemented** —
  mirrors `fsc_call`'s identical, already-accepted deviation.
