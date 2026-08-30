# Design: mtls-client-certificate-transport

## Context

`IStandaardenClient`, `FscDirectoryClient`, and `DsoClient` (all merged
2026-07-14/15) each hand-roll a Guzzle `Client` injected via the constructor
and dispatch requests directly (`$this->httpClient->request($method, $url,
$requestOptions)`). None goes through `CallService` (which already has
`getCertificate()`/`removeFiles()` cert-material plumbing for `type=soap`
Sources) — they are standalone REST bindings behind their own provider
interfaces. Each documented, verbatim, the same open question: the real
transport (GGk/VECOZO, FSC Outway/Inway, DSO-LV/PKIoverheid) needs mutual TLS
client-certificate auth, not the bearer token they ship with.

## Key-storage decision (verified, not assumed)

**Decision: `OCP\Security\ICrypto`-encrypted-at-rest fields on
`configuration.authentication.mtls`, not `credentialRef`/the OpenRegister
credential broker.**

Evidence gathered before deciding:

1. `CredentialBrokerService::resolveInjectable(string $credentialId, string
   $appId, ?string $actingUserId=null): ?string` (openregister) returns a
   **single opaque string**. The backing `CredentialStore::get(): ?string`
   contract is the same shape.
2. `openregister/lib/Settings/credential-providers.json` lists five
   `inject_only` provider types: `generic-apikey`, `generic-bearer`,
   `generic-basic`, `generic-oauth2`, `generic-jwt`. Every one substitutes a
   single `{secret}` token into a single header template. None models a
   multi-field bundle (certificate PEM + private key PEM + passphrase + CA
   bundle) — and there is no generic "opaque JSON blob" provider type either
   (the shapes are enumerated, not open-ended).
3. Most tellingly: the fleet has **already faced this exact problem** for
   PKIoverheid material and already made a decision.
   `lib/Adapters/Digikoppeling/PkiOverheidCredentialResolver.php` (merged
   2026-07-15, `dso-stam-pkioverheid-signature-verification`) resolves
   PKIoverheid signing material for in-process WS-Security signing. Its
   docblock states, verbatim: *"The current broker … is a CONSTRAINED PROXY:
   it performs an outbound call on the credential's behalf but deliberately
   never hands raw secret material back to a calling app. In-process XML
   signing cannot use that proxy shape, so until the broker grows an
   explicit 'signing-material' capability this resolver FAILS CLOSED …"* It
   reflection-probes for a not-yet-existing
   `CredentialBrokerService::issueSigningMaterial()` method and throws a
   configuration error today, every time, by design.

   An mTLS client certificate for outbound HTTP has the **identical**
   requirement: Guzzle/cURL needs the private key bytes in-process (as a
   file on disk, however briefly) to perform the TLS handshake itself — this
   is not a "broker calls the endpoint on our behalf" shape, it is "we need
   the key". The broker's constrained-proxy design, and the fleet's own
   just-established precedent of failing closed rather than working around
   it, both apply directly.

Conclusion: the broker **genuinely cannot hold this material safely today**
(verified against the actual method signatures and the fleet's own prior
decision on the structurally identical case), so per the task brief's
explicit fallback clause this change stores mTLS material `ICrypto`-encrypted
at rest, exactly mirroring how `IStandaardenClient`/`FscDirectoryClient`/
`DsoClient`/`RestNotifyNlProvider`/KISS already store their bearer tokens
(`configuration.authentication.encryptedToken`, decrypted in-process only for
the instant a request needs it, never logged, never persisted decrypted).

**Upgrade path** (documented, not built): the moment OpenRegister's broker
grows a signing/material-issuing capability (the same one
`PkiOverheidCredentialResolver` already watches for via
`method_exists($broker, 'issueSigningMaterial')`), `MtlsConfigResolver` can
add a `certificateRef` branch that probes for it the same way — no changes
needed to `MtlsTransportService`, `MtlsTransportOptionsBuilder`, or any of
the three adapters, because they consume an `MtlsCertificateBundle` value
object regardless of where its PEM strings came from. Building that branch
now, before the capability exists, would be dead code with no way to test it
meaningfully (`PkiOverheidCredentialResolverTest` already covers "fails
closed when the method doesn't exist" for the identical shape) — so it is
deliberately deferred, matching the existing precedent's own reasoning.

## Architecture

```
authentication.mode = "token" (default)        authentication.mode = "mtls"
        │                                                │
        ▼                                                ▼
buildAuthorizationHeader()                    MtlsConfigResolver::resolve()
  (unchanged, ICrypto)                          decrypts configuration.authentication.mtls.*
        │                                        → MtlsCertificateBundle
        │                                        (fails closed: missing/invalid
        │                                         cert|key, expired cert,
        │                                         bad passphrase — pre-flight,
        │                                         no network call yet)
        │                                                │
        ▼                                                ▼
$httpClient->request(...)                     MtlsTransportService::request(
  (existing path, untouched)                     $httpClient, $method, $url,
                                                  $requestOptions, $bundle)
                                                    │
                                                    ├─ MtlsTransportOptionsBuilder::materialize()
                                                    │    tempnam() + chmod 0600 (mirrors
                                                    │    CallService::writeFile()) for cert,
                                                    │    key, optional CA bundle
                                                    ├─ merge 'cert'/'ssl_key'/'verify' Guzzle
                                                    │    options into $requestOptions
                                                    ├─ dispatch via the SAME injected Guzzle
                                                    │    Client (no parallel HTTP stack)
                                                    ├─ GuzzleException during dispatch →
                                                    │    wrapped as MtlsHandshakeException
                                                    │    (stable errorCode, no secrets)
                                                    └─ finally: cleanup() unlinks every
                                                         materialized temp file — success,
                                                         exception, or GuzzleException, always
```

`MtlsTransportService` is the ONLY place that touches disk for key material
and the ONLY place that merges TLS options into a Guzzle request — each
adapter's `send()`/`call()` gains a small `if (mode === 'mtls')` branch that
calls it instead of `$this->httpClient->request()` directly; the token-mode
branch is byte-for-byte what shipped before. No parallel HTTP client, no
adapter contract change.

## Threat model / secret-handling notes

- **At rest**: cert PEM, key PEM, optional passphrase, optional CA bundle
  are each `ICrypto::encrypt()`-ed independently before being stored on the
  source's `configuration.authentication.mtls` object (encryption happens at
  admin-save time, outside this change's scope, mirroring
  `encryptedToken`). Never stored plaintext, never in an OpenRegister schema.
- **In memory**: `MtlsCertificateBundle` holds decrypted PEM strings only for
  the duration of one `MtlsConfigResolver::resolve()` → `MtlsTransportService::request()`
  call; nothing caches or persists the decrypted bundle.
- **On disk**: temp files are created with `tempnam()` in the system temp
  dir, `chmod 0600` re-asserted before and after write (mirrors
  `CallService::writeFile()`'s already-audited pattern, #1012(a)), and are
  ALWAYS removed in a `finally` block — verified by a test that forces the
  wrapped Guzzle call to throw and asserts the temp files no longer exist
  afterwards, plus a happy-path equivalent.
- **In logs/exceptions**: `MtlsTransportException` and subclasses take a
  `$message` that names configuration keys and stable `errorCode` constants
  only, never cert/key contents or temp file *contents* (file *paths* may
  appear in framework-level Guzzle exception messages, which is standard
  practice and not secret material — the path is not the key). A dedicated
  test asserts none of the PEM/passphrase fixture strings appear in any
  exception message or log call across the failure-mode test matrix.
- **Never fail open**: if `authentication.mode === 'mtls'` but the bundle
  cannot be resolved or the handshake fails, the call throws — there is no
  branch that falls back to token mode or proceeds without a cert.
  `MtlsConfigResolver` validates PEM shape (`BEGIN CERTIFICATE`/`BEGIN …
  PRIVATE KEY` markers), certificate expiry (`openssl_x509_parse()`), and
  passphrase correctness (`openssl_pkey_get_private()`) BEFORE any network
  call is attempted, so most misconfigurations are caught pre-flight and
  testable without a live TLS handshake.

## What remains operator-side (out of scope)

- **Cert provisioning/enrolment** — obtaining a PKIoverheid client
  certificate, registering it with GGk/VECOZO, FSC's federation, or
  DSO-LV/Digikoppeling, and rotating it before expiry, is an organisational
  process this change does not automate (matches the already-documented
  non-goal in `fsc-connectivity`'s design.md: "Cert-exchange / Outway-Inway
  provisioning is out of scope").
- **Admin UI for encrypting the cert fields on save** — this change
  provides the resolver/transport; the admin-settings save path that calls
  `ICrypto::encrypt()` on submitted PEM fields mirrors the existing
  `encryptedToken` save path and is not re-implemented here (same as prior
  adapter PRs, which also assume the encrypt-on-save wiring exists
  app-wide).
- **`credentialRef`/broker-issued material** — deferred, see "Upgrade path"
  above.

## Testing strategy

Mock at the HTTP client boundary (Guzzle `MockHandler`, matching all three
adapters' existing test style) — no live TLS handshake, no live certs beyond
small PHPUnit-generated self-signed fixtures (via `openssl_pkey_new()` /
`openssl_csr_sign()` at test-fixture build time, not committed binary certs).
