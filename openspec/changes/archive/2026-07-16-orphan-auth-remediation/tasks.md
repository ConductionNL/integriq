# Tasks — openconnector orphan-auth remediation

> Scope: gate-6 (`orphan-auth`) reports 2 orphan methods on clean
> `origin/development`. Triage each to exactly one verdict, wire the one on a
> live path, document the one that is a consuming-app seam. No schema/seed/
> notification surface is touched.

## 1. Triage

- [x] 1.1 Establish clean baseline: full unit suite green on `origin/development` (1768 tests, 0 failures)
- [x] 1.2 Enumerate gate-6 findings with a private log (shared `/tmp` log is clobbered by parallel sessions) — 2 methods, both LTI
- [x] 1.3 Confirm `class-injected ≠ method-called` via `grep -rnE -- '->method\(' lib/ src/` for each finding
- [x] 1.4 Record verdict table with file:line evidence in `design.md`

## 2. WIRE — assertSingleRegistrationReference (live AGS/NRPS path)

- [x] 2.1 Confirm `findDeploymentByUuid()` is the sole deployment-by-uuid resolver (callers: `LtiAgsService:150,291,489`, `LtiNrpsService:90`)
- [x] 2.2 Confirm the write-time superseder (`lti_deployment.oneOf`) exists but is write-time only; read-time gate is absent
- [x] 2.3 Call `assertSingleRegistrationReference()` inside `findDeploymentByUuid()` after resolving a non-null deployment, fail closed on ambiguous rows
- [x] 2.4 Add regression test: single-reference deployment resolves unchanged
- [x] 2.5 Add bad-path tests: both-references and neither-reference rows are rejected with `LtiValidationException` on the live resolution path
- [x] 2.6 Re-run gate-6 — `assertSingleRegistrationReference` now has a caller (resolved)

## 3. LEAVE (documented seam) — verifyDeepLinkingResponse

- [x] 3.1 Confirm it is one of five uncalled Platform-role consuming-app service methods (REQ-LTI-006), not an unprotected live path
- [x] 3.2 Confirm no HTTP route processes a deep-linking response without verification (none exists)
- [x] 3.3 Document as a follow-up (orphaned capability) on ocon#210 rather than delete or force-wire

## 4. Verify

- [x] 4.1 Full unit suite green after change (1771 tests, 0 failures)
- [x] 4.2 Spec delta added to `lti-platform` (REQ-LTI-001 read-time enforcement scenario)
- [x] 4.3 No unauthored deletions; SPDX/i18n unchanged (no new PHP file, no user-facing string)
