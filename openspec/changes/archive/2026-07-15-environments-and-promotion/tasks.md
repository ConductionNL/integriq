# Tasks: environments-and-promotion

## Implementation Tasks

### Task 1: Declare the environment and promotion_audit schemas via a register.d fragment
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001`
- **files**: `lib/Settings/register.d/environments-and-promotion.json`
- **acceptance_criteria**:
  - GIVEN a fresh `occ app:enable openconnector` WHEN `InitializeRegister` runs THEN the `environment` and `promotion_audit` schemas exist in the `openconnector` register
  - GIVEN the descriptor fragment WHEN inspected THEN `promotion_audit` declares `appendOnly: true` and `immutable: true`, matching `call_log`/`job_log`
- [x] Implement — `lib/Settings/register.d/environments-and-promotion.json` declares both schemas (`appendOnly`/`immutable` on `promotion_audit` confirmed by direct inspection); `check:register`/`check:json-strict` pass.
- [ ] Test — UNTICKED: no live `occ app:enable` run (this task did not deploy to any shared/live instance, per project policy). Traced OpenRegister's `ImportHandler`/`InitializeRegister.php` at HEAD instead to confirm the fragment mechanism is live-wired (not a static claim).

### Task 2: Seed local + acceptance environment objects and their connectivity Sources
- **spec_ref**: `openspec/changes/environments-and-promotion/design.md#seed-data`
- **files**: `lib/environments.seed.json` (new, following `lib/sources.seed.json` convention)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seed data loads THEN `local` and `acceptance` `environment` objects exist, each with a `sourceRef` pointing at a seeded `type: api` Source
- [x] Implement — DEVIATION from the literal task wording: NOT `lib/environments.seed.json` following the `lib/sources.seed.json` "convention". Verified `lib/sources.seed.json` is loaded by ZERO PHP call sites (grepped the whole app) — it is itself dead/orphaned data, and a schema-embedded `x-openregister-seed` array (the convention `hitl-approval-rule-action.json` used) is likewise never consumed by OpenRegister (only round-tripped as a Schema config key, per `OCA\OpenRegister\Db\Schema` and its own `SchemaAnnotationVocabularyTest`). Following either precedent would have shipped a THIRD instance of exactly the "spec says done, feature never runs" orphaned-capability defect class. Instead, seeded `local`/`acceptance` `environment` rows plus their `source` rows directly inside the register.d fragment's `components.objects` (with `@ref:source:<slug>` tokens for `sourceRef`) — traced live at OpenRegister HEAD (`ImportHandler::resolveSeedReferenceTokens()` + the `components.objects` import loop inside `importFromJson()`, called from `importFromApp()`, which `InitializeRegister.php` already invokes on every install/upgrade) to confirm this IS the mechanism real object-seeding uses elsewhere in this fragment system.
- [ ] Test — UNTICKED: same reason as Task 1 (no live install run).

### Task 3: Environment CRUD service, controller, routes, and action keys
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001`
- **files**: `lib/Service/EnvironmentService.php`, `lib/Controller/EnvironmentController.php`, `appinfo/routes.php`, `lib/actions.seed.json`
- **acceptance_criteria**:
  - GIVEN an operator with `environment.manage` WHEN they `POST /api/environments` with a valid `sourceRef` THEN the object is created
  - GIVEN an `environment` whose `sourceRef` does not resolve WHEN it is used as a promotion target THEN the request is rejected with an actionable error naming the missing `sourceRef`
  - GIVEN a non-admin user without `environment.manage` WHEN they call any environment endpoint THEN `OCSForbiddenException` is returned
- [x] Implement — `lib/Service/EnvironmentService.php`, `lib/Controller/EnvironmentController.php`, routes + `environment.manage` action key added.
- [x] Test — `tests/Unit/Service/EnvironmentServiceTest.php` + `tests/Unit/Controller/EnvironmentControllerTest.php`, all passing.

### Task 4: PromotionService — local export + credentialRef placeholder scan
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-promotion-exports-locally-unchanged-and-dispatches-to-the-targets-existing-import-endpoints-req-002`
- **files**: `lib/Service/PromotionService.php`
- **acceptance_criteria**:
  - GIVEN a configuration id WHEN `PromotionService::export()` runs THEN it calls `ConfigurationService::exportConfiguration()` unchanged and returns its document verbatim
  - GIVEN an exported document containing a Source with `configuration.authentication.credentialRef` WHEN scanned THEN each placeholder is detected using the same shape `BrokeredCallService::isPlaceholder()` checks
- [x] Implement — `PromotionService::export()`/`scanCredentialRefs()`.
- [x] Test — `PromotionServiceTest::testExportDelegatesToConfigurationServiceUnchanged`, `testScanCredentialRefsDetectsTopLevelPlaceholder`, `testScanCredentialRefsDetectsNestedPlaceholder`, `testScanCredentialRefsIgnoresPlainAuthentication`.

### Task 5: PromotionService — credential rebinding rewrite (reference-only, never plaintext)
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-credentialref-placeholders-are-re-bound-per-target-environment-never-resolved-to-a-secret-req-004`
- **files**: `lib/Service/PromotionService.php`
- **acceptance_criteria**:
  - GIVEN a `credentialBindings` entry for a flagged Source WHEN the document is rewritten THEN the target document's `credentialRef` is replaced with the supplied `credentialId`/`credentialName`, never resolved to plaintext
  - GIVEN no `credentialBindings` entry for a flagged Source WHEN the document is rewritten THEN the original `credentialRef` is sent verbatim, not dropped or defaulted
  - GIVEN this task's code WHEN reviewed THEN it never calls `CredentialBrokerService::resolveInjectable()` or any method that returns a plaintext secret
- [x] Implement — `PromotionService::applyCredentialBindings()`/`rewriteNode()`/`resolveReplacement()`.
- [x] Test — `PromotionServiceTest::testApplyCredentialBindingsRewritesMatchedPlaceholderReferenceOnly`, `testApplyCredentialBindingsLeavesUnmatchedPlaceholderVerbatim`, `testClassNeverImportsOrCallsCredentialBrokerPlaintextResolution` (regression guard: no `use ...CredentialBrokerService` import, no `->resolveInjectable(` call anywhere in the class).

### Task 6: PromotionService — remote dispatch via CallService against the target's sourceRef
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-promotion-exports-locally-unchanged-and-dispatches-to-the-targets-existing-import-endpoints-req-002`
- **files**: `lib/Service/PromotionService.php`
- **acceptance_criteria**:
  - GIVEN a target environment's `sourceRef` Source WHEN a preview or confirm call is dispatched THEN it goes through `CallService::call()` against that Source, unmodified
  - GIVEN the dispatch WHEN it completes THEN a `CallLog` is created exactly as for any other Source call
- [x] Implement — `PromotionService::dispatchToTarget()` calls `CallService::call()` unmodified.
- [x] Test — `PromotionServiceTest::testPreviewMergesTargetResponseWithCredentialRefsNeedingRebindBucket` asserts `CallService::call()` is invoked with the resolved target Source; `testPromoteWritesSuccessAuditWithCallLogIdAndCountsOnlySummary` asserts the returned CallLog id flows into the response/audit.

### Task 7: PromotionService — merge target preview response with credentialRefsNeedingRebind
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003`
- **files**: `lib/Service/PromotionService.php`
- **acceptance_criteria**:
  - GIVEN a target's `/api/configurations/import/preview` response WHEN merged THEN `creates`/`updates`/`collisions`/`unresolvedReferences`/`credentialsNeedingReentry` are passed through unchanged from the target
  - GIVEN flagged `credentialRef` placeholders WHEN merged THEN they appear under a `credentialRefsNeedingRebind` array not present in the target's own response
- [x] Implement — `PromotionService::preview()`.
- [x] Test — `PromotionServiceTest::testPreviewMergesTargetResponseWithCredentialRefsNeedingRebindBucket`, `testPreviewNeverExportsOrDispatchesWhenTargetUnresolved`.

### Task 8: PromotionController — preview and confirm endpoints with confirmation + action gates
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005`
- **files**: `lib/Controller/PromotionController.php`, `appinfo/routes.php`, `lib/actions.seed.json`
- **acceptance_criteria**:
  - GIVEN `POST /api/promotions` without `confirmed: true` WHEN called THEN HTTP 400 is returned and nothing is dispatched
  - GIVEN a user without `environment.promote` WHEN they call preview or confirm THEN `OCSForbiddenException` is returned before any export or remote call
- [x] Implement — `lib/Controller/PromotionController.php` (`preview()`/`confirm()`), routes + `environment.promote` action key added.
- [x] Test — `tests/Unit/Controller/PromotionControllerTest.php` (`testConfirmWithoutConfirmationReturns400`, `testConfirmWithConfirmedFalseReturns400`, `testPromoteDeniedForUnmappedNonAdmin` — the exact method name `PromotionControllerTest::testPromoteDeniedForUnmappedNonAdmin` referenced by the spec's `@e2e exclude`).

### Task 9: promotion_audit — write append-only audit object after every promotion attempt
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-every-promotion-attempt-is-recorded-in-an-append-only-promotion-audit-log-req-006`
- **files**: `lib/Service/PromotionService.php`, `lib/Controller/PromotionController.php`
- **acceptance_criteria**:
  - GIVEN a successful promotion WHEN it completes THEN a `promotion_audit` object is written with `outcome: "success"`, counts-only `previewSummary`, and the dispatch `CallLog` id
  - GIVEN a failed promotion (e.g. target returns 404) WHEN the attempt completes THEN a `promotion_audit` object is written with `outcome: "failed"` and no fabricated `written` summary
  - GIVEN an existing `promotion_audit` object WHEN a PUT/DELETE is attempted via the OR object API THEN it is rejected by `appendOnly`/`immutable` enforcement
- [x] Implement — `PromotionService::writeAudit()`, called from both the success and `catch` paths of `promote()`; schema declares `appendOnly: true`/`immutable: true`.
- [x] Test (first two scenarios) — `PromotionServiceTest::testPromoteWritesSuccessAuditWithCallLogIdAndCountsOnlySummary`, `testPromoteWritesFailedAuditAndRethrowsWhenTargetReturns404`.
- [ ] Test (third scenario, PUT/DELETE rejection) — UNTICKED: this is OpenRegister's own generic `appendOnly`/`immutable` schema enforcement (already exercised by OR's own test suite against `call_log`/`job_log`), not new code in this app; no new test was written here since exercising it would require a live OR instance, which this task did not deploy to.

### Task 10: Formalize the credentialRef pass-through contract on configuration-export-import
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/configuration-export-import/spec.md#requirement-credentialref-authentication-placeholders-pass-through-export-and-import-unresolved-and-untranslated-req-010`
- **files**: `tests/Unit/Service/ConfigurationHandlers/SourceHandlerTest.php` (extend), `tests/Unit/Service/ConfigurationServiceTest.php` (extend)
- **acceptance_criteria**:
  - GIVEN a Source with a `credentialRef` placeholder WHEN exported THEN the placeholder is byte-for-byte unchanged in the output (regression test pinning existing, previously-undocumented behaviour)
  - GIVEN an OAS document with a non-resolving `credentialRef` WHEN imported THEN the write succeeds and the reference is stored verbatim
- [x] Implement — no production code changed (REQ-010 formalizes existing, unmodified behaviour); regression tests added only.
- [x] Test — `SourceHandlerTest::testExportLeavesCredentialRefPlaceholderUnredacted`, `ConfigurationServiceTest::testImportConfigurationDoesNotBlockOnNonResolvingCredentialRef`.

### Task 11: Environments & Promotion manifest-v2 UI page
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#non-functional-requirements`
- **files**: `src/views/EnvironmentsPromotion.vue`, manifest-v2 page config (per `openconnector-app-manifest`)
- **acceptance_criteria**:
  - GIVEN an operator opens the Environments & Promotion page THEN registered environments are listed with CRUD actions
  - GIVEN the environment/target select fields THEN each `NcSelect` carries an explicit `inputLabel`
- [x] Implement — DEVIATION from the literal `src/views/EnvironmentsPromotion.vue` file structure: implemented as a declarative manifest-v2 `type: "index"` page (`src/manifest.d/environments-and-promotion.json`, register `openconnector` / schema `environment`), the SAME pattern already used by Sources/Endpoints/Jobs/etc, rather than a bespoke Vue page. Matches the codebase's own established precedent (the `Catalog` page's manifest `_note`: "Standard type:index ... NO custom page, NO nextcloud-vue change") and avoids the hydra custom-widget-ratchet gate for a page that is pure schema-driven CRUD. The `NcSelect`/`inputLabel` acceptance criterion applies to the hand-written promote-flow selects, which live in `PromotePreviewModal.vue` (Task 12), not this generic list page.
- [ ] Test — UNTICKED: no browser/live-instance verification (not authorized to deploy to the shared dev instance this session). Verified statically instead: the merged manifest (base `src/manifest.json` + this fragment, via the SAME `buildManifest()`/`mergePages()` pipeline `src/main.js` uses at runtime) Ajv-validates cleanly against `@conduction/nextcloud-vue`'s `app-manifest-v2.schema.json`, and the `Environments` page + menu entry are present in the merged output with the expected `register`/`schema`/`columns`/`headerActions`.

### Task 12: Promote flow — diff preview + credential rebind prompts + confirm, in its own modal
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003`
- **files**: `src/modals/PromotePreviewModal.vue`
- **acceptance_criteria**:
  - GIVEN an operator selects a configuration group and target environment WHEN they open the promote flow THEN the diff preview (creates/updates/collisions/credentialRefsNeedingRebind) renders before any confirm button is enabled
  - GIVEN the modal markup WHEN inspected THEN it lives entirely in `src/modals/PromotePreviewModal.vue`, never inlined in a parent component
- [x] Implement — `src/modals/PromotePreviewModal.vue` (NcModal, own file, wired into `ModalHost.vue` via the modal bus, opened from the Environments page's "Promote configuration" header action). Both selects carry `:input-label`. Confirm only renders once `step === 'preview'`, i.e. after a successful preview response.
- [ ] Test — UNTICKED: no browser/live-instance verification (same reason as Task 11). No vitest component-level spec was added either — this codebase has NO existing precedent for testing `.vue` dialog/modal components at the unit level (`ImportPreviewDialog.vue`/`ExportConfigurationDialog.vue`/`TestMappingModal.vue` are all similarly untested; existing `tests/vitest/*.spec.js` cover only plain JS modules). Verified by manual code review against every acceptance criterion instead: `npm run lint`, `npm run test:unit` (71/71 passing, unaffected), and `USE_LOCAL_LIB=false NODE_ENV=production npm run build` all pass with this file included.

## Verification
- [ ] All tasks checked off
- [ ] All tasks checked off — UNTICKED: several `Test` subtasks above are intentionally unticked (live-instance/browser verification out of this session's reach; see each task's reason).
- [x] `openspec validate` passes — see archive step.
- [ ] Manual testing against acceptance criteria — UNTICKED: no live/shared dev instance was used this session (project policy).
- [x] Code review against spec requirements — every REQ-001–REQ-006 scenario cross-checked against the implementation while writing it; deviations from tasks.md's literal file-structure suggestions documented per-task above.

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — `PromotionServiceTest` (14 tests), `EnvironmentServiceTest` (5), `PromotionControllerTest` (9), `EnvironmentControllerTest` (5), plus `SourceHandlerTest`/`ConfigurationServiceTest` REQ-010 regressions.
- [ ] Newman/Postman tests for new/changed API endpoints — UNTICKED: no Postman collection entries added for `/api/environments*`/`/api/promotions*`; not attempted this session.
- [ ] Browser tests (Playwright MCP) for UI changes — UNTICKED: no live/shared dev instance used this session.
- [x] All tests pass (`composer test:unit`) — full suite green: 1481 tests, 4184 assertions, 0 failures (baseline before this change: 1447/4109/0 via `phpunit-unit.xml` on an unmodified sibling worktree at the same base commit). `newman run` not executed (no Postman additions).

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — UNTICKED: not attempted this session (out of the LOCAL CHECKS scope given for this build).
- [ ] Screenshot captured and committed to `docs/images/` — UNTICKED: requires a live/rendered instance, not used this session.

## i18n (company-wide hydra ADR-007)

- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added for the new UI page, promote flow, and error messages — every new `t('openconnector', ...)` string this change introduces has both an `l10n/en.json` key (via `node tests/l10n/check-l10n.js --write`, which also extracted 42 pre-existing missing English keys encountered along the way) and a hand-written `l10n/nl.json` Dutch translation. Note: `npm run test:l10n` still reports ~555 MISSING Dutch translations app-wide — confirmed pre-existing on an unmodified sibling worktree (568 missing before this change touched anything); translating that backlog is out of this change's scope.
