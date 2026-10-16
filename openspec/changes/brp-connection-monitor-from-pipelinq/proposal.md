# The BRP connection monitor comes to integriq

Ruben's decision of 2026-10-09: the BRP monitor (pipelinq board `PqBrpMonitor`: query performance and certificate expiry) moves entirely to integriq, because connections and their health belong here. Certificates and credentials of any connection live in keepiq; integriq references the keepiq secret and shows its expiry with a link to keepiq to renew it. pipelinq keeps only doing the BRP lookups, through integriq.

This is integriq's share. The counterpart changes are:

- ConductionNL/pipelinq `brp-monitor-moves-to-integriq`: pipelinq drops its monitor, its certificate check and its direct Haal Centraal fallback, and hands its connection settings to integriq once.
- ConductionNL/keepiq `connection-certificate-expiry-for-integriq`: keepiq answers the expiry date of a certificate integriq references, and gives a renew link.
- ConductionNL/design-system: board `PqBrpMonitor` becomes `integriq/IqBrpMonitor`, drawn in integriq's header and side bar.

## Why

- pipelinq watches the BRP connection on its own: a daily job reads the certificate **file** on the server (`brp.cert_path`), and a second job aggregates lookups from pipelinq's audit trail. integriq, which makes the call through the `brp-haalcentraal` source, shows nothing of it.
- integriq keeps mTLS material **inside the source object**, encrypted with `ICrypto` (`MtlsConfigResolver`, `configuration.authentication.mtls.encryptedCertificate`). Other credentials already moved to the credential broker, backed by keepiq (`source-credential-custody`). Certificates did not, so their expiry is known only when a call fails pre-flight.

## What changes

- **A source shows how it performs.** For the last 24 hours: calls, errors by kind, average and slowest response time, from integriq's own call log. Shown on the source page and on a connection monitor page reached from Operational health.
- **A source shows when its certificate expires.** When the source's mTLS material is a keepiq reference, integriq asks keepiq for the expiry date and shows it with days left and a link to the secret in keepiq to renew it. integriq never sees or stores the expiry by parsing the certificate itself once the reference is in place.
- **Expiry alerts are integriq's.** The hourly connection health job opens an alert at 30, 14 and 7 days, using the existing threshold and alert machinery (REQ-CRUN-004, REQ-CRUN-005).
- **mTLS material can be a keepiq reference.** `MtlsConfigResolver` accepts `{credentialRef}` for the certificate, key, passphrase and CA bundle, resolved through the credential broker at call time. Inline encrypted material keeps working.
- **pipelinq's BRP settings arrive once.** integriq listens for pipelinq's handover event, fills the `brp-haalcentraal` source where it is empty, and mints keepiq secrets for the client secret, certificate, key and CA bundle.

## Capabilities

### Modified capabilities

- `connection-run-monitoring`: per-source performance and certificate expiry, with expiry alerts.
- `mtls-client-certificate-transport`: mTLS material by keepiq reference.
- `source-credential-custody`: a connection handed over by another app stores its secrets in keepiq.

## Impact

- `lib/Service/Mtls/MtlsConfigResolver.php` (reference support), the connection health job, `lib/Observability/` (performance aggregation over `call_log`)
- New listener for pipelinq's handover event; new page `ConnectionMonitor` in `src/manifest.json`, linked from `OperationalHealth` and from the source page
- Depends on keepiq exposing a certificate's expiry for a reference (keepiq counterpart).
- Feature tier: V1.
