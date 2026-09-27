# endpoint-runtime Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- gateway-upstream-routing

## Purpose

An endpoint spreads calls over several targets, tries a new release on a share of the traffic, and routes by what is in the request. Rows `integriq:gw-loadbalance`, `integriq:gw-canary` and `integriq:gw-content-route`.

## ADDED Requirements

### Requirement: An endpoint routes to one of several targets (REQ-UPRT-001)

Integriq MUST let an endpoint of type `api` carry a list of targets, each a source with a weight and an optional rule over the request's parameters, headers and JSON body. It MUST use the first group whose rule matches, else the targets without a rule, and MUST pick within the group by weight, or by a stable hash of the consumer when the endpoint is sticky. An endpoint without targets MUST behave as before.

#### Scenario: a new release gets five percent of the traffic
- GIVEN an endpoint with target `zaken-oud` at weight 95 and `zaken-nieuw` at weight 5
- WHEN consumers make a thousand calls
- THEN about fifty reach `zaken-nieuw`, and each call log names its target
- @e2e exclude traffic split; covered by PHPUnit

#### Scenario: archive requests go to the archive system
- GIVEN a target `zaken-archief` with a rule that matches `archief=true`
- WHEN a consumer calls with `?archief=true`
- THEN the call reaches `zaken-archief` and the call log gives the matched rule as the reason
- @e2e exclude covered by PHPUnit

### Requirement: A target that is down is skipped (REQ-UPRT-002)

Integriq MUST leave out a target whose source circuit breaker is open and cooling down. For GET, HEAD, PUT and DELETE it MUST retry once on another healthy target of the same group after a connection error or a 502, 503 or 504. It MUST NOT retry POST or PATCH. When no target is healthy it MUST answer 503 without calling any.

#### Scenario: one server fails and callers do not notice
- GIVEN two targets, one whose breaker has just opened
- WHEN a consumer sends a GET
- THEN the call goes to the healthy target and succeeds
- @e2e exclude covered by PHPUnit with fake sources

### Requirement: The call log records which target served a call (REQ-UPRT-003)

Integriq MUST record on each call log the target that served the call and the reason it was chosen. The endpoint page MUST show the split of calls per target.

#### Scenario: an administrator watches a canary
- GIVEN an endpoint in a canary split
- WHEN the administrator opens its page after a day of traffic
- THEN they see how many calls each target served
- e2e: `tests/e2e/endpoint-targets.spec.ts`
