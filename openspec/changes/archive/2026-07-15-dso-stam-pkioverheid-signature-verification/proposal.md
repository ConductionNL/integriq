---
kind: code
depends_on: []
---

## Why

The DSO / Omgevingsloket STAM koppelvlak (`lib/Controller/DSOController.php`) is the
inbound endpoint that would receive vergunningaanvragen, meldingen, and
informatieverzoeken pushed from DSO-LV. Its own spec
(`openspec/specs/dso-omgevingsloket/spec.md`, REQ-DSO-001) requires:

> "the adapter validates the webhook signature ... **Invalid webhook signature
> rejected** ... returns HTTP 401 Unauthorized"

But `DSOController::validateSignature()` (`lib/Controller/DSOController.php:185-213`)
does not verify any cryptographic signature at all:

- When the `dso_signature_enforcement` app-config flag is `false` (the shipped
  default), the method only checks that an `X-DSO-Signature` header is
  **present and non-empty** (`lib/Controller/DSOController.php:207-209`) — any
  caller can send an arbitrary non-empty string and pass.
- When the flag is `true`, the method unconditionally returns `false`
  (`lib/Controller/DSOController.php:195-201`) because "Full PKIoverheid
  certificate-chain verification (REQ-DSO-050) is not yet implemented."

The PKIoverheid HMAC/RSA verifier is tracked as **Task 12** in the archived
`openspec/changes/archive/2026-06-14-dso-omgevingsloket/tasks.md` (REQ-DSO-050),
which documents the same gap:

> "Task 1 is marked `[~]` BLOCKED-ON-Task-12 ... accepting a verzoek without
> cryptographic verification is OWASP A07/A02."

Because that change already archived with Task 12 unimplemented and no
follow-up change exists, `appinfo/routes.php` correctly keeps the
`dSO#receiveVerzoek` route commented out (`appinfo/routes.php:20-34`) — the
endpoint is unreachable in production today, so there is no live
vulnerability. But the feature itself (STAM koppelvlak for municipalities
running procest bezwaar/beroep against DSO-LV) remains entirely undelivered:
zero cryptographic verification exists, and the route cannot be safely
re-enabled without it.

## What Changes

- Implement the missing PKIoverheid certificate-chain / HMAC-RSA body-signature
  verifier (REQ-DSO-050 / Task 12) as a new `DSOSignatureVerifierService`,
  replacing the placeholder logic in `DSOController::validateSignature()`
  (`lib/Controller/DSOController.php:185-213`).
- Support certificate-based verification against a configured PKIoverheid
  Private Root CA chain, with the signing certificate + intermediate chain
  supplied via admin settings (`OpenConnectorAdmin`), never hardcoded.
- Keep the existing fail-closed behavior for every unconfigured or
  misconfigured state (no cert configured, chain validation failure, expired
  cert, signature mismatch) — all MUST return HTTP 401, matching the
  "Invalid webhook signature rejected" scenario in REQ-DSO-001.
- Re-enable `['name' => 'dSO#receiveVerzoek', 'url' => '/api/dso/stam/verzoeken', 'verb' => 'POST']`
  in `appinfo/routes.php` (currently commented out at lines 20-34) and restore
  the `#[PublicPage]` / `#[NoCSRFRequired]` attributes on `receiveVerzoek()`
  together with the gate-suppression note referenced in the controller's own
  docblock (`lib/Controller/DSOController.php:76-86`).
- Flip the `dso_signature_enforcement` app-config default to `true` once the
  verifier is proven against DSO-LV pre-production certificates, so the
  "accept present-but-unverified signature" placeholder path
  (`lib/Controller/DSOController.php:203-211`) is retired, not left reachable
  as a silent bypass.
- **BREAKING (behavioural):** any caller currently relying on the
  present-but-unverified-header placeholder (there should be none in
  production, since the route is unregistered) will be rejected once real
  verification is enforced.

## Impact

- `lib/Controller/DSOController.php` — replace `validateSignature()` body.
- `lib/Service/` — new `DSOSignatureVerifierService`.
- `lib/Settings/` — admin settings fields for the PKIoverheid cert chain.
- `appinfo/routes.php` — re-add the `dSO#receiveVerzoek` route.
- `openspec/specs/dso-omgevingsloket/spec.md` — MODIFIED requirement (REQ-DSO-001)
  to record the verifier as implemented, not TBD.
