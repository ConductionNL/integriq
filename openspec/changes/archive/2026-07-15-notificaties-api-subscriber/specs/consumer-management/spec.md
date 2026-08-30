# consumer-management — Delta: apiKey consumer authentication is a reusable public contract

## Purpose

Formalizes that `AuthorizationService::authorizeApiKey()`/`resolveConsumerByApiKey()` — the mechanism
behind REQ-CON-001's apiKey enforcement — is a public, DI-injectable contract usable by any controller, not
plumbing internal to the `endpoint-runtime` dispatch pipeline. `notificaties-api-connector`'s callback
controller is the first consumer outside `endpoint-runtime` to rely on this, and this delta locks the
contract in so a future refactor of `endpoint-runtime` cannot silently make `authorizeApiKey()`
private/endpoint-runtime-only without that being a breaking, spec-visible change.

## ADDED Requirements

### Requirement: apiKey consumer authentication MUST remain callable outside the endpoint-runtime dispatch path (REQ-CON-002)

`AuthorizationService::authorizeApiKey(string $header, array $keys)` MUST remain a public method on a
DI-injectable service (alongside its consumer-resolution behaviour, REQ-CON-001), callable by any
controller that needs to authenticate an inbound request against a `consumer` OR-object's
`authorizationConfiguration.apiKey` —
not refactored into a private helper reachable only from `EndpointsController`/`EndpointService`'s
dispatch path. A controller calling `authorizeApiKey()` directly (passing an empty `$keys` array when it
has no rule-inline keys of its own) MUST receive identical fail-closed, constant-time-comparison behaviour
to the endpoint-runtime call site: a presented key matching no `consumer` with `authorizationType = apiKey`
MUST throw `AuthenticationException`, and an empty presented key MUST never match.

#### Scenario: a non-endpoint-runtime controller authenticates via the same consumer apiKey path

- **GIVEN** a `consumer` with `authorizationType = 'apiKey'` and `authorizationConfiguration.apiKey =
  '<secret>'`
- **WHEN** a controller OTHER than `EndpointsController` calls `AuthorizationService::authorizeApiKey('<secret>',
  [])`
- **THEN** the call SHALL succeed and `getResolvedConsumer()` SHALL return that consumer — identical
  behaviour to the `endpoint-runtime`-mediated call site

#### Scenario: an unmatched key fails closed identically regardless of caller

- **GIVEN** the same consumer
- **WHEN** a non-endpoint-runtime controller calls `authorizeApiKey('wrong-key', [])`
- **THEN** an `AuthenticationException` SHALL be thrown
- **AND** `getResolvedConsumer()` SHALL return `null`
