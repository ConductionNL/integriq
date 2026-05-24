# Retrofit — authorization-jwt

Describes observed behavior of 9 methods under `authorization-jwt` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Service/AuthorizationService.php::authorizeJwt()`
- `lib/Service/AuthorizationService.php::authorizeBasic()`
- `lib/Service/AuthorizationService.php::authorizeOAuth()`
- `lib/Service/AuthorizationService.php::authorizeApiKey()`
- `lib/Service/AuthorizationService.php::corsAfterController()`
- `lib/Service/AuthorizationService.php::validatePayload()` (private helper of authorizeJwt)
- `lib/Service/AuthorizationService.php::findIssuer()` (private helper of authorizeJwt)
- `lib/Service/AuthorizationService.php::checkHeaders()` (private helper of authorizeJwt)
- `lib/Service/AuthorizationService.php::getJWK()` (private helper of authorizeJwt)

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces observed-but-suspicious behavior (commented-out user/group checks, temp-file public key writes, duplicate HS256 in algorithm manager)

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
