# Tasks — enforce Consumer apiKey authentication

> Investigate first (done): confirmed at HEAD that `authorizeApiKey()` only
> checked rule-inline keys and never read any `consumer` record, leaving
> `Consumer.authorizationType: apiKey` (`REQ-CON-001`) inert. Fix wires
> Consumer-backed apiKey resolution onto the existing seam, fail-closed.

- [x] Make `AuthorizationService::authorizeApiKey()` Consumer-backed: after the
      existing rule-inline `keys` check (kept first, backward compatible),
      resolve the `consumer` whose `authorizationType` is `apiKey` and whose
      `authorizationConfiguration.apiKey` matches the presented key
- [x] Compare keys with `hash_equals()` (constant-time); an empty presented key
      never matches and never queries the consumer store
- [x] On a Consumer match, record it as the resolved consumer (so inbound
      rate-limit/quota `REQ-CON-RL-002` keys on it) and set the session user when
      the consumer names a `userId`; a consumer without a `userId` still authenticates
- [x] Fail closed: throw `AuthenticationException` (→ HTTP 401) when neither a
      rule-inline key nor a Consumer apiKey matches
- [x] Add `resolveConsumerByApiKey()` using the injected `orObjectService->findAll()`
      seam (register `openconnector`, schema `consumer`); no new dependency, no crypto
- [x] Unit tests (`AuthorizationServiceApiKeyTest`): valid consumer key →
      authenticates + resolves consumer; missing key → rejected (store not
      queried); wrong key → rejected + no consumer resolved; non-apiKey consumer
      ignored; rule-inline key still works; consumer without userId still authenticates
- [x] Update `consumer-management` spec delta: `REQ-CON-001` apiKey path is
      Consumer-backed and fail-closed
- [x] Run the unit suite in `oc-phpunit-83:local`; confirm no regressions vs the
      clean origin/development baseline
- [x] Run PHPCS on the changed lib file (`composer` standard); keep it clean

Acceptance criteria (verified by /opsx-verify):

- A request presenting a valid Consumer `apiKey` authenticates and the Consumer
  is the resolved consumer (rate-limit/quota now applies to apiKey consumers)
- A request with no apiKey or a wrong apiKey to an apiKey-protected endpoint is
  rejected 401/403 with no data served (fail-closed)
- A Consumer whose `authorizationType` is not `apiKey` is never matched via the
  apiKey path
- Pre-existing rule-inline `keys` endpoints and consumers without an apiKey are
  unaffected (backward compatible)
- Full unit suite green with no regressions; changed lib file PHPCS-clean
