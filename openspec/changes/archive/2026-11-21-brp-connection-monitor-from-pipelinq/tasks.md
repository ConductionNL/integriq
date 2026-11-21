# Tasks: brp-connection-monitor-from-pipelinq (integriq)

Tier V1. Section 2 waits on keepiq `connection-certificate-expiry-for-integriq`.

## 1. Performance per source

- [x] 1.1 Aggregate `call_log` per source over 24 hours (calls, errors by kind, average and slowest duration); show on a `ConnectionMonitor` page, reached from the source page's Monitor header action and from the BRP monitor menu entry, as drawn on `integriq/IqBrpMonitor`.
  - spec_ref: `specs/connection-run-monitoring/spec.md#requirement-a-source-shows-how-it-performed-in-the-last-24-hours-req-crun-006`
  - files: `lib/Service/ConnectionMonitorService.php`, `lib/Controller/ConnectionMonitorController.php` (`GET /api/connection-monitor?source=`), `src/manifest.d/connection-monitor.json` (page `ConnectionMonitor` at `/connection-monitor/:id`, menu entry BRP monitor for `brp-haalcentraal`), `tests/Unit/Service/ConnectionMonitorServiceTest.php`, `tests/vitest/connectionMonitorManifest.spec.js`

## 2. Certificates in keepiq

- [x] 2.1 `MtlsConfigResolver` accepts `credentialRef` parts and resolves them through the broker.
  - spec_ref: `specs/mtls-client-certificate-transport/spec.md#requirement-mtls-material-may-be-a-keepiq-reference-req-005`
  - test: `vendor/bin/phpunit --no-coverage --filter MtlsConfigResolverTest`
- [x] 2.2 Expiry from keepiq with days left and a renew link; "expiry unknown" when keepiq does not answer. The monitor dispatches `OCA\Keepiq\Event\CredentialExpiryRequestedEvent` when the class exists; inline encrypted material (`MtlsConfigResolver::certificateNotAfter`) and a plain `configuration.cert` PEM are read directly until they move to keepiq.
  - spec_ref: `specs/connection-run-monitoring/spec.md#requirement-a-source-shows-when-its-certificate-expires-req-crun-007`
- [x] 2.3 Expiry alerts at 30, 14 and 7 days in the hourly health job.
  - spec_ref: `#requirement-an-expiring-certificate-opens-an-alert-req-crun-008`

## 3. Handover from pipelinq

- [x] 3.1 Listener for pipelinq's handover event: fill empty fields, mint, verify, reference, answer per part.
  - spec_ref: `specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq`

## 4. Verify

- [x] 4.1 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run test:l10n` once before push.
  - Run on development 2026-11-21 with every task of this change merged. check:no-legacy-types, check:routes, lint and phpstan pass. phpcs errors sit only in `SmsDispatchService*` and `EventListenerBindings`, not in this change's files. psalm is at the development count (369). In this change's files its findings are the repo-wide UndefinedClass on OpenRegister's `Kernel\Contracts\Value` option classes (293 such findings across `lib/`), which the stubs do not carry. phpmd's findings here are the repo-wide StaticAccess on `RegisterSave` (240 across `lib/`). PHPUnit: 10708 tests, the same 4 errors and 4 failures as development (missing ext-soap and ext-bcmath in the cloud image). The change's own tests (`ConnectionMonitor`, `MtlsConfigResolver`, handover) pass, 60 tests. `npm run lint` has 0 errors. `npm run test:l10n` is OK after #3757 added the 177 keys development was missing; none of those keys came from this change.
