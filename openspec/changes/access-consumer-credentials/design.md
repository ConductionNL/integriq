# Design: access-consumer-credentials

Kind: code. Size M. The consumer schema, `AuthorizationService`, `EndpointService::processAuthenticationRule()`, and the consumer editor.

## Context at development 92f282bc

- Consumer schema: `lib/Settings/integriq_register.json` (consumer, properties `authorizationType`, `authorizationConfiguration`, `domains`, `ips`, `rateLimit`, `quota`), with `lib/Settings/register.d/99-consumer-secrets-writeonly.json` marking `authorizationConfiguration` write-only.
- `AuthorizationService::authorizeApiKey()` (`lib/Service/AuthorizationService.php:810`) and `resolveConsumerByApiKey()` (`:872`) match the presented key against each consumer's stored plaintext.
- `EndpointService::processAuthenticationRule()` (`lib/Service/EndpointService.php:2948`) switches on one `authentication.type` per rule.
- The consumer editor lives in `src/modals/v2/` with `consumerDraft.js` holding the type list (`:72`) and the rules that decide when a stored credential is kept or retired.

## D1. Credentials are a list, keys are hashed

A consumer gains `credentials`, an array. Each entry: `id`, `label`, `type`, `createdAt`, `expiresAt`, `lastUsedAt`, and for an API key `keyHash` plus a short `keyPrefix` (the first six characters, shown so a person can tell keys apart). `keyHash` is an HMAC-SHA256 of the key under an instance pepper held in the credential broker, so a lookup is one hash and one indexed filter, not a scan with `hash_equals()` over plaintext.

A keyed hash is verification material, not a secret: it cannot be turned back into the key. ADR-064 decision 1 forbids secrets on objects; this change removes the plaintext key, it does not add one. `credentials` is still marked write-only for the hash field, since there is no reason to read it back.

The existing single `authorizationConfiguration.apiKey` is migrated: a repair step hashes it into a first credential entry labelled "migrated", then nulls the plaintext only after the entry is written (ADR-064 decision 6, step 2). `authorizationType` stays for the JWT and Basic paths.

Rejected: two fixed fields, `apiKey` and `apiKeyNext`. It covers rotation and nothing else, and it keeps plaintext.

## D2. Generate and reveal once

`POST /api/consumers/{id}/credentials` generates a 32-byte random key, stores its hash, and returns the key in that one response. The editor shows it in a dialog with a copy button and the sentence "Copy this key now. You cannot see it again." Closing the dialog drops it from memory. There is no read route for a key.

## D3. Rotation is add, observe, revoke

Two keys are valid at once. `lastUsedAt` is written at most once a minute per credential, so the list shows when the old key stopped being used. `DELETE /api/consumers/{id}/credentials/{credentialId}` revokes one. An optional `expiresAt` lets the administrator set the old key to stop on a date instead.

## D4. Client certificates

The web server terminates TLS. Integriq reads the verified certificate from `SSL_CLIENT_VERIFY` and `SSL_CLIENT_CERT` in the server environment, or, behind a reverse proxy, from one configured header (default `X-SSL-Client-Cert`) accepted only when the request comes from a Nextcloud trusted proxy (`IRequest::getRemoteAddress()` honours `trusted_proxies`). A credential of type `clientCertificate` pins either the SHA-256 fingerprint, or the issuing CA fingerprint plus a subject pattern. For PKIoverheid certificates the OIN is read from the subject `serialNumber` and shown on the consumer. `PkiOverheidCredentialResolver` (`lib/Adapters/Digikoppeling/PkiOverheidCredentialResolver.php:49`) already parses these subjects for outbound use and is reused for parsing.

A new authentication type `mtls` on an endpoint rule passes when the presented certificate matches any `clientCertificate` credential of any consumer allowed on the endpoint, and records that consumer as the resolved one for rate limits and quotas.

## D5. Any one of several methods

An endpoint rule's `authentication` may carry `anyOf`, a list of method configurations (`apikey`, `jwt`, `basic`, `oauth`, `mtls`). `processAuthenticationRule()` tries each in order and passes on the first success. On failure the 401 lists each method's reason. A rule without `anyOf` behaves as today.

## Declarative versus imperative

No lifecycle, aggregation, notification or relation behaviour. Credential checks are request-time authorization logic and stay in `AuthorizationService`.

## Seed data

The seeded consumers keep working after the repair step. One example consumer gets two API key credentials, one with `expiresAt` in the past, so the list shows an expired key.

## Risks

- The repair step fails halfway. Mitigation: per consumer, the plaintext is nulled only after its hashed entry is saved, so a failure leaves that consumer working.
- A reverse proxy forwards a forged certificate header. Mitigation: the header is read only from a trusted proxy address, and ignored otherwise.
