---
kind: code
depends_on: []
---

# Proposal: mtls-client-certificate-transport

## Summary

Every real Dutch-government transport behind the three translation adapters
shipped this week — `IStandaardenClient` (iwmo-ijw-adapter, GGk/VECOZO iWMO/
iJW), `FscDirectoryClient` (fsc-connectivity, FSC Outway/Inway), `DsoClient`
(dso-connector-adapter, DSO-LV) — historically authenticates the OUTBOUND leg
with mutual TLS client certificates (PKIoverheid), not a bearer token. Each
adapter shipped with Bearer-token auth as a DELIBERATE, explicitly documented
deviation and named the exact same open question: "client-cert support is a
documented gap, not a silent omission." This change closes that gap ONCE with
a shared mTLS transport (`OCA\OpenConnector\Service\Mtls`) that slots behind
the existing per-adapter provider seams, so each client can dispatch over a
real mutual-TLS connection when configured, while token mode keeps working
unchanged (config-driven mode selection).

## Motivation

Without this change none of the three adapters can go live against a real
GGk/VECOZO, FSC Outway, or DSO-LV production/PKIoverheid endpoint — they are
demonstrable only against bearer-token stand-ins. Building the transport
three times (once per adapter) would triple the security surface for private
key handling; building it once, behind the seams the adapters already
expose, is the point of this change.

## Key design decision — key-storage path

`CredentialBrokerService::resolveInjectable()` (openregister) returns a
single opaque secret **string** and every registered `generic-*` provider
type (`generic-apikey`, `generic-bearer`, `generic-basic`, `generic-oauth2`,
`generic-jwt`) is a single-header-template shape — none can carry a
multi-field PEM certificate + private key + passphrase + CA bundle bundle,
and the broker is explicitly documented (`PkiOverheidCredentialResolver`,
merged 2026-07-15, `dso-stam-pkioverheid-signature-verification`) as a
"constrained proxy" that "deliberately never hands raw secret material back
to a calling app" for in-process use (WS-Security signing) — it fails closed
pending a not-yet-built `issueSigningMaterial()` broker capability. An mTLS
client certificate has the identical requirement: Guzzle/cURL needs the
private key IN-PROCESS to perform the TLS handshake, the same shape the
broker already declined to support. Per the task brief's explicit fallback
clause, this change stores certificate material `OCP\Security\ICrypto`-
encrypted at rest on the source's `configuration.authentication.mtls` block —
exactly the pattern `IStandaardenClient`/`FscDirectoryClient`/`DsoClient`/
`RestNotifyNlProvider` already use for their bearer tokens — never in an
OpenRegister schema, never plaintext, never logged. See design.md for the
full evidence trail and the documented upgrade path once the broker gains a
signing-material capability.

## Scope

1. Shared `OCA\OpenConnector\Service\Mtls` transport: `MtlsCertificateBundle`
   (value object), `MtlsConfigResolver` (decrypts + validates the encrypted
   config block into a bundle, fail-closed), `MtlsTransportOptionsBuilder`
   (materialises PEM material to 0600 temp files, builds Guzzle `cert`/
   `ssl_key`/`verify` options, guarantees cleanup), `MtlsTransportService`
   (single call-site: materialise → dispatch → cleanup in `finally`,
   regardless of outcome).
2. Wire the transport behind `IStandaardenClient::send()`,
   `FscDirectoryClient::call()`, `DsoClient::send()` via one new
   constructor-injected `MtlsTransportService` per client — config-driven
   `authentication.mode` (`token`|`mtls`, default `token`) selects the path;
   public interfaces (`IwmoIjwProviderInterface`, `FscConnectivityProviderInterface`,
   `DsoConnectorProviderInterface`) are unchanged.
3. Typed, stable-errorCode failure semantics (`MtlsTransportException` and
   subclasses) for missing/invalid cert, invalid key, expired cert,
   passphrase failure, and handshake failure — never a silent downgrade to
   plaintext/token.
4. Update the three adapters' design.md "Open Questions" to record the gap
   as closed (cert *provisioning*/enrolment remains an operator task).
5. Prove routing with tests (orphaned-capability rule) — not just that the
   builder exists.
6. Full PHPUnit coverage + `composer check:strict`, diffed against a
   pristine `origin/development` baseline.

## Non-goals

- Cert *provisioning*/enrolment (PKIoverheid registration, FSC Outway/Inway
  federation onboarding) — operator-side, out of scope everywhere already.
- Wiring `credentialRef`/broker-issued signing material — explicitly
  deferred pending a broker capability that does not exist yet (see design.md).
