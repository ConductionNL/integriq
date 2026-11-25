---
kind: code
---

# Proposal: consumer-auth-on-openregister

## Summary

Integriq checks every inbound credential (JWT, Basic, OAuth, API key, Nextcloud session) in its own `lib/Service/AuthorizationService.php`. Hydra gate 23 (rule 6, `consume-or-rbac-fleet-wide`) flags that file, and since 3 October the gate blocks. OpenRegister #4361 now offers the same checks as public entry points with a pluggable consumer source. This change moves integriq onto them: OpenRegister verifies, integriq supplies its consumers. Integriq keeps its `consumer` schema and its data.

## Motivation

- Ruben's decisions 56, 62 and 64 (build-all DECISIONS.md, 3 and 5 October): migrate the flagged class onto OpenRegister, build the gaps in OpenRegister first, and let integriq keep its consumers through a pluggable source (Q5).
- The gap note (`for-ruben/integriq-gate23-gap.md`) named five gaps. #4361 closed them: public entry points, RS/PS verification, jti replay refusal and a future-`iat` refusal, `authorizeNcSession`, and `getResolvedConsumer`.
- One local copy of token verification is one more place a fix has to land. The jti and issue-time checks were already the same code in both apps.

## Scope

- `IntegriqConsumerSource` offers integriq's `consumer` objects to OpenRegister (`findByIssuer` by name, `findByApiKey` over `apiKey` consumers, constant-time).
- `OpenRegisterCredentialBridge` calls OpenRegister's entry points, keeps integriq's own exception, hands the runtime the consumer object it keys rate limits and call logs on, and keeps the 3600-second lifetime cap.
- The six callers (endpoint runtime, endpoints controller, SCIM, Notificaties callback, EUDI wallet, LTI launch) depend on the bridge.
- `lib/Service/AuthorizationService.php` is removed. Nothing calls it.

## Out of scope

- Moving integriq's consumers into OpenRegister's consumer table. Ruben chose the pluggable source (Q5); no data migration.
- Whether OpenRegister itself caps a token's lifetime. That is Q4, open with Ruben. Until he answers, integriq keeps its cap on its own side.
- The register descriptions that still name the old class (`authorizationConfiguration`, `userId` in `integriq_register.json`). Changing them moves the schema-l10n catalogue and the app version; they go with the next register change.
- `access-oauth-and-token-validation` (0 of 15 tasks done) still plans its JWKS resolver inside the removed class. It needs a new home (a consumer source that reads a JWKS, or OpenRegister), to be planned when that change starts.

## Behaviour changes

| What | Before | After |
|---|---|---|
| jti replay cache | `integriq.jti` | `openregister.jti`. A token whose jti was used just before the upgrade can be used once more after it, within its own lifetime (at most an hour, by integriq's cap). |
| Over-long token | refused before the jti was recorded and before any user acted | refused after OpenRegister accepted it: its jti is recorded and the acting user is set, then cleared by integriq before the 401 |
| OpenRegister without #4361 | not applicable | every check refused with "Inbound authentication is unavailable"; no fallback |
| Malformed Basic header | could raise a PHP warning before the refusal | refused cleanly (strict base64, password may contain `:`) |
| OAuth caller outside the allow-list | reason "not allowed to view endpoint" | reason "not allowed to login on this endpoint" (same 401) |
| A consumer whose configuration holds a `grant` | ignored | OpenRegister binds it as a ceiling on what the token may do (narrows only) |
| JWT caller | not attributed in OpenRegister's audit trail | attributed to the consumer (OpenRegister's token context) |
