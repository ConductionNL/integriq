# consumer-management — Delta: enforce Consumer apiKey authentication

## Purpose

Closes the declared-but-unenforced part of `REQ-CON-001`. The system already
resolved a `consumer` from a JWT `iss` claim, but the **apiKey** case was inert:
inbound apiKey validation only ever compared against keys stored inline on an
endpoint's authentication rule, never against a `consumer` record, so a Consumer
configured `authorizationType: apiKey` enforced nothing. This delta makes the
apiKey path Consumer-backed and fail-closed, so a Consumer's configured apiKey is
actually checked and the Consumer is resolved (enabling `REQ-CON-RL-002`
rate-limiting on apiKey consumers).

## MODIFIED Requirements

### REQ-CON-001: Consumer authentication enforcement

The system SHALL enforce consumer-level authentication on inbound calls to
OpenConnector endpoints by resolving the `consumer` record associated with the
request and checking that the caller's credentials match the configured
`authorizationType` (none, apiKey, jwt, basic, oauth2). Requests failing
consumer auth SHALL receive HTTP 401 (or HTTP 403 when the credential is absent
on a protected endpoint).

For `authorizationType: apiKey`, the system SHALL resolve the `consumer` whose
`authorizationConfiguration.apiKey` matches the presented key under a
constant-time comparison, and SHALL record it as the resolved consumer for the
request. A presented key that matches no such consumer (and no rule-inline key)
SHALL be rejected fail-closed; an empty presented key SHALL never match. This
enforcement SHALL be additive to, and not regress, the pre-existing rule-inline
key path.

@e2e exclude backend consumer auth enforcement — covered by PHPUnit/Newman, not browser UI

#### Scenario: missing API key is rejected

- **GIVEN** a consumer with `authorizationType: apiKey`
- **WHEN** a request arrives without a matching API key header
- **THEN** the response is HTTP 401 (or 403 when the credential is absent)
- @e2e exclude backend enforcement — covered by PHPUnit

#### Scenario: a valid Consumer apiKey authenticates and resolves the consumer

- **GIVEN** a consumer with `authorizationType: apiKey` and a configured
  `authorizationConfiguration.apiKey`
- **WHEN** a request presents that exact key on the configured header
- **THEN** authentication passes AND that consumer is the resolved consumer for
  the request (so its `rateLimit`/`quota` apply per `REQ-CON-RL-002`)
- @e2e exclude backend enforcement — covered by PHPUnit

#### Scenario: a wrong API key is rejected

- **GIVEN** a consumer with `authorizationType: apiKey` and a configured apiKey
- **WHEN** a request presents a different key
- **THEN** the response is HTTP 401 AND no consumer is resolved AND no data is served
- @e2e exclude backend enforcement — covered by PHPUnit

#### Scenario: a non-apiKey consumer is never matched via the apiKey path

- **GIVEN** a consumer whose `authorizationType` is not `apiKey`
- **WHEN** a request presents a key equal to a value in that consumer's config
- **THEN** the apiKey path does not authenticate it
- @e2e exclude backend enforcement — covered by PHPUnit

#### Scenario: authorizationType none passes regardless of headers

- **GIVEN** a consumer with `authorizationType: none`
- **WHEN** any request arrives on a matched endpoint
- **THEN** auth passes regardless of headers
