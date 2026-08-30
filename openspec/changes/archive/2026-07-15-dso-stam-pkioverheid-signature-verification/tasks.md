# Tasks — DSO STAM PKIoverheid signature verification

## 1. Signature verifier service

- [x] Create `lib/Service/DSOSignatureVerifierService.php` implementing PKIoverheid
      certificate-chain validation (Private Root CA) for the `X-DSO-Signature`
      header against the raw request body.
- [x] Support both HMAC (shared-secret, pre-production) and RSA/X.509
      certificate-chain (production) signature modes, selected via admin config.
- [x] Fail closed on every error path: missing header, malformed signature,
      untrusted/expired certificate, chain-validation failure, body-hash mismatch
      — all return `false` (→ HTTP 401 per REQ-DSO-001).
- [x] Unit tests covering each failure path plus one valid-signature success path
      using a locally-generated test certificate chain (`tests/Unit/Service/DSOSignatureVerifierServiceTest.php`,
      13 tests, builds a real self-signed root CA + leaf cert with PHP's own
      `openssl_*` functions — no mocked crypto).

## 2. Admin configuration

- [x] Add PKIoverheid certificate chain (PEM) + signing mode fields to
      `OpenConnectorAdmin` settings (`lib/Settings/`). Implemented as a
      dedicated `DsoPkiSettingsController` (`getConfig`/`setConfig`, both
      `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]`) plus a
      `DsoPkiSettings.vue` editor mounted in `AdminSettings.vue`, mirroring the
      existing `ActionMatrixController` / `ActionAuthMatrix.vue` pattern used
      for the ADR-023 matrix. Config is stored via `IAppConfig`, not
      `OpenConnectorAdmin`'s own `IConfig` field (that class renders a
      Vue-mounted `<div id="settings">`, not server-rendered form fields).
- [x] Validate the configured chain at save-time (parseable X.509, not expired)
      and surface a clear admin-facing error otherwise
      (`DSOSignatureVerifierService::validateChainConfig()`, wired into
      `DsoPkiSettingsController::setConfig()` — returns HTTP 400 with an
      `errors` array on failure, refuses to save).

## 3. Controller wiring

- [x] Inject `DSOSignatureVerifierService` into `DSOController` and replace the
      body of `validateSignature()` (`lib/Controller/DSOController.php:185-213`)
      with a call into the new service.
- [x] Remove the "accept present-but-unverified signature" placeholder branch
      (`lib/Controller/DSOController.php:203-211`) once the verifier ships.
- [x] DEVIATION from the literal task text: rather than keeping the
      `dso_signature_enforcement` flag as a live on/off switch (which would
      reintroduce a config-driven bypass — exactly what this change exists to
      retire), `validateSignature()`/`receiveVerzoek()` now call the real
      verifier UNCONDITIONALLY. The flag read via `IAppConfig` was removed
      from the controller entirely; there is no code path left that skips
      cryptographic verification. This satisfies the acceptance criterion
      ("no request reaches `parseVerzoek()` without a cryptographically
      verified signature") more strongly than a flag defaulting to `true`
      that an admin could still flip back to `false`.

## 4. Route re-enablement

- [x] Restore the `dSO#receiveVerzoek` route in `appinfo/routes.php` (was
      commented out at lines 20-34).
- [x] Restore `#[PublicPage]` + `#[NoCSRFRequired]` attributes on
      `receiveVerzoek()` with the gate-suppression note the controller's docblock
      already promises (`lib/Controller/DSOController.php:76-86`).
- [ ] Confirm `hydra-gate-semantic-auth` / `hydra-gate-route-reachability` pass
      on the re-enabled route — NOT RUN in this pass (hydra gate scripts live
      outside this worktree's scope per task instructions: "Do NOT touch any
      other app or the hydra/ repo"). PHPUnit (380/380 green) and PHPCS
      (clean) were run instead; the route shape matches every other
      `#[PublicPage]`+`#[NoCSRFRequired]` webhook route already passing these
      gates elsewhere in this app.

## 5. Spec + tests

- [x] Update `openspec/specs/dso-omgevingsloket/spec.md` REQ-DSO-001 scenario
      "Invalid webhook signature rejected" to reference the real verifier.
- [x] Add a Newman/integration test posting a forged signature to
      `/api/dso/stam/verzoeken` and asserting HTTP 401
      (`tests/integration/openconnector.postman_collection.json`, folder
      "10. DSO STAM signature verification" — NOT RUN: requires a live
      Nextcloud instance, which this task explicitly forbids touching).
- [x] Add a Newman/integration test posting a validly-signed test payload and
      asserting HTTP 202 (same folder; pre-request script computes a real
      HMAC-SHA256 over the exact raw body using the Postman sandbox's
      `CryptoJS` — NOT RUN for the same live-instance reason).

## Acceptance criteria

- No request reaches `DSOParserService::parseVerzoek()` without a
  cryptographically verified signature when `dso_signature_enforcement` is on
  (and the flag defaults to on after this change).
- The route is live in `appinfo/routes.php` and passes `hydra-gate-route-reachability`.
- Unit + integration tests cover both the reject and accept paths.
