---
kind: code
depends_on: [access-oauth-and-token-validation]
---

# Proposal: access-consumer-credentials

## Summary

A consumer holds one credential today, typed by an administrator, stored on the consumer object, and replacing it means downtime for the partner. This change gives a consumer several credentials at once so a key can be rotated without an outage, generates each key on the server and shows it exactly once, accepts a client certificate as a credential, and lets one endpoint accept any one of several login methods.

## Why

Four rows of integriq's capability matrix, access area, decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `integriq:acc-multi-auth` | no | build: a featureRequest demand row plus three competitors yes |
| `integriq:acc-mtls-in` | no | build: four competitors yes |
| `integriq:acc-secret-rotation` | no | build: two competitors yes |
| `integriq:acc-secret-reveal-once` | partial, built | build, riding with `acc-secret-rotation`: its missing half is the same generate-and-reveal screen |

Demand and competitor cells, quoted from the matrix:

- `acc-multi-auth`: featureRequest https://github.com/TykTechnologies/tyk/issues/2623, "OR logic for multiple authentication modes on one API". APISIX 3.18.0 `apisix/plugins/multi-auth.lua:27` "accepts a caller that passes any one of them". Tyk v5.15.0 `apidef/oas/authentication.go:22` compliant mode with OR logic. WSO2 v4.7.0 publisher offers API key and OAuth on one API.
- `acc-mtls-in`: MuleSoft https://docs.mulesoft.com/gateway/latest/policies-included-tls.md "Transport Layer Security (TLS) Inbound, enables authentication between a client and the API proxy". Tyk v5.15.0 `apidef/oas/server.go:19` client certificate allowlist, APISIX 3.18.0 `schema_def.lua:831` `client.ca`, WSO2 v4.7.0 "If Mutual SSL option is selected, a trusted client certificate should be" uploaded.
- `acc-secret-rotation`: changelog https://apim.docs.wso2.com/en/latest/get-started/about-this-release/ (WSO2 API Manager 4.7.0, multiple client secrets per application). APISIX 3.18.0 `apisix/admin/credentials.lua:48` "a consumer can hold several credentials at once".
- `acc-secret-reveal-once`: WSO2 v4.7.0 devportal "Please make a note of the generated consumer secret value as it will be" shown once.

## What integriq already has

- `authorizationConfiguration` on the consumer holds one `apiKey`, or one `publicKey` and `algorithm` (`lib/Settings/integriq_register.json`, consumer). `99-consumer-secrets-writeonly.json` marks it write-only, so it is never read back: stronger than shown once, but there is no generate-and-copy moment either.
- `AuthorizationService::resolveConsumerByApiKey()` (`lib/Service/AuthorizationService.php:872`) loads every consumer and compares the stored plaintext key with `hash_equals()`.
- `EndpointService::processRules()` (`lib/Service/EndpointService.php:2472`) runs every rule in turn, and `processAuthenticationRule()` (`:2948`) returns 401 when its one configured type fails. Two authentication rules stack; there is no either-or.
- Every mTLS class in `lib/Service/Mtls/` is outbound. Nothing inbound reads a client certificate.

## What this change builds

1. A `credentials` list on a consumer: each entry has an id, a label, a type (`apiKey` or `clientCertificate`), a created and an optional expiry date, and the last time it was used. An API key is stored as a keyed hash, never as plaintext.
2. Generate a key on the server and show it once, with a copy button and a warning. It cannot be shown again.
3. Rotation: add a second key, see which key each call used, then revoke the old one.
4. A client certificate as a credential: pinned by SHA-256 fingerprint, or by issuing CA plus subject, with the PKIoverheid OIN read from the subject.
5. `anyOf` on an endpoint's authentication rule: the call passes when one listed method passes.

## Out of scope

- JWKS, OIDC and integriq-issued tokens. `access-oauth-and-token-validation`.
- TLS termination in PHP. The web server verifies the certificate chain; integriq checks what the web server hands over.
- Source (outbound) credentials. They already go through the credential broker (`migrate-inline-secrets-to-broker`).
