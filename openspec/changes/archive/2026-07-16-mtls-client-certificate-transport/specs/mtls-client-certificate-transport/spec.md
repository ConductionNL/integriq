# mtls-client-certificate-transport Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- mtls-client-certificate-transport

## Purpose

Provides ONE shared mutual-TLS (mTLS) client-certificate transport
(`OCA\OpenConnector\Service\Mtls`) that `IStandaardenClient` (iwmo-ijw-adapter),
`FscDirectoryClient` (fsc-connectivity), and `DsoClient` (dso-connector-adapter)
each dispatch through when configured for it, closing the "mTLS is a
documented gap" open question every one of those adapters shipped with. Token
(Bearer) auth continues to work unchanged; mode selection is config-driven
per source. Certificate material is `OCP\Security\ICrypto`-encrypted at rest
(see design.md for why the OpenRegister credential broker's
`resolveInjectable()` was verified unsuitable for raw private-key material).

## ADDED Requirements

### Requirement: Shared mTLS transport builds Guzzle TLS options from encrypted-at-rest certificate material (REQ-001)

`MtlsConfigResolver` MUST decrypt `configuration.authentication.mtls`
(`encryptedCertificate`, `encryptedPrivateKey`, optional
`encryptedPassphrase`, optional `encryptedCaBundle`) via `OCP\Security\ICrypto`
and return an `MtlsCertificateBundle`. It MUST validate, before any network
call is attempted: certificate PEM shape, private key PEM shape, certificate
expiry, and (when a passphrase is configured) that the passphrase actually
unlocks the private key — raising `MtlsConfigurationException` with a stable
`errorCode` and a secret-free message on any failure.

#### Scenario: Valid mTLS configuration resolves to a usable certificate bundle

- GIVEN a source's `configuration.authentication.mtls` carries a valid
  encrypted certificate, private key, and no passphrase
- WHEN `MtlsConfigResolver::resolve()` is called
- THEN it returns an `MtlsCertificateBundle` with the decrypted PEM strings
- @e2e exclude backend credential resolution — covered by PHPUnit

#### Scenario: Missing certificate or key material fails closed before any request

- GIVEN `configuration.authentication.mode=mtls` but `mtls.encryptedCertificate`
  or `mtls.encryptedPrivateKey` is absent
- WHEN a resolve is attempted
- THEN `MtlsConfigurationException` is thrown with a stable errorCode and no
  HTTP request is made
- @e2e exclude backend fail-closed guard — covered by PHPUnit

#### Scenario: An expired certificate is rejected pre-flight

- GIVEN a configured certificate whose validity end date is in the past
- WHEN a resolve is attempted
- THEN `MtlsConfigurationException` is thrown (expired-certificate errorCode)
  before any network call
- @e2e exclude backend pre-flight validation — covered by PHPUnit

#### Scenario: An incorrect passphrase is rejected pre-flight

- GIVEN a configured private key that requires a passphrase and a configured
  passphrase that does not unlock it
- WHEN a resolve is attempted
- THEN `MtlsConfigurationException` is thrown (passphrase errorCode) before
  any network call
- @e2e exclude backend pre-flight validation — covered by PHPUnit

### Requirement: Certificate material is materialised to disk only transiently, with guaranteed cleanup (REQ-002)

`MtlsTransportOptionsBuilder` MUST write certificate/key/CA-bundle material
to temp files created with `0600` permissions only when the HTTP client
requires file paths, and `MtlsTransportService` MUST remove every
materialised file in a `finally` block regardless of whether the wrapped
dispatch succeeds or throws.

#### Scenario: Temp files are removed after a successful dispatch

- GIVEN a valid `MtlsCertificateBundle`
- WHEN `MtlsTransportService::request()` completes successfully
- THEN no materialised temp file remains on disk afterwards
- @e2e exclude backend file lifecycle — covered by PHPUnit

#### Scenario: Temp files are removed even when the dispatch throws

- GIVEN a valid `MtlsCertificateBundle` and a Guzzle client configured to
  throw during dispatch
- WHEN `MtlsTransportService::request()` is called
- THEN `MtlsHandshakeException` is thrown AND no materialised temp file
  remains on disk afterwards
- @e2e exclude backend file lifecycle — covered by PHPUnit

### Requirement: mTLS never fails open to plaintext or token auth (REQ-003)

The system MUST raise a typed exception with a stable errorCode whenever
`authentication.mode=mtls` is configured and the certificate bundle is
unusable or the handshake fails. The system MUST NOT proceed without a
client certificate and MUST NOT fall back to token/plaintext auth in that
case.

#### Scenario: A handshake failure raises a typed exception, not a silent fallback

- GIVEN `authentication.mode=mtls` with a valid bundle and a wrapped Guzzle
  client that throws a connection/SSL error during dispatch
- WHEN the adapter dispatches its request
- THEN `MtlsHandshakeException` is thrown (handshake errorCode) and the
  request is never retried in token mode
- @e2e exclude backend fail-closed guard — covered by PHPUnit

### Requirement: Each adapter routes through the mTLS transport only when configured, proving no orphaned capability (REQ-004)

`IStandaardenClient`, `FscDirectoryClient`, and `DsoClient` MUST each
dispatch through `MtlsTransportService` when their source's
`configuration.authentication.mode=mtls`, and MUST continue to dispatch via
the existing token-mode path (unchanged) when `mode` is `token` or absent.
Public provider interfaces (`IwmoIjwProviderInterface`,
`FscConnectivityProviderInterface`, `DsoConnectorProviderInterface`) are
unchanged.

#### Scenario: IStandaardenClient routes through the mTLS transport when configured

- GIVEN a `type=rest` iWMO/iJW source with `authentication.mode=mtls` and a
  valid `mtls` block
- WHEN `IStandaardenClient::send()` is called
- THEN the request is dispatched via `MtlsTransportService` carrying the
  resolved certificate bundle, not the plain `Client::request()` call
- @e2e exclude backend adapter routing proof — covered by PHPUnit

#### Scenario: FscDirectoryClient routes through the mTLS transport when configured

- GIVEN an FSC source with `authentication.mode=mtls` and a valid `mtls`
  block
- WHEN `FscDirectoryClient::call()` is called
- THEN the request is dispatched via `MtlsTransportService` carrying the
  resolved certificate bundle
- @e2e exclude backend adapter routing proof — covered by PHPUnit

#### Scenario: DsoClient routes through the mTLS transport when configured

- GIVEN a DSO source with `authentication.mode=mtls` and a valid `mtls`
  block
- WHEN `DsoClient::send()` is called
- THEN the request is dispatched via `MtlsTransportService` carrying the
  resolved certificate bundle
- @e2e exclude backend adapter routing proof — covered by PHPUnit

#### Scenario: Token mode is unchanged when mTLS is not configured

- GIVEN any of the three sources with `authentication.mode` absent or
  `token`
- WHEN the adapter dispatches a request
- THEN it uses the existing `encryptedToken` Bearer-header path unchanged
  and `MtlsTransportService` is never invoked
- @e2e exclude backend regression guard — covered by PHPUnit
