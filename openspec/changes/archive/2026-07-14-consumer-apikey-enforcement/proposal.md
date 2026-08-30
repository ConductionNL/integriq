---
kind: code
depends_on: []
---

# openconnector — enforce Consumer apiKey authentication on inbound endpoint requests

## Why

`consumer-management` `REQ-CON-001` already states that the system SHALL enforce
consumer-level authentication by *"resolving the `consumer` record associated
with the request and checking that the caller's credentials match the configured
`authorizationType` (none, apiKey, jwt, basic, oauth2)"*, and documents the
scenario **"missing API key is rejected → HTTP 401"**.

Verified at HEAD, that control is **declared but not enforced for apiKey** — the
recurring "orphaned capability" defect class:

- `AuthorizationService::authorizeApiKey()` validated the presented key **only**
  against the `keys` map stored inline on the endpoint's *authentication rule*
  (`configuration.authentication.keys`, shape `apiKeyValue => nextcloudUserId`).
  It never read any `consumer` record.
- Nothing anywhere read `Consumer.authorizationType: apiKey` or
  `Consumer.authorizationConfiguration.apiKey`. The **only** code that resolves a
  `consumer` from a request is the JWT path (`authorizeJwt()` → `findIssuer()`
  by `iss`). The service comment stated outright: *"Other methods
  (apikey/basic/oauth) authenticate a Nextcloud user rather than a consumer and
  therefore return null."*
- Consequence: a Consumer configured with `authorizationType: apiKey` and an
  `apiKey` credential enforced **nothing** — that key was dead. A valid Consumer
  apiKey did not authenticate; and because no consumer was ever resolved on the
  apiKey path, `getResolvedConsumer()` returned null, so the inbound
  rate-limit/quota (`REQ-CON-RL-002`) also silently did not apply to apiKey
  consumers.

The rule-inline apiKey path itself *did* fail closed (a wrong/absent key on an
endpoint carrying an authentication rule returns 401/403). But the
Consumer-model apiKey control promised by `REQ-CON-001` was inert. This change
makes it real and keyed to the Consumer record.

## What Changes

- `AuthorizationService::authorizeApiKey()` becomes **Consumer-backed** while
  preserving the pre-existing rule-inline behaviour. Order of resolution, first
  match wins:
  1. Rule-inline `keys` map (unchanged — backward compatible).
  2. The `consumer` record whose `authorizationType` is `apiKey`
     (case-insensitive) and whose `authorizationConfiguration.apiKey` equals the
     presented key under a constant-time `hash_equals()` comparison.
- On a Consumer match the consumer is recorded as the **resolved consumer** (so
  the inbound rate-limit/quota choke point keys on it, exactly like the JWT
  issuer path) and, when the consumer names a `userId`, that Nextcloud user is
  set on the session.
- **Fail-closed**: when neither a rule-inline key nor a Consumer apiKey matches,
  an `AuthenticationException` is thrown, which the endpoint runtime converts to
  HTTP 401. An empty presented key never matches and never queries the store.
- No new crypto: comparison uses the existing `hash_equals()` seam; consumer
  lookup uses the existing `orObjectService->findAll()` seam already used by
  `findIssuer()`.

Out of scope (noted for follow-up on issue #159): `Consumer.domains` /
`Consumer.ips` scoping (Claim 2) and a declarative Consumer import handler
(Claim 3) remain unenforced/absent and are tracked separately.

## Impact

- Affected spec: `consumer-management` (`REQ-CON-001` clarified — apiKey path is
  Consumer-backed and fail-closed).
- Affected code: `lib/Service/AuthorizationService.php`.
- Backward compatible: endpoints relying on rule-inline `keys` are unchanged;
  consumers that never set an apiKey are unaffected.
