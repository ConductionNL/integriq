# endpoint-runtime Specification

## ADDED Requirements

### Requirement: Path placeholders reach the upstream path (REQ-SCB-004)

An endpoint's address MAY name placeholders such as `{owner}`. Integriq MUST take their values from the incoming path. For an endpoint that proxies a source, it MUST put those values, and the query and body values, into the upstream path template before the call.

#### Scenario: a repository path is passed on
- GIVEN an endpoint `/labels/{owner}/{repo}` that proxies to `/hydra/label/{owner}/{repo}`
- WHEN a consumer calls `/labels/conduction/integriq`
- THEN the source receives a call on `/hydra/label/conduction/integriq`
- @e2e exclude gateway dispatch; covered by PHPUnit on EndpointService

### Requirement: Endpoints answer without a Nextcloud session (REQ-SCB-005)

Every endpoint MUST be reachable without a Nextcloud login. An endpoint that needs a caller to identify itself MUST say so with an authentication rule in its rule pipeline. Without such a rule, anyone may call it.

#### Scenario: a public data endpoint answers an anonymous caller
- GIVEN an endpoint with no authentication rule
- WHEN a caller without a session or key calls it
- THEN the endpoint answers with its data
- @e2e exclude gateway dispatch; covered by Newman

#### Scenario: an authentication rule closes it
- GIVEN the same endpoint with an authentication rule
- WHEN a caller without a key calls it
- THEN the answer is 401 and the target is not called
- @e2e exclude gateway dispatch; covered by Newman

### Requirement: A saved endpoint applies on the next request (REQ-SCB-006)

When an endpoint is created, changed or deleted, integriq MUST clear its endpoint cache. The next request MUST be resolved against the stored endpoints, without a restart.

#### Scenario: a changed address is used at once
- GIVEN an endpoint that has served requests on `/zaken`
- WHEN an administrator changes its address to `/cases` and saves
- THEN the next request to `/cases` reaches it, and `/zaken` answers 404
- @e2e exclude cache invalidation; covered by PHPUnit on the invalidation listener
