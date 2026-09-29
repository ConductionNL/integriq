# webhook-signing Specification (delta)

## ADDED Requirements

### Requirement: Inbound verification reads the Microsoft Teams scheme (REQ-WHS-005)

The `webhook_signature` configuration SHALL accept a fourth scheme, `teams`.
Under it the rule MUST read the configured header (Teams sends
`Authorization`), MUST accept the value in the form `HMAC <base64>`, and MUST
compare it in constant time against the base64-encoded HMAC-SHA256 of the RAW
request body under the base64-DECODED shared secret, which is how Teams signs
an outgoing webhook.

The scheme carries no timestamp. `toleranceSeconds` SHALL be ignored with a
logged warning, the way `github` already ignores it, rather than refused.

A failure SHALL be handled exactly as every other scheme's failure is: HTTP
401, an undifferentiated body, and no downstream rule executed. An
unverifiable payload on a route that opens cases SHALL NOT be accepted, and
there SHALL be no configuration that lets it be.

#### Scenario: A correctly signed Teams post passes

- **GIVEN** an endpoint with a `webhook_signature` rule (`scheme: teams`) and the secret Teams was configured with
- **WHEN** Teams posts a message signed `HMAC <base64>` over the raw body
- **THEN** the rule SHALL pass and the intake pipeline SHALL run

#### Scenario: The secret is used base64-decoded

- **GIVEN** the same endpoint, and a sender that signed under the literal secret string instead of its decoded bytes
- **WHEN** the request arrives
- **THEN** the response SHALL be HTTP 401
- **AND** no message SHALL have been created

#### Scenario: Tolerance is ignored rather than refused

- **GIVEN** a `teams` rule configured with a `toleranceSeconds` value
- **WHEN** a correctly signed request arrives
- **THEN** it SHALL pass
- **AND** the ignored setting SHALL be logged as a warning
