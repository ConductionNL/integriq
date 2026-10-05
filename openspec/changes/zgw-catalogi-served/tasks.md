# Tasks: zgw-catalogi-served

Kind: code. Size S. Woo row 17.18. Wave 2. Waits on `filinq/document-register`.

Every test named here fails on `development` today: no Catalogi route,
binding, handler or starter set exists.

## Implementation tasks

### Task 1: The binding
- **spec_ref**: `openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001`
- **files**: `lib/Settings/register.d/zgw-catalogi-facade.json` (admin-only `catalogi_binding` schema: `domein`, `rsin`, `naam`, `publishedUuid`, `register`, `schema`, `fieldMap`; register version bump), `lib/Service/Catalogi/CatalogiBinding.php` (the default field map for a `documentType` shape, with no app id in code)
- **acceptance_criteria**:
  - GIVEN no binding WHEN resolved THEN null, and the handler answers `catalogue-not-configured`
  - GIVEN a binding whose schema does not resolve WHEN resolved THEN null, as above
  - GIVEN the default field map WHEN a `documentType` object is mapped THEN the six resource fields come out as REQ-ZCS-001 says
- [ ] Implement
- [ ] Test: PHPUnit `CatalogiBindingTest::testNoBindingResolvesToNull`, `::testTheDefaultFieldMap`; a test that greps `lib/Service/Catalogi/` for an app id (`filinq`, `docudesk`) and fails on a hit, as `ZgwSetCatalogue` demands

### Task 2: The read endpoints
- **spec_ref**: `openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001`
- **files**: `lib/Service/Catalogi/CatalogusEndpointHandler.php`, `lib/Service/Catalogi/InformatieobjecttypeEndpointHandler.php`, `lib/Service/Catalogi/CatalogiOpenRegisterAccess.php`, `lib/Service/Catalogi/CatalogiWiring.php` (registered from `Application::register()`, as `ObjectenWiring` is), the endpoint seeds in `lib/Settings/register.d/zgw-catalogi-facade.json`
- **acceptance_criteria**:
  - GIVEN a bound type WHEN fetched by uuid THEN its `url` equals the request URL and its `catalogus` resolves
  - GIVEN 120 types WHEN listed THEN `count` 120, 100 in `results`, and `next` set
  - GIVEN `catalogus` filter for another catalogue WHEN listed THEN empty `results`
  - GIVEN any write method WHEN called THEN 405
- [ ] Implement. Read `objecten-api-facade` design D7 first; the handlers take their OpenRegister access as callables and the wiring class hands them the production side.
- [ ] Test: PHPUnit `InformatieobjecttypeEndpointHandlerTest::testATypeIsServedWithItsOwnUrl`, `::testListsArePaged`, `::testWritesAnswer405`, `::testAnUnboundFacadeAnswersNotConfigured`; `CatalogusEndpointHandlerTest::testTheCatalogueResolves`. Double OpenRegister's `ObjectService` with `environmentAwareDouble` after reading its real `findAll()` and `find()` signatures on `openregister` `development`.
- [ ] Wiring: `CatalogiWiringTest::testEveryHandlerIsBuiltWithItsSeams` boots `Application::register()` and asserts no handler is autowired with a null seam. The objecten facade shipped exactly that defect once (every route 401 or 404); this test is why.
- [ ] `testATypeIsServedWithItsOwnUrl` must be shown failing on `development` before the change. Paste the failing line in the PR body.

### Task 3: Authorisation and concept types
- **spec_ref**: `openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-only-an-authorised-client-reads-and-concept-types-stay-hidden-req-zcs-002`
- **files**: `lib/Service/Catalogi/CatalogiTokenService.php` (on the existing `jwt-zgw` auth), the handlers
- **acceptance_criteria**:
  - GIVEN a JWT without `catalogi.lezen` WHEN any route is called THEN 403 and the OpenRegister access callable is never invoked
  - GIVEN a type mapped `concept: true` WHEN listed by default THEN omitted; WHEN fetched by uuid THEN 404
  - GIVEN a client allowed concept reads WHEN listed with `status=alles` THEN included
- [ ] Implement
- [ ] Test: PHPUnit `CatalogiTokenServiceTest::testAMissingScopeIsRefusedBeforeAnyRead`; `InformatieobjecttypeEndpointHandlerTest::testConceptTypesAreHiddenByDefault`, `::testAConceptReaderSeesConceptsWhenAsked`

### Task 4: The Woo starter set
- **spec_ref**: `openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003`
- **files**: `lib/Service/Catalogi/WooStarterSet.php`, `lib/Controller/CatalogiAdminController.php` (`POST /api/catalogi/woo-starter-set`, admin checked in the method), `appinfo/routes.php`, the integriq admin page (a button with the result counts), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN one existing type in "besluiten" and 17 TOOI categories WHEN run THEN 16 created, 1 skipped, and no existing type changed
  - GIVEN the TOOI scheme unreadable WHEN run THEN `tooi-scheme-missing` and nothing created
  - GIVEN a non-administrator WHEN the route is called THEN 403
- [ ] Implement. Read the TOOI scheme through OpenRegister's concept register service; read its real method signature on `openregister` `development` and name it in the PR body. Do not bundle a copy of the category list (decision D3).
- [ ] Test: PHPUnit `WooStarterSetTest::testOnlyMissingCategoriesAreCreated`, `::testAMissingSchemeRefuses`; `CatalogiAdminControllerTest::testANonAdminIsRefused`; the route-reachability gate

### Task 5: Contract tests and documentation
- **spec_ref**: `openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001`
- **files**: `tests/postman/zgw-catalogi-served.postman_collection.json`, `tests/e2e/zgw-catalogi-served.spec.ts`, `docs/features/zgw-catalogi.md`, `lib/Settings/catalog.seed.json` (the catalog entry), the throttle configuration (ADR-082)
- **acceptance_criteria**:
  - GIVEN a JWT client WHEN the collection runs THEN list, get by uuid, follow `catalogus`, write refused and missing scope refused all pass
  - GIVEN the admin page WHEN the starter set runs THEN the counts show and the types are listed
- [ ] Implement
- [ ] Test: Newman collection and Playwright spec above

## Verification

The building agent follows `~/memcap-work/woo-build/LANE-RULES-BUILD.md`:

- [ ] Own clone, `git checkout --no-track -b <branch> origin/development`, `TMPDIR` a sibling outside the clone.
- [ ] PHPUnit judged by the `Tests:` line, or with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`, `npm run check:register`.
- [ ] CI runs the gates on the full tree; the coverage guard needs a test for every added statement.
- [ ] `openspec validate zgw-catalogi-served --type change --strict` passes.
- [ ] One PR, `--base development`; merge `development` in, never rebase; no `Co-Authored-By` trailer.
- [ ] Done means merged on `development` with CI green. Row 17.18 is `production` only once a store release carries it.
