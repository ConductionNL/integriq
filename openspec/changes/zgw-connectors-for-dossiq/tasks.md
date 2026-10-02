# Tasks: zgw-connectors-for-dossiq

## Implementation tasks

### Task 1: The six packaged sets
- **spec_ref**: `openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-six-packaged-slug-referenced-zgw-consumer-sets-req-zgwc-001`
- **files**: `lib/Settings/configurations/zgw-zaken.json`, `zgw-documenten.json`, `zgw-catalogi.json`, `zgw-besluiten.json`, `zgw-objecten.json`, `zgw-notificaties.json`
- Seed half done: `lib/Settings/register.d/zgw-consumer-sets.json` seeds every source, mapping and synchronization the five data sets name (`tests/Unit/Settings/ZgwConsumerSetsSeedTest.php`, every seed validated against the register). Mock-mode pull done (design D1): `tests/Unit/Service/Zgw/ZgwSetPullFixtureTest.php` installs each of the five data sets with the real installer and pulls a recorded page of three resources through the real synchronization and mapping engines into the bound schema, contracts keyed by url, idempotent on a second run.
- Shape decided (design D5, Ruben 2 Oct 2026): a bound schema holds the store's own ZGW shape. The mappings stay bare pass-throughs and the six set files no longer name `ZgwResourceTranslatorInterface` (a key no code read). `ZgwConsumerSetsSeedTest::testEveryMappingPassesTheStoresOwnShapeThroughUntranslated` refuses a translator or a reshaping mapping; the pull test asserts every field of each recorded resource arrives as the store sent it.
- [x] Implement
- [x] Test (each set installs against a mock-mode source and pulls the fixture)

### Task 2: Target binding and the installer guard
- **spec_ref**: `openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002`
- **files**: `lib/Service/Zgw/ZgwSetInstaller.php` (the design named `ConfigurationSetInstaller.php`; the installer sits beside the guard it runs), `lib/Controller/ZgwSetsController.php`, `appinfo/routes.php`
- [x] Implement
- [x] Test (`tests/Unit/Service/Zgw/ZgwSetInstallerTest.php` against the real seeded synchronizations, each save validated against the register; `tests/Unit/Controller/ZgwSetsControllerTest.php`)

### Task 3: Notification-triggered pull and write-back
- **spec_ref**: `openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003`
- **files**: `lib/Service/Zgw/ZgwNotificationPullListener.php`, the push synchronizations in the sets
- Pull half done (design D3): `ZgwNotificationPullListener` (called from `NotificatiesSubscriberService::handleInboundNotification()` after the CloudEvent) pulls the main object of an installed set's kanaal through `getObjectFromSource()` + `replaySynchronizationItem()`, refusing a url off the set's source; `zgw-notificaties` installs without a register and schema as a subscription set (`ZgwSetInstaller::subscribe()`, one abonnement per installed data set, recorded in `zgw_set_subscriptions`; seeded source `zgw-set-notificaties`). Tests: `tests/Unit/Service/Zgw/ZgwNotificationPullListenerTest.php`, `tests/Unit/Service/Zgw/ZgwNotificatiesInstallTest.php` (real `NotificatiesSubscriberService`, every save validated against the register; it found that a registered abonnement saved `lastError: null`, which the schema refuses, now `''`), `NotificatiesSubscriberServiceTest::testHandleInboundNotificationHandsItToTheZgwPull`. Write-back half done (design D4): the four push synchronizations declare `targetConfig.targetIdPosition: url` (a pulled object is PATCHed where it lives instead of POSTed again; a url off the target source is refused) and `targetConfig.conflictStatusProperty: syncStatus` (a 4xx raises `TargetWriteRefusedException`; the object handler keeps the edit and silently records `syncStatus = conflict`, cleared to `synced` by the next accepted push; a 5xx and any synchronization without the key behave as before). Tests: `tests/Unit/Service/SynchronizationWriteBackTest.php` (the real seeded `zgw-zaken-push`, the real engine write path and object handler).
- [x] Implement
- [x] Test

### Task 4: Docs and i18n
- Install guide per set, Dutch and English strings on the installer.
- [x] Implement
  - `docs/features/zgw-sets.md`: per set its component, endpoint, source and credential, what lands in the bound schema (D5), the install steps and request, notifications, the refusals and write-back. The refusals an operator meets go through `IL10N` in `ZgwSetInstallGuard::refuse()` and `ZgwSetInstaller` (eight strings in `l10n/en.json` and `l10n/nl.json`); `ZgwSetInstallerL10nTest` produces each of the seven refusal paths in Dutch through the real catalogue and checks every asked string is in both. The template refusals (`refuseTemplate()`) are build-time checks on the packaged files and stay English. There is no install screen: the installer is the `/api/zgw-sets` endpoint.
  - Still owed (design D4 put it here): `src/integration/SyncedFromTab.vue` renders the contract rows and does not show `syncStatus = conflict` yet. That needs the sync-contract provider to pass the object's `syncStatus` with each row, and one frontend string in `l10n/en.js` and every locale.
- [ ] Test (`tests/e2e/zgw-set-install.spec.ts`)
  - Owed, needs a live instance: install `zgw-zaken` against a schema through `POST /api/zgw-sets/zgw-zaken/install`, then install `zgw-besluiten` on the same schema and read the Dutch refusal naming `zgw-zaken` with the user language set to `nl`.
