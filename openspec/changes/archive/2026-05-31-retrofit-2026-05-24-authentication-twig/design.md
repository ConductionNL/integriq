# Design — Retrofit authentication-twig

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`AuthenticationService` (405 LOC, 10 methods) is the outbound-auth toolkit: it builds
Guzzle call options for OAuth Client Credentials / Password / Decos, and mints signed
JWTs using HS256/384/512 + RS256/384/512 + PS256. Two Twig runtimes
(`AuthenticationRuntime`, `MappingRuntime`) expose these primitives plus generic
encoding/mapping/file helpers to source-configuration Twig templates so an operator
can write `{{ oauthToken(source) }}` directly into a templated header.

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `fetchOAuthTokens`, `createClientCredentialConfig`, `createPasswordConfig` |
| REQ-002 | `fetchJWTToken`, `getJWK`, `getRSJWK`, `getHSJWK`, `getJWTPayload`, `generateJWT` |
| REQ-003 | `fetchDecosToken` |
| REQ-004 | `AuthenticationRuntime::oauthToken/decosToken/jwtToken` |
| REQ-005 | `MappingRuntime::b64enc/b64dec/json_decode/callSource/executeMapping/generateUuid/getFileContents/getFiles/createSlug` |

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `getRSJWK` | private key written to `/var/tmp/privatekey-<microtime+pid>` then unlink — leak window | **high** |
| `generateJWT` | catches its own Exception, returns the error message AS the JWT string — silently broken tokens | **high** (functional bug) |
| `getHSJWK` | `addslashes` before base64-encoding the secret — peer mismatch on quote/backslash/NUL chars | medium |
| `getJWTPayload` | Twig-renders payload before JSON-decoding — template injection if payload is caller-controlled | medium |
| `fetchDecosToken` | posts entire remaining config as JSON body, not just credentials | low |
| `MappingRuntime::executeMapping` | hydrates caller-supplied array directly into Mapping entity | medium |
| `MappingRuntime::callSource` | `$decode` parameter accepted but never used | low |
| `MappingRuntime::createSlug` | regex permits only ASCII; non-ASCII silently stripped | low |

The combo of REQ-002's `generateJWT` swallowing exceptions and REQ-001 inlining a
JWT via `fetchJWTToken` for client_assertion means a misconfigured RSA key would
result in an `Authorization: Bearer <error message>` header being sent upstream.

## What the spec deliberately does NOT cover

- The Twig extensions themselves (`AuthenticationExtension`, `MappingExtension`)
  that register the runtime — they are wiring, not observable behaviour.
- `MappingService::executeMapping` — that lives in cluster `mapping-and-search`
  (Wave 5).
- The `REQUIRED_PARAMETERS_*` constants are referenced in REQ-001 prose but not
  spelled out as their own REQ.

## Validation

After archive, `openspec validate authentication-twig --strict` MUST pass and Specter
MUST register the spec as part of the retrofit cohort.
