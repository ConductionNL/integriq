# authorization-jwt Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- access-consumer-credentials

## Purpose

An endpoint can require a client certificate and can accept any one of several login methods. Rows `integriq:acc-mtls-in` and `integriq:acc-multi-auth`.

## ADDED Requirements

### Requirement: An endpoint can require a client certificate (REQ-CRED-004)

Integriq MUST offer an `mtls` authentication type on an endpoint rule. It MUST read the client certificate the web server verified, and from a forwarding header only when the request comes from a configured trusted proxy. A call MUST pass only when the certificate matches a pinned client certificate credential of a consumer allowed on the endpoint.

#### Scenario: a municipality's system calls with its PKIoverheid certificate
- GIVEN a consumer with a client certificate credential pinned to an issuing CA and a subject with OIN 00000001234567890000
- WHEN that system calls an endpoint of type `mtls` with the certificate verified by the web server
- THEN the call passes as that consumer, and the call log shows the OIN
- @e2e exclude TLS client authentication cannot be driven from the browser test; covered by PHPUnit with fixture certificates

#### Scenario: a forged header from outside is ignored
- GIVEN a request from an address that is not a trusted proxy
- WHEN it carries an `X-SSL-Client-Cert` header
- THEN integriq ignores the header and answers 401
- @e2e exclude covered by PHPUnit

### Requirement: An endpoint accepts any one of several login methods (REQ-CRED-005)

Integriq MUST let an endpoint's authentication rule list several methods under `anyOf`. A call MUST pass when any one listed method passes. When none passes, the 401 MUST give each method's reason.

#### Scenario: old and new partners share an endpoint
- GIVEN an endpoint whose rule lists `apikey` and `jwt` under `anyOf`
- WHEN one partner calls with an API key and another with a JWT
- THEN both calls pass, and a call with neither gets 401 naming both reasons
- @e2e exclude request-time check; covered by PHPUnit and Newman
