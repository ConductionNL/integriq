# endpoint-runtime Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- gateway-response-cache-and-problem-errors

## Purpose

Repeated calls are answered from a cache, and every error from the gateway is a standard problem document. Rows `integriq:gw-cache` and `integriq:gw-problem-json`.

## ADDED Requirements

### Requirement: An endpoint can cache its answers (REQ-RCPE-001)

Integriq MUST let an administrator enable a response cache on an endpoint with a lifetime, the methods and status codes to cache, and the headers that vary the answer. The cache key MUST include the consumer unless the endpoint shares answers across consumers. Authentication and rate limits MUST run before a cache lookup. An upstream answer marked `private` or `no-store` MUST NOT be cached. An administrator MUST be able to clear one endpoint's cache.

#### Scenario: a busy lookup no longer reaches the source every time
- GIVEN an endpoint caching GET answers for 60 seconds
- WHEN the same consumer asks the same question twice within a minute
- THEN the source is called once, the second answer carries `X-Cache: HIT`, and the call log marks it as a hit
- @e2e exclude caching is server behaviour; covered by PHPUnit

#### Scenario: an administrator clears a stale answer
- GIVEN a cached endpoint whose source data just changed
- WHEN the administrator presses clear cache on the endpoint page
- THEN the next call reaches the source
- e2e: `tests/e2e/endpoint-cache.spec.ts`

### Requirement: Every gateway error is a problem document (REQ-RCPE-002)

Integriq MUST answer every error from an endpoint with an RFC 9457 problem document and the content type `application/problem+json`, with `type` a URI, `title`, `status`, `detail` and `instance`. Validation errors MUST list each field in `invalid-params`. For one release the document MUST also carry the old `error` and `details` members.

#### Scenario: a client reads a failed authentication
- GIVEN a consumer with a wrong key
- WHEN it calls a protected endpoint
- THEN the answer is 401 with content type `application/problem+json`, a `type` URI for authentication failures, and the old `error` member still present
- @e2e exclude response format; covered by PHPUnit and Newman
