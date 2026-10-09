# consumer-management Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- access-oauth-and-token-validation

## Purpose

A consumer reaches only the endpoints and actions its scopes allow. Row `integriq:acc-scopes`.

## ADDED Requirements

### Requirement: A consumer is limited to the endpoints and actions its scopes allow (REQ-TOKV-005)

Integriq MUST store a list of scopes on a consumer and MUST let an endpoint rule require scopes per HTTP method. When an endpoint requires scopes, a caller MUST hold all of them, from its token's `scope` claim or from its consumer's stored scopes, or receive 403 naming the missing scope. An endpoint without required scopes MUST behave as before.

#### Scenario: a read-only consumer cannot write
- GIVEN an endpoint that requires `zaken:write` on POST and a consumer with only `zaken:read`
- WHEN the consumer posts to it
- THEN the answer is 403 and names `zaken:write`, and a GET from the same consumer passes
- @e2e exclude request-time check; covered by PHPUnit and Newman

#### Scenario: an administrator sees who a new requirement locks out
- GIVEN an administrator adding `zaken:write` to an endpoint's POST rule
- WHEN they open the save dialog
- THEN the dialog lists the consumers that call this endpoint and lack the scope
- e2e: `tests/e2e/endpoint-required-scopes.spec.ts`
