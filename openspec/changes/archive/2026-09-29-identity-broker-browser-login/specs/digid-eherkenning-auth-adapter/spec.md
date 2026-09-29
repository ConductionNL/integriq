# digid-eherkenning-auth-adapter Specification

## ADDED Requirements

### Requirement: A login starts at integriq with a signed, single-use state (REQ-IDP-001)

Integriq MUST offer a browser start address per provider that takes the organisation, the consumer, the requested trust, a return address and a relay state. It MUST refuse an unknown or disabled consumer, a return address not registered for that consumer, a disabled broker and an unconfigured adapter, showing its own error page and redirecting nowhere. Otherwise it MUST store a signed state valid once for at most five minutes and MUST send the browser to the identity provider through the adapter.

#### Scenario: a resident starts a DigiD login from the portal
- GIVEN portaliq registered with the return address `https://portal.example.nl/portal/api/session/broker/callback`
- WHEN the browser arrives at `/api/idp/digid/start` for organisation `gemeente-x`, trust `substantial`, with that address and a relay state
- THEN a state is stored and the browser is sent to the identity provider
- @e2e exclude cross-app browser redirect; covered by Newman with the scripted adapter

#### Scenario: a return address that is not registered
- GIVEN portaliq registered with one return address
- WHEN the start is called with another address
- THEN integriq's error page shows and the browser is not redirected
- @e2e exclude cross-app browser redirect; covered by Newman

### Requirement: The callback returns the browser with a one-time code (REQ-IDP-002)

The callback MUST read the assertion through the adapter, MUST consume the state it answers, MUST run the assertion and replay guards, and MUST mint the envelope with the state's consumer as audience and the state's organisation. It MUST then issue a one-time code and redirect to the state's return address with the code and the relay state. Every failure MUST redirect to that return address with one generic error and the relay state, and MUST log the real reason. An assertion that answers no stored state MUST be refused.

#### Scenario: the resident comes back signed in
- GIVEN a stored state for portaliq and a DigiD assertion answering it
- WHEN the callback runs
- THEN the browser is redirected to portaliq's return address with a code and the relay state, and redeeming the code at the exchange yields one envelope whose audience is `portaliq` and whose subject is a pseudonym
- @e2e exclude cross-app browser redirect; covered by a Newman round trip with the scripted adapter

#### Scenario: an identity provider starts a login on its own
- GIVEN an assertion that answers no stored state
- WHEN it reaches the callback
- THEN it is refused and no code is issued
- @e2e exclude backend guard; covered by PHPUnit

### Requirement: A consuming app is registered with its return addresses (REQ-IDP-003)

Each consuming app MUST be registered with a secret held by broker reference, a list of allowed return addresses and an enabled flag. A consumer registered in the older form, with only a secret, MUST still be able to redeem a code and MUST NOT be able to start a login. Integriq MUST ship a disabled `portaliq` entry and an `occ` command to set a consumer's addresses and secret reference.

#### Scenario: an administrator enables portaliq
- GIVEN the seeded disabled `portaliq` consumer
- WHEN the administrator runs the consumer command with the portal's return address and a secret reference
- THEN portaliq can start a login and redeem codes
- @e2e exclude occ command; covered by PHPUnit

### Requirement: An eHerkenning envelope carries the branch the login was restricted to (REQ-IDP-004)

When an eHerkenning assertion restricts the login to a branch, the envelope MUST carry that branch number as the claim `branch`. An envelope from any other provider, or from an assertion without a branch, MUST NOT carry the claim.

#### Scenario: an employee signs in for one branch
- GIVEN an eHerkenning assertion for KvK 12345678 restricted to branch 000012345678
- WHEN integriq mints the envelope
- THEN the envelope carries `sub` 12345678, `subType` kvk and `branch` 000012345678
- @e2e exclude backend envelope; covered by PHPUnit
