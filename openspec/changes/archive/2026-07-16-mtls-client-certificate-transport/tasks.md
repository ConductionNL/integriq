# Tasks: mtls-client-certificate-transport

## 1. Shared transport

- [x] 1.1 `lib/Service/Mtls/MtlsCertificateBundle.php` — immutable value
      object (certificatePem, privateKeyPem, ?passphrase, ?caBundlePem).
- [x] 1.2 `lib/Exception/MtlsTransportException.php` — base exception with
      stable `errorCode` constants + `getErrorCode()`.
- [x] 1.3 `lib/Exception/MtlsConfigurationException.php` — extends base,
      thrown for missing/invalid material, expired cert, bad passphrase.
- [x] 1.4 `lib/Exception/MtlsHandshakeException.php` — extends base, thrown
      when the wrapped Guzzle dispatch fails.
- [x] 1.5 `lib/Service/Mtls/MtlsConfigResolver.php` — decrypts
      `configuration.authentication.mtls.*` via `ICrypto`, validates PEM
      shape + expiry + passphrase pre-flight, returns `MtlsCertificateBundle`
      or throws `MtlsConfigurationException`.
- [x] 1.6 `lib/Service/Mtls/MtlsTransportOptionsBuilder.php` — materialises
      PEM strings to 0600 temp files (mirrors `CallService::writeFile()`),
      builds Guzzle `cert`/`ssl_key`/`verify` options, cleans up.
- [x] 1.7 `lib/Service/Mtls/MtlsTransportService.php` — single call-site:
      materialise → dispatch (existing injected Guzzle `Client`, no parallel
      stack) → cleanup in `finally`; wraps `GuzzleException` as
      `MtlsHandshakeException`.

## 2. Wire the three adapters

- [x] 2.1 `IStandaardenClient` — constructor gains `MtlsTransportService`;
      `send()` branches on `authentication.mode` (`token` default | `mtls`);
      config schema documents the `mtls` block; token path byte-identical.
- [x] 2.2 `FscDirectoryClient` — same wiring on `call()` (directory lookup
      `resolveService()` stays token/plain — only the downstream service
      invocation needs mTLS).
- [x] 2.3 `DsoClient` — same wiring on `send()`.

## 3. Adapter docs

- [x] 3.1 Update `openspec/changes/archive/2026-07-14-iwmo-ijw-adapter/design.md`
      Open Questions — mTLS gap closed via this change, link it.
- [x] 3.2 Update `openspec/changes/archive/2026-07-15-fsc-connectivity/design.md`
      Outway/mTLS deviation note — same.
- [x] 3.3 Update `openspec/changes/archive/2026-07-15-dso-connector-adapter/design.md`
      Open Questions — same.

## 4. Tests

- [x] 4.1 `MtlsConfigResolverTest` — options building from valid config;
      missing cert/key; invalid PEM shape; expired cert; wrong passphrase;
      decrypt failure — every path a stable `errorCode`.
- [x] 4.2 `MtlsTransportOptionsBuilderTest` — temp-file lifecycle: 0600
      perms, cleanup after success, cleanup after exception (`finally`
      proof), cert/key/CA all materialised correctly.
- [x] 4.3 `MtlsTransportServiceTest` — dispatch merges TLS options into the
      request; `GuzzleException` wrapped as `MtlsHandshakeException`; no
      secret material (PEM bytes, passphrase) appears in any exception
      message.
- [x] 4.4 Per-adapter routing proof (orphaned-capability rule): a test per
      client asserting that with `authentication.mode=mtls` configured, the
      request actually flows through `MtlsTransportService` (spy/mock), and
      with `mode=token` (or absent) it does NOT — token-mode regression
      tests continue to pass unmodified.
- [x] 4.5 Fail-closed proof: mTLS configured but unusable (bad passphrase,
      expired cert, handshake failure) never falls back to token/plaintext —
      the call throws every time.

## 5. Verification

- [x] 5.1 Full PHPUnit suite via `oc-phpunit-83:local`.
- [x] 5.2 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan).
- [x] 5.3 Diff failures against a pristine `origin/development` baseline —
      only the known pre-existing `SynchronizationServiceTest` DomCrawler
      failure permitted.
- [x] 5.4 Merge `origin/development`, grep for conflict markers, rerun.
