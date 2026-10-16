# Tasks: brp-connection-monitor-from-pipelinq (integriq)

Spec only in this PR. Tier V1. Section 2 waits on keepiq `connection-certificate-expiry-for-integriq`.

## 1. Performance per source

- [ ] 1.1 Aggregate `call_log` per source over 24 hours (calls, errors by kind, average and slowest duration); show on the source page and a `ConnectionMonitor` page linked from Operational health, as drawn on `integriq/IqBrpMonitor`.
  - spec_ref: `specs/connection-run-monitoring/spec.md#requirement-a-source-shows-how-it-performed-in-the-last-24-hours-req-crun-006`
  - files: `lib/Service/ConnectionPerformanceService.php`, `src/manifest.json`, tests

## 2. Certificates in keepiq

- [ ] 2.1 `MtlsConfigResolver` accepts `credentialRef` parts and resolves them through the broker.
  - spec_ref: `specs/mtls-client-certificate-transport/spec.md#requirement-mtls-material-may-be-a-keepiq-reference-req-005`
  - test: `vendor/bin/phpunit --no-coverage --filter MtlsConfigResolverTest`
- [ ] 2.2 Expiry from keepiq with days left and a renew link; "expiry unknown" when keepiq does not answer.
  - spec_ref: `specs/connection-run-monitoring/spec.md#requirement-a-source-shows-when-its-certificate-expires-req-crun-007`
- [ ] 2.3 Expiry alerts at 30, 14 and 7 days in the hourly health job.
  - spec_ref: `#requirement-an-expiring-certificate-opens-an-alert-req-crun-008`

## 3. Handover from pipelinq

- [ ] 3.1 Listener for pipelinq's handover event: fill empty fields, mint, verify, reference, answer per part.
  - spec_ref: `specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq`

## 4. Verify

- [ ] 4.1 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run test:l10n` once before push.
