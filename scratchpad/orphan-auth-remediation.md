# openconnector — orphan-auth remediation

Change: `openspec/changes/orphan-auth-remediation`
Worktree: `/home/rubenlinde/wave2-worktrees/openconnector-orphan-auth` (base `origin/development` @ dd44a78)

## Baseline (clean origin/development, oc-phpunit-83:local)
- Unit suite: **1768 tests, 4852 assertions, 0 failures** (1 pre-existing PHPUnit deprecation)
- gate-6 orphan-auth: **2** methods (both LTI)

## Verdict table

| # | Method (file:line) | Callers | Verdict | Note |
|---|---|---|---|---|
| 1 | `LtiRegistrationResolverService::assertSingleRegistrationReference` :279 | 0→1 | **WIRE** | into `findDeploymentByUuid` (sole resolver for live AGS/NRPS dispatch) |
| 2 | `LtiLaunchService::verifyDeepLinkingResponse` :549 | 0 | **SEAM/LEAVE** | 1 of 5 uncalled Platform-role consuming-app service methods (REQ-LTI-006); no live path; follow-up ocon#210 |

## WIRE proof (bad-path rejected on live resolution path)
`testDeploymentWithBothReferencesIsRejected` + `testDeploymentWithNeitherReferenceIsRejected`
→ `findDeploymentByUuid()` throws `LtiValidationException` on ambiguous rows.
`testDeploymentWithSingleReferenceResolves` → well-formed row resolves (regression guard).
`LtiRegistrationResolverServiceTest`: 11 → **14 tests, 18 assertions, 0 failures**.

## After change
- Unit suite: **1771 tests, 4856 assertions, 0 failures**
- gate-6 orphan-auth: **1** remaining (`verifyDeepLinkingResponse` — the documented seam)

## FLAG
No LIVE unprotected path found. `verifyDeepLinkingResponse` guards an inbound
Platform-role deep-linking response that has **no HTTP route** — an orphaned
capability (whole Platform-role surface unwired: `initiatePlatformLaunch`,
`buildDeepLinkingResponse`, `verifyDeepLinkingResponse`, `consumeLaunchReference`,
`resolveResourceMapping` all 0 callers), not an exploitable dead auth. Follow-up: ocon#210.
