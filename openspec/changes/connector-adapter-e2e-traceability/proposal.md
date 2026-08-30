---
kind: code
depends_on: []
---

## Why

Three of integriq's largest capability specs — `stuf-adapter` (58 scenarios,
`openspec/specs/stuf-adapter/spec.md`), `dso-omgevingsloket` (52 scenarios,
`openspec/specs/dso-omgevingsloket/spec.md`), and `ibabs-notubiz-connector` (46
scenarios, `openspec/specs/ibabs-notubiz-connector/spec.md`) — carry **zero** `@e2e`
annotations of any kind (confirmed via `grep -c "@e2e" openspec/specs/{stuf-adapter,
dso-omgevingsloket,ibabs-notubiz-connector}/spec.md` → all `0`). That is a different,
worse failure mode than the rest of the app: every other backend-only capability here
(`endpoint-runtime`, `dead-letter-replay`) carries an explicit
`@e2e exclude backend ... — covered by Newman/PHPUnit, not browser UI` line per scenario
(e.g. `openspec/specs/endpoint-runtime/spec.md:66`), which is how gate-19
(`check_e2e_coverage.py`) is satisfied honestly for backend-only capability. These three
government-integration adapters have none — not because someone judged them out of
scope, but because the annotation pass never happened. Gate-19 is diff-scoped (ADR-020)
so this legacy gap does not currently block CI, but it is real debt on the fleet's
highest-stakes integrations (StUF-BG/StUF-ZKN, DSO/Omgevingsloket STAM, iBabs/NotuBiz
RIS).

Digging past the annotation gap into actual coverage depth surfaced a genuine
security-relevant hole, not just documentation debt:

- **REQ-STUF-011 (PKIoverheid mTLS Authentication)** and **REQ-STUF-012 (WS-Security
  UsernameToken Authentication)** in `openspec/specs/stuf-adapter/spec.md:86-116` have
  **zero PHPUnit coverage of any kind** — `grep -rn "PKIoverheid|mTLS|WS-Security|
  UsernameToken" tests/` matches nothing under `tests/Unit/Service/StUF*.php`. Neither
  the certificate-write/cleanup path (`CallService::getCertificate()` /
  `removeFiles()`) nor the WS-Security header construction (`PasswordDigest` hashing,
  `PasswordText` plaintext, nonce/timestamp) that `AuthenticationService` is supposed to
  produce is exercised by a single test. This is squarely the "controller/service with
  zero test coverage on a security-relevant path" pattern the 2026-07-07 review didn't
  check for.
- By contrast, `dso-omgevingsloket`'s equivalent PKIoverheid gap (REQ-DSO-050,
  `DSOController::validateSignature()`) is **already tracked** by the active change
  `openspec/changes/dso-stam-pkioverheid-signature-verification/` — this change
  explicitly does not duplicate that work. It only adds the missing `@e2e` traceability
  for the other 51 `dso-omgevingsloket` scenarios (all backend, no Vue UI exists for
  this capability — confirmed via `find src -iname "*dso*"` → no results).
- `ibabs-notubiz-connector` and `stuf-adapter` likewise have no Vue UI (`find src
  -iname "*stuf*" -o -iname "*ibabs*" -o -iname "*notubiz*"` → no results); their
  `@e2e exclude` annotations are legitimate — this is a backend-to-backend integration
  surface (StUF/DSO/iBabs are SOAP/REST government systems, not browser-driven), the
  same shape as `endpoint-runtime`.

## What Changes

- Add `@e2e exclude backend <adapter> integration — covered by PHPUnit/Newman, not
  browser UI` annotation lines to every scenario in `openspec/specs/stuf-adapter/spec.md`,
  `openspec/specs/dso-omgevingsloket/spec.md` (the 51 scenarios not already covered by
  the in-flight PKIoverheid-signature change), and
  `openspec/specs/ibabs-notubiz-connector/spec.md`, mirroring the existing
  `endpoint-runtime` pattern exactly.
- Add `tests/Unit/Service/StUFAuthenticationServiceTest.php` (or extend the existing
  `AuthenticationService` test file if StUF auth is built there) covering: mTLS
  certificate write/mTLS-flag-passthrough/cleanup (REQ-STUF-011, all three scenarios),
  WS-Security UsernameToken header construction with both `PasswordDigest` (verify the
  `Base64(SHA1(Nonce + Created + Password))` formula) and `PasswordText` modes
  (REQ-STUF-012, all three scenarios).
- No Vue/UI change — no e2e Playwright spec is added, since no browser surface exists
  for these three capabilities (verified by repo-wide `find src -iname` above).

## Impact

- **`openspec/specs/stuf-adapter/spec.md`**, **`openspec/specs/dso-omgevingsloket/spec.md`**,
  **`openspec/specs/ibabs-notubiz-connector/spec.md`** — annotation-only edits.
- **`tests/Unit/Service/`** — new/extended PHPUnit coverage for STUF mTLS + WS-Security.
- **No production `lib/` code change** — REQ-STUF-011/012 already have implementations
  (`CallService`, `AuthenticationService`); this change only proves them with tests. If
  the new tests reveal the implementation does not actually match the spec (e.g. digest
  formula wrong), that is a **new, separate** finding to be raised as its own change —
  not silently patched here.
- Does not touch `dso-omgevingsloket`'s REQ-DSO-050 (owned by
  `dso-stam-pkioverheid-signature-verification`).
