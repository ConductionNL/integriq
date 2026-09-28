# http-call-engine delta

## ADDED Requirements

### Requirement: A source logs in with the login it declares (REQ-SDL-001)

When a source declares `auth: basic` with a username, integriq MUST send the username and password as HTTP Basic credentials on every call to it. When a source declares `auth: apikey` with a key, integriq MUST send the key in the header the source names in `authorizationHeader`, or in `Authorization` when it names none. The call log MUST NOT hold the password or the key.

#### Scenario: an administrator sets up a source with a username and password
- GIVEN a source with `auth` basic, username `koppeling` and a password
- WHEN integriq calls the source
- THEN the request carries HTTP Basic credentials for `koppeling`, and the call log shows the call with the credentials replaced by a placeholder
- @e2e exclude outbound request shape; covered by PHPUnit on CallService

#### Scenario: an administrator sets up a source with an API key
- GIVEN a source with `auth` apikey, `authorizationHeader` `X-Api-Key` and a key
- WHEN integriq calls the source
- THEN the request carries the key in `X-Api-Key`, and the call log does not show it
- @e2e exclude outbound request shape; covered by PHPUnit on CallService

### Requirement: An explicit login and the broker win (REQ-SDL-002)

A source whose `configuration.auth` is set, whose headers already carry the header the key would go in, or that holds a broker `credentialRef`, MUST be called exactly as before this requirement.

#### Scenario: a source that already works keeps working
- GIVEN a source that sends its key through a hand-written header `{{ source.apikey }}`
- WHEN integriq calls the source
- THEN the request carries that header once, as rendered from the hand-written template
- @e2e exclude outbound request shape; covered by PHPUnit on CallService
