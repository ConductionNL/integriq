# authorization-jwt Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- consumer-auth-on-openregister

## Purpose

Integriq's inbound credential checks run in OpenRegister's AuthorizationService (Hydra gate 23, decisions 56, 62 and 64). Integriq supplies its consumers and keeps its token lifetime cap; OpenRegister verifies. Requirements REQ-001 to REQ-005 keep their outcomes; this delta says where the checks run and what integriq still owns.

## ADDED Requirements

### Requirement: integriq consumers are checked by OpenRegister (REQ-006)

Integriq MUST verify inbound JWT, Basic, OAuth, API-key and Nextcloud-session credentials by calling OpenRegister's public AuthorizationService entry points, and MUST NOT verify a signature, password or key with code of its own. For JWT and consumer API keys it MUST pass a consumer source that reads integriq's `consumer` schema objects, so integriq keeps its schema and its data. After a check that resolved a consumer, the endpoint runtime MUST receive that consumer's object, so rate limits, quotas, consumer scope and call logs key on the same consumer as before. Every refusal MUST reach the caller as integriq's AuthenticationException and become a 401.

#### Scenario: a token signed with the consumer's key gets in
- GIVEN integriq consumers `hs-consumer` (HS256 secret), `rs-consumer` (RS256 public key) and `ps-consumer` (PS256 public key), each acting as user `alice`
- WHEN a caller presents a token signed with the matching key on an endpoint with a `jwt` or `jwt-zgw` authentication rule
- THEN the call passes, `alice` acts for the request and the runtime's resolved consumer is that consumer's object
- @e2e exclude token verification has no browser surface; covered by PHPUnit InboundCredentialCallersTest

#### Scenario: a token for the wrong consumer is refused
- GIVEN the same consumers
- WHEN the token names an issuer that is no consumer, is signed with another key, or carries a header algorithm other than the consumer's
- THEN the endpoint answers 401 with "The issuer was not found" or "The token could not be validated" and nobody acts for the request
- @e2e exclude covered by PHPUnit InboundCredentialCallersTest

#### Scenario: a reused jti and a future iat are refused
- GIVEN a token with jti `once-only` that was accepted once
- WHEN it is presented again, or a token is issued an hour in the future
- THEN the endpoint answers 401 with "The token has already been used (jti replay)" or "The token has an invalid issue time"
- @e2e exclude covered by PHPUnit InboundCredentialCallersTest

#### Scenario: a consumer API key resolves its consumer
- GIVEN consumer `key-consumer` of authorizationType `apiKey`
- WHEN a caller presents its key on an `apikey` rule with no rule-inline keys
- THEN the call passes as that consumer, and a wrong key is refused with "Invalid API key"
- @e2e exclude covered by PHPUnit InboundCredentialCallersTest and OpenRegisterCredentialBridgeApiKeyTest

### Requirement: integriq keeps its token lifetime cap (REQ-007)

Integriq MUST refuse a token whose `exp` lies more than 3600 seconds after its `iat`, on the endpoint runtime and on the LTI timing check, after OpenRegister accepted the token. A refusal MUST leave neither the consumer's user acting nor a resolved consumer. A lifetime of exactly 3600 seconds MUST pass.

#### Scenario: a two-hour token is refused
- GIVEN a token signed correctly for `hs-consumer`, `rs-consumer` or `ps-consumer` with `exp = iat + 7200`
- WHEN it is presented on a `jwt` rule
- THEN the endpoint answers 401 with "The token lifetime exceeds the maximum allowed duration", nobody acts for the request and no consumer is resolved
- @e2e exclude covered by PHPUnit InboundCredentialCallersTest

#### Scenario: the LTI timing check keeps the cap
- GIVEN an LTI launch payload with `exp = iat + 7200`
- WHEN `LtiLaunchService::validateTiming()` runs
- THEN it throws LtiValidationException 401 with the same message
- @e2e exclude covered by PHPUnit InboundCredentialCallersTest

### Requirement: an OpenRegister without the public checks is refused (REQ-008)

On an OpenRegister that lacks the ConsumerSource interface or any of the public entry points, every inbound credential check MUST be refused with "Inbound authentication is unavailable" and a reason that says to update OpenRegister. Integriq MUST NOT fall back to a check of its own and MUST NOT call OpenRegister's protected methods.

#### Scenario: an OpenRegister from before #4361
- GIVEN an OpenRegister whose AuthorizationService has the checks protected and no ConsumerSource interface
- WHEN any of the JWT, API-key, Basic, OAuth, Nextcloud-session or payload checks is called
- THEN each is refused with integriq's AuthenticationException, nobody acts for the request and no consumer is resolved
- @e2e exclude covered by PHPUnit OpenRegisterCredentialBridgeOldOpenRegisterTest
