# Retrofit — authentication-twig

Describes observed behavior of 22 methods under `authentication-twig` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

AuthenticationService.php (10 methods):
- `fetchOAuthTokens`, `fetchDecosToken`, `fetchJWTToken`, `createClientCredentialConfig`, `createPasswordConfig`, `getRSJWK`, `getHSJWK`, `getJWK`, `getJWTPayload`, `generateJWT`

AuthenticationRuntime.php (3 methods, Twig runtime):
- `oauthToken`, `decosToken`, `jwtToken`

MappingRuntime.php (9 methods, Twig runtime):
- `b64enc`, `b64dec`, `json_decode`, `callSource`, `executeMapping`, `generateUuid`, `getFileContents`, `getFiles`, `createSlug`

## Approach

- Observed-but-suspicious behaviour flagged in REQ Notes (private RSA key written to `/var/tmp/privatekey-<microtime+pid>` then unlink — leak window on crash; HS-secret base64 includes `addslashes` quirk; JWT payload Twig-rendered then JSON-decoded — template injection if payload comes from caller-controlled data; `generateJWT` catches its own exception and returns the error message as the JWT — silently broken tokens propagate)

Source: openspec/coverage-report.md generated 2026-05-24.
