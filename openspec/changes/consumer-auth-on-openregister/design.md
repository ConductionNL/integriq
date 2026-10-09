# Design: consumer-auth-on-openregister

## Context

OpenRegister's `AuthorizationService` (development since #4361) has public `authorizeJwt($header, ?ConsumerSource)`, `authorizeApiKey($header, $keys, ?ConsumerSource)`, `authorizeBasic`, `authorizeOAuth`, `authorizeNcSession`, `validatePayload`, `corsAfterController` and `getResolvedConsumer(): ?ResolvedConsumer`. Verification lives in `JwtValidator` (HMAC, `RsaJwsVerifier` for RS and PS) and the allow-lists in `EndpointAllowList`. The algorithm is pinned from the consumer's stored configuration, never from the token header.

## Decisions

### D1: integriq's consumers reach OpenRegister through a ConsumerSource

`IntegriqConsumerSource implements ConsumerSource` reads register `integriq`, schema `consumer` with `ObjectService::findAll(..., _rbac: false, _multitenancy: false)`, exactly as the removed class did. It maps an object to `ResolvedConsumer` with `source: 'integriq'`, `configuration` = the object's `authorizationConfiguration` (where `algorithm`, `publicKey` and `apiKey` live) and the `ObjectEntity` itself in `record`. Only consumers of `authorizationType` `apiKey` answer `findByApiKey`; an empty key never matches; the comparison is `hash_equals`.

### D2: a bridge, not a second service

`OpenRegisterCredentialBridge` keeps the method names the callers already used, so the six callers change only their type. It holds no verification of its own. It:

- passes the consumer source to every consumer-backed check;
- turns OpenRegister's `AuthenticationException` into integriq's, which every caller already catches and turns into a 401;
- returns the `ObjectEntity` from `ResolvedConsumer::$record`, so rate limits, quotas, consumer scope and call logs keep keying on the same object;
- clears the resolved consumer at the start of every check.

The name says what it does and does not end in `AuthorizationService`: the checks it fronts are OpenRegister's.

### D3: the lifetime cap stays in integriq, after OpenRegister

OpenRegister accepts any caller `exp` (Q4 open). Integriq refused `exp - iat > 3600` and still does: `capLifetime()` runs after OpenRegister accepted the token (JWT) or the claims (LTI). Because OpenRegister has by then set the consumer's user for the request, a refusal clears it (`setVolatileActiveUser(null)`) before the 401. If Ruben chooses a cap in OpenRegister (the gap note: one line in `JwtValidator::validateClaims()`), this check becomes redundant and can go.

### D4: an older OpenRegister is refused, never bypassed

On an OpenRegister before #4361 the class exists with the checks protected and the `ConsumerSource` interface does not exist. Loading `IntegriqConsumerSource` there would be a fatal error and calling a protected method another. The bridge checks `interface_exists(ConsumerSource::class)` and `is_callable()` on every entry point before the first call and refuses with "Inbound authentication is unavailable" (reason: update OpenRegister). There is no local fallback: a fallback is exactly the second copy gate 23 retires, and it would be the looser path on a stale instance.

### D5: the jti cache moves to OpenRegister

OpenRegister remembers a jti in `openregister.jti`. integriq's `integriq.jti` is no longer read. A token used once in the last hour before the upgrade can be presented once more after it; integriq's cap bounds that window to one hour. No migration of cache entries: the cache is short-lived by design.

### D6: tests run OpenRegister's real code

The unit bootstrap loads OpenRegister's `AuthorizationService`, `JwtValidator`, `RsaJwsVerifier`, `EndpointAllowList`, `ConsumerSource`, `ResolvedConsumer` and `AuthenticationException` from byte-for-byte copies under `tests/stubs/OCA/OpenRegister/` (openregister development 75088d6237), guarded by `class_exists()` so the installed app wins on CI's server leg. Only `ConsumerMapper` is a stand-in, created without its constructor, because integriq never lets the service read OpenRegister's table. phpstan (`scanFiles`) and psalm (`<stubs>`) read the same copies.

## Risks

- A future OpenRegister that renames an entry point is refused (D4), not silently bypassed. The refusal names the cause.
- The copies under `tests/stubs` can drift from OpenRegister. CI's server leg runs the installed OpenRegister, so drift shows up there as a difference between the legs.
