# Tasks: platform-connector-extension-kit

Kind: code. Size M. Row `integriq:plt-connector-sdk`.

### Task 1: The collect event and lazy registries
- **spec_ref**: openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
- **files**: `lib/Event/RegisterConnectorExtensionsEvent.php`, `lib/Intake/IntakeChannelRegistry.php`, `lib/Service/DigitalPost/DigitalPostProviderRegistry.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN no extension WHEN a registry is built THEN it holds exactly today's built-ins
  - GIVEN a listener adding a channel WHEN the intake registry is built THEN the channel is registered with its app id
- [x] Implement
- [x] Test (PHPUnit on each registry with a real event and a stub listener)
  > Built: `RegisterConnectorExtensionsEvent` (intake channels, digital post providers, payment providers, template directories, each with an app id) and `ConnectorExtensionCollector`, which dispatches it once per process. `IntakeChannelRegistry` and `DigitalPostProviderRegistry` keep their built-ins and ask the collector on first use; each entry remembers its app (`appIdOf()`). The registries are wired in `IntakeBindings` and `ServiceBindings`, not `Application.php`, where they live today. Tests: `tests/Unit/Service/Extension/ConnectorExtensionKitTest.php` (real event, stub listener).

### Task 2: The payment provider registry
- **spec_ref**: openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
- **files**: `lib/Service/Payment/PaymentProviderRegistry.php`, `lib/Service/PaymentIntentService.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN provider `mollie` WHEN a payment is created THEN the Mollie binding runs through the registry
  - GIVEN a contributed `buckaroo` provider WHEN a source names it THEN it runs
- [x] Implement
- [x] Test (PHPUnit on `resolveProvider()` against the registry)
  > Built: `PaymentProviderRegistry`, keyed by the new `PaymentProviderInterface::getProviderId()` (`mollie`, `log`); `PaymentIntentService::resolveProvider()` reads it, and an id nothing answers to still falls back to the log binding. Tests: `tests/Unit/Service/PaymentIntentServiceProviderRegistryTest.php`.

### Task 3: Collisions refused and logged
- **spec_ref**: openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-adds-and-never-replaces-a-built-in-req-cxk-002
- **files**: the three registries
- **acceptance_criteria**:
  - GIVEN a contribution claiming `teams` WHEN the registry is built THEN the built-in keeps it and the log names the app and class
- [x] Implement
- [x] Test (PHPUnit per registry)
  > Built: a contribution claiming a taken id is refused, the built-in keeps it, and the warning names the app, class and seam (`ConnectorExtensionKitTest`, one case per registry).

### Task 4: Templates, the Store and the occ command
- **spec_ref**: openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
- **files**: `lib/Service/CatalogRegistryService.php`, `lib/Command/ExtensionsList.php`, `appinfo/info.xml` (command), `src/components/CatalogItemCard.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an extension with a template directory WHEN the Store is materialised THEN its templates show as provided by that app
  - GIVEN a refused contribution WHEN `occ integriq:extensions` runs THEN it is listed as refused
- [x] Implement
  > Store and templates: `ContributedTemplates` reads each directory an extension app contributes
  > with the same template library, marks every card `providedBy` that app and refuses (logs and
  > records) a slug integriq or an earlier app already holds. `CatalogRegistryService` lists those
  > cards and finds their payloads; `MaterializeCatalogItems` keeps `providedBy` on the
  > `catalog_item` (schema 1.2.0); `CatalogItemCard.vue` shows "Provided by <app>";
  > `occ integriq:extensions` lists the templates too.
- [ ] Test (PHPUnit on the command and `collect()`; `tests/e2e/connector-extension-kit.spec.ts`)
  > PHPUnit: `ContributedTemplatesTest` (cards, refusal, payloads, the Store, the command);
  > vitest: `catalogItemCardProvidedBy.spec.js`. The e2e spec is left (browser).
  > Earlier: `occ integriq:extensions` (`lib/Command/ExtensionsList.php`, registered in `appinfo/info.xml`) lists every contribution with app, seam, id and whether it was refused (`tests/Unit/Command/ExtensionsListTest.php`). Not yet: the Store cards (`CatalogRegistryService` extension list, `CatalogItemCard.vue` "provided by"), materialising contributed template directories, and the e2e test.

### Task 5: Contract tests, the skeleton and the guide
- **spec_ref**: openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-a-developer-builds-an-extension-from-a-guide-a-skeleton-and-contract-tests-req-cxk-004
- **files**: `tests/Contract/*ContractTestCase.php`, `examples/connector-extension/`, `docs/developers/connector-kit.md`, the CI workflow that runs the skeleton's tests
- **acceptance_criteria**:
  - GIVEN the skeleton WHEN CI runs THEN its adapter passes the intake channel contract test case
  - GIVEN the guide WHEN a developer follows it THEN every file it names exists in the skeleton
- [ ] Implement
- [ ] Test (the skeleton's PHPUnit run in CI; `tests/e2e/connector-extension-kit.spec.ts` with the skeleton enabled)

## Verification

- `openspec validate platform-connector-extension-kit --type change --strict`
- Enable the skeleton app on a local instance, post a signed message to its
  channel, and see it in the intake inbox and in `occ integriq:extensions`.
- `composer check:strict` and `npm run lint` once before push.
  > Not started: the contract test cases, the skeleton app, the guide and its CI run.

