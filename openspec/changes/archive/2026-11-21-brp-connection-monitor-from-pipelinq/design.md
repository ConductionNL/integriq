# Design: the BRP connection monitor comes to integriq

## Decisions

### 1. Generic per source, BRP first

The board is the BRP monitor, but nothing in it is BRP specific: calls, errors, response time, certificate expiry. integriq builds it for every source, and the `brp-haalcentraal` source is the first one with a certificate in keepiq. The page title names the source.

### 2. Performance comes from the call log

Every call integriq makes is logged (`outbound-call-log`). The view aggregates `call_log` rows of the source over 24 hours: total, errors grouped by kind (time-out, 4xx, 5xx, TLS), average and slowest duration. pipelinq's cache hits are not integriq's business and are not shown.

### 3. The certificate lives in keepiq, integriq keeps a reference

`configuration.authentication.mtls` may hold `{credentialRef: {credentialId}}` per part instead of `encrypted*`. At call time `MtlsConfigResolver` resolves the reference through the OpenRegister credential broker (`resolveInjectable(..., actingOrganisationId)`), materialises the PEM transiently as today (REQ-002) and cleans up. The pre-flight expiry check stays.

For the monitor, integriq asks keepiq for the certificate's `notAfter` through a string-named event (`OCA\Keepiq\Event\CredentialExpiryRequestedEvent`, defined in keepiq's change). The answer is a date and a link, never certificate material. When keepiq is not installed or does not answer, the view says "Expiry unknown, keepiq did not answer" rather than guessing.

### 4. Alerts reuse the threshold machinery

The hourly connection health job reads the expiry and opens an alert per source and threshold (30, 14, 7 days, configurable), notifying the group named for the source (REQ-CRUN-005). One alert per source and threshold, never repeated.

### 5. The handover from pipelinq

integriq listens for `OCA\Pipelinq\Event\BrpConnectionHandoverEvent` (string-named, registered when the class exists). It fills empty fields of `brp-haalcentraal`, mints keepiq secrets for the secret material, verifies each resolves byte for byte (the same mint, verify, then reference rule as `source-credential-custody`), and answers which parts were taken and which conflicted. It never overwrites a set value.
