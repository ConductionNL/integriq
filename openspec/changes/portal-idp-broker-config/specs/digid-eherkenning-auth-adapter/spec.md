# digid-eherkenning-auth-adapter Specification (delta)

## MODIFIED Requirements

### Requirement: The callback returns the browser with a one-time code (REQ-IDP-002)

The callback MUST read the assertion through the adapter bound to the registration of the state's organisation, MUST consume the state it answers, MUST run the assertion and replay guards, and MUST mint the envelope with the state's consumer as audience and the state's organisation. It MUST then issue a one-time code and redirect to the state's return address with the code and the relay state. Every failure MUST redirect to that return address with one generic error and the relay state, and MUST log the real reason, with one exception: an assertion below the assurance floor (idp-broker-configuration REQ-IDPC-006) MUST show integriq's own refusal page, whose one action leads to the return address with `error=assurance_below_floor` and the relay state. An assertion that answers no stored state MUST be refused.

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

#### Scenario: a login below the floor is explained, not hidden
- GIVEN a stored state for portaliq and a DigiD assertion at level Basis answering it
- WHEN the callback runs
- THEN no code is issued and integriq's refusal page names the level used and the level needed
- AND its one action leads to portaliq's return address with `error=assurance_below_floor` and the relay state
- @e2e exclude cross-app browser redirect; the page itself is covered by the floor e2e in idp-broker-configuration
