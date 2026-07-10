# Tasks — DSO STAM PKIoverheid signature verification

## 1. Signature verifier service

- [ ] Create `lib/Service/DSOSignatureVerifierService.php` implementing PKIoverheid
      certificate-chain validation (Private Root CA) for the `X-DSO-Signature`
      header against the raw request body.
- [ ] Support both HMAC (shared-secret, pre-production) and RSA/X.509
      certificate-chain (production) signature modes, selected via admin config.
- [ ] Fail closed on every error path: missing header, malformed signature,
      untrusted/expired certificate, chain-validation failure, body-hash mismatch
      — all return `false` (→ HTTP 401 per REQ-DSO-001).
- [ ] Unit tests covering each failure path plus one valid-signature success path
      using a locally-generated test certificate chain.

## 2. Admin configuration

- [ ] Add PKIoverheid certificate chain (PEM) + signing mode fields to
      `OpenConnectorAdmin` settings (`lib/Settings/`).
- [ ] Validate the configured chain at save-time (parseable X.509, not expired)
      and surface a clear admin-facing error otherwise.

## 3. Controller wiring

- [ ] Inject `DSOSignatureVerifierService` into `DSOController` and replace the
      body of `validateSignature()` (`lib/Controller/DSOController.php:185-213`)
      with a call into the new service.
- [ ] Remove the "accept present-but-unverified signature" placeholder branch
      (`lib/Controller/DSOController.php:203-211`) once the verifier ships.
- [ ] Flip the `dso_signature_enforcement` app-config default to `true`.

## 4. Route re-enablement

- [ ] Restore the `dSO#receiveVerzoek` route in `appinfo/routes.php` (currently
      commented out at lines 20-34).
- [ ] Restore `#[PublicPage]` + `#[NoCSRFRequired]` attributes on
      `receiveVerzoek()` with the gate-suppression note the controller's docblock
      already promises (`lib/Controller/DSOController.php:76-86`).
- [ ] Confirm `hydra-gate-semantic-auth` / `hydra-gate-route-reachability` pass
      on the re-enabled route.

## 5. Spec + tests

- [ ] Update `openspec/specs/dso-omgevingsloket/spec.md` REQ-DSO-001 scenario
      "Invalid webhook signature rejected" to reference the real verifier.
- [ ] Add a Newman/integration test posting a forged signature to
      `/api/dso/stam/verzoeken` and asserting HTTP 401.
- [ ] Add a Newman/integration test posting a validly-signed test payload and
      asserting HTTP 202.

## Acceptance criteria

- No request reaches `DSOParserService::parseVerzoek()` without a
  cryptographically verified signature when `dso_signature_enforcement` is on
  (and the flag defaults to on after this change).
- The route is live in `appinfo/routes.php` and passes `hydra-gate-route-reachability`.
- Unit + integration tests cover both the reject and accept paths.
